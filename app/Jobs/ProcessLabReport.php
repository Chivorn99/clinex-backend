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

        // Get the full, absolute path to the stored PDF
        $pdfPath = $this->labReport->getFullPath();

        $pythonScriptPath = base_path('scripts/python/ocr_processor.py');

        $result = Process::run("python \"{$pythonScriptPath}\" \"{$pdfPath}\"");

        if ($result->successful()) {
            $extractedText = $result->output();

            Log::info("Successfully extracted text from report ID: {$this->labReport->id}");

            // For now, let's just log the first 500 characters
            Log::info("Extracted Text (sample): " . substr($extractedText, 0, 500));

            // In the future, we will save this text to the extracted_data table

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
