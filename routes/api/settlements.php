<?php

declare(strict_types=1);

use App\Http\Controllers\Api\SettlementController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.token')->group(function () {
    // Settlements - members only (sections 23, 24)
    Route::middleware('group.member')->group(function () {
        Route::get('/groups/{group}/settlements', [SettlementController::class, 'index']);
        Route::post('/groups/{group}/settlements', [SettlementController::class, 'store']);
    });
});