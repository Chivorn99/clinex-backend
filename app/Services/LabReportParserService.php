<?php

namespace App\Services;

use Google\Cloud\DocumentAI\V1\Document;
use Illuminate\Support\Facades\Log;

class LabReportParserService
{
    private $fullText;
    private $lines;

    const SECTION_HEADERS = ['BIOCHEMISTRY', 'IMMUNOLOGY', 'HEMATOLOGY', 'URINE ANALYSIS'];
    const STOP_KEYWORD = 'Validated By';

    public function parse(Document $document): array
    {
        $fullText = str_replace(["\r\n", "\r"], "\n", $document->getText());
        Log::debug("--- RAW OCR TEXT ---\n" . $fullText . "\n--- END RAW OCR TEXT ---");

        // Pre-process the text to handle the "label on one line, value on the next" pattern
        $this->lines = $this->preprocessLines(explode("\n", $fullText));

        return [
            'patientInfo' => $this->parseInfoSection([
                'name' => '/Name/i',
                'patientId' => '/Patient ID/i',
                'age' => '/Age/i',
                'gender' => '/Gender/i',
                'phone' => '/Phone/i',
            ]),
            'labInfo' => $this->parseInfoSection([
                'labId' => '/Lab ID/i',
                'requestedBy' => '/Requested By/i',
                'requestedDate' => '/Requested Date/i',
                'collectedDate' => '/Collected Date/i',
                'analysisDate' => '/Analysis Date/i',
                'validatedBy' => '/Validated By/i',
            ]),
            'testResults' => $this->parseTestResults(),
        ];
    }

    private function parsePatientInfo(): array
    {
        // Regex patterns that are more tolerant to OCR mistakes (e.g., missing colons)
        return [
            'name' => $this->match('/ឈោ្មះ\/Name\s*:?\s*([^\n]+)/'),
            'patientId' => $this->match('/Patient ID\s*:?\s*([^\n]+)/'),
            'age' => $this->match('/អាយុ\/Age\s*:?\s*([^\s]+)/'),
            'gender' => $this->match('/ភេទ\/Sex\s*:?\s*([^\n]+)/'),
            'phone' => $this->match('/Phone\s*:?\s*([\d\s\-]+)/'),
        ];
    }

    private function preprocessLines(array $rawLines): array
    {
        $processedLines = [];
        $count = count($rawLines);
        for ($i = 0; $i < $count; $i++) {
            $currentLine = trim($rawLines[$i]);
            if ($i + 1 < $count) {
                $nextLine = trim($rawLines[$i + 1]);
                if (str_starts_with($nextLine, ':')) {
                    // This is a key-value pair split across two lines. Merge them.
                    $processedLines[] = $currentLine . ' ' . $nextLine;
                    $i++; // Skip the next line since we've already processed it
                    continue;
                }
            }
            $processedLines[] = $currentLine;
        }
        return $processedLines;
    }

    private function parseInfoSection(array $patterns): array
    {
        $info = [];
        foreach ($patterns as $key => $pattern) {
            $info[$key] = null; // Initialize with null
            foreach ($this->lines as $line) {
                if (preg_match($pattern, $line)) {
                    // Split at the colon and take the second part as the value
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $info[$key] = trim($parts[1]);
                        break; // Found it, move to the next pattern
                    }
                }
            }
        }
        return $info;
    }

    private function parseTestResults(): array
    {
        $results = [];
        $lines = explode("\n", $this->fullText);
        $isCapturing = false;
        $currentCategory = 'Uncategorized';

        // Regex Explanation:
        // ^(.+?)                - Group 1: The Test Name (non-greedy)
        // \s{2,}               - At least two spaces separating name from result
        // ([\d\.<>]+)           - Group 2: The Result (digits, dots, <, >)
        // \s+                  - One or more spaces
        // ([a-zA-Z\/µL]+)       - Group 3: The Unit (letters, slashes, µL)
        // \s+                  - One or more spaces
        // ([\d\.\s\-to]+)      - Group 4: The Reference Range (digits, dots, spaces, -, 'to')
        // (\s+[A-Z])?           - Group 5 (Optional): A space followed by a capital letter for the Flag
        foreach ($this->lines as $line) {
            if (empty($line))
                continue;

            if (in_array(strtoupper($line), self::SECTION_HEADERS)) {
                $isCapturing = true;
                $currentCategory = ucwords(strtolower($line));
                continue;
            }

            if (str_starts_with($line, self::STOP_KEYWORD)) {
                $isCapturing = false;
                continue; // Don't break, allow parsing of other info like "Lab Technician"
            }

            // A test result line must contain a colon that is not at the beginning
            if ($isCapturing && strpos($line, ':') > 0) {
                list($testName, $valuesStr) = explode(':', $line, 2);
                $testName = trim($testName);

                // Regex to find: 1. Result, 2. Unit, 3. Reference, 4. Flag
                preg_match('/([\d\.<>]+|NEGATIVE|POSITIVE)\s*([a-zA-Z\/µL%]+)?\s*(\(.*\))?\s*([A-Z])?/', trim($valuesStr), $matches);

                $results[] = [
                    'category' => $currentCategory,
                    'testName' => $testName,
                    'result' => trim($matches[1] ?? ''),
                    'unit' => trim($matches[2] ?? ''),
                    'referenceRange' => trim($matches[3] ?? '', '() '),
                    'flag' => trim($matches[4] ?? ''),
                ];
            }
        }
        return $results;
    }

    private function match(string $regex): ?string
    {
        return preg_match($regex, $this->fullText, $matches) ? trim($matches[1]) : null;
    }
}