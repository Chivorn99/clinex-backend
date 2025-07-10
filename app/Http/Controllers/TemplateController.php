<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Services\TemplateAnalyzerService;
use App\Services\DocumentAiService;
use App\Services\TemplateZonesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Jobs\CreateTemplateFromPdf;
use Illuminate\Support\Facades\Log;
use App\Events\PdfProcessingProgress;
use Illuminate\Support\Str;
use App\Jobs\ProcessPdfForExtraction;
use Illuminate\Validation\ValidationException;


class TemplateController extends Controller
{
    protected $templateAnalyzer;
    protected $templateZonesService;

    public function __construct(TemplateAnalyzerService $templateAnalyzer, TemplateZonesService $templateZonesService)
    {
        $this->templateAnalyzer = $templateAnalyzer;
        $this->templateZonesService = $templateZonesService;
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
     * Show the empty form for creating a new template.
     */
    public function create()
    {
        // This now only returns the view with no data.
        // The data will be loaded dynamically via JavaScript.
        return view('templates.create', [
            'aiData' => null,
            'clinexFields' => [
                'patient_info' => ['patient_id', 'name', 'age', 'gender', 'lab_id', 'phone'],
                'table_columns' => ['test_name', 'value', 'unit', 'reference_range', 'flag'],
            ]
        ]);
    }

    /**
     * Analyze a PDF file on-the-fly and return extracted data as JSON.
     * This is our new API endpoint.
     */
    public function analyzePdf(Request $request, DocumentAiService $documentAiService, TemplateAnalyzerService $analyzerService)
    {
        $request->validate([
            'pdf_file' => 'required|file|mimes:pdf|max:10240', // 10MB Max
            'processor_id' => 'required|string',
        ]);

        try {
            $file = $request->file('pdf_file');
            $processorId = $request->input('processor_id');

            $document = $documentAiService->processDocument($file->getPathname(), $processorId);

            if (!$document) {
                throw new \Exception('The document could not be processed by Google AI.');
            }

            // Use our dedicated service to parse the complex response into a simple array
            $parsedData = $analyzerService->parse($document);

            // Also get the PDF to display it in the preview pane
            $pdfBase64 = base64_encode(file_get_contents($file->getPathname()));

            return response()->json([
                'success' => true,
                'data' => $parsedData,
                'pdf_preview_src' => 'data:application/pdf;base64,' . $pdfBase64,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Template analysis failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'An error occurred during analysis.'], 500);
        }
    }

    /**
     * Store the final template configuration in the database.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'processor_id' => 'required|string',
            'mappings' => 'required|array'
        ]);

        $template = Template::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Template saved successfully!',
            'redirect_url' => route('dashboard') // Or wherever you want to go after saving
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
            $tempDir = storage_path('app/temp_pdfs');
            // Create directory if needed
            if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
            
            $fullPath = $tempDir . DIRECTORY_SEPARATOR . $fileName;
            if (!$pdfFile->move($tempDir, $fileName)) {
                throw new \Exception("Failed to save uploaded file");
            }
            
            Log::info("PDF uploaded for extraction: " . $fullPath);

            // Use the enhanced processing method
            $aiService = new DocumentAiService();
            
            // Use Document OCR processor instead of enhanced processing
            $extractedData = $aiService->processDocumentEnhanced(
                $fullPath,
                'ocr',  // This will use your OCR processor
                'application/pdf',
                true,   // Still enhance PDF before processing
                true    // Use advanced features
            );
            
            // Since OCR may return less structured data, add post-processing
            $extractedData = $this->postProcessOcrData($extractedData);
            
            if (!$extractedData) {
                throw new \Exception("Failed to process document with Document AI");
            }

            // Clean up
            if (file_exists($fullPath)) {
                unlink($fullPath);
                Log::info("Cleaned up temporary file: " . $fullPath);
            }

            return response()->json([
                'success' => true,
                'message' => 'PDF data extracted successfully',
                'data' => $extractedData
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
    // public function store(Request $request)
    // {
    //     $request->validate([
    //         'name' => 'required|string|max:255',
    //         'description' => 'nullable|string',
    //         'processor_id' => 'string|max:255',
    //         'lab_type' => 'string',
    //         'header_fields' => 'array',
    //         'test_sections' => 'array', // Changed from table_mappings
    //         'footer_fields' => 'nullable|array',
    //         'custom_categories' => 'nullable|array'
    //     ]);

    //     // Create the mappings structure for the template
    //     $mappings = [
    //         'header' => [],
    //         'test_sections' => [], // Changed from tables
    //         'footer' => [],
    //         'custom_categories' => $request->custom_categories ?? []
    //     ];

    //     // Process header fields
    //     if ($request->header_fields) {
    //         foreach ($request->header_fields as $field) {
    //             $mappings['header'][$field['field_name']] = [
    //                 'extracted_value' => $field['extracted_value'] ?? '',
    //                 'mapped_field' => $field['mapped_field']
    //             ];
    //         }
    //     }

    //     // Process test sections
    //     if ($request->test_sections) {
    //         foreach ($request->test_sections as $section) {
    //             $mappings['test_sections'][] = [
    //                 'section_name' => $section['section_name'],
    //                 'category' => $section['category'],
    //                 'expected_tests' => $section['test_results'] ?? [] // Store as expected test structure
    //             ];
    //         }
    //     }

    //     // Process footer fields
    //     if ($request->footer_fields) {
    //         foreach ($request->footer_fields as $field) {
    //             $mappings['footer'][$field['field_name']] = [
    //                 'extracted_value' => $field['extracted_value'] ?? '',
    //                 'mapped_field' => $field['mapped_field']
    //             ];
    //         }
    //     }

    //     // Store custom categories globally
    //     if (!empty($request->custom_categories)) {
    //         $this->storeCustomCategories($request->custom_categories);
    //     }

    //     $template = Template::create([
    //         'name' => $request->name,
    //         'description' => $request->description,
    //         'processor_id' => $request->processor_id ?? 'default',
    //         'lab_type' => $request->lab_type ?? 'mixed',
    //         'mappings' => $mappings,
    //         'is_active' => true
    //     ]);

    //     return response()->json([
    //         'success' => true,
    //         'template' => $template,
    //         'message' => 'Template created successfully'
    //     ]);
    // }

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

    /**
     * Post-process OCR data to extract structured information
     */
    private function postProcessOcrData($ocrData)
    {
        // Keep original OCR data
        $processedData = $ocrData;
        
        // Ensure we have text to process
        $fullText = $ocrData['text'] ?? '';
        if (empty($fullText)) {
            return $ocrData;
        }
        
        // Extract patient information
        $patientInfo = [];
        
        // Common patterns for patient info
        $patterns = [
            'patient_name' => '/(?:Patient|Name)\s*[:;]\s*([A-Za-z\s\.\-]+)(?:\n|,|$)/i',
            'patient_id' => '/(?:ID|Patient ID|MRN)\s*[:;]\s*([A-Z0-9\-]+)/i',
            'dob' => '/(?:DOB|Date of Birth|Born)\s*[:;]\s*([0-9\/\.\-]+)/i',
            'gender' => '/(?:Gender|Sex)\s*[:;]\s*(Male|Female|M|F)/i',
            'age' => '/(?:Age)\s*[:;]\s*(\d+)/i',
        ];
        
        foreach ($patterns as $field => $pattern) {
            if (preg_match($pattern, $fullText, $matches)) {
                $patientInfo[$field] = trim($matches[1]);
            }
        }
        
        // Add extracted patient info to the processed data
        if (!empty($patientInfo)) {
            $processedData['entities'] = $processedData['entities'] ?? [];
            foreach ($patientInfo as $field => $value) {
                $processedData['entities'][] = [
                    'type' => $field,
                    'text' => $value,
                    'confidence' => 0.9 // Estimated confidence from regex matching
                ];
            }
        }
        
        // Extract tables using line breaks and consistent spacing
        // This is more complex - here's a simplified approach
        $tables = $this->extractTablesFromOcrText($fullText);
        if (!empty($tables)) {
            $processedData['tables'] = array_merge($processedData['tables'] ?? [], $tables);
        }
        
        return $processedData;
    }

    /**
     * Extract tables from OCR text based on patterns
     */
    private function extractTablesFromOcrText($text)
    {
        $tables = [];
        
        // Look for table indicators
        $tablePatterns = [
            'lab_results' => '/(?:Test Results|Laboratory Results|TEST NAME|RESULT|REFERENCE)/i',
            'vitals' => '/(?:Vital Signs|VITALS|HEIGHT|WEIGHT|BMI|BLOOD PRESSURE)/i'
        ];
        
        foreach ($tablePatterns as $tableType => $pattern) {
            if (preg_match($pattern, $text)) {
                // Try to find table rows based on common patterns
                // This is simplified - real implementation would be more robust
                preg_match_all('/([A-Za-z\s\-\(\)]+)\s*[:]\s*([\d\.]+)\s*([a-zA-Z\/]+)?\s*(?:\(([^)]+)\))?/m', $text, $matches, PREG_SET_ORDER);
                
                $rows = [];
                foreach ($matches as $match) {
                    $row = [];
                    $row[] = trim($match[1]); // Test name
                    $row[] = trim($match[2]); // Value
                    $row[] = isset($match[3]) ? trim($match[3]) : ''; // Unit
                    $row[] = isset($match[4]) ? trim($match[4]) : ''; // Reference range
                    
                    $rows[] = $row;
                }
                
                if (!empty($rows)) {
                    $tables[] = [
                        'name' => ucfirst($tableType),
                        'headers' => ['Test', 'Result', 'Unit', 'Reference Range', 'Flag'],
                        'rows' => $rows
                    ];
                }
            }
        }
        
        return $tables;
    }

    /**
     * Process a PDF using a template's zones
     */
    public function processWithTemplate(Request $request)
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:10240',
            'template_id' => 'required|exists:templates,id',
        ]);
        
        $template = Template::findOrFail($request->template_id);
        $pdfFile = $request->file('pdf');
        $filePath = $pdfFile->getRealPath();
        
        $extractedData = $this->templateZonesService->processWithTemplate($filePath, $template);
        
        if (!$extractedData) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to extract data using template'
            ], 500);
        }
        
        return response()->json([
            'success' => true,
            'data' => $extractedData
        ]);
    }

    /**
     * Extract data from specific zones in a PDF
     */
    public function extractFromZones(Request $request)
    {
        try {
            $request->validate([
                'pdf' => 'required|file|mimes:pdf|max:10240',
                'zones' => 'required|json',
            ]);
            
            $pdfFile = $request->file('pdf');
            $zones = json_decode($request->input('zones'), true);
            
            $fileName = uniqid() . '.pdf';
            $tempDir = storage_path('app/temp_pdfs');
            
            // Create directory if needed
            if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
            
            $fullPath = $tempDir . DIRECTORY_SEPARATOR . $fileName;
            if (!$pdfFile->move($tempDir, $fileName)) {
                throw new \Exception("Failed to save uploaded file");
            }
            
            Log::info("PDF uploaded for zonal extraction: {$fullPath}");
            
            // Use Document AI Service for basic OCR
            $aiService = new DocumentAiService();
            $fullExtraction = $aiService->processDocumentEnhanced(
                $fullPath,
                'ocr',
                'application/pdf',
                true,
                false // Skip advanced features to avoid API issues
            );
            
            if (!$fullExtraction) {
                throw new \Exception("Document AI extraction returned no results");
            }
            
            // Process zones to extract targeted data
            $zonalExtraction = $this->processZonalExtraction($fullExtraction, $zones);
            
            // Clean up
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
            
            return response()->json([
                'success' => true,
                'data' => $zonalExtraction
            ]);
            
        } catch (\Exception $e) {
            Log::error('Zonal extraction failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => 'Error processing zones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process extracted text to get only content from specified zones
     */
    private function processZonalExtraction($fullExtraction, $zones)
    {
        $result = [
            'entities' => [],
            'tables' => []
        ];
        
        // Extract text blocks from the full extraction
        $textBlocks = [];
        if (isset($fullExtraction['pages'])) {
            foreach ($fullExtraction['pages'] as $page) {
                if (isset($page['blocks'])) {
                    foreach ($page['blocks'] as $block) {
                        if (isset($block['layout']['boundingPoly']['vertices'])) {
                            $vertices = $block['layout']['boundingPoly']['vertices'];
                            $text = $this->getTextFromBlock($block, $fullExtraction);
                            
                            if ($text) {
                                $textBlocks[] = [
                                    'text' => $text,
                                    'x' => $vertices[0]['x'] ?? 0,
                                    'y' => $vertices[0]['y'] ?? 0,
                                    'width' => ($vertices[1]['x'] ?? 0) - ($vertices[0]['x'] ?? 0),
                                    'height' => ($vertices[3]['y'] ?? 0) - ($vertices[0]['y'] ?? 0)
                                ];
                            }
                        }
                    }
                }
            }
        }
        
        // Process each zone
        foreach ($zones as $zone) {
            if ($zone['type'] === 'field') {
                // Find text blocks that intersect with this zone
                $zoneText = $this->getTextInZone($textBlocks, $zone);
                if ($zoneText) {
                    $result['entities'][$zone['field_name']] = $zoneText;
                }
            } 
            else if ($zone['type'] === 'table') {
                // For tables, try to find structured rows
                $tableRows = $this->extractTableFromZone($textBlocks, $zone);
                if (!empty($tableRows)) {
                    $result['tables'][] = [
                        'name' => $zone['field_name'],
                        'rows' => $tableRows
                    ];
                }
            }
        }
        
        return $result;
    }

    /**
     * Get text from blocks that fall within a specific zone
     */
    private function getTextInZone($textBlocks, $zone)
    {
        $zoneText = '';
        
        foreach ($textBlocks as $block) {
            // Check if block intersects with zone
            if ($this->blocksIntersect($block, $zone)) {
                $zoneText .= $block['text'] . ' ';
            }
        }
        
        return trim($zoneText);
    }

    /**
     * Extract table data from blocks in a zone
     */
    private function extractTableFromZone($textBlocks, $zone)
    {
        $zoneBlocks = [];
        
        // Get blocks in this zone
        foreach ($textBlocks as $block) {
            if ($this->blocksIntersect($block, $zone)) {
                $zoneBlocks[] = $block;
            }
        }
        
        // Sort blocks by Y position first (rows)
        usort($zoneBlocks, function($a, $b) {
            $rowDiff = $a['y'] - $b['y'];
            if (abs($rowDiff) <= 10) { // Blocks on same row (within 10px)
                return $a['x'] - $b['x']; // Sort by X position
            }
            return $rowDiff;
        });
        
        // Group blocks into rows
        $rows = [];
        $currentRow = [];
        $lastY = -100;
        
        foreach ($zoneBlocks as $block) {
            // If this block is on a new row (>10px difference)
            if (abs($block['y'] - $lastY) > 10) {
                if (!empty($currentRow)) {
                    $rows[] = $currentRow;
                    $currentRow = [];
                }
                $lastY = $block['y'];
            }
            
            $currentRow[] = $block;
        }
        
        // Add the last row
        if (!empty($currentRow)) {
            $rows[] = $currentRow;
        }
        
        // Convert rows to table data
        $tableData = [];
        foreach ($rows as $row) {
            $tableRow = [];
            
            foreach ($row as $block) {
                $tableRow[] = $block['text'];
            }
            
            $tableData[] = $tableRow;
        }
        
        return $tableData;
    }

    /**
     * Check if two blocks intersect
     */
    private function blocksIntersect($block, $zone)
    {
        // Check if block is completely outside zone
        if ($block['x'] >= $zone['x'] + $zone['width'] ||
            $block['x'] + $block['width'] <= $zone['x'] ||
            $block['y'] >= $zone['y'] + $zone['height'] ||
            $block['y'] + $block['height'] <= $zone['y']) {
            return false;
        }
        
        // Block intersects with zone
        return true;
    }

    /**
     * Helper to get text from a block
     */
    private function getTextFromBlock($block, $fullExtraction)
    {
        if (!isset($block['layout']['textAnchor']['textSegments'])) {
            return '';
        }
        
        $text = '';
        $textSegments = $block['layout']['textAnchor']['textSegments'];
        
        foreach ($textSegments as $segment) {
            $startIndex = $segment['startIndex'] ?? 0;
            $endIndex = $segment['endIndex'] ?? 0;
            
            if (isset($fullExtraction['text']) && $startIndex < strlen($fullExtraction['text'])) {
                $text .= substr($fullExtraction['text'], $startIndex, $endIndex - $startIndex);
            }
        }
        
        return $text;
    }
}
