<?php
// filepath: app/Jobs/ProcessLabReportBatch.php

namespace App\Jobs;

use App\Models\ReportBatch;
use App\Models\LabReport;
use App\Jobs\ProcessSingleLabReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessLabReportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour timeout

    public function __construct(
        public ReportBatch $batch
    ) {}

    public function handle(): void
    {
        Log::info("Starting batch processing for batch ID: {$this->batch->id}");

        $this->batch->update([
            'status' => 'processing',
            'processing_started_at' => now()
        ]);

        // Get all uploaded reports in this batch
        $reports = $this->batch->labReports()
                              ->whereIn('status', ['uploaded', 'failed'])
                              ->get();

        if ($reports->isEmpty()) {
            Log::warning("No reports to process in batch ID: {$this->batch->id}");
            $this->batch->update(['status' => 'completed']);
            return;
        }

        // Dispatch individual processing jobs
        foreach ($reports as $report) {
            ProcessSingleLabReport::dispatch($report, $this->batch);
        }

        Log::info("Dispatched {$reports->count()} processing jobs for batch ID: {$this->batch->id}");
    }
}