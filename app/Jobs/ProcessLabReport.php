<?php

namespace App\Jobs;

use App\Models\LabReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use App\Services\ReportParserService;
use App\Models\ExtractedData;

class ProcessLabReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public LabReport $labReport
    ) {
        // This is how we pass the uploaded lab report into the job
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Processing lab report ID: {$this->labReport->id}");
        $this->labReport->update(['status' => 'processing']);

        $pdfPath = $this->labReport->getFullPath();
        $pythonScriptPath = base_path('scripts/python/ocr_processor.py');

        $result = Process::run("python \"{$pythonScriptPath}\" \"{$pdfPath}\"");

        if ($result->successful()) {
            $extractedText = $result->output();

            // 1. Instantiate our new parser service
            $parser = new ReportParserService();

            // 2. Parse the raw text to get structured data
            $structuredData = $parser->parse($extractedText);

            // 3. Log the structured data to see the result
            Log::info("Parsed structured data for report ID: {$this->labReport->id}", $structuredData);

            // 4. Save the parsed data to the database
            foreach ($structuredData['patient_info'] as $fieldName => $value) {
                if ($value) { // Only save if a value was found
                    ExtractedData::create([
                        'lab_report_id' => $this->labReport->id,
                        'section' => 'patient_info',
                        'field_name' => $fieldName,
                        'value' => $value,
                    ]);
                }
            }

            $this->labReport->update(['status' => 'processed']);
        } else {
            $errorOutput = $result->errorOutput();
            Log::error("Failed to process report ID: {$this->labReport->id}", [
                'error' => $errorOutput
            ]);
            $this->labReport->update([
                'status' => 'failed',
                'processing_error' => $errorOutput
            ]);
        }

        Log::info("Finished processing job for lab report ID: {$this->labReport->id}");
    }
}
