<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function ($request) {
    return $request->user();
});
