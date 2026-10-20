<?php

use App\Http\Controllers\TallyIntegrationController;
use App\Http\Controllers\HsnSacController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function ($request) {
    return $request->user();
});
Route::middleware('auth:sanctum')->get('/hsn-sac/search', [HsnSacController::class, 'search']);
Route::post('/tally/connector/ingest', [TallyIntegrationController::class, 'connectorIngest'])->middleware('throttle:60,1')->name('tally.connector.ingest');
Route::post('/tally/connector/pair', [TallyIntegrationController::class, 'connectorPair'])->middleware('throttle:10,1')->name('tally.connector.pair');
