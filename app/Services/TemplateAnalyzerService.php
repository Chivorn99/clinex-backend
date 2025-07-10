<?php

namespace App\Services;

use App\Services\DocumentAiService;
use Illuminate\Support\Facades\Log;

class TemplateAnalyzerService
{
    protected $documentAiService;

    public function __construct(DocumentAiService $documentAiService)
    {
        $this->documentAiService = $documentAiService;
    }

    /**
     * Analyze a PDF to extract its structure for template creation
     */
    public function analyzePdfStructure(string $pdfPath, string $processorId = 'a2439f686e4b0f79'): array
    {
        try {
            $processorId = $processorId ?? config('services.document_ai.default_processor_id');
            
            Log::info("Analyzing PDF structure for template creation: {$pdfPath}");

            // Process the document with Document AI
            $document = $this->documentAiService->processDocument($pdfPath, $processorId);

            if ($document === null) {
                throw new \Exception('Failed to process PDF with Document AI');
            }

            // Extract structure information
            $structureData = $this->extractStructureFromDocument($document);

            Log::info("Successfully analyzed PDF structure");
            return $structureData;

        } catch (\Exception $e) {
            Log::error("Failed to analyze PDF structure: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Extract structure information from Document AI response
     */
    private function extractStructureFromDocument($document): array
    {
        $structureData = [
            'entities' => [],
            'tables' => [],
            'text_blocks' => []
        ];

        // Extract entities with error handling
        try {
            $entities = $document->getEntities();
            foreach ($entities as $entity) {
                try {
                    $mentionText = $entity->getMentionText();
                    $normalizedValue = $entity->getNormalizedValue() ? $entity->getNormalizedValue()->getText() : null;
                    
                    $structureData['entities'][] = [
                        'type' => $entity->getType(),
                        'mention_text' => $this->sanitizeText($mentionText),
                        'confidence' => $entity->getConfidence(),
                        'normalized_value' => $normalizedValue ? $this->sanitizeText($normalizedValue) : null
                    ];
                } catch (\Exception $e) {
                    Log::warning("Error extracting entity: " . $e->getMessage());
                    continue;
                }
            }
        } catch (\Exception $e) {
            Log::warning("Error extracting entities: " . $e->getMessage());
        }

        // Extract tables with error handling
        try {
            $pages = $document->getPages();
            foreach ($pages as $pageIndex => $page) {
                try {
                    $tables = $page->getTables();
                    foreach ($tables as $tableIndex => $table) {
                        try {
                            $tableData = [
                                'page' => $pageIndex,
                                'table_index' => $tableIndex,
                                'name' => $this->guessTableNameSafely($table, $document),
                                'headers' => [],
                                'rows' => []
                            ];

                            // Extract headers safely
                            $headerRows = $table->getHeaderRows();
                            if ($headerRows->count() > 0) {
                                $firstHeaderRow = $headerRows->offsetGet(0);
                                foreach ($firstHeaderRow->getCells() as $cell) {
                                    try {
                                        $cellText = $this->extractCellText($cell, $document);
                                        $tableData['headers'][] = $cellText;
                                    } catch (\Exception $e) {
                                        $tableData['headers'][] = ''; // Empty cell on error
                                    }
                            }
                        }

                        // Extract data rows safely
                        $bodyRows = $table->getBodyRows();
                        $rowCount = 0;
                        foreach ($bodyRows as $row) {
                            if ($rowCount >= 5) break;
                            
                            try {
                                $rowData = [];
                                foreach ($row->getCells() as $cell) {
                                    try {
                                        $cellText = $this->extractCellText($cell, $document);
                                        $rowData[] = $cellText;
                                    } catch (\Exception $e) {
                                        $rowData[] = ''; // Empty cell on error
                                    }
                                }
                                
                                if (!empty(array_filter($rowData))) {
                                    $tableData['rows'][] = $rowData;
                                    $rowCount++;
                                }
                            } catch (\Exception $e) {
                                Log::warning("Error extracting table row: " . $e->getMessage());
                                continue;
                            }
                        }

                        $structureData['tables'][] = $tableData;
                    } catch (\Exception $e) {
                        Log::warning("Error extracting table: " . $e->getMessage());
                        continue;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Error extracting tables from page {$pageIndex}: " . $e->getMessage());
                continue;
            }
        }
    } catch (\Exception $e) {
        Log::warning("Error extracting tables: " . $e->getMessage());
    }

    // Extract text blocks with error handling
    try {
        $pages = $document->getPages(); // Get pages again for text blocks
        foreach ($pages as $pageIndex => $page) {
            try {
                $blocks = $page->getBlocks();
                foreach ($blocks as $block) {
                    try {
                        $structureData['text_blocks'][] = [
                            'page' => $pageIndex,
                            'text' => $this->extractBlockText($block, $document),
                            'bounding_box' => $this->extractBoundingBox($block->getLayout()->getBoundingPoly())
                        ];
                    } catch (\Exception $e) {
                        Log::warning("Error extracting text block: " . $e->getMessage());
                        continue;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Error extracting blocks from page {$pageIndex}: " . $e->getMessage());
                continue;
            }
        }
    } catch (\Exception $e) {
        Log::warning("Error extracting text blocks: " . $e->getMessage());
    }

    return $structureData;
}

/**
 * Extract text from a table cell with UTF-8 handling
 */
private function extractCellText($cell, $document): string
{
    $text = '';
    $layout = $cell->getLayout();
    
    if ($layout && $layout->getTextAnchor()) {
        $textSegments = $layout->getTextAnchor()->getTextSegments();
        $fullText = $document->getText();
        
        foreach ($textSegments as $segment) {
            $startIndex = $segment->getStartIndex();
            $endIndex = $segment->getEndIndex();
            
            if ($startIndex !== null && $endIndex !== null) {
                $extractedText = substr($fullText, $startIndex, $endIndex - $startIndex);
                $text .= $this->sanitizeText($extractedText);
            }
        }
    }
    
    return trim($text);
}

/**
 * Extract text from a block with UTF-8 handling
 */
private function extractBlockText($block, $document): string
{
    $text = '';
    $layout = $block->getLayout();
    
    if ($layout && $layout->getTextAnchor()) {
        $textSegments = $layout->getTextAnchor()->getTextSegments();
        $fullText = $document->getText();
        
        foreach ($textSegments as $segment) {
            $startIndex = $segment->getStartIndex();
            $endIndex = $segment->getEndIndex();
            
            if ($startIndex !== null && $endIndex !== null) {
                $extractedText = substr($fullText, $startIndex, $endIndex - $startIndex);
                $text .= $this->sanitizeText($extractedText);
            }
        }
    }
    
    return trim($text);
}

/**
 * Sanitize text to handle UTF-8 encoding issues
 */
private function sanitizeText(string $text): string
{
    // Handle null or empty text
    if (empty($text)) {
        return '';
    }

    // Convert to UTF-8 and handle malformed sequences
    $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    
    // Remove non-printable characters except newlines and tabs
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', '', $text);
    
    // Remove BOM if present
    $text = preg_replace('/\xEF\xBB\xBF/', '', $text);
    
    // Replace common problematic characters
    $text = str_replace([
        "\u{FEFF}", // Zero-width no-break space
        "\u{200B}", // Zero-width space
        "\u{200C}", // Zero-width non-joiner
        "\u{200D}", // Zero-width joiner
    ], '', $text);
    
    return $text;
}

/**
 * Safely guess table name
 */
private function guessTableNameSafely($table, $document): string
{
    try {
        return $this->guessTableName($table, $document);
    } catch (\Exception $e) {
        Log::warning("Error guessing table name: " . $e->getMessage());
        return 'Lab Test Results';
    }
}

/**
 * Guess table name based on content (with UTF-8 safety)
 */
private function guessTableName($table, $document): string
{
    // Try to find table name from header content
    $headerRows = $table->getHeaderRows();
    if ($headerRows->count() > 0) {
        $firstHeaderRow = $headerRows->offsetGet(0);
        $cells = $firstHeaderRow->getCells();
        
        if ($cells->count() > 0) {
            $firstCell = $cells->offsetGet(0);
            $firstCellText = $this->extractCellText($firstCell, $document);
            
            // Check if it looks like a category name
            if (preg_match('/(biochemistry|hematology|serology|immunology|clinical|blood|urine|chemistry)/i', $firstCellText)) {
                return $firstCellText;
            }
        }
    }

    // Default naming based on document content
    $fullText = $this->sanitizeText($document->getText());
    if (stripos($fullText, 'biochemistry') !== false) {
        return 'Biochemistry Results';
    } elseif (stripos($fullText, 'hematology') !== false) {
        return 'Hematology Results';
    } elseif (stripos($fullText, 'complete blood count') !== false) {
        return 'Complete Blood Count';
    }

    return 'Lab Test Results';
}

/**
 * Extract bounding box coordinates
 */
private function extractBoundingBox($boundingPoly): array
{
    $vertices = $boundingPoly->getVertices();
    $coords = [];
    
    foreach ($vertices as $vertex) {
        $coords[] = [
            'x' => $vertex->getX() ?? 0,
            'y' => $vertex->getY() ?? 0
        ];
    }
    
    return $coords;
}

/**
 * Classify text blocks into header, body, or footer based on position
 */
public function classifyTextBlocks(array $textBlocks): array
{
    $classified = [
        'header' => [],
        'body' => [],
        'footer' => []
    ];

    // Find the page height to determine relative positions
    $maxY = 0;
    foreach ($textBlocks as $block) {
        foreach ($block['bounding_box'] as $vertex) {
            $maxY = max($maxY, $vertex['y']);
        }
    }

    $headerThreshold = $maxY * 0.2; // Top 20% of page
    $footerThreshold = $maxY * 0.8; // Bottom 20% of page

    foreach ($textBlocks as $block) {
        $avgY = 0;
        foreach ($block['bounding_box'] as $vertex) {
            $avgY += $vertex['y'];
        }
        $avgY /= count($block['bounding_box']);
        
        if ($avgY < $headerThreshold) {
            $classified['header'][] = $block;
        } elseif ($avgY > $footerThreshold) {
            $classified['footer'][] = $block;
        } else {
            $classified['body'][] = $block;
        }
    }

    return $classified;
}
}