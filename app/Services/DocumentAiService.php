<?php

namespace App\Services;

use Google\Cloud\DocumentAI\V1\Client\DocumentProcessorServiceClient;
use Google\Cloud\DocumentAI\V1\ProcessRequest;
use Google\Cloud\DocumentAI\V1\RawDocument;
use Google\Cloud\DocumentAI\V1\ProcessOptions;
use Google\Cloud\DocumentAI\V1\OcrConfig;
use Illuminate\Support\Facades\Log;

class DocumentAiService
{
    protected $client;
    protected $projectId;
    protected $location = 'us';
    protected $processorMap = [];

    /**
     * Create a new service instance.
     */
    public function __construct()
    {
        // Use config helper instead of direct env() calls
        $this->projectId = config('services.google.project_id', 'clinex-application');
        $this->location = config('services.google.location', 'us');

        // Load processor IDs with logging
        $ocrId = config('services.google.ocr_processor_id', env('GOOGLE_CLOUD_DOCUMENT_OCR_PROCESSOR_ID', '7039da43cbe33faf'));
        $defaultId = config('services.google.document_ai_processor_id', env('GOOGLE_CLOUD_DOCUMENT_AI_PROCESSOR_ID', 'a2439f686e4b0f79'));

        Log::debug("Loaded OCR processor ID: {$ocrId}");
        Log::debug("Loaded default processor ID: {$defaultId}");

        $this->processorMap = [
            'ocr' => $ocrId,
            'lab_report' => $defaultId,
            'default' => $defaultId
        ];

        // Create Document AI client
        try {
            $this->client = new DocumentProcessorServiceClient([
                'credentials' => config('services.google.credentials'),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to initialize Document AI client: " . $e->getMessage());
        }
    }

    /**
     * Get the correctly formatted processor name.
     */
    protected function getFormattedProcessorName($processorType = 'default')
    {
        $processorId = $this->processorMap[$processorType] ?? $this->processorMap['default'];

        // If empty, log and use hardcoded fallbacks
        if (empty($processorId)) {
            Log::warning("Processor ID not found for type: {$processorType}, using hardcoded fallback");

            // Hardcoded fallbacks from your .env
            $hardcodedMap = [
                'ocr' => '7039da43cbe33faf',
                'lab_report' => 'a2439f686e4b0f79',
                'default' => 'a2439f686e4b0f79'
            ];

            $processorId = $hardcodedMap[$processorType] ?? $hardcodedMap['default'];
        }

        // Debug what we're using
        Log::info("Using processor ID for {$processorType}: {$processorId}");

        // Check if already a fully qualified name
        if (str_starts_with($processorId, 'projects/')) {
            return $processorId;
        }

        // Format it correctly
        return "projects/{$this->projectId}/locations/{$this->location}/processors/{$processorId}";
    }

    /**
     * Process a document using a specific Document AI processor with enhanced features.
     */
    public function processDocumentEnhanced(
        string $filePath,
        string $processorType = 'lab_report',
        string $mimeType = 'application/pdf',
        bool $enhancePdf = true,
        bool $useAdvancedFeatures = true
    ) {
        try {
            // Fix path inconsistencies (convert forward/back slashes)
            $filePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);

            // Optionally enhance the PDF for better OCR
            $fileToProcess = $filePath;
            if ($enhancePdf) {
                $fileToProcess = $this->enhancePdfForProcessing($filePath);
            }

            // Get formatted processor name
            $formattedProcessorName = $this->getFormattedProcessorName($processorType);

            // Process with retry logic
            $document = $this->processDocumentWithRetry($fileToProcess, $formattedProcessorName, $mimeType, $useAdvancedFeatures);

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
            if (isset($fileToProcess) && $enhancePdf && $fileToProcess !== $filePath) {
                @unlink($fileToProcess);
            }

            return null;
        }
    }

    /**
     * Process document with retry logic for better reliability.
     */
    public function processDocumentWithRetry(
        string $documentPath,
        string $formattedProcessorName,
        string $mimeType = 'application/pdf',
        bool $useAdvancedFeatures = true,
        int $maxRetries = 3
    ) {
        $attempt = 0;
        $delay = 1; // Starting delay in seconds

        while ($attempt < $maxRetries) {
            try {
                $document = $this->processDocument($documentPath, $formattedProcessorName, $mimeType, $useAdvancedFeatures);

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
     * Process a document using Google Document AI.
     */
    public function processDocument(
        string $filePath,
        string $formattedProcessorName,
        string $mimeType = 'application/pdf',
        bool $useAdvancedFeatures = false
    ) {
        try {
            // Fix path inconsistencies (convert forward/back slashes)
            $filePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);

            // Make sure file exists and is readable
            if (!file_exists($filePath)) {
                Log::error("File does not exist: {$filePath}");
                return null;
            }

            // Read the file content
            $documentContent = file_get_contents($filePath);
            if ($documentContent === false) {
                Log::error("Failed to read file content from path: {$filePath}");
                return null;
            }

            Log::info("Successfully read file ({$filePath}), size: " . strlen($documentContent) . " bytes");

            // Create a RawDocument object
            $rawDocument = new RawDocument([
                'content' => $documentContent,
                'mime_type' => $mimeType,
            ]);

            // Create the basic process request
            $request = new ProcessRequest();
            $request->setName($formattedProcessorName);
            $request->setRawDocument($rawDocument);

            // NO advanced features - they're causing compatibility issues
            // We'll add them back gradually when basic functionality works

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
                // On Windows systems, tell ImageMagick where to find Ghostscript
                if (PHP_OS === 'WINNT' || PHP_OS === 'Windows') {
                    // Try to find gswin64c.exe in common locations
                    $gsPath = 'C:\Program Files\gs\gs10.05.1\bin';
                    if (is_dir($gsPath)) {
                        putenv("MAGICK_GHOSTSCRIPT_PATH={$gsPath}");
                    }
                }

                // Create Imagick instance with correct namespace
                $imagick = new \Imagick();
                $imagick->readImage($filePath);

                // Set resolution higher for better quality
                $imagick->setResolution(300, 300);

                // Enhance contrast for better text recognition
                $imagick->contrastImage(1);

                // Convert to grayscale to simplify processing
                $imagick->setImageColorspace(\Imagick::COLORSPACE_GRAY);

                // REMOVED: reduceNoiseImage - not available in your Imagick version
                // REMOVED: sharpenImage - may not be available

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
            'text' => $document->getText(),
            'entities' => [],
            'tables' => []
        ];

        // Simple entities extraction for now
        foreach ($document->getEntities() as $entity) {
            $type = $entity->getType();
            $text = $entity->getMentionText();

            $cleanedData['entities'][$type] = $text;
        }

        // Simple table extraction
        foreach ($document->getPages() as $page) {
            foreach ($page->getTables() as $index => $table) {
                $tableData = [
                    'name' => "Table " . ($index + 1),
                    'rows' => []
                ];

                // Extract rows
                foreach ($table->getBodyRows() as $row) {
                    $rowData = [];
                    foreach ($row->getCells() as $cell) {
                        // Get text segments from the cell
                        $textSegments = $cell->getLayout()->getTextAnchor()->getTextSegments();
                        $cellText = '';
                        foreach ($textSegments as $segment) {
                            $startIndex = $segment->getStartIndex();
                            $endIndex = $segment->getEndIndex();
                            $cellText .= substr($document->getText(), $startIndex, $endIndex - $startIndex);
                        }
                        $rowData[] = trim($cellText);
                    }
                    $tableData['rows'][] = $rowData;
                }

                $cleanedData['tables'][] = $tableData;
            }
        }

        return $cleanedData;
    }

    /**
     * Simple mock version that returns sample data for UI testing
     */
    public function extractTextFromZonesMock($pdfPath, $zones)
    {
        Log::info("Using mock data for zones extraction testing");

        $result = [
            'entities' => [],
            'tables' => []
        ];

        foreach ($zones as $zone) {
            if ($zone['type'] === 'field') {
                // Generate appropriate sample data based on field name
                if (stripos($zone['field_name'], 'name') !== false) {
                    $result['entities'][$zone['field_name']] = 'John Smith';
                } else if (stripos($zone['field_name'], 'dob') !== false) {
                    $result['entities'][$zone['field_name']] = '1978-05-15';
                } else if (stripos($zone['field_name'], 'age') !== false) {
                    $result['entities'][$zone['field_name']] = '47';
                } else if (stripos($zone['field_name'], 'gender') !== false) {
                    $result['entities'][$zone['field_name']] = 'Male';
                } else if (stripos($zone['field_name'], 'id') !== false) {
                    $result['entities'][$zone['field_name']] = 'PT12345';
                } else {
                    $result['entities'][$zone['field_name']] = 'Sample data for ' . $zone['field_name'];
                }
            } else if ($zone['type'] === 'table') {
                $result['tables'][] = [
                    'name' => $zone['field_name'],
                    'rows' => [
                            ['Test Name', 'Result', 'Unit', 'Reference Range', 'Flag'],
                            ['Hemoglobin', '14.2', 'g/dL', '13.0-17.0', 'Normal'],
                            ['WBC', '7.5', '10³/μL', '4.5-11.0', 'Normal'],
                            ['Platelet Count', '250', '10³/μL', '150-450', 'Normal'],
                            ['Glucose', '95', 'mg/dL', '70-99', 'Normal']
                        ]
                ];
            }
        }

        return $result;
    }

    /**
     * Extract text and tables from specified zones in a PDF document.
     */
    public function extractTextFromZones($pdfPath, $zones)
    {
        try {
            Log::info("Processing PDF for zonal extraction: {$pdfPath}");

            // Process with OCR processor first (better for pure text extraction)
            $ocrProcessorName = $this->getFormattedProcessorName('ocr');
            $ocrDocument = $this->processDocumentWithRetry($pdfPath, $ocrProcessorName, 'application/pdf', false);

            // Process with Form Parser processor (better for structured data)
            $formProcessorName = $this->getFormattedProcessorName('default'); // 'default' maps to your form parser
            $formDocument = $this->processDocumentWithRetry($pdfPath, $formProcessorName, 'application/pdf', false);

            if (!$ocrDocument && !$formDocument) {
                throw new \Exception("Document AI extraction returned no results from either processor");
            }

            // Use the document that worked, with preference for form parser for tables
            $textDocument = $ocrDocument ?? $formDocument; // Prefer OCR for text
            $tableDocument = $formDocument ?? $ocrDocument; // Prefer Form Parser for tables

            // Log which processors succeeded
            if ($ocrDocument)
                Log::info("OCR processor successful");
            if ($formDocument)
                Log::info("Form parser processor successful");

            // Calculate dimensions from whichever document we have
            $document = $textDocument; // Use for dimension calculation

            // Rest of your existing dimension calculation code...
            $firstPage = $document->getPages()[0];
            $pageLayout = $firstPage->getLayout();

            // Check if we have a valid bounding poly
            if ($pageLayout && $pageLayout->getBoundingPoly()) {
                $vertices = $pageLayout->getBoundingPoly()->getVertices();

                // Calculate dimensions from bounding polygon vertices (bottom-right minus top-left)
                $width = $vertices[2]->getX() - $vertices[0]->getX();
                $height = $vertices[2]->getY() - $vertices[0]->getY();

                Log::info("Document dimensions calculated: width={$width}, height={$height}");
            } else {
                // Fallback to default dimensions if not available
                Log::warning("Could not determine document dimensions, using defaults");
                $width = 612;  // Standard US Letter width in points (8.5 x 72)
                $height = 792; // Standard US Letter height in points (11 x 72)
            }

            // Use the appropriate document for each type of extraction
            $result = [
                'entities' => [],
                'tables' => []
            ];

            foreach ($zones as $zone) {
                // Convert normalized zone to absolute coordinates
                $absZone = [
                    'x' => $zone['x'] * $width,
                    'y' => $zone['y'] * $height,
                    'width' => $zone['width'] * $width,
                    'height' => $zone['height'] * $height
                ];

                if ($zone['type'] === 'field') {
                    $zoneText = $this->extractTextFromDocumentZone($textDocument, $absZone);
                    $result['entities'][$zone['field_name']] = trim($zoneText);
                } else if ($zone['type'] === 'table') {
                    $tableRows = $this->extractTableFromZone($tableDocument, $absZone);
                    if (!empty($tableRows)) {
                        $result['tables'][] = [
                            'name' => $zone['field_name'],
                            'rows' => $tableRows
                        ];
                    }
                }
            }

            return $result;

        } catch (\Exception $e) {
            Log::error("Zonal extraction failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Extract text from a specific zone in the document - compatible with Document AI v2.2.1
     */
    private function extractTextFromDocumentZone($document, $zone)
    {
        $fullText = $document->getText();
        $extractedText = '';

        // Log zone for debugging
        Log::debug("Extracting from zone: " . json_encode($zone));

        // Loop through pages
        foreach ($document->getPages() as $pageIndex => $page) {
            // Work directly with text segments instead of blocks/paragraphs structure
            // This approach works across different versions of Document AI
            $textSegments = [];

            // Get all text segments with their bounding boxes
            foreach ($page->getTokens() as $token) {
                if ($token->getLayout() && $token->getLayout()->getBoundingPoly()) {
                    $vertices = $token->getLayout()->getBoundingPoly()->getVertices();
                    if (count($vertices) >= 4) {
                        $tokenX = $vertices[0]->getX();
                        $tokenY = $vertices[0]->getY();
                        $tokenWidth = $vertices[2]->getX() - $tokenX;
                        $tokenHeight = $vertices[2]->getY() - $tokenY;

                        // Check if this token is within our zone
                        if (
                            $this->isBoxInZone([
                                'x' => $tokenX,
                                'y' => $tokenY,
                                'width' => $tokenWidth,
                                'height' => $tokenHeight
                            ], $zone)
                        ) {
                            // Get text content of this token
                            if ($token->getLayout()->getTextAnchor()) {
                                $anchor = $token->getLayout()->getTextAnchor();
                                foreach ($anchor->getTextSegments() as $segment) {
                                    $startIdx = $segment->getStartIndex();
                                    $endIdx = $segment->getEndIndex();
                                    $extractedText .= mb_substr($fullText, $startIdx, $endIdx - $startIdx, 'UTF-8');
                                }
                                $extractedText .= ' ';
                            }
                        }
                    }
                }
            }

            // Add line breaks between lines of text
            $extractedText = preg_replace('/\s{3,}/', "\n", $extractedText);
        }

        // Clean up extracted text
        $extractedText = trim($extractedText);

        // Log what we found
        if (empty($extractedText)) {
            Log::info("No text found in zone");
            return "";
        } else {
            Log::info("Extracted from zone: " . substr($extractedText, 0, 100) . (strlen($extractedText) > 100 ? "..." : ""));
            return $extractedText;
        }
    }

    /**
     * Generic helper to check if a box is within a zone
     */
    private function isBoxInZone($box, $zone)
    {
        // Calculate box boundaries
        $boxRight = $box['x'] + $box['width'];
        $boxBottom = $box['y'] + $box['height'];

        // Calculate zone boundaries
        $zoneRight = $zone['x'] + $zone['width'];
        $zoneBottom = $zone['y'] + $zone['height'];

        // Calculate overlap percentage
        $overlapWidth = min($boxRight, $zoneRight) - max($box['x'], $zone['x']);
        $overlapHeight = min($boxBottom, $zoneBottom) - max($box['y'], $zone['y']);

        // Add debug logging
        Log::debug("Box: ({$box['x']},{$box['y']}) to ({$boxRight},{$boxBottom})");
        Log::debug("Zone: ({$zone['x']},{$zone['y']}) to ({$zoneRight},{$zoneBottom})");

        // Box is in zone if there's overlap
        if ($overlapWidth > 0 && $overlapHeight > 0) {
            $boxArea = $box['width'] * $box['height'];
            $overlapArea = $overlapWidth * $overlapHeight;
            $overlapPercentage = $overlapArea / $boxArea;

            // Lower the threshold to 25%
            $threshold = 0.25;

            Log::debug("Overlap: " . round($overlapPercentage * 100, 2) . "% - " .
                ($overlapPercentage >= $threshold ? "MATCH" : "NO MATCH"));

            return $overlapPercentage >= $threshold;
        }

        return false;
    }

    /**
     * Check if a block is within the specified zone
     */
    private function isBlockInZone($block, $zone)
    {
        // Get bounding box of the block
        $vertices = $block->getLayout()->getBoundingPoly()->getVertices();

        // Calculate block's normalized coordinates
        $blockX = $vertices[0]->getX();
        $blockY = $vertices[0]->getY();
        $blockWidth = $vertices[2]->getX() - $blockX;
        $blockHeight = $vertices[2]->getY() - $blockY;

        // Check if block overlaps with zone
        $zoneRight = $zone['x'] + $zone['width'];
        $zoneBottom = $zone['y'] + $zone['height'];
        $blockRight = $blockX + $blockWidth;
        $blockBottom = $blockY + $blockHeight;

        return !(
            $blockRight < $zone['x'] ||
            $blockX > $zoneRight ||
            $blockBottom < $zone['y'] ||
            $blockY > $zoneBottom
        );
    }

    /**
     * Check if a table is within the specified zone
     */
    private function isTableInZone($table, $zone)
    {
        // Similar to isBlockInZone but for table coordinates
        $tableRight = $table['x'] + $table['width'];
        $tableBottom = $table['y'] + $table['height'];
        $zoneRight = $zone['x'] + $zone['width'];
        $zoneBottom = $zone['y'] + $zone['height'];

        // Check for overlap between table and zone
        return !(
            $tableRight < $zone['x'] ||
            $table['x'] > $zoneRight ||
            $tableBottom < $zone['y'] ||
            $table['y'] > $zoneBottom
        );
    }

    /**
     * Extract table structure from a document zone
     */
    private function extractTableFromZone($document, $zone)
    {
        $tables = [];

        // Look for tables in the zone
        foreach ($document->getPages() as $page) {
            foreach ($page->getTables() as $table) {
                // Check if this table is within our zone
                $tableLayout = $table->getLayout();
                $tableVertices = $tableLayout->getBoundingPoly()->getVertices();

                // Calculate table's normalized coordinates
                $tableX = $tableVertices[0]->getX();
                $tableY = $tableVertices[0]->getY();
                $tableWidth = $tableVertices[2]->getX() - $tableX;
                $tableHeight = $tableVertices[2]->getY() - $tableY;

                // Check if table overlaps sufficiently with zone
                if ($this->isTableInZone(['x' => $tableX, 'y' => $tableY, 'width' => $tableWidth, 'height' => $tableHeight], $zone)) {
                    // Extract table rows
                    $rows = [];

                    // Get headers
                    $headerRow = [];
                    foreach ($table->getHeaderRows() as $headerRowObj) {
                        $rowCells = [];
                        foreach ($headerRowObj->getCells() as $cell) {
                            $rowCells[] = $this->getCellText($document, $cell);
                        }
                        $headerRow = $rowCells;
                    }

                    // Add header row if it exists
                    if (!empty($headerRow)) {
                        $rows[] = $headerRow;
                    }

                    // Get body rows
                    foreach ($table->getBodyRows() as $bodyRow) {
                        $rowCells = [];
                        foreach ($bodyRow->getCells() as $cell) {
                            $rowCells[] = $this->getCellText($document, $cell);
                        }
                        $rows[] = $rowCells;
                    }

                    return $rows;
                }
            }
        }

        // If no table is found in the zone, try to extract text and parse as CSV
        $zoneText = $this->extractTextFromDocumentZone($document, $zone);
        return $this->parseTextAsTable($zoneText);
    }

    /**
     * Get text content from a table cell
     */
    private function getCellText($document, $cell)
    {
        $fullText = $document->getText();
        $textAnchor = $cell->getLayout()->getTextAnchor();
        $text = '';

        foreach ($textAnchor->getTextSegments() as $segment) {
            $startIdx = $segment->getStartIndex();
            $endIdx = $segment->getEndIndex();
            $text .= mb_substr($fullText, $startIdx, $endIdx - $startIdx, 'UTF-8');
        }

        return trim($text);
    }

    /**
     * Parse free text into a table structure
     */
    private function parseTextAsTable($text)
    {
        $lines = explode("\n", trim($text));
        $rows = [];

        foreach ($lines as $line) {
            // Try to detect delimiters
            $line = trim($line);
            if (empty($line))
                continue;

            // Try different delimiters (tab, pipe, multiple spaces)
            if (strpos($line, "\t") !== false) {
                $cells = explode("\t", $line);
            } else if (strpos($line, "|") !== false) {
                $cells = array_map('trim', explode("|", $line));
            } else {
                // Split by multiple spaces
                $cells = preg_split('/\s{2,}/', $line);
            }

            // Filter empty cells and trim values
            $cells = array_map('trim', $cells);
            $cells = array_filter($cells, function ($cell) {
                return !empty($cell);
            });

            if (!empty($cells)) {
                $rows[] = array_values($cells);
            }
        }

        return $rows;
    }
}