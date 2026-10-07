<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\GroupMemberController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\BalanceController;
use App\Http\Controllers\Api\SettlementController;

Route::get('/ping', function () {
    return response()->json([
        'success' => true,
        'message' => 'API is alive.',
        'data' => [
            'laravel' => app()->version(),
            'driver' => DB::connection()->getDriverName(),
        ],
    ]);
});

// Public
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Authenticated
Route::middleware('api.token')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [ProfileController::class, 'show']);

    Route::get('/groups', [GroupController::class, 'index']);
    Route::post('/groups', [GroupController::class, 'store']);

    // Expenses addressed by id - the service resolves the group and its membership
    Route::get('/expenses/{expense}', [ExpenseController::class, 'show']);
    Route::match(['put', 'patch'], '/expenses/{expense}', [ExpenseController::class, 'update']);
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);

    // Members only
    Route::middleware('group.member')->group(function () {
        Route::get('/groups/{group}', [GroupController::class, 'show']);
        Route::get('/groups/{group}/members', [GroupMemberController::class, 'index']);
        // Balances - members only (section 14)
        Route::get('/groups/{group}/balances', [BalanceController::class, 'index']);
        Route::get('/groups/{group}/debts', [BalanceController::class, 'debts']);

        Route::get('/groups/{group}/expenses', [ExpenseController::class, 'index']);
        Route::post('/groups/{group}/expenses', [ExpenseController::class, 'store']);

        // Settlements - members only (sections 23, 24)
        Route::get('/groups/{group}/settlements', [SettlementController::class, 'index']);
        Route::post('/groups/{group}/settlements', [SettlementController::class, 'store']);
    });

    // Owner only
    Route::middleware('group.owner')->group(function () {
        Route::match(['put', 'patch'], '/groups/{group}', [GroupController::class, 'update']);
        Route::delete('/groups/{group}', [GroupController::class, 'destroy']);
        Route::post('/groups/{group}/members', [GroupMemberController::class, 'store']);
        Route::delete('/groups/{group}/members/{user}', [GroupMemberController::class, 'destroy']);
    });
});