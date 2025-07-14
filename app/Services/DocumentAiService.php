<?php

namespace App\Services;

use Google\Cloud\DocumentAI\V1\Client\DocumentProcessorServiceClient;
use Google\Cloud\DocumentAI\V1\ProcessRequest;
use Google\Cloud\DocumentAI\V1\RawDocument;
use Illuminate\Support\Facades\Log;
use App\Services\LabReportParserService; 

class DocumentAiService
{
    protected $client;
    protected $parser;
    protected $processorName;

    /**
     * Create a new service instance.
     */
    public function __construct(LabReportParserService $parser)
    {
        $this->parser = $parser;

        try {
            // --- THIS IS THE BULLETPROOF FIX FOR YOUR CREDENTIALS ---
            // It directly points to the credentials file, bypassing .env lookup issues.
            $credentialsPath = storage_path('app/google/clinex-application-ea5913277c08.json');

            if (!file_exists($credentialsPath)) {
                // If the file doesn't exist, this provides a clear, actionable error.
                throw new \Exception("Google Cloud credentials file not found at: {$credentialsPath}");
            }

            $this->client = new DocumentProcessorServiceClient([
                'credentials' => $credentialsPath
            ]);
            // --- END OF FIX ---

            // Load configuration from config/services.php
            $projectId = config('services.google.project_id');
            $location = config('services.google.location');
            $processorId = config('services.google.processor_id');

            if (!$projectId || !$location || !$processorId) {
                throw new \Exception('Google Cloud project_id, location, and processor_id must be configured in config/services.php');
            }

            // Construct the full processor name required by the API
            $this->processorName = $this->client->processorName($projectId, $location, $processorId);

        } catch (\Exception $e) {
            Log::error("FATAL: Failed to initialize Document AI client: " . $e->getMessage());
            // Fail fast if the service can't start.
            throw $e;
        }
    }

    /**
     * The single public method to process a lab report PDF.
     */
    public function processLabReport(string $filePath): ?array
    {
        try {
            $documentContent = file_get_contents($filePath);
            if ($documentContent === false) {
                Log::error("Failed to read file content: {$filePath}");
                return null;
            }

            $rawDocument = new RawDocument([
                'content' => $documentContent,
                'mime_type' => 'application/pdf',
            ]);

            $request = (new ProcessRequest())
                ->setName($this->processorName)
                ->setRawDocument($rawDocument);

            $result = $this->client->processDocument($request);
            $document = $result->getDocument();

            if (!$document) {
                Log::warning("Document AI returned an empty document object for: {$filePath}");
                return null;
            }

            // Hand off the complex parsing logic to our dedicated parser service
            return $this->parser->parse($document);

        } catch (\Exception $e) {
            Log::error('Google Document AI processing failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Cleanly close the client connection.
     */
    public function __destruct()
    {
        if ($this->client) {
            $this->client->close();
        }
    }
}