<?php

namespace App\Services;

use Google\Cloud\DocumentAI\V1\Client\DocumentProcessorServiceClient;
use Google\Cloud\DocumentAI\V1\ProcessRequest;
use Google\Cloud\DocumentAI\V1\RawDocument;

class DocumentAiService
{
    protected DocumentProcessorServiceClient $client;
    protected string $projectId;
    protected string $location = 'us'; // Or 'eu', depending on your processor location

    /**
     * Create a new service instance.
     */
    public function __construct()
    {
        // Use the credentials we configured in config/services.php
        $credentials = config('services.google.credentials');
        $this->projectId = config('services.google.project_id');

        // Create a new Document AI client, authenticating with our key
        $this->client = new DocumentProcessorServiceClient([
            'credentials' => $credentials,
        ]);
    }

    /**
     * Process a document using a specific Document AI processor.
     *
     * @param string $filePath The absolute path to the PDF file.
     * @param string $processorId The ID of the processor to use.
     * @param string $mimeType The mime type of the file (e.g., 'application/pdf').
     * @return \Google\Cloud\DocumentAI\V1\Document|null
     */
    public function processDocument(string $filePath, string $processorId, string $mimeType = 'application/pdf')
    {
        try {
            // The full resource name of the processor
            $name = $this->client->processorName($this->projectId, $this->location, $processorId);

            // Read the file content from the local disk
            $documentContent = file_get_contents($filePath);
            if ($documentContent === false) {
                // Log an error if the file cannot be read
                \Log::error("Failed to read file content from path: {$filePath}");
                return null;
            }

            // Create a RawDocument object
            $rawDocument = new RawDocument([
                'content' => $documentContent,
                'mime_type' => $mimeType,
            ]);

            // Create the process request
            $request = (new ProcessRequest())
                ->setName($name)
                ->setRawDocument($rawDocument);

            // Send the request to the Document AI API
            $result = $this->client->processDocument($request);

            // Return the structured Document object from the response
            return $result->getDocument();

        } catch (\Exception $e) {
            // Log any errors that occur during the API call
            \Log::error('Google Document AI processing failed: ' . $e->getMessage());
            return null;
        }
    }
}