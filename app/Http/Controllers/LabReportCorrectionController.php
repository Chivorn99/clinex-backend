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
        // Get extracted data from the ExtractedData table
        $extractedDataRecords = ExtractedData::where('lab_report_id', $labReport->id)->get();
        
        $extractedData = [
            'patient_info' => [],
            'lab_info' => [],
            'test_results' => []
        ];

        foreach ($extractedDataRecords as $record) {
            if ($record->section === 'patient_info') {
                $extractedData['patient_info'] = json_decode($record->value, true);
            } elseif ($record->section === 'lab_info') {
                $extractedData['lab_info'] = json_decode($record->value, true);
            } else {
                // This is a test results section
                $extractedData['test_results'][$record->section] = json_decode($record->value, true);
            }
        }
        
        return view('lab-reports.correct', [
            'labReport' => $labReport,
            'extractedData' => $extractedData,
            'pdfUrl' => route('lab-reports.pdf', $labReport) // Use the route instead of Storage::url
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
