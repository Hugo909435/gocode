<?php

use App\Http\Controllers\Api\GitController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\SessionStreamController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

/*
 * Le SPA doit d'abord appeler GET /sanctum/csrf-cookie (géré automatiquement
 * par Sanctum) pour obtenir le cookie XSRF-TOKEN avant toute requête POST.
 */

// Routes publiques
Route::post('/login', [LoginController::class, 'store']);

// Routes protégées — le cookie de session sert aussi pour les connexions SSE
// (EventSource envoie automatiquement les cookies en same-origin, ou avec
// withCredentials: true en cross-origin dev Vite → Laravel)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy']);
    Route::get('/me', [LoginController::class, 'me']);

    // Sessions par projet
    Route::get('/projects/{project}/sessions', [SessionController::class, 'index']);
    Route::post('/projects/{project}/sessions', [SessionController::class, 'store']);

    // Git — lecture seule, scopé au path du projet
    Route::prefix('projects/{project}/git')->group(function () {
        Route::get('/status', [GitController::class, 'status']);
        Route::get('/diff', [GitController::class, 'diff']);
        Route::get('/branch', [GitController::class, 'branch']);
        Route::get('/log', [GitController::class, 'log']);
    });

    // Cycle de vie d'une session
    Route::get('/sessions/{session}/stream', SessionStreamController::class);
    Route::get('/sessions/{session}', [SessionController::class, 'show']);
    Route::post('/sessions/{session}/instruction', [SessionController::class, 'sendInstruction']);
    Route::post('/sessions/{session}/confirm', [SessionController::class, 'confirm']);
    Route::post('/sessions/{session}/stop', [SessionController::class, 'stop']);
    Route::patch('/sessions/{session}', [SessionController::class, 'update']);
});
