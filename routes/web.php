<?php

use App\Http\Controllers\DownloadLandingController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/downloads/{grantPublicId}', DownloadLandingController::class)
    ->where('grantPublicId', '[A-Za-z0-9-]+')
    ->name('downloads.exchange');
