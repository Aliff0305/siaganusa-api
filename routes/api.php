<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\DisasterController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Endpoint Bencana (BMKG, SiPongi, InaTEWS)
Route::prefix('disasters')->group(function () {
    Route::get('/earthquakes/latest', [DisasterController::class, 'latestEarthquake']);
    Route::get('/earthquakes', [DisasterController::class, 'earthquakes']);
    Route::get('/hotspots', [DisasterController::class, 'hotspots']);
    Route::get('/tsunami', [DisasterController::class, 'tsunami']);
});

// Kontak Darurat & Panduan Mitigasi
Route::get('/emergency-contacts', [ContentController::class, 'emergencyContacts']);
Route::get('/mitigation-guides', [ContentController::class, 'mitigationGuides']);

// Endpoint Terproteksi Token Sanctum
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);
});