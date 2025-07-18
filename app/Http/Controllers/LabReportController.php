<?php

namespace App\Http\Controllers;

use App\Models\LabReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Jobs\ProcessLabReport;
use Illuminate\Validation\ValidationException;
use App\Services\DocumentAiService;
use App\Models\Patient;
use App\Models\ExtractedData;
use App\Models\ExtractedLabInfo;
use DB;


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
            $request->validate([
                'pdf_file' => 'required|file|mimes:pdf|max:10240',
            ]);

            $pdfFile = $request->file('pdf_file');

            Log::info("Processing uploaded PDF: " . $pdfFile->getClientOriginalName());

            $structuredData = $this->documentAiService->processLabReport($pdfFile->getRealPath());

            if (!$structuredData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to extract data from the document. The format might not be recognized or the file could be empty.'
                ], 422); // Unprocessable Entity
            }

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
            ], 500);
        }
    }
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = LabReport::with(['batch', 'patient', 'uploader']);

        if ($request->filled('batch_id')) {
            $query->where('batch_id', $request->batch_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('verified')) {
            if ($request->boolean('verified')) {
                $query->whereNotNull('verified_at');
            } else {
                $query->whereNull('verified_at');
            }
        }

        $labReports = $query->latest()->paginate($request->get('per_page', 15));

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => $labReports,
                'message' => 'Lab reports retrieved successfully'
            ]);
        }

        return view('lab-reports.index', compact('labReports'));
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
        $labReport->load(['batch', 'patient', 'uploader', 'verifier']);

        if (request()->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'lab_report' => $labReport,
                    'extracted_data' => $labReport->extracted_data,
                    'needs_verification' => $labReport->status === 'processed' && !$labReport->verified_at,
                    'can_edit' => !$labReport->verified_at || auth()->user()->role === 'admin'
                ],
                'message' => 'Lab report retrieved successfully'
            ]);
        }

        return view('lab-reports.show', compact('labReport'));
    }

    /**
     * Verify extracted data and store to database
     */
    public function verify(Request $request, LabReport $labReport)
    {
        if ($labReport->status !== 'processed') {
            return response()->json([
                'success' => false,
                'message' => 'Lab report must be processed before verification'
            ], 422);
        }

        if ($labReport->verified_at) {
            return response()->json([
                'success' => false,
                'message' => 'Lab report is already verified'
            ], 422);
        }

        $request->validate([
            'verified_data' => 'required|array',
            'verified_data.patientInfo' => 'required|array',
            'verified_data.labInfo' => 'required|array',
            'verified_data.testResults' => 'required|array',
            'notes' => 'nullable|string|max:1000'
        ]);

        DB::beginTransaction();

        try {
            $verifiedData = $request->verified_data;

            $patient = $this->createOrUpdatePatient($verifiedData['patientInfo']);

            $labInfo = $this->storeLabInfo($labReport, $verifiedData['labInfo']);

            $this->storeTestResults($labReport, $verifiedData['testResults']);

            $labReport->update([
                'patient_id' => $patient->id,
                'verified_by' => auth()->id(),
                'verified_at' => now(),
                'notes' => $request->notes,
                'status' => 'verified'
            ]);

            // 5. Update batch verified count
            $labReport->batch->increment('verified_reports');

            DB::commit();

            Log::info('Lab report verified successfully', [
                'lab_report_id' => $labReport->id,
                'patient_id' => $patient->id,
                'verified_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'data' => $labReport->fresh(['patient', 'batch']),
                'message' => 'Lab report verified and stored successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            
            Log::error('Lab report verification failed', [
                'lab_report_id' => $labReport->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Verification failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store lab information
     */
    private function storeLabInfo($labReport, $labInfo)
    {
        return ExtractedLabInfo::updateOrCreate(
            ['lab_report_id' => $labReport->id],
            [
                'lab_id' => $labInfo['labId'],
                'requested_by' => $labInfo['requestedBy'],
                'requested_date' => $this->parseDate($labInfo['requestedDate']),
                'collected_date' => $this->parseDate($labInfo['collectedDate']),
                'analysis_date' => $this->parseDate($labInfo['analysisDate']),
                'validated_by' => $labInfo['validatedBy'],
            ]
        );
    }

    /**
     * Store test results
     */
    private function storeTestResults($labReport, $testResults)
    {
        ExtractedData::where('lab_report_id', $labReport->id)->delete();

        foreach ($testResults as $test) {
            ExtractedData::create([
                'lab_report_id' => $labReport->id,
                'category' => $test['category'],      
                'test_name' => $test['testName'],     
                'result' => $test['result'],      
                'unit' => $test['unit'] ?? null,  
                'reference' => $test['referenceRange'] ?? null, 
                'flag' => $test['flag'],          
                'coordinates' => null,
                'confidence_score' => 1.0,
                'is_verified' => true,
            ]);
        }
    }

    /**
     * Create or update patient from verified data
     */
    private function createOrUpdatePatient($patientInfo)
    {
        $patient = Patient::where('patient_id', $patientInfo['patientId'])
            ->orWhere(function($query) use ($patientInfo) {
                $query->where('name', $patientInfo['name']);
                if (!empty($patientInfo['phone'])) {
                    $query->where('phone', $patientInfo['phone']);
                }
            })
            ->first();

        if ($patient) {
            // Update existing patient
            $patient->update([
                'name' => $patientInfo['name'],
                'age' => $patientInfo['age'],  
                'gender' => $patientInfo['gender'],   
                'phone' => $patientInfo['phone'] ?? $patient->phone,
            ]);
        } else {
            // Create new patient
            $patient = Patient::create([
                'patient_id' => $patientInfo['patientId'],
                'name' => $patientInfo['name'],
                'age' => $patientInfo['age'], 
                'gender' => $patientInfo['gender'], 
                'phone' => $patientInfo['phone'],
            ]);
        }

        return $patient;
    }

    /**
     * Parse date string to Carbon instance
     */
    private function parseDate($dateString)
    {
        try {
            return \Carbon\Carbon::createFromFormat('d/m/Y H:i', $dateString);
        } catch (\Exception $e) {
            return null;
        }
    }
}
