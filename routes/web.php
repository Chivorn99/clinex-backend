<?php

use App\Http\Controllers\LabReportCorrectionController;
use App\Http\Controllers\ProfileController;
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
    
    // 🔥 ADD BACK: Lab report correction routes (web interface)
    Route::get('/lab-reports/{labReport}/correct', [LabReportCorrectionController::class, 'show'])
        ->name('lab-reports.correct');
    Route::post('/lab-reports/{labReport}/corrections', [LabReportCorrectionController::class, 'saveCorrections'])
        ->name('lab-reports.save-corrections');
    Route::get('/lab-reports/{labReport}/pdf', [LabReportCorrectionController::class, 'showPdf'])
        ->name('lab-reports.pdf');
});

require __DIR__.'/auth.php';
