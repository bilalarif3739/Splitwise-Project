<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\GroupMemberController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ExpenseController;

/*
|--------------------------------------------------------------------------
| Health check
|--------------------------------------------------------------------------
*/
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

/*
|--------------------------------------------------------------------------
| Public routes - no token required
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Authenticated routes - valid Bearer token required
|--------------------------------------------------------------------------
*/
Route::middleware('api.token')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [ProfileController::class, 'show']);

    // -------------------------------------------------------------------
    // Groups - any authenticated user
    // -------------------------------------------------------------------
    Route::get('/groups', [GroupController::class, 'index']);
    Route::post('/groups', [GroupController::class, 'store']);

    // -------------------------------------------------------------------
    // Groups - members only
    // (section 14: a non-member must not reach a group's private data)
    // -------------------------------------------------------------------
    Route::middleware('group.member')->group(function () {
        Route::get('/groups/{group}', [GroupController::class, 'show']);
        Route::get('/groups/{group}/members', [GroupMemberController::class, 'index']);
        // Expenses - any member may create and list them (sections 26, 18)
        Route::get('/groups/{group}/expenses', [ExpenseController::class, 'index']);
        Route::post('/groups/{group}/expenses', [ExpenseController::class, 'store']);
    });

    // -------------------------------------------------------------------
    // Groups - owner only
    // (section 14: the group owner/admin has additional permissions)
    // -------------------------------------------------------------------
    Route::middleware('group.owner')->group(function () {
        Route::match(['put', 'patch'], '/groups/{group}', [GroupController::class, 'update']);
        Route::delete('/groups/{group}', [GroupController::class, 'destroy']);
        Route::post('/groups/{group}/members', [GroupMemberController::class, 'store']);
        Route::delete('/groups/{group}/members/{user}', [GroupMemberController::class, 'destroy']);
    });
});