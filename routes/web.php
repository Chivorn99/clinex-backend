<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LabReportCorrectionController;

Route::get('/', function () {
    return view('welcome');
});

// Lab report correction routes
Route::get('/lab-reports/{labReport}/correct', [LabReportCorrectionController::class, 'show'])
    ->name('lab-reports.correct');
Route::post('/lab-reports/{labReport}/corrections', [LabReportCorrectionController::class, 'saveCorrections'])
    ->name('lab-reports.save-corrections');
Route::get('/lab-reports/{labReport}/pdf', [LabReportCorrectionController::class, 'showPdf'])
    ->name('lab-reports.pdf');