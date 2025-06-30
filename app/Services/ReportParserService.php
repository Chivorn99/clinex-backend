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
        return trim($value);
    }

    private function parseTestSections(string $rawText): array
    {
        $sections = [];
        $currentSection = null;

        $sectionKeywords = ['BIOCHEMISTRY', 'ENZYMOLOGY', 'SEROLOGY / IMMUNOLOGY', 'HEMATOLOGY', 'DRUG URINE', 'URINE ANALYSIS', 'HEMOSTASIS'];
        $stopKeywords = ['Validated By', 'Lab Technician:', 'អាសយដ្ឋាន:', 'លេខទូរស័ព្ទ:', 'HOSPITAL'];

        $lines = preg_split('/\r\n|\r|\n/', $rawText);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line))
                continue;

            // Check for stop keywords
            foreach ($stopKeywords as $stopWord) {
                if (str_contains($line, $stopWord)) {
                    $currentSection = null;
                    continue 2;
                }
            }

            // Check for section headers
            $isHeader = false;
            foreach ($sectionKeywords as $keyword) {
                if (str_contains($line, $keyword)) {
                    // Check for false positives (e.g., a test name containing the keyword)
                    if (strlen($line) < strlen($keyword) + 5) {
                        $currentSection = strtolower(str_replace([' ', '/'], '_', $keyword));
                        $sections[$currentSection] = [];
                        $isHeader = true;
                        break;
                    }
                }
            }

            if ($isHeader || str_contains($line, 'Reference Range')) {
                continue;
            }

            // --- NEW PARSING LOGIC ---
            if ($currentSection !== null) {
                // Split the line by a colon or at least 3 dots
                $parts = preg_split('/(?::|\.{3,})/', $line, 2);

                if (count($parts) === 2) {
                    $testName = $this->cleanValue(trim($parts[0]));
                    $valuePart = trim($parts[1]);

                    // Now, extract the primary value from the second part
                    $value = null;
                    $extras = null;

                    // Regex to find the first word (like NEGATIVE) or number (like 9.8 or 100)
                    if (preg_match('/^([A-Z0-9\.\-]+)/', $valuePart, $valueMatches)) {
                        $value = $this->cleanValue($valueMatches[0]);
                        // The rest of the string becomes the 'extras'
                        $extras = $this->cleanValue(substr($valuePart, strlen($value)));
                    }

                    if ($value !== null) {
                        $sections[$currentSection][] = [
                            'test_name' => $testName,
                            'value' => $value,
                            'extras' => $extras,
                        ];
                    }
                }
            }
            // --- END OF NEW LOGIC ---
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
