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

    private function cleanValue($value)
    {
        // Remove non-printable characters and OCR noise
        $value = preg_replace('/[^\x20-\x7E\p{L}\p{N}\p{P}\p{S}]/u', '', $value);

        // Remove common OCR noise patterns - more aggressive
        $value = preg_replace('/\b(eee|we|wee|cece|Lecce|ece|ee|0000-|00-|0-|os)\b/i', '', $value);
        
        // Fix common OCR mistakes in medical terms
        $value = str_replace(['acide', 'Gamm ', 'Transferas'], ['acid', 'Gamma ', 'Transferase'], $value);

        // Remove excessive dots that aren't part of decimal numbers
        $value = preg_replace('/\.{3,}/', '', $value);

        // Clean up multiple spaces
        $value = preg_replace('/\s+/', ' ', $value);

        // Remove trailing OCR artifacts from test names
        $value = preg_replace('/\s+(0+\-?|[0-9]+\-?)$/', '', $value);

        // Remove leading/trailing non-alphanumeric characters except for valid ones
        $value = preg_replace('/^[^\w\-\+\(\<\>]+|[^\w\-\+\)\<\>]+$/', '', $value);

        return trim($value);
    }

    private function parseTestSections(string $rawText): array
    {
        $sections = [];
        $currentSectionName = null;
        $isBodySection = false;

        $startKeyword = 'LABORATORY REPORT';
        $stopKeywords = ['Validated By', 'Lab Technician:', 'Report Generated', 'Page'];

        $lines = preg_split('/\r\n|\r|\n/', $rawText);

        foreach ($lines as $lineNumber => $line) {
            $originalLine = $line;
            $trimmedLine = trim($line);

            if (empty($trimmedLine))
                continue;

            // Check if we've reached the end of the body
            $shouldStop = false;
            foreach ($stopKeywords as $stopWord) {
                if (stripos($trimmedLine, $stopWord) !== false) {
                    $isBodySection = false;
                    $shouldStop = true;
                    break;
                }
            }
            if ($shouldStop)
                continue;

            // Check if we've entered the main body of test results
            if (stripos($trimmedLine, $startKeyword) !== false) {
                $isBodySection = true;
                continue;
            }

            // If we're not in the body section, skip processing
            if (!$isBodySection) {
                continue;
            }

            // Detect section headers - now considering center alignment
            if ($this->isSectionHeader($trimmedLine, $originalLine)) {
                $currentSectionName = $this->normalizeSectionName($trimmedLine);

                if (!isset($sections[$currentSectionName])) {
                    $sections[$currentSectionName] = [];
                }
                continue;
            }

            // Skip lines that are clearly not test results
            if ($this->shouldSkipLine($trimmedLine)) {
                continue;
            }

            // Parse test results if we have a current section
            if ($currentSectionName !== null) {
                $testResult = $this->parseTestLine($trimmedLine);
                if ($testResult !== null) {
                    $sections[$currentSectionName][] = $testResult;
                }
            }
        }

        return $sections;
    }

    private function isSectionHeader($trimmedLine, $originalLine = null): bool
    {
        $upperLine = strtoupper(trim($trimmedLine));

        // Skip empty or very short lines
        if (strlen($upperLine) < 3) return false;

        // Check basic header characteristics
        $isAllCaps = preg_match('/^[A-Z\s\/\-\(\)&_]+$/', $upperLine);
        $reasonableLength = strlen($upperLine) >= 4 && strlen($upperLine) <= 30;
        $noNumbers = !preg_match('/\d/', $upperLine);
        $hasLeadingSpaces = $originalLine !== null && preg_match('/^\s{5,}/', $originalLine);
        
        // Exclude lines that are clearly not headers
        $hasResultPatterns = preg_match('/[\.]{2,}|:\s*\d|:\s*(NEGATIVE|POSITIVE)|mg\/dL|U\/L|g\/dL/i', $trimmedLine);
        $isTableHeader = preg_match('/\b(Results?|Unit|Reference|Range|Flag)\b/i', $upperLine);
        $isTestResult = preg_match('/\b(NEGATIVE|POSITIVE|NORMAL|ABNORMAL)\b/', $upperLine);
        
        // Known problematic patterns that should NOT be headers
        $knownNonHeaders = [
            'URINE ANALYSIS', // This appears in test results, not just as header
            'RESULTS',
            'TRANSAMINASE',
            'UNIT', 
            'REFERENCE',
            'RANGE',
            'FLAG',
            'TEST'
        ];
        
        // Skip if it matches known non-header patterns exactly
        if (in_array($upperLine, $knownNonHeaders)) {
            return false;
        }
        
        // Must be all caps, reasonable length, no numbers, and either:
        // 1. Has leading spaces (centered), OR
        // 2. Is a standalone line that looks like a section header
        return $isAllCaps && 
               $reasonableLength && 
               $noNumbers && 
               !$hasResultPatterns && 
               !$isTableHeader && 
               !$isTestResult &&
               ($hasLeadingSpaces || strlen($upperLine) >= 8);
    }

    private function normalizeSectionName($line): string
    {
        $normalized = strtolower(trim($line));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
        $normalized = preg_replace('/_{2,}/', '_', $normalized);
        return trim($normalized, '_');
    }

    private function shouldSkipLine($line): bool
    {
        // Skip reference range lines
        if (stripos($line, 'Reference Range') !== false || stripos($line, 'Normal Range') !== false) {
            return true;
        }

        // Skip lines with only dots, spaces, or OCR noise
        if (preg_match('/^[\.ewe\s]+$/', $line)) {
            return true;
        }

        // Skip very short lines
        if (strlen($line) < 3) {
            return true;
        }

        // Skip lines with excessive OCR noise
        if (substr_count($line, 'eee') > 2) {
            return true;
        }

        // Skip lines that are clearly formatting artifacts
        if (preg_match('/^[\.:\s\-_]+$/', $line)) {
            return true;
        }

        return false;
    }

    private function parseTestLine($line): ?array
    {
        // Skip obvious non-test lines early
        if (preg_match('/^(Results?|Unit|Reference|Range|Flag|TEST)\s/i', $line)) {
            return null;
        }
        
        // Skip lines that are just section names repeated
        $upperLine = strtoupper(trim($line));
        if (preg_match('/^(URINE ANALYSIS|HEMATOLOGY|BIOCHEMISTRY|ENZYMOLOGY|DRUG URINE)(\s+\d+)?(\s+TEST)?$/i', $upperLine)) {
            return null;
        }

        // Enhanced patterns for better test result extraction
        $patterns = [
            // Pattern 1: "Test Name ................: Value Unit (Range) Flag"
            '/^([A-Za-z][A-Za-z0-9\s\/\(\)\-\%\,\.\&]+?)\s*\.{2,}\s*:?\s*([0-9\.\-]+|NEGATIVE|POSITIVE|NOT\s+DETECTED|DETECTED|NORMAL|ABNORMAL)\s*([A-Za-z\/\^0-9\(\)\%]*)\s*(\([^\)]*\))?\s*([A-Z]?)$/i',

            // Pattern 2: "Test Name : Value Unit (Range) Flag"  
            '/^([A-Za-z][A-Za-z0-9\s\/\(\)\-\%\,\.\&]+?)\s*:\s*([0-9\.\-]+|NEGATIVE|POSITIVE|NOT\s+DETECTED|DETECTED|NORMAL|ABNORMAL)\s*([A-Za-z\/\^0-9\(\)\%]*)\s*(\([^\)]*\))?\s*([A-Z]?)$/i',

            // Pattern 3: Tabular format "Test Name    Value    Unit    (Range)    Flag"
            '/^([A-Za-z][A-Za-z0-9\s\/\(\)\-\%\,\.\&]{2,}?)\s{2,}([0-9\.\-]+|NEGATIVE|POSITIVE)\s+([A-Za-z\/\^0-9\(\)\%]*)\s*(\([^\)]*\))?\s*([A-Z]?)$/i',

            // Pattern 4: Simple format "Test Name Value" (no separators)
            '/^([A-Za-z][A-Za-z0-9\s\/\(\)\-\%\,\.\&]+?)\s+([0-9\.\-]+|NEGATIVE|POSITIVE|NOT\s+DETECTED|DETECTED)\s*$/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line, $matches)) {
                $testName = $this->cleanValue($matches[1]);
                $value = $this->cleanValue($matches[2]);
                $unit = isset($matches[3]) ? $this->cleanValue($matches[3]) : '';
                $referenceRange = isset($matches[4]) ? $this->cleanValue($matches[4]) : '';
                $flag = isset($matches[5]) ? $this->cleanValue($matches[5]) : '';

                // Clean up test name more aggressively
                $testName = preg_replace('/\s+0+\-?\s*$/', '', $testName); // Remove trailing "0-" artifacts
                $testName = preg_replace('/\b(os|we|eee)\s+/i', '', $testName); // Remove OCR noise words
                $testName = trim($testName);

                // Validation for test names
                if (
                    strlen($testName) >= 2 &&
                    !preg_match('/^[\.ewe\s0-9\-]+$/', $testName) &&
                    preg_match('/[A-Za-z]/', $testName) &&
                    !empty($value) &&
                    !preg_match('/^(Results?|Unit|Reference|Range|Flag|TEST)$/i', $testName) &&
                    // Don't capture obvious section headers as test names
                    !preg_match('/^(URINE ANALYSIS|HEMATOLOGY|BIOCHEMISTRY|ENZYMOLOGY|DRUG URINE)$/i', $testName)
                ) {
                    $result = [
                        'test_name' => $testName,
                        'value' => $value
                    ];

                    // Add optional fields if they exist and are meaningful
                    if (!empty($unit) && !preg_match('/^[0-9\(\)\-\s]+$/', $unit)) {
                        $result['unit'] = $unit;
                    }
                    if (!empty($referenceRange) && preg_match('/\([^\)]+\)/', $referenceRange)) {
                        $result['reference_range'] = $referenceRange;
                    }
                    if (!empty($flag) && preg_match('/^[A-Z]$/', $flag)) {
                        $result['flag'] = $flag;
                    }

                    return $result;
                }
            }
        }

        return null;
    }

    // Example usage function to demonstrate the expected JSON output
    private function getStructuredLabResults(string $rawText): array
    {
        $sections = $this->parseTestSections($rawText);

        return [
            'patient_info' => $this->parsePatientInfo($rawText), // Use your existing function
            'test_sections' => $sections,
            'parsed_at' => date('Y-m-d H:i:s')
        ];
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


}
