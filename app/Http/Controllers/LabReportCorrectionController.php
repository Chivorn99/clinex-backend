<?php

namespace App\Http\Controllers;

use App\Models\LabReport;
use App\Models\OcrCorrection;
use App\Models\ExtractedData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LabReportCorrectionController extends Controller
{
    public function show(LabReport $labReport)
    {
        // In a real scenario, we'd get this from the processed job.
        // For the mock-up, we'll use the text from the log.
        $rawExtractedText = "KV Hospital...\nPatient ID: PT001876\n...\nLABORATORY REPORT\nBIOCHIMISTRY\nGlucose... \nValidated By:...\nLab Technician:...";

        // --- "Best Guess" Parsing Logic ---
        $headerKeywords = ['/Name', 'Patient ID', 'Age', 'Gender', 'Lab ID', 'Requested Date'];
        $footerKeywords = ['Validated By', 'Lab Technician'];

        $parsedData = [
            'header' => [],
            'body' => [
                ['test_name' => 'Glucose', 'value' => '94', 'unit' => 'mg/dL'],
                ['test_name' => 'Cholesterole Total', 'value' => '173', 'unit' => 'mg/dL'],
            ], // pre-populate with some body fields
            'footer' => [],
        ];
        // This is a simplified "best guess". A real implementation would be more robust.
        // For now, it just sets up the sections for the UI.

        return view('lab-reports.verify', [ // 👈 We will create this new view file
            'labReport' => $labReport,
            'rawText' => $rawExtractedText,
            'parsedData' => $parsedData,
            'pdfUrl' => route('lab-reports.pdf', $labReport)
        ]);
    }

    public function saveCorrections(Request $request, LabReport $labReport)
    {
        $corrections = $request->input('corrections', []);
        $updatedData = $request->input('extractedData', []);

        // Learn from each correction
        foreach ($corrections as $correction) {
            OcrCorrection::learnCorrection(
                $correction['original'],
                $correction['corrected'],
                $correction['type']
            );

            Log::info("Learned correction", [
                'original' => $correction['original'],
                'corrected' => $correction['corrected'],
                'type' => $correction['type']
            ]);
        }

        // Update the extracted data in database
        $this->updateExtractedData($labReport, $updatedData);

        // Update lab report status
        $labReport->update([
            'status' => 'corrected',
            'updated_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Corrections saved successfully! System has learned from ' . count($corrections) . ' corrections.',
            'corrections_count' => count($corrections)
        ]);
    }

    private function updateExtractedData(LabReport $labReport, array $updatedData)
    {
        // Update patient info
        if (isset($updatedData['patient_info'])) {
            ExtractedData::updateOrCreate(
                [
                    'lab_report_id' => $labReport->id,
                    'section' => 'patient_info',
                    'field_name' => 'patient_data'
                ],
                ['value' => json_encode($updatedData['patient_info'], JSON_UNESCAPED_UNICODE)]
            );
        }

        // Update test results
        if (isset($updatedData['test_results'])) {
            foreach ($updatedData['test_results'] as $section => $results) {
                ExtractedData::updateOrCreate(
                    [
                        'lab_report_id' => $labReport->id,
                        'section' => $section,
                        'field_name' => 'test_results'
                    ],
                    ['value' => json_encode($results, JSON_UNESCAPED_UNICODE)]
                );
            }
        }
    }

    public function showPdf(LabReport $labReport)
    {
        $filePath = storage_path('app/private/' . $labReport->storage_path);

        return response()->file($filePath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $labReport->original_filename . '"'
        ]);
    }
}
