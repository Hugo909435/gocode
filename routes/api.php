<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Routes publiques (auth)
Route::post('/login', [\App\Http\Controllers\Auth\LoginController::class, 'store']);
Route::post('/logout', [\App\Http\Controllers\Auth\LoginController::class, 'destroy'])
    ->middleware('auth:sanctum');

// Routes protégées
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
});
