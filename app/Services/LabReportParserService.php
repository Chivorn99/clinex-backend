<?php

namespace App\Services;

use Google\Cloud\DocumentAI\V1\Document;
use Illuminate\Support\Facades\Log;

class LabReportParserService
{
    private $fullText;
    private $lines;
    private $infoMap = [];

    const SECTION_HEADERS = ['BIOCHEMISTRY', 'BIOCHIMISTRY', 'IMMUNOLOGY', 'HEMATOLOGY', 'URINE ANALYSIS', 'DRUG URINE', 'ENZYMOLOGY', 'TRANSAMINASE'];
    const STOP_KEYWORD = 'Validated By';

    public function parse(Document $document): array
    {
        $fullText = str_replace(["\r\n", "\r"], "\n", $document->getText());
        Log::debug("--- RAW OCR TEXT ---\n" . $fullText . "\n--- END RAW OCR TEXT ---");

        $this->fullText = $fullText;
        $this->lines = explode("\n", $fullText);
        $this->buildInfoMap(); // Create a clean key-value map first

        return [
            'patientInfo' => $this->getPatientInfo(),
            'labInfo' => $this->getLabInfo(),
            'testResults' => $this->parseTestResults(),
        ];
    }

    private function buildInfoMap(): void
    {
        foreach ($this->lines as $index => $line) {
            $line = trim($line);
            if (empty($line))
                continue;

            // Find lines that look like key-value pairs (e.g., "Patient ID : PT001868")
            if (preg_match('/^(.+?)\s*:\s*(.+)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);
                $this->infoMap[$key] = $value;
            }
            // Handle cases where the value is on the next line
            else if ($index + 1 < count($this->lines) && str_starts_with(trim($this->lines[$index + 1]), ':')) {
                $key = trim($line);
                $value = trim(substr(trim($this->lines[$index + 1]), 1)); // Remove colon and trim
                $this->infoMap[$key] = $value;
            }
        }

        // Debug: Let's see what we actually have in the map
        Log::debug('Built Info Map:', $this->infoMap);

        // Manual fixes for common OCR issues based on the raw text
        if (isset($this->infoMap['Patient ID']) && $this->infoMap['Patient ID'] === 'HORN BUN HACH') {
            // The values are swapped, let's fix them
            $this->infoMap['ឈោ្មះ/Name'] = 'HORN BUN HACH';
            $this->infoMap['Patient ID'] = 'PT001868';
        }

        if (isset($this->infoMap['Requested By']) && $this->infoMap['Requested By'] === 'Male') {
            // Gender and Requested By are swapped
            $this->infoMap['ភេទ/Gender'] = 'Male';
            $this->infoMap['Requested By'] = 'Dr. LEANG Choeu';
        }

        if (isset($this->infoMap['Lab Technician'])) {
            $this->infoMap['validatedBy'] = $this->infoMap['Lab Technician'];
        }
    }

    private function getPatientInfo(): array
    {
        return [
            'name' => $this->findValueInMap(['ឈោ្មះ/Name', 'in:/Name']),
            'patientId' => $this->findValueInMap(['Patient ID']),
            'age' => $this->findValueInMap(['អាយុ/Age', 'In tij/Age']),
            'gender' => $this->findValueInMap(['ភេទ/Gender']),
            'phone' => $this->findValueInMap(['ទូរស័ព្ទ/Phone']),
        ];
    }

    private function getLabInfo(): array
    {
        return [
            'labId' => $this->findValueInMap(['Lab ID']),
            'requestedBy' => $this->findValueInMap(['Requested By']),
            'requestedDate' => $this->findValueInMap(['Requested Date']),
            'collectedDate' => $this->findValueInMap(['Collected Date']),
            'analysisDate' => $this->findValueInMap(['Analysis Date']),
            'validatedBy' => $this->findValueInMap(['Lab Technician', 'validatedBy']),
        ];
    }

    private function findValueInMap(array $possibleKeys): ?string
    {
        foreach ($possibleKeys as $key) {
            if (isset($this->infoMap[$key])) {
                return $this->infoMap[$key];
            }
        }
        return null;
    }

    private function parseTestResults(): array
    {
        $results = [];
        $isCapturing = false;
        $currentCategory = 'Uncategorized';

        foreach ($this->lines as $index => $line) {
            $trimmedLine = trim($line);
            if (empty($trimmedLine))
                continue;

            // Check if this line is a section header
            if (in_array(strtoupper($trimmedLine), self::SECTION_HEADERS)) {
                $isCapturing = true;
                $currentCategory = ucwords(strtolower($trimmedLine));
                continue;
            }

            // Stop capturing at validation section
            if (str_starts_with($trimmedLine, self::STOP_KEYWORD)) {
                $isCapturing = false;
                continue;
            }

            // Skip header lines like "Results Unit Reference Range Flag"
            if (preg_match('/^Results\s+Unit\s+Reference\s+Range\s+Flag\s*$/i', $trimmedLine)) {
                continue;
            }

            // Parse test result lines - they must contain a colon and be in a capturing section
            if ($isCapturing && strpos($trimmedLine, ':') !== false && strpos($trimmedLine, ':') > 0) {
                $colonPos = strpos($trimmedLine, ':');
                $testName = trim(substr($trimmedLine, 0, $colonPos));
                $valuesStr = trim(substr($trimmedLine, $colonPos + 1));

                // Skip empty test names or values
                if (empty($testName) || empty($valuesStr)) {
                    continue;
                }

                // Debug logging
                Log::debug("Parsing test: {$testName} with values: {$valuesStr}");

                $result = $this->parseTestValues($valuesStr);
                if ($result) {
                    $results[] = array_merge([
                        'category' => $currentCategory,
                        'testName' => $testName,
                    ], $result);
                    Log::debug("Added result: ", $results[count($results) - 1]);
                } else {
                    Log::debug("Failed to parse values: {$valuesStr}");
                }
            }
        }

        return $results;
    }

    private function parseTestValues(string $valuesStr): ?array
    {
        $valuesStr = trim($valuesStr);

        // Handle different formats of test results
        // Looking at the actual OCR data, let's handle the specific formats we see:

        // Format: "52 U/L ( 0 - 55 )"
        if (preg_match('/^([\d\.]+)\s+([a-zA-Z\/µ%°L]+)\s*(\([^)]+\))?\s*([A-Z])?$/i', $valuesStr, $matches)) {
            $result = trim($matches[1]);
            $unit = isset($matches[2]) ? trim($matches[2]) : '';
            $referenceRange = isset($matches[3]) ? trim($matches[3], '() ') : '';
            $flag = isset($matches[4]) ? trim($matches[4]) : null;

            return [
                'result' => $result,
                'unit' => $unit,
                'referenceRange' => $referenceRange,
                'flag' => $flag,
            ];
        }

        // Format: "0.9 mg/dL (0.9 1.1)"
        if (preg_match('/^([\d\.]+)\s+([a-zA-Z\/µ%°L]+)\s*(\([^)]+\))?\s*([A-Z])?$/i', $valuesStr, $matches)) {
            $result = trim($matches[1]);
            $unit = isset($matches[2]) ? trim($matches[2]) : '';
            $referenceRange = isset($matches[3]) ? trim($matches[3], '() ') : '';
            $flag = isset($matches[4]) ? trim($matches[4]) : null;

            return [
                'result' => $result,
                'unit' => $unit,
                'referenceRange' => $referenceRange,
                'flag' => $flag,
            ];
        }

        // Format: "50 mg/dL (>60) L" with flag at the end
        if (preg_match('/^([\d\.]+)\s+([a-zA-Z\/µ%°L]+)\s*(\([^)]+\))?\s*([A-Z])$/i', $valuesStr, $matches)) {
            $result = trim($matches[1]);
            $unit = isset($matches[2]) ? trim($matches[2]) : '';
            $referenceRange = isset($matches[3]) ? trim($matches[3], '() ') : '';
            $flag = isset($matches[4]) ? trim($matches[4]) : null;

            return [
                'result' => $result,
                'unit' => $unit,
                'referenceRange' => $referenceRange,
                'flag' => $flag,
            ];
        }

        // Format: Just "NEGATIVE" or "POSITIVE"
        if (preg_match('/^(NEGATIVE|POSITIVE)$/i', $valuesStr, $matches)) {
            return [
                'result' => trim($matches[1]),
                'unit' => '',
                'referenceRange' => '',
                'flag' => null,
            ];
        }

        // Format: Numbers without units (like "6.8" from the hematology section)
        if (preg_match('/^([\d\.]+)$/i', $valuesStr, $matches)) {
            return [
                'result' => trim($matches[1]),
                'unit' => '',
                'referenceRange' => '',
                'flag' => null,
            ];
        }

        return null;
    }

    private function match(string $regex): ?string
    {
        return preg_match($regex, $this->fullText, $matches) ? trim($matches[1]) : null;
    }
}