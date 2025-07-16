<?php

namespace App\Http\Controllers;

use App\Models\ReportBatch;
use App\Models\LabReport;
use App\Jobs\ProcessLabReportBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportBatchController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = ReportBatch::with(['uploader', 'labReports'])
                            ->withCount('labReports');

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by uploader
        if ($request->filled('uploaded_by')) {
            $query->where('uploaded_by', $request->uploaded_by);
        }

        // Search by name
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $batches = $query->latest()->paginate($request->get('per_page', 15));

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => $batches,
                'message' => 'Batches retrieved successfully'
            ]);
        }

        return view('batches.index', compact('batches'));
    }

    /**
     * Upload multiple lab reports as a batch.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'files' => 'required|array|min:1|max:50', // Max 50 files per batch
            'files.*' => 'required|file|mimes:pdf|max:10240', // 10MB per file
            'auto_process' => 'boolean',
        ]);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            return back()->withErrors($validator)->withInput();
        }

        DB::beginTransaction();

        try {
            // Create the batch
            $batch = ReportBatch::create([
                'name' => $request->name,
                'description' => $request->description,
                'uploaded_by' => auth()->id(),
                'total_reports' => count($request->file('files')),
                'processed_reports' => 0,
                'verified_reports' => 0,
                'failed_reports' => 0,
                'status' => 'pending',
            ]);

            $uploadedFiles = [];
            $batchFolder = 'lab_reports/batch_' . $batch->id;

            // Upload each file
            foreach ($request->file('files') as $index => $file) {
                $originalName = $file->getClientOriginalName();
                $storedName = time() . '_' . $index . '_' . Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) . '.pdf';
                $storagePath = $batchFolder . '/' . $storedName;
                
                // Store file
                Storage::disk('private')->putFileAs($batchFolder, $file, $storedName);

                // Create lab report record
                $labReport = LabReport::create([
                    'batch_id' => $batch->id,
                    'uploaded_by' => auth()->id(),
                    'original_filename' => $originalName,
                    'stored_filename' => $storedName,
                    'storage_path' => $storagePath,
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'file_hash' => hash_file('sha256', $file->getPathname()),
                    'status' => 'uploaded',
                ]);

                $uploadedFiles[] = [
                    'id' => $labReport->id,
                    'original_name' => $originalName,
                    'stored_name' => $storedName,
                    'size' => $file->getSize(),
                ];
            }

            DB::commit();

            // Auto-process if requested
            if ($request->boolean('auto_process', true)) {
                ProcessLabReportBatch::dispatch($batch);
                $batch->update([
                    'status' => 'queued',
                    'processing_started_at' => now()
                ]);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'batch' => $batch->load(['uploader', 'labReports']),
                        'uploaded_files' => $uploadedFiles,
                    ],
                    'message' => 'Batch uploaded successfully'
                ], 201);
            }

            return redirect()->route('batches.show', $batch)
                           ->with('success', 'Batch uploaded successfully');

        } catch (\Exception $e) {
            DB::rollback();
            
            // Clean up uploaded files on error
            if (isset($batchFolder)) {
                Storage::disk('private')->deleteDirectory($batchFolder);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to upload batch',
                    'error' => $e->getMessage()
                ], 500);
            }

            return back()->withErrors(['error' => 'Failed to upload batch'])->withInput();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, ReportBatch $reportBatch)
    {
        $reportBatch->load(['uploader', 'labReports.patient']);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => $reportBatch,
                'message' => 'Batch retrieved successfully'
            ]);
        }

        return view('batches.show', compact('reportBatch'));
    }

    /**
     * Start processing a batch manually.
     */
    public function process(Request $request, ReportBatch $reportBatch)
    {
        if ($reportBatch->status === 'processing') {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Batch is already being processed'
                ], 422);
            }
            return back()->withErrors(['error' => 'Batch is already being processed']);
        }

        if ($reportBatch->status === 'completed') {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Batch has already been processed'
                ], 422);
            }
            return back()->withErrors(['error' => 'Batch has already been processed']);
        }

        ProcessLabReportBatch::dispatch($reportBatch);
        
        $reportBatch->update([
            'status' => 'queued',
            'processing_started_at' => now()
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => $reportBatch,
                'message' => 'Batch processing started'
            ]);
        }

        return back()->with('success', 'Batch processing started');
    }

    /**
     * Get batch processing status.
     */
    public function status(Request $request, ReportBatch $reportBatch)
    {
        $reportBatch->load(['labReports' => function($query) {
            $query->select('id', 'batch_id', 'status', 'processing_error');
        }]);

        $statusCounts = $reportBatch->labReports->countBy('status');
        $progressPercentage = $reportBatch->getProgressPercentage();

        $response = [
            'success' => true,
            'data' => [
                'batch' => $reportBatch,
                'progress_percentage' => round($progressPercentage, 2),
                'status_counts' => $statusCounts,
                'is_complete' => $reportBatch->isComplete(),
                'is_processing' => $reportBatch->isProcessing(),
            ],
            'message' => 'Batch status retrieved successfully'
        ];

        if ($request->expectsJson()) {
            return response()->json($response);
        }

        return view('batches.status', $response['data']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, ReportBatch $reportBatch)
    {
        try {
            // Delete physical files
            $batchFolder = 'lab_reports/batch_' . $reportBatch->id;
            Storage::disk('private')->deleteDirectory($batchFolder);

            // Delete database records (cascade will handle lab_reports)
            $reportBatch->delete();

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Batch deleted successfully'
                ]);
            }

            return redirect()->route('batches.index')
                           ->with('success', 'Batch deleted successfully');

        } catch (\Exception $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to delete batch',
                    'error' => $e->getMessage()
                ], 500);
            }

            return back()->withErrors(['error' => 'Failed to delete batch']);
        }
    }

    /**
     * Retry failed reports in a batch.
     */
    public function retryFailed(Request $request, ReportBatch $reportBatch)
    {
        $failedReports = $reportBatch->labReports()->where('status', 'failed')->get();

        if ($failedReports->isEmpty()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No failed reports to retry'
                ], 422);
            }
            return back()->withErrors(['error' => 'No failed reports to retry']);
        }

        // Reset failed reports to uploaded status
        $reportBatch->labReports()->where('status', 'failed')->update([
            'status' => 'uploaded',
            'processing_error' => null
        ]);

        // Update batch counts
        $reportBatch->update([
            'failed_reports' => 0,
            'processed_reports' => $reportBatch->processed_reports - $failedReports->count()
        ]);

        // Restart processing
        ProcessLabReportBatch::dispatch($reportBatch);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => $reportBatch,
                'message' => "Retrying {$failedReports->count()} failed reports"
            ]);
        }

        return back()->with('success', "Retrying {$failedReports->count()} failed reports");
    }
}
