<?php

namespace App\Jobs;

use App\Models\LabReport;
use App\Models\Template;
use App\Services\DocumentAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessLabReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public LabReport $labReport
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(DocumentAiService $documentAiService): void
    {
        // --- 1. Load the Template from the Database ---
        $template = Template::find($this->labReport->template_id);
        if (!$template) {
            $this->labReport->update(['status' => 'failed', 'processing_error' => "Template ID {$this->labReport->template_id} not found."]);
            Log::error("Template ID {$this->labReport->template_id} not found for Lab Report {$this->labReport->id}.");
            return;
        }

        Log::info("Processing lab report ID: {$this->labReport->id} with Template: '{$template->name}'");
        $this->labReport->update(['status' => 'processing']);

        // --- 2. Get Processor ID from the Template ---
        $pdfPath = $this->labReport->getFullPath();
        $processorId = $template->processor_id; // 👈 NEW: No longer hard-coded

        $document = $documentAiService->processDocument($pdfPath, $processorId);

        if ($document !== null) {
            Log::info("Successfully processed report ID: {$this->labReport->id}.");

            // --- 3. (Future Step) Apply Mappings ---
            // $parser = new DocumentAiParserService();
            // $structuredData = $parser->parse($document, $template->mappings);
            // $this->saveData($structuredData);

            Log::info("Extracted Text: " . substr($document->getText(), 0, 1500) . "...");
            $this->labReport->update(['status' => 'processed']);

        } else {
            $this->labReport->update([
                'status' => 'failed',
                'processing_error' => 'Failed to process with Google Document AI. See logs for details.'
            ]);
        }

        Log::info("Finished processing job for lab report ID: {$this->labReport->id}");
    }
}