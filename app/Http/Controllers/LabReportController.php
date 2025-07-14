<?php

namespace App\Http\Controllers;

use App\Models\LabReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Jobs\ProcessLabReport;
use Illuminate\Validation\ValidationException;
use App\Services\DocumentAiService;


class LabReportController extends Controller
{

    protected $documentAiService;
    

    /**
     * Inject the DocumentAiService into the controller.
     */
    public function __construct(DocumentAiService $documentAiService)
    {
        $this->documentAiService = $documentAiService;
    }

    /**
     * Process an uploaded lab report PDF.
     * This is the single endpoint for handling the PDF upload and extraction.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function process(Request $request)
    {
        try {
            // 1. Validate the incoming request to ensure a PDF file is present.
            $request->validate([
                'pdf_file' => 'required|file|mimes:pdf|max:10240',
            ]);

            $pdfFile = $request->file('pdf_file');

            Log::info("Processing uploaded PDF: " . $pdfFile->getClientOriginalName());

            // 2. Pass the file path to the service for processing.
            // The service handles the Google AI call and the parsing logic.
            $structuredData = $this->documentAiService->processLabReport($pdfFile->getRealPath());

            // 3. Check if the service returned valid data.
            if (!$structuredData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to extract data from the document. The format might not be recognized or the file could be empty.'
                ], 422); // Unprocessable Entity
            }

            // 4. Return the successful, structured data to the frontend.
            return response()->json([
                'success' => true,
                'data' => $structuredData
            ]);

        } catch (ValidationException $e) {
            // Handle cases where the upload is not a PDF or is too large.
            Log::error('Validation failed for PDF upload: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->errors()], 422);
        } catch (\Exception $e) {
            // Catch any other unexpected errors during processing.
            Log::error('A critical error occurred in LabReportController: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred on the server. Please check the logs.'
            ], 500); // Internal Server Error
        }
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => 'required|file|mimes:pdf|max:10240',
            'template_id' => 'required|exists:templates,id'
        ]);

        try {
            $uploadedFiles = [];
            $template = \App\Models\Template::findOrFail($request->template_id);

            foreach ($request->file('files') as $file) {
                $filename = time() . '_' . uniqid() . '_' . $file->getClientOriginalName();
                $path = $file->storeAs('lab-reports', $filename, 'private');

                $labReport = LabReport::create([
                    'original_filename' => $file->getClientOriginalName(),
                    'storage_path' => $path,
                    'template_id' => $template->id,
                    'status' => 'pending'
                ]);

                ProcessLabReport::dispatch($labReport);

                $uploadedFiles[] = [
                    'id' => $labReport->id,
                    'filename' => $labReport->original_filename,
                    'status' => $labReport->status,
                    'template' => $template->name
                ];
            }

            return response()->json([
                'success' => true,
                'message' => count($uploadedFiles) . ' PDF files uploaded successfully with template: ' . $template->name,
                'data' => $uploadedFiles
            ], 201);

        } catch (\Exception $e) {
            Log::error('Batch upload failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(LabReport $labReport)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(LabReport $labReport)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, LabReport $labReport)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(LabReport $labReport)
    {
        //
    }
}
