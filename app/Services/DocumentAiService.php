<?php

namespace App\Services;

use Google\Cloud\DocumentAI\V1\Client\DocumentProcessorServiceClient;
use Google\Cloud\DocumentAI\V1\ProcessRequest;
use Google\Cloud\DocumentAI\V1\RawDocument;
use Google\Cloud\DocumentAI\V1\OcrConfig;
use Google\Cloud\DocumentAI\V1\ProcessOptions;
use Illuminate\Support\Facades\Log;
use Imagick;

class DocumentAiService
{
    protected DocumentProcessorServiceClient $client;
    protected string $projectId;
    protected string $location = 'us';

    // Map document types to specialized processors
    protected array $processorMap = [];

    /**
     * Create a new service instance.
     */
    public function __construct()
    {
        // Set up processor map in the constructor
        $this->processorMap = [
            'ocr' => env('GOOGLE_CLOUD_DOCUMENT_OCR_PROCESSOR_ID'),
            'lab_report' => env('GOOGLE_CLOUD_DOCUMENT_AI_PROCESSOR_ID'),
            'default' => env('GOOGLE_CLOUD_DOCUMENT_AI_PROCESSOR_ID')
        ];
        
        // Use the credentials we configured in config/services.php
        $credentials = config('services.google.credentials');
        $this->projectId = config('services.google.project_id');

        // Create a new Document AI client, authenticating with our key
        $this->client = new DocumentProcessorServiceClient([
            'credentials' => $credentials,
        ]);
    }

    /**
     * Process a document using a specific Document AI processor with enhanced features.
     */
    public function processDocumentEnhanced(
        string $filePath,
        string $processorIdOrType = 'lab_report',
        string $mimeType = 'application/pdf',
        bool $enhancePdf = true,
        bool $useAdvancedFeatures = true
    ) {
        // Determine processor ID from type if needed
        $processorId = $this->getProcessorId($processorIdOrType);

        // Optionally enhance the PDF for better OCR
        $fileToProcess = $filePath;
        if ($enhancePdf) {
            $fileToProcess = $this->enhancePdfForProcessing($filePath);
        }

        try {
            // Process with retry logic
            $document = $this->processDocumentWithRetry($fileToProcess, $processorId, $mimeType, $useAdvancedFeatures);

            if (!$document) {
                return null;
            }

            // Clean up and structure the extracted data
            $extractedData = $this->cleanupExtractedData($document);

            // Clean up temporary enhanced file if created
            if ($enhancePdf && $fileToProcess !== $filePath) {
                @unlink($fileToProcess);
            }

            return $extractedData;

        } catch (\Exception $e) {
            Log::error('Enhanced document processing failed: ' . $e->getMessage());

            // Clean up temporary enhanced file if created
            if ($enhancePdf && $fileToProcess !== $filePath) {
                @unlink($fileToProcess);
            }

            return null;
        }
    }

    /**
     * Get processor ID from document type or return the ID if directly provided.
     */
    protected function getProcessorId(string $processorIdOrType): string
    {
        if (isset($this->processorMap[$processorIdOrType])) {
            return $this->processorMap[$processorIdOrType];
        }

        // If not found in map, assume it's already a processor ID
        return $processorIdOrType;
    }

    /**
     * Process a document using a specific Document AI processor.
     */
    public function processDocument(
        string $filePath,
        string $processorId,
        string $mimeType = 'application/pdf',
        bool $useAdvancedFeatures = false
    ) {
        try {
            // The full resource name of the processor
            $name = $this->client->processorName($this->projectId, $this->location, $processorId);

            // Read the file content
            $documentContent = file_get_contents($filePath);
            if ($documentContent === false) {
                Log::error("Failed to read file content from path: {$filePath}");
                return null;
            }

            // Create a RawDocument object
            $rawDocument = new RawDocument([
                'content' => $documentContent,
                'mime_type' => $mimeType,
            ]);

            // Create the basic process request
            $request = new ProcessRequest();
            $request->setName($name);
            $request->setRawDocument($rawDocument);

            // Add advanced features if requested - but using setter methods instead of constructor
            // This is the key fix - don't use the array constructor approach for these fields
            if ($useAdvancedFeatures) {
                // Initialize advanced features using the v2.2 API methods
                $ocrConfig = new OcrConfig();
                $ocrConfig->setEnableNativePdfParsing(true);
                $ocrConfig->setEnableImageQualityScores(true);
                
                $request->setOcrConfig($ocrConfig);
                
                // Skip the ProcessOptions for now since it's causing issues
                if (method_exists($request, 'setFormExtractionEnabled')) {
                    $request->setFormExtractionEnabled(true);
                }
            }

            // Send the request to the Document AI API
            $result = $this->client->processDocument($request);

            // Return the structured Document object from the response
            return $result->getDocument();

        } catch (\Exception $e) {
            Log::error('Google Document AI processing failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Process document with retry logic for better reliability.
     */
    public function processDocumentWithRetry(
        string $filePath,
        string $processorId,
        string $mimeType = 'application/pdf',
        bool $useAdvancedFeatures = true,
        int $maxRetries = 3
    ) {
        $attempt = 0;
        $delay = 1; // Starting delay in seconds

        while ($attempt < $maxRetries) {
            try {
                $document = $this->processDocument($filePath, $processorId, $mimeType, $useAdvancedFeatures);

                if ($document) {
                    return $document;
                }

                // If result is null, retry after delay
                $attempt++;
                Log::warning("Document AI processing attempt {$attempt} failed, retrying in {$delay} seconds");
                sleep($delay);
                $delay *= 2; // Exponential backoff

            } catch (\Exception $e) {
                Log::error("Document AI error on attempt {$attempt}: {$e->getMessage()}");
                $attempt++;
                sleep($delay);
                $delay *= 2;
            }
        }

        return null;
    }

    /**
     * Pre-process PDF to enhance quality for better OCR results.
     */
    public function enhancePdfForProcessing(string $filePath): string
    {
        // Skip if not a PDF file
        if (!file_exists($filePath) || pathinfo($filePath, PATHINFO_EXTENSION) !== 'pdf') {
            return $filePath;
        }

        try {
            // Create an enhanced copy with a unique name
            $outputPath = storage_path('app/temp_pdfs/' . uniqid() . '_enhanced.pdf');

            // Ensure directory exists
            if (!is_dir(dirname($outputPath))) {
                mkdir(dirname($outputPath), 0755, true);
            }

            // Check if we have Imagick extension and class available
            if (extension_loaded('imagick') && class_exists('\Imagick')) {
                $imagick = new \Imagick();
                $imagick->readImage($filePath);

                // Set resolution higher for better quality
                $imagick->setResolution(300, 300);

                // Enhance contrast for better text recognition
                $imagick->contrastImage(1);

                // Convert to grayscale to simplify processing
                $imagick->setImageColorspace(\Imagick::COLORSPACE_GRAY);

                // Reduce noise in the image
                $imagick->reduceNoiseImage(3);

                // Improve sharpness
                $imagick->sharpenImage(0, 1.0);

                // Save the enhanced PDF
                $imagick->writeImages($outputPath, true);
                $imagick->clear();

                Log::info("Enhanced PDF created at: {$outputPath}");
                return $outputPath;
            } else {
                // If Imagick is not available, use the original file
                Log::warning("Imagick extension not available for PDF enhancement");
                return $filePath;
            }
        } catch (\Exception $e) {
            Log::error("PDF enhancement failed: {$e->getMessage()}");
            return $filePath; // Return original path if enhancement fails
        }
    }

    /**
     * Clean and structure extracted data from Document AI.
     */
    private function cleanupExtractedData($document): array
    {
        $cleanedData = [
            'text' => $this->sanitizeText($document->getText()),
            'entities' => [],
            'tables' => [],
            'custom_fields' => []
        ];

        // Process entities
        foreach ($document->getEntities() as $entity) {
            $type = $entity->getType();
            $text = $entity->getMentionText();
            $confidence = $entity->getConfidence();

            // Clean up text values
            $text = $this->sanitizeText($text);

            $cleanedData['entities'][] = [
                'type' => $type,
                'text' => $text,
                'confidence' => $confidence
            ];
        }

        // Process tables
        $pages = $document->getPages();
        $tableIndex = 1;

        foreach ($pages as $pageIndex => $page) {
            $tables = $page->getTables();

            foreach ($tables as $tableNum => $table) {
                $cleanTable = [
                    'name' => "Table {$tableIndex}",
                    'headers' => [],
                    'rows' => []
                ];

                // Extract headers from the table
                $headerRows = $table->getHeaderRows();
                if ($headerRows && count($headerRows) > 0) {
                    $headerRow = $headerRows[0];
                    $cells = $headerRow->getCells();
                    foreach ($cells as $cell) {
                        $text = $this->extractTextFromCell($cell, $document->getText());
                        $cleanTable['headers'][] = $this->sanitizeText($text);
                    }
                }

                // Extract data rows
                $bodyRows = $table->getBodyRows();
                foreach ($bodyRows as $row) {
                    $rowData = [];
                    $cells = $row->getCells();
                    foreach ($cells as $cell) {
                        $text = $this->extractTextFromCell($cell, $document->getText());
                        $rowData[] = $this->sanitizeText($text);
                    }

                    // Skip empty rows
                    if (!empty(array_filter($rowData))) {
                        $cleanTable['rows'][] = $rowData;
                    }
                }

                // Only add tables that actually contain data
                if (!empty($cleanTable['headers']) || !empty($cleanTable['rows'])) {
                    $cleanedData['tables'][] = $cleanTable;
                    $tableIndex++;
                }
            }
        }

        // Add custom field extraction using regex patterns
        $cleanedData['custom_fields'] = $this->extractCustomFields($cleanedData['text']);

        return $cleanedData;
    }

    /**
     * Extract text from a table cell.
     */
    private function extractTextFromCell($cell, $fullText): string
    {
        try {
            $layout = $cell->getLayout();
            if (!$layout)
                return '';

            $textAnchor = $layout->getTextAnchor();
            if (!$textAnchor)
                return '';

            $textSegments = $textAnchor->getTextSegments();
            $text = '';

            foreach ($textSegments as $segment) {
                $startIndex = $segment->getStartIndex();
                $endIndex = $segment->getEndIndex();

                if ($startIndex !== null && $endIndex !== null) {
                    $text .= substr($fullText, $startIndex, $endIndex - $startIndex);
                }
            }

            return $text;
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Sanitize text by removing special characters and normalizing whitespace.
     */
    private function sanitizeText(?string $text): string
    {
        if (empty($text))
            return '';

        // Fix character encoding issues
        if (!mb_check_encoding($text, 'UTF-8')) {
            $encoding = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
            if ($encoding !== false) {
                $text = mb_convert_encoding($text, 'UTF-8', $encoding);
            } else {
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }
        }

        // Remove non-printable characters
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Fix common OCR errors
        $replacements = [
            'â¹' => '³',
            'â¾' => '‰',
            'â\s*$' => '',
            'Ã©' => 'é',
            'Ã¨' => 'è',
            'Ã¼' => 'ü',
            'Ãª' => 'ê',
            '응' => '',
        ];

        foreach ($replacements as $search => $replace) {
            $text = preg_replace('/' . $search . '/u', $replace, $text);
        }

        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Extract custom fields using regex patterns.
     */
    private function extractCustomFields($text): array
    {
        $fields = [];

        // Patient information patterns
        $patterns = [
            'patient_name' => '/(?:name|patient name|patient)\s*[:;]\s*([^\n\r,\.]{3,40})/i',
            'patient_id' => '/(?:id|patient id|patient number|mrn|chart)\s*[:;]\s*([a-z0-9\-]{3,20})/i',
            'date_of_birth' => '/(?:dob|date of birth|birth date|born)\s*[:;]\s*([^\n\r,\.]{3,20})/i',
            'age' => '/(?:age)\s*[:;]\s*(\d{1,3})\s*(?:years|yrs|yr|y)?/i',
            'gender' => '/(?:gender|sex)\s*[:;]\s*([^\n\r,\.]{1,10})/i',

            // Lab information
            'lab_name' => '/(?:laboratory|lab|performed at|performed by)\s*[:;]\s*([^\n\r,\.]{3,40})/i',
            'collection_date' => '/(?:collection date|collected on|specimen date|sample date)\s*[:;]\s*([^\n\r,\.]{3,20})/i',
            'report_date' => '/(?:report date|reported on|date of report|date reported)\s*[:;]\s*([^\n\r,\.]{3,20})/i',
        ];

        foreach ($patterns as $field => $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $fields[$field] = $this->sanitizeText($matches[1]);
            }
        }

        // Extract lab test results using more sophisticated patterns
        $testPattern = '/([A-Za-z\s\-\(\)]{3,30})\s*[:;]\s*((?:\d+(?:\.\d+)?)\s*(?:[a-zA-Z%\/]+)?)/';
        preg_match_all($testPattern, $text, $matches, PREG_SET_ORDER);

        $fields['tests'] = [];
        foreach ($matches as $match) {
            $testName = $this->sanitizeText($match[1]);
            $value = $this->sanitizeText($match[2]);
            $fields['tests'][$testName] = $value;
        }

        return $fields;
    }
}