<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BalanceController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.token')->group(function () {
    // Balances - members only (sections 14, 21, 22)
    Route::middleware('group.member')->group(function () {
        Route::get('/groups/{group}/balances', [BalanceController::class, 'index']);
        Route::get('/groups/{group}/debts', [BalanceController::class, 'debts']);
    });
});