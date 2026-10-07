<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ExpenseController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.token')->group(function () {
    // Expenses addressed by id - the service resolves the group and its membership
    Route::get('/expenses/{expense}', [ExpenseController::class, 'show']);
    Route::match(['put', 'patch'], '/expenses/{expense}', [ExpenseController::class, 'update']);
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);

    // Members only
    Route::middleware('group.member')->group(function () {
        Route::get('/groups/{group}/expenses', [ExpenseController::class, 'index']);
        Route::post('/groups/{group}/expenses', [ExpenseController::class, 'store']);
    });
});