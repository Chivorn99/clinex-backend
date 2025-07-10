<?php

use App\Http\Controllers\LabReportCorrectionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Lab report upload and processing routes
    Route::get('/upload', function () {
        return view('lab-reports.upload');
    })->name('lab-reports.upload');

    // Lab report correction routes (web interface)
    Route::get('/lab-reports/{labReport}/correct', [LabReportCorrectionController::class, 'show'])
        ->name('lab-reports.correct');
    Route::post('/lab-reports/{labReport}/corrections', [LabReportCorrectionController::class, 'saveCorrections'])
        ->name('lab-reports.save-corrections');
    Route::get('/lab-reports/{labReport}/pdf', [LabReportCorrectionController::class, 'showPdf'])
        ->name('lab-reports.pdf');
    
    // Template management routes (admin only)
    Route::middleware('can:admin')->group(function () {
        Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::get('/templates/create', [TemplateController::class, 'create'])->name('templates.create');
        Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
        Route::get('/templates/{template}', [TemplateController::class, 'show'])->name('templates.show');
        Route::get('/templates/{template}/edit', [TemplateController::class, 'edit'])->name('templates.edit');
        Route::patch('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
        Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
        
        // Template creation from PDF
        Route::post('/templates/create-from-pdf', [TemplateController::class, 'processPdfForTemplate'])->name('templates.create-from-pdf');
        Route::get('/templates/custom-categories', [TemplateController::class, 'getCustomCategories'])->name('templates.custom-categories');
        Route::post('/templates/extract-pdf', [TemplateController::class, 'extractFromPdf'])->name('templates.extract-pdf');
    });
});

require __DIR__ . '/auth.php';
