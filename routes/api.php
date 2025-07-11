<?php

use App\Http\Controllers\LabReportController;
use App\Http\Controllers\LabReportCorrectionController;
use App\Http\Controllers\TemplateController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Lab report API routes
Route::middleware('auth:sanctum')->group(function () {
    // Lab report upload and processing
    Route::post('/lab-reports/upload', [LabReportController::class, 'upload'])->name('api.lab-reports.upload');
    Route::get('/lab-reports', [LabReportController::class, 'index'])->name('api.lab-reports.index');
    Route::get('/lab-reports/{labReport}', [LabReportController::class, 'show'])->name('api.lab-reports.show');

    // Lab report corrections
    Route::get('/lab-reports/{labReport}/corrections', [LabReportCorrectionController::class, 'getCorrections'])
        ->name('api.lab-reports.corrections');
    Route::post('/lab-reports/{labReport}/corrections', [LabReportCorrectionController::class, 'saveCorrections'])
        ->name('api.lab-reports.save-corrections');

    // Template selection for upload (all authenticated users)
    Route::get('/templates/upload', [TemplateController::class, 'getTemplatesForUpload'])->name('api.templates.upload');

    // Admin-only template API routes
    Route::middleware('can:admin')->group(function () {
        Route::get('/templates', [TemplateController::class, 'apiIndex'])->name('api.templates.index');
        Route::post('/templates/analyze', [TemplateController::class, 'analyze'])->name('api.templates.analyze');
        Route::post('/templates/create-from-pdf', [TemplateController::class, 'processPdfForTemplate'])->name('api.templates.create-from-pdf');
        Route::get('/templates/custom-categories', [TemplateController::class, 'getCustomCategories'])->name('api.templates.custom-categories');
    });
});