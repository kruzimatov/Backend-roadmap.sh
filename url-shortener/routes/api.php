<?php

use App\Http\Controllers\UrlController;

Route::post('/urls', [UrlController::class, 'store']);
