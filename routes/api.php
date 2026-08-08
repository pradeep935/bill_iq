<?php

use App\Http\Controllers\HsnSacController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function ($request) {
    return $request->user();
});

Route::middleware('auth:sanctum')->get('/hsn-sac/search', [HsnSacController::class, 'search']);
