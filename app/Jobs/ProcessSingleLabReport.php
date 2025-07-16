<?php
// filepath: app/Jobs/ProcessSingleLabReport.php

namespace App\Jobs;

use App\Models\LabReport;
use App\Models\ReportBatch;
use App\Models\Patient;
use App\Models\ExtractedData;
use App\Models\ExtractedLabInfo;
use App\Services\ReportParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ProcessSingleLabReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes per report

    public function __construct(
        public LabReport $labReport,
        public ReportBatch $batch
    ) {}

    public function handle(): void
    {
        Log::info("Processing lab report ID: {$this->labReport->id} from batch ID: {$this->batch->id}");

        try {
            $this->labReport->update([
                'status' => 'processing',
                'processing_started_at' => now()
            ]);

            $pdfPath = $this->labReport->getFullPath();
            $pythonScriptPath = base_path('scripts/python/ocr_processor.py');

            $result = Process::run("python \"{$pythonScriptPath}\" \"{$pdfPath}\"");

            if ($result->successful()) {
                $extractedText = $result->output();
                $parser = new ReportParserService();
                $structuredData = $parser->parse($extractedText);

                // Process patient information
                $this->processPatientInfo($structuredData['patient_info'] ?? []);

                // Process lab information
                $this->processLabInfo($structuredData['lab_info'] ?? []);

                // Process test results
                $this->processTestResults($structuredData['test_results'] ?? []);

                $this->labReport->update([
                    'status' => 'processed',
                    'processing_completed_at' => now()
                ]);

                // Update batch progress
                $this->updateBatchProgress('processed');

            } else {
                throw new \Exception($result->errorOutput());
            }

        } catch (\Exception $e) {
            Log::error("Failed to process report ID: {$this->labReport->id}", [
                'error' => $e->getMessage(),
                'batch_id' => $this->batch->id
            ]);

            $this->labReport->update([
                'status' => 'failed',
                'processing_error' => $e->getMessage(),
                'processing_completed_at' => now()
            ]);

            // Update batch progress
            $this->updateBatchProgress('failed');
        }

        Log::info("Finished processing lab report ID: {$this->labReport->id}");
    }

    private function processPatientInfo(array $patientInfo): void
    {
        if (empty($patientInfo)) return;

        $patient = null;
        if (!empty($patientInfo['patient_id'])) {
            $patient = Patient::updateOrCreate(
                ['patient_id' => $patientInfo['patient_id']],
                [
                    'name' => $patientInfo['name'] ?? null,
                    'age' => $patientInfo['age'] ?? null,
                    'gender' => $patientInfo['gender'] ?? null,
                    'phone' => $patientInfo['phone'] ?? null,
                    'email' => $patientInfo['email'] ?? null,
                ]
            );

            $this->labReport->update(['patient_id' => $patient->id]);
        }
    }

    private function processLabInfo(array $labInfo): void
    {
        if (empty($labInfo)) return;

        ExtractedLabInfo::updateOrCreate(
            ['lab_report_id' => $this->labReport->id],
            [
                'lab_id' => $labInfo['lab_id'] ?? null,
                'requested_by' => $labInfo['requested_by'] ?? null,
                'requested_date' => $labInfo['requested_date'] ?? null,
                'collected_date' => $labInfo['collected_date'] ?? null,
                'analysis_date' => $labInfo['analysis_date'] ?? null,
                'validated_by' => $labInfo['validated_by'] ?? null,
            ]
        );
    }

    private function processTestResults(array $testResults): void
    {
        if (empty($testResults)) return;

        foreach ($testResults as $category => $tests) {
            if (is_array($tests)) {
                foreach ($tests as $test) {
                    ExtractedData::create([
                        'lab_report_id' => $this->labReport->id,
                        'category' => $category,
                        'test_name' => $test['test_name'] ?? null,
                        'result' => $test['result'] ?? null,
                        'unit' => $test['unit'] ?? null,
                        'reference' => $test['reference'] ?? null,
                        'flag' => $test['flag'] ?? null,
                        'confidence_score' => $test['confidence'] ?? null,
                        'is_verified' => false,
                    ]);
                }
            }
        }
    }

    private function updateBatchProgress(string $status): void
    {
        if ($status === 'processed') {
            $this->batch->increment('processed_reports');
        } elseif ($status === 'failed') {
            $this->batch->increment('failed_reports');
        }

        // Check if batch is complete
        $totalProcessed = $this->batch->processed_reports + $this->batch->failed_reports;
        if ($totalProcessed >= $this->batch->total_reports) {
            $this->batch->update([
                'status' => 'completed',
                'processing_completed_at' => now()
            ]);
        }
    }
}