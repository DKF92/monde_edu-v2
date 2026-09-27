<?php

use App\Http\Controllers\Api\AnneeScolaireController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EleveController;
use App\Http\Controllers\Api\EtablissementController;
use App\Http\Controllers\Api\PeriodeController;
use Illuminate\Support\Facades\Route;

Route::get('/etablissements', [EtablissementController::class, 'index']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'etablissement', 'contexte-scolaire'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/annees-scolaires', [AnneeScolaireController::class, 'index']);
    Route::get('/periodes', [PeriodeController::class, 'index']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::get('/eleves', [EleveController::class, 'index']);
    Route::get('/eleves/{eleve}', [EleveController::class, 'show']);
});
