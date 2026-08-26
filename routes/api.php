<?php

use App\Http\Controllers\Api\Admin\AgentController as AdminAgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\RouterStatsController;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/router-stats', [RouterStatsController::class, 'index']);
    Route::get('/router-stats/traffic', [RouterStatsController::class, 'traffic']);
    Route::get('/logs', [LogController::class, 'index']);
    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings', [SettingsController::class, 'update']);

    Route::get('/clients/online', [ClientController::class, 'online']);
    Route::get('/clients/{client}/status', [ClientController::class, 'status']);
    Route::get('/clients/{client}/logs', [ClientController::class, 'logs']);
    Route::post('/clients/{client}/renew', [ClientController::class, 'renew']);
    Route::post('/clients/{client}/trial', [ClientController::class, 'trial']);
    Route::post('/clients/{client}/debt/pay', [ClientController::class, 'payDebt']);
    Route::post('/clients/{client}/debt/add', [ClientController::class, 'addDebt']);
    Route::apiResource('clients', ClientController::class);

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/agents', [AdminAgentController::class, 'index']);
        Route::get('/agents/online', [AdminAgentController::class, 'online']);
        Route::get('/agents/logs', [AdminAgentController::class, 'logs']);
        Route::get('/agents/{agent}', [AdminAgentController::class, 'show']);
        Route::put('/agents/{agent}', [AdminAgentController::class, 'update']);
        Route::delete('/agents/{agent}', [AdminAgentController::class, 'destroy']);
        Route::get('/agents/{agent}/logs', [AdminAgentController::class, 'agentLogs']);
        Route::post('/agents/{agent}/renew', [AdminAgentController::class, 'renew']);
    });
});
