<?php

use Illuminate\Support\Facades\Route;
use Lacv\PipoScanner\Http\Controllers\ScannerController;

Route::middleware(['web', 'auth'])->group(function () {
    Route::post(
        config('pipo-scanner.upload_route', '/pipo-scanner/upload'),
        [ScannerController::class, 'upload']
    )->name('pipo-scanner.upload');
});
