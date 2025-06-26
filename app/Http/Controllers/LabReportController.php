<?php

namespace App\Http\Controllers;

use App\Models\LabReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Jobs\ProcessLabReport;


class LabReportController extends Controller
{
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
            'files.*' => 'required|file|mimes:pdf|max:10240'
        ]);

        try {
            $uploadedFiles = [];

            foreach ($request->file('files') as $file) {
                $filename = time() . '_' . uniqid() . '_' . $file->getClientOriginalName();
                $path = $file->storeAs('lab-reports', $filename, 'private');

                $labReport = LabReport::create([
                    'original_filename' => $file->getClientOriginalName(),
                    'storage_path' => $path,
                    'status' => 'pending'
                ]);

                ProcessLabReport::dispatch($labReport);

                $uploadedFiles[] = [
                    'id' => $labReport->id,
                    'filename' => $labReport->original_filename,
                    'status' => $labReport->status
                ];
            }

            return response()->json([
                'success' => true,
                'message' => count($uploadedFiles) . ' PDF files uploaded successfully',
                'data' => $uploadedFiles
            ], 201);

        } catch (\Exception $e) {
            Log::error('Batch upload failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Upload failed'
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
