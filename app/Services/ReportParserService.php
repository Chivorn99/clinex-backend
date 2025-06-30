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

    /**
     * Main function to parse the raw OCR text.
     *
     * @param string $rawText The full text extracted from the PDF.
     * @return array The structured, parsed data.
     */
    public function parse(string $rawText): array
    {
        $structuredData = [];
        $structuredData['patient_info'] = $this->parsePatientInfo($rawText);

        // In the future, we will add methods to parse dynamic sections here.
        // $structuredData['biochemistry'] = $this->parseBiochemistry($rawText);
        // $structuredData['hematology'] = $this->parseHematology($rawText);

        return $structuredData;
    }
    private function cleanValue($value)
    {
        $value = preg_replace('/[^\x20-\x7E\p{L}\p{N}\p{P}\p{S}]/u', '', $value);
        return trim($value);
    }

    /**
     * Parses the static patient information block by processing the text line-by-line.
     *
     * @param string $text
     * @return array
     */
    private function parsePatientInfo(string $text): array
    {
        $patientInfo = [];
        $patterns = [
            'name' => '/(?:thifs\/Name|ឈ្មោះ\/Name)\s*:\s*([^\r\n]+?)(?:\s+(?:Uis\/Age|អាយុ\/Age)|$)/',
            'patient_id' => '/Patient ID\s*:\s*([^\s]+)/',
            'age' => '/(?:Uis\/Age|អាយុ\/Age)\s*:\s*([^\s]+)/',
            'gender' => '/(?:ភេទ\/Gender)\s*:\s*([^\r\n]+)/',
            'lab_id' => '/Lab ID\s*:\s*([^\s]+)/',
            'collected_date' => '/Collected Date\s*:\s*([^\r\n]+?)(?:\s+Analysis Date|$)/',
            'analysis_date' => '/Analysis Date\s*:\s*([^\r\n]+?)(?:\s+Requested By|$)/',
            'requested_by' => '/Requested By\s*:\s*([^\r\n]+)/',
            'phone' => '/(?:gifig\/Phone)\s*:\s*([^\r\n]+)/'
        ];

        $lines = explode("\n", $text);

        // Process each line 
        foreach ($lines as $line) {
            foreach ($patterns as $key => $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    $value = $this->cleanValue(trim($matches[1]));

                    // Overwrite check
                    if (!isset($patientInfo[$key])) {
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
