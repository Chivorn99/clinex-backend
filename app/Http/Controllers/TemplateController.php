<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Services\TemplateAnalyzerService;
use App\Services\DocumentAiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Jobs\CreateTemplateFromPdf;
use Illuminate\Support\Facades\Log;
use App\Events\PdfProcessingProgress;
use Illuminate\Support\Str;
use App\Jobs\ProcessPdfForExtraction;


class TemplateController extends Controller
{
    protected $templateAnalyzer;

    public function __construct(TemplateAnalyzerService $templateAnalyzer)
    {
        $this->templateAnalyzer = $templateAnalyzer;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $templates = Template::orderBy('created_at', 'desc')->get();
        return view('templates.index', compact('templates'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // Check if we have temporary extracted data from session
        $aiData = session('extracted_pdf_data', null);

        return view('templates.create', [
            'aiData' => $aiData,
            'clinexFields' => [
                'patient_info' => ['patient_id', 'name', 'age', 'gender', 'lab_id', 'phone'],
                'table_columns' => ['test_name', 'value', 'unit', 'reference_range', 'flag'],
            ]
        ]);
    }

    /**
     * Process uploaded PDF and extract structure for template creation
     */
    public function processPdfForTemplate(Request $request)
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:10240', // Route expects 'pdf_file'
            'template_name' => 'required|string|max:255',
            'processor_id' => 'required|string'
        ]);

        try {
            $pdfFile = $request->file('pdf');
            $fileName = uniqid() . '.pdf';

            // Create the directory if it doesn't exist
            $tempDir = storage_path('app/temp_pdfs');
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            // Store the file directly with move()
            $fullPath = $tempDir . DIRECTORY_SEPARATOR . $fileName;

            if (!$pdfFile->move($tempDir, $fileName)) {
                throw new \Exception("Failed to save uploaded file");
            }

            // Verify file exists
            if (!file_exists($fullPath)) {
                throw new \Exception("File was not saved properly at: " . $fullPath);
            }

            Log::info("PDF successfully saved at: " . $fullPath);
            Log::info("File size: " . filesize($fullPath) . " bytes");

            // Dispatch the job
            CreateTemplateFromPdf::dispatch(
                $fullPath,
                $request->template_name,
                $request->processor_id,
                auth()->id()
            );

            return response()->json([
                'message' => 'Template creation started. Processing in background...',
                'status' => 'processing',
                'file_path' => $fullPath // For debugging
            ]);

        } catch (\Exception $e) {
            Log::error('Error starting template creation: ' . $e->getMessage());

            return response()->json([
                'error' => 'Failed to start template creation: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Extract data from PDF for template creation (doesn't save template)
     */
    public function extractFromPdf(Request $request)
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:10240',
        ]);

        try {
            $pdfFile = $request->file('pdf');
            $fileName = uniqid() . '.pdf';
            
            // Store temporarily
            $tempDir = storage_path('app/temp_pdfs');
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            $fullPath = $tempDir . DIRECTORY_SEPARATOR . $fileName;
            
            if (!$pdfFile->move($tempDir, $fileName)) {
                throw new \Exception("Failed to save uploaded file");
            }
            
            Log::info("PDF uploaded for extraction: " . $fullPath);

            // Process directly without job (for testing)
            $aiService = new \App\Services\DocumentAiService();
            $document = $aiService->processDocument($fullPath, 'a2439f686e4b0f79');

            if (!$document) {
                throw new \Exception("Failed to process document with Document AI");
            }

            // Transform data (simplified)
            $extractedData = $this->transformDocumentToStructureData($document);
            $transformedData = $this->transformStructureDataForTemplate($extractedData);

            // Clean up
            if (file_exists($fullPath)) {
                unlink($fullPath);
                Log::info("Cleaned up temporary file: " . $fullPath);
            }

            // Store the extracted data in a log file for analysis
            $jsonData = json_encode($transformedData, JSON_PRETTY_PRINT);
            $this->logExtractedData($jsonData, $fileName);

            // Also store in session for later access if needed
            session(['extracted_pdf_data' => $transformedData]);

            return response()->json([
                'success' => true,
                'message' => 'PDF data extracted successfully',
                'data' => $transformedData
            ]);

        } catch (\Exception $e) {
            Log::error('PDF extraction failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to extract PDF data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Log extracted data to a separate file for analysis
     */
    private function logExtractedData($jsonData, $fileName)
    {
        try {
            // Create directory if it doesn't exist
            $logDir = storage_path('logs/extractions');
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            // Create a log file with date and original filename
            $logFile = $logDir . '/' . date('Y-m-d_H-i-s') . '_' . pathinfo($fileName, PATHINFO_FILENAME) . '.json';
            file_put_contents($logFile, $jsonData);
            
            Log::info("Extracted data logged to: " . $logFile);
        } catch (\Exception $e) {
            Log::warning("Failed to log extracted data: " . $e->getMessage());
        }
    }

    /**
     * Transform structure data for template creation form
     */
    private function transformStructureDataForTemplate(array $structureData): array
    {
        $aiData = [
            'entities' => [],
            'tables' => []
        ];

        // Process entities
        foreach ($structureData['entities'] as $entity) {
            $aiData['entities'][] = [
                'type' => $entity['type'],
                'text' => $entity['mention_text'],
                'confidence' => $entity['confidence'],
                'normalized_value' => $entity['normalized_value']
            ];
        }

        // Process tables
        foreach ($structureData['tables'] as $index => $table) {
            if (empty($table['headers']) && empty($table['rows'])) {
                continue;
            }

            $aiData['tables'][] = [
                'index' => $index,
                'name' => $table['name'] ?? "Test Results " . ($index + 1),
                'headers' => $table['headers'],
                'rows' => $table['rows']
            ];
        }

        return $aiData;
    }

    /**
     * Transform Document AI response to structured data
     */
    private function transformDocumentToStructureData($document): array
    {
        $structureData = [
            'entities' => [],
            'tables' => []
        ];

        try {
            // Get the full text from the document with UTF-8 handling
            $fullText = $this->sanitizeText($document->getText());
            
            // Process entities from Document AI (if available)
            $entities = $document->getEntities();
            foreach ($entities as $entity) {
                $structureData['entities'][] = [
                    'type' => $this->sanitizeText($entity->getType()),
                    'mention_text' => $this->sanitizeText($entity->getMentionText()),
                    'confidence' => $entity->getConfidence(),
                    'normalized_value' => $entity->getNormalizedValue() ? 
                        $this->sanitizeText($entity->getNormalizedValue()->getText()) : 
                        $this->sanitizeText($entity->getMentionText())
                ];
            }

            // Process pages and tables from Document AI
            $pages = $document->getPages();
            foreach ($pages as $page) {
                $tables = $page->getTables();
                foreach ($tables as $tableIndex => $table) {
                    $headers = [];
                    $rows = [];

                    // Extract headers from header rows
                    $headerRows = $table->getHeaderRows();
                    if ($headerRows && count($headerRows) > 0) {
                        $headerRow = $headerRows[0];
                        $cells = $headerRow->getCells();
                        foreach ($cells as $cell) {
                            $headers[] = $this->extractTextFromDocumentAICell($cell, $fullText);
                        }
                    }

                    // Extract data rows
                    $bodyRows = $table->getBodyRows();
                    foreach ($bodyRows as $row) {
                        $rowData = [];
                        $cells = $row->getCells();
                        foreach ($cells as $cell) {
                            $rowData[] = $this->extractTextFromDocumentAICell($cell, $fullText);
                        }
                        if (!empty(array_filter($rowData))) { // Only add non-empty rows
                            $rows[] = $rowData;
                        }
                    }

                    if (!empty($headers) || !empty($rows)) {
                        $structureData['tables'][] = [
                            'name' => "Table " . ($tableIndex + 1),
                            'headers' => $headers,
                            'rows' => $rows
                        ];
                    }
                }
            }

            return $structureData;

        } catch (\Exception $e) {
            Log::error('Error transforming Document AI response: ' . $e->getMessage());
            
            // Return empty structure if processing fails
            return [
                'entities' => [],
                'tables' => []
            ];
        }
    }

    /**
     * Extract text content from Document AI table cell using object methods
     */
    private function extractTextFromDocumentAICell($cell, $fullText): string
    {
        try {
            $layout = $cell->getLayout();
            if (!$layout) {
                return '';
            }

            $textAnchor = $layout->getTextAnchor();
            if (!$textAnchor) {
                return '';
            }

            $textSegments = $textAnchor->getTextSegments();
            $text = '';

            foreach ($textSegments as $segment) {
                $startIndex = $segment->getStartIndex();
                $endIndex = $segment->getEndIndex();
                
                if ($startIndex !== null && $endIndex !== null) {
                    $extractedText = substr($fullText, $startIndex, $endIndex - $startIndex);
                    $text .= $extractedText;
                }
            }

            return $this->sanitizeText(trim($text));

        } catch (\Exception $e) {
            Log::warning('Error extracting text from cell: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Sanitize text to handle UTF-8 encoding issues
     */
    private function sanitizeText(?string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // Convert to UTF-8 if not already
        if (!mb_check_encoding($text, 'UTF-8')) {
            // Try to detect and convert encoding
            $encoding = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
            if ($encoding !== false) {
                $text = mb_convert_encoding($text, 'UTF-8', $encoding);
            } else {
                // If detection fails, force UTF-8 conversion and remove invalid characters
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }
        }

        // Remove or replace problematic characters
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text); // Remove control characters
        $text = preg_replace('/[^\P{C}\t\r\n]/u', '', $text); // Remove other control characters but keep tabs and newlines
        
        // Clean up extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        return trim($text);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'processor_id' => 'string|max:255',
            'lab_type' => 'string',
            'header_fields' => 'array',
            'test_sections' => 'array', // Changed from table_mappings
            'footer_fields' => 'nullable|array',
            'custom_categories' => 'nullable|array'
        ]);

        // Create the mappings structure for the template
        $mappings = [
            'header' => [],
            'test_sections' => [], // Changed from tables
            'footer' => [],
            'custom_categories' => $request->custom_categories ?? []
        ];

        // Process header fields
        if ($request->header_fields) {
            foreach ($request->header_fields as $field) {
                $mappings['header'][$field['field_name']] = [
                    'extracted_value' => $field['extracted_value'] ?? '',
                    'mapped_field' => $field['mapped_field']
                ];
            }
        }

        // Process test sections
        if ($request->test_sections) {
            foreach ($request->test_sections as $section) {
                $mappings['test_sections'][] = [
                    'section_name' => $section['section_name'],
                    'category' => $section['category'],
                    'expected_tests' => $section['test_results'] ?? [] // Store as expected test structure
                ];
            }
        }

        // Process footer fields
        if ($request->footer_fields) {
            foreach ($request->footer_fields as $field) {
                $mappings['footer'][$field['field_name']] = [
                    'extracted_value' => $field['extracted_value'] ?? '',
                    'mapped_field' => $field['mapped_field']
                ];
            }
        }

        // Store custom categories globally
        if (!empty($request->custom_categories)) {
            $this->storeCustomCategories($request->custom_categories);
        }

        $template = Template::create([
            'name' => $request->name,
            'description' => $request->description,
            'processor_id' => $request->processor_id ?? 'default',
            'lab_type' => $request->lab_type ?? 'mixed',
            'mappings' => $mappings,
            'is_active' => true
        ]);

        return response()->json([
            'success' => true,
            'template' => $template,
            'message' => 'Template created successfully'
        ]);
    }

    public function getCustomCategories()
    {
        $customCategories = cache()->get('custom_test_categories', []);
        return response()->json($customCategories);
    }

    private function storeCustomCategories($categories)
    {
        $existingCategories = cache()->get('custom_test_categories', []);

        foreach ($categories as $category) {
            if (!in_array($category, $existingCategories)) {
                $existingCategories[] = $category;
            }
        }

        cache()->forever('custom_test_categories', $existingCategories);
    }

    /**
     * Display the specified resource.
     */
    public function show(Template $template)
    {
        return view('templates.show', compact('template'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Template $template)
    {
        return view('templates.edit', compact('template'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Template $template)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'processor_id' => 'required|string|max:255',
            'mappings' => 'required|array'
        ]);

        try {
            $template->update([
                'name' => $request->name,
                'description' => $request->description,
                'processor_id' => $request->processor_id,
                'mappings' => $request->mappings
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Template updated successfully',
                'template' => $template
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update template: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Template $template)
    {
        try {
            $template->delete();

            return response()->json([
                'success' => true,
                'message' => 'Template deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete template: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get templates for template selection during upload
     */
    public function getTemplatesForUpload()
    {
        $templates = Template::select('id', 'name', 'description', 'processor_id')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'templates' => $templates
        ]);
    }
}
