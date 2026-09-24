<?php

use App\Http\Controllers\UrlController;

Route::post('/urls', [UrlController::class, 'store']);
Route::get('/urls', [UrlController::class, 'index']);
Route::get('/urls/{code}', [UrlController::class, 'show']);
Route::put('/urls/{code}', [UrlController::class, 'update']);
Route::delete('/urls/{code}', [UrlController::class, 'destroy']);
Route::get('/urls/{code}/stats', [UrlController::class, 'stats']);
