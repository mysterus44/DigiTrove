<?php

use App\Http\Controllers\DownloadFileController;
use App\Http\Controllers\DownloadLandingController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/downloads/{grantPublicId}', DownloadLandingController::class)
    ->where('grantPublicId', '[A-Za-z0-9-]+')
    ->name('downloads.exchange');

Route::match(['GET', 'HEAD'], '/downloads/{grantPublicId}/file', DownloadFileController::class)
    ->where('grantPublicId', '[A-Za-z0-9-]+')
    ->middleware('throttle:download-file')
    ->name('downloads.file');
