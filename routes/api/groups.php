<?php

declare(strict_types=1);

use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\GroupMemberController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.token')->group(function () {
    Route::get('/groups', [GroupController::class, 'index']);
    Route::post('/groups', [GroupController::class, 'store']);

    // Members only (section 14)
    Route::middleware('group.member')->group(function () {
        Route::get('/groups/{group}', [GroupController::class, 'show']);
        Route::get('/groups/{group}/members', [GroupMemberController::class, 'index']);
    });

    // Owner only (section 11)
    Route::middleware('group.owner')->group(function () {
        Route::match(['put', 'patch'], '/groups/{group}', [GroupController::class, 'update']);
        Route::delete('/groups/{group}', [GroupController::class, 'destroy']);
        Route::post('/groups/{group}/members', [GroupMemberController::class, 'store']);
        Route::delete('/groups/{group}/members/{user}', [GroupMemberController::class, 'destroy']);
    });
});