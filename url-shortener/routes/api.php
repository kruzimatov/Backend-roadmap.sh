<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UrlController;

// Register login
Route::middleware('throttle:register')->post('/auth/register', [AuthController::class, 'register']);

Route::middleware('throttle:login')->post('/auth/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'throttle:create-url'])
    ->post('/urls', [UrlController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/urls', [UrlController::class, 'index']);
    Route::get('/urls/{code}', [UrlController::class, 'show']);
    Route::put('/urls/{code}', [UrlController::class, 'update']);
    Route::delete('/urls/{code}', [UrlController::class, 'destroy']);
    Route::get('/urls/{code}/stats', [UrlController::class, 'stats']);

    Route::post('/auth/logout', [AuthController::class, 'logout']);
});
