<?php

namespace App\Services;

class ReportParserService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }


    public function parse(string $rawText): array
    {
        $structuredData = [];

        // Call the method to parse patient info (your existing method)
        $structuredData['patient_info'] = $this->parsePatientInfo($rawText);

        // Call our new method to parse the test sections
        $structuredData['test_results'] = $this->parseTestSections($rawText);

        return $structuredData;
    }

    private function parseTestSections(string $rawText): array
    {
        $allSectionsData = [];
        $currentSectionName = null;
        $isBody = false;

        // Include OCR variations of section keywords
        $sectionKeywords = [
            'BIOCHEMISTRY' => 'biochemistry',
            'BIOCHIMISTRY' => 'biochemistry',  // OCR variation
            'ENZYMOLOGY' => 'enzymology',
            'HEMATOLOGY' => 'hematology',
            'DRUG URINE' => 'drug_urine',
            'URINE ANALYSIS' => 'urine_analysis',
            'HEMOSTASIS' => 'hemostasis'
        ];

        $startPattern = '/LABORATORY\s+REPORT/i';
        $stopPattern = '/(?:Validated\s+By|Lab\s+Technician:)/i';
        $tableHeaderPattern = '/\b(?:Results?|Unit|Reference|Range|Flag)\b/i';

        $lines = preg_split('/\r\n|\r|\n/', $rawText);
        $sectionLines = [];

        // First, group lines by the section they belong to
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $trimmedLine = trim($line);

            if (empty($trimmedLine))
                continue;

            // Check for start of body section
            if (preg_match($startPattern, $trimmedLine)) {
                $isBody = true;
                continue;
            }

            if (!$isBody)
                continue;

            // Check for end of body section
            if (preg_match($stopPattern, $trimmedLine)) {
                $isBody = false;
                $currentSectionName = null;
                continue;
            }

            // Flexible header detection as per requirements
            $isHeader = false;
            $lineAbove = isset($lines[$i - 1]) ? trim($lines[$i - 1]) : '';

            foreach ($sectionKeywords as $keyword => $normalizedName) {
                if (stripos($trimmedLine, $keyword) !== false) {
                    // Check if line above contains "LABORATORY REPORT" OR line is short and title-like
                    $followsReport = preg_match($startPattern, $lineAbove);
                    $isShortTitle = strlen($trimmedLine) < strlen($keyword) + 15;

                    if ($followsReport || $isShortTitle) {
                        $currentSectionName = $normalizedName;
                        if (!isset($sectionLines[$currentSectionName])) {
                            $sectionLines[$currentSectionName] = [];
                        }
                        $isHeader = true;
                        break;
                    }
                }
            }

            if ($isHeader)
                continue;

            // Skip table headers
            if (preg_match($tableHeaderPattern, $trimmedLine))
                continue;

            // Add line to current section if valid
            if ($currentSectionName !== null) {
                $sectionLines[$currentSectionName][] = $line;
            }
        }

        // Parse each section block using the new "Clean, Then Split" strategy
        foreach ($sectionLines as $sectionName => $lines) {
            $allSectionsData[$sectionName] = $this->parseTestLines($lines);
        }

        return $allSectionsData;
    }

    /**
     * Parse test lines using "Clean, Then Split" strategy
     */
    private function parseTestLines(array $lines): array
    {
        $results = [];

        foreach ($lines as $line) {
            // Step 1: Clean the line aggressively
            $cleanedLine = $this->cleanTestLine($line);

            // Skip obviously invalid lines
            if (strlen($cleanedLine) < 3)
                continue;

            // Step 2: Split the line into test name and value parts
            $parts = $this->splitTestLine($cleanedLine);

            if ($parts === null)
                continue;

            [$testNamePart, $valuePart] = $parts;

            // Step 3: Surgically extract the value
            $extractedData = $this->surgicalValueExtractor($testNamePart, $valuePart);

            if ($extractedData !== null) {
                $results[] = $extractedData;
            }
        }

        return $results;
    }

    /**
     * Aggressively clean OCR noise from test lines
     */
    private function cleanTestLine(string $line): string
    {
        // Remove common OCR noise patterns
        $cleaned = preg_replace('/\s*(?:we|eee|ee|os|cece|wee)\s*\.?\s*/i', ' ', $line);

        // Remove excessive dots but preserve dot separators (2+ dots)
        $cleaned = preg_replace('/\.{4,}/', '...', $cleaned);

        // Clean up multiple spaces
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);

        // Remove trailing numbers with dashes (OCR artifacts)
        $cleaned = preg_replace('/\s+\d+\-?\s*$/', '', $cleaned);

        return trim($cleaned);
    }

    /**
     * Split cleaned line into test name and value parts
     */
    private function splitTestLine(string $cleanedLine): ?array
    {
        // Try different separator patterns in order of preference
        $separatorPatterns = [
            '/\s*\.{2,}\s*:\s*/',  // dots followed by colon: "Test ..... : value"
            '/\s*\.{2,}\s*/',      // just dots: "Test ..... value"
            '/\s*:\s*/',           // just colon: "Test : value"
        ];

        foreach ($separatorPatterns as $pattern) {
            $parts = preg_split($pattern, $cleanedLine, 2);
            if (count($parts) === 2 && !empty(trim($parts[0])) && !empty(trim($parts[1]))) {
                return [trim($parts[0]), trim($parts[1])];
            }
        }

        return null;
    }

    /**
     * Surgical Value Extractor - operates in order of priority
     */
    private function surgicalValueExtractor(string $testNamePart, string $valuePart): ?array
    {
        // Clean the test name
        $testName = $this->cleanValue($testNamePart);

        // Validate test name
        if (!$this->isValidTestName($testName)) {
            return null;
        }

        // Step 1: Search for text-based keywords first (highest priority)
        $textValues = ['NEGATIVE', 'POSITIVE', 'NORMAL', 'ABNORMAL', 'NOT DETECTED', 'DETECTED'];
        foreach ($textValues as $textValue) {
            if (preg_match('/\b' . preg_quote($textValue, '/') . '\b/i', $valuePart, $matches)) {
                $value = strtoupper($matches[0]);
                // $extras = trim(str_replace($matches[0], '', $valuePart));
                // $extras = $this->cleanValue($extras);

                // return $this->buildTestResult($testName, $value, $extras);
                return $this->buildTestResult($testName, $value, null);
            }
        }

        // Step 2: Search for numerical values (second priority)
        if (preg_match('/^([0-9]+\.?[0-9]*)\s*(.*)/', $valuePart, $matches)) {
            $value = $matches[1];
            // $extras = trim($matches[2]);
            // $extras = $this->cleanValue($extras);

            // return $this->buildTestResult($testName, $value, $extras);
            return $this->buildTestResult($testName, $value, null);
        }

        // Step 3: Fallback - take first word as value
        if (preg_match('/^(\S+)\s*(.*)/', $valuePart, $matches)) {
            $value = $this->cleanValue($matches[1]);
            // $extras = trim($matches[2]);
            // $extras = $this->cleanValue($extras);

            if (!empty($value)) {
                // return $this->buildTestResult($testName, $value, $extras);
                return $this->buildTestResult($testName, $value, null);
            }
        }

        return null;
    }

    /**
     * Validate if test name is meaningful
     */
    private function isValidTestName(string $testName): bool
    {
        return strlen($testName) > 1 &&
            !preg_match('/^\d+$/', $testName) && // Not just numbers
            !preg_match('/^[\.ewe\s]+$/i', $testName) && // Not just OCR noise
            preg_match('/[A-Za-z]/', $testName) && // Contains at least one letter
            !preg_match('/^(?:Unit|Reference|Range|Flag|Results?)$/i', $testName); // Not table headers
    }

    /**
     * Build the final test result array
     */
    private function buildTestResult(string $testName, string $value, ?string $extras): array
    {
        $result = [
            'test_name' => $testName,
            'value' => $value,
        ];

        // if (!empty($extras) && strlen($extras) > 1) {
        //     $result['extras'] = $extras;
        // }

        return $result;
    }

    private function parsePatientInfo(string $text): array
    {
        $patientInfo = [];

        // OCR pattern definitions
        $patterns = [
            'name' => '/thifs\/Name\s*:\s*([^\/\r\n]+?)(?:\s+\w+\/Age|$)/i',
            'patient_id' => '/Patient\s*ID\s*:\s*([A-Z0-9]+)/i',
            'age' => '/(\d+Y(?:,?\d+M)?(?:,?\d+D)?)\s+ti\s*[^\w]*\/Gender/i',
            'gender' => '/Gender\s*:\s*(\w+)/i',
            'lab_id' => '/Lab\s*ID\s*:\s*([A-Z0-9]+)/i',
            'collected_date' => '/Collected\s*Date[^:]*:\s*([0-9\/\s:]+?)(?:\s+Analysis|$)/i',
            'analysis_date' => '/Analysis\s*Date[^:]*:\s*([0-9\/\s:]+?)(?:\s+Requested|$)/i',
            'requested_by' => '/Requested\s*By\s*:\s*([^\r\n]+)/i',
            'phone' => '/giaig\/Phone\s+(\d+)/i'
        ];

        $lines = explode("\n", $text);

        // Process each line 
        foreach ($lines as $line) {
            foreach ($patterns as $key => $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    $value = $this->cleanValue(trim($matches[1]));

                    // Overwritten prevention
                    if (!isset($patientInfo[$key]) && !empty($value)) {
                        $patientInfo[$key] = $value;
                    }
                }
            }
        }

        foreach ($patterns as $key => $pattern) {
            if (!isset($patientInfo[$key])) {
                $patientInfo[$key] = null;
            }
        }

        return $patientInfo;
    }

    private function cleanValue($value)
    {
        if (empty($value))
            return '';

        // Remove non-printable characters and OCR noise
        $value = preg_replace('/[^\x20-\x7E\p{L}\p{N}\p{P}\p{S}]/u', '', $value);

        // Remove common OCR noise patterns in one comprehensive regex
        $value = preg_replace('/\b(?:eee|we|wee|cece|Lecce|ece|ee|os|0{2,}\-?)\b/i', '', $value);

        // Fix common OCR mistakes in medical terms
        $medicalTermFixes = [
            'acide' => 'acid',
            'Gamm ' => 'Gamma ',
            'Transferas' => 'Transferase',
            'Cholesterole' => 'Cholesterol',
            'Tryglyceride' => 'Triglyceride',
            'Uric acide' => 'Uric acid', // Be specific
        ];
        $value = str_replace(array_keys($medicalTermFixes), array_values($medicalTermFixes), $value);

        // Remove excessive dots (3 or more) that aren't part of decimal numbers
        $value = preg_replace('/\.{3,}/', '', $value);

        // Clean up multiple whitespace characters
        $value = preg_replace('/\s+/', ' ', $value);

        // Remove trailing OCR artifacts from test names (numbers followed by dash)
        $value = preg_replace('/\s+\d+\-?\s*$/', '', $value);

        // Remove leading/trailing non-alphanumeric characters except valid ones
        $value = preg_replace('/^[^\w\-\+\(\<\>]+|[^\w\-\+\)\<\>\.]+$/', '', $value);

        return trim($value);
    }



}
