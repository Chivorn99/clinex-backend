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
        $value = preg_replace('/[^\x20-\x7E\p{L}\p{N}\p{P}\p{S}]/u', '', $value);
        $value = preg_replace('/\b(eee|we|wee|cece|Lecce)\b/i', '', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }

    private function parseTestSections(string $rawText): array
    {
        $sections = [];
        $currentSectionName = null;
        $isBodySection = false; // Flag to track if we are inside the main body

        $sectionKeywords = ['BIOCHEMISTRY', 'ENZYMOLOGY', 'SEROLOGY / IMMUNOLOGY', 'HEMATOLOGY', 'DRUG URINE', 'URINE ANALYSIS', 'HEMOSTASIS'];
        $startKeyword = 'LABORATORY REPORT';
        $stopKeywords = ['Validated By', 'Lab Technician:'];

        $lines = preg_split('/\r\n|\r|\n/', $rawText);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line))
                continue;

            // Check if we have reached the end of the body on this page
            foreach ($stopKeywords as $stopWord) {
                if (str_contains($line, $stopWord)) {
                    $isBodySection = false; // We have left the body
                    continue 2; // Move to the next line in the document
                }
            }

            // Check if we have entered the main body of test results
            if (str_contains($line, $startKeyword)) {
                $isBodySection = true;
                continue; // Move to the next line
            }

            // If we are not in the body, skip all other processing
            if (!$isBodySection) {
                continue;
            }

            // Check if the line is a new section header (e.g., BIOCHEMISTRY)
            $isHeader = false;
            foreach ($sectionKeywords as $keyword) {
                // Check if the line primarily consists of the keyword
                if (str_contains($line, $keyword) && strlen($line) < strlen($keyword) + 5) {
                    $currentSectionName = strtolower(str_replace([' ', '/'], '_', $keyword));
                    if (!isset($sections[$currentSectionName])) {
                        $sections[$currentSectionName] = [];
                    }
                    $isHeader = true;
                    break;
                }
            }

            if ($isHeader || str_contains($line, 'Reference Range')) {
                continue;
            }
            // --- Enhanced Parsing Logic ---
            if ($currentSectionName !== null) {
                // Skip lines that are mostly OCR noise
                if (
                    preg_match('/^[\.ewe\s]+$/', $line) ||
                    strlen($line) < 5 ||
                    substr_count($line, 'eee') > 2
                ) {
                    continue;
                }

                // Enhanced regex patterns for better test result extraction
                $patterns = [
                    // Pattern 1: Standard format with dots: "WBC ......: 6.8 10^3/uL (4.0-10.0)"
                    '/^([A-Z][A-Za-z\s\/\(\)]+?)\s*\.{2,}\s*:?\s*([0-9\.]+|NEGATIVE|POSITIVE)\s*(.*)$/i',

                    // Pattern 2: Simple colon format: "Morphine : NEGATIVE"
                    '/^([A-Z][A-Za-z\s\/\(\)]+?)\s*:\s*([0-9\.]+|NEGATIVE|POSITIVE)\s*(.*)$/i',

                    // Pattern 3: Format without separator: "WBC 6.8 10^3/uL (4.0-10.0)"
                    '/^([A-Z][A-Za-z\s\/\(\)]{2,})\s+([0-9\.]+|NEGATIVE|POSITIVE)\s+(.*)$/i'
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $line, $matches)) {
                        $testName = $this->cleanValue(trim($matches[1]));
                        $value = $this->cleanValue(trim($matches[2]));
                        $extras = isset($matches[3]) ? $this->cleanValue(trim($matches[3])) : '';

                        // Additional filtering for test names
                        if (
                            strlen($testName) >= 3 &&
                            !preg_match('/^[\.ewe\s]+$/', $testName) &&
                            preg_match('/[A-Za-z]/', $testName)
                        ) {

                            $sections[$currentSectionName][] = [
                                'test_name' => $testName,
                                'value' => $value,
                                'extras' => $extras,
                            ];
                            break; // Stop trying other patterns once we find a match
                        }
                    }
                }
            }
        }

        return $sections;
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
