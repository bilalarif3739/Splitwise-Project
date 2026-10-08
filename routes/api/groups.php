<?php

declare(strict_types=1);

use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\GroupMemberController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.token')->group(function () {
    Route::get('/groups', [GroupController::class, 'index']);
    Route::post('/groups', [GroupController::class, 'store']);

    // Members only (section 14) - GroupPolicy@view
    Route::middleware('group.member')->group(function () {
        Route::get('/groups/{group}', [GroupController::class, 'show']);
        Route::get('/groups/{group}/members', [GroupMemberController::class, 'index']);
    });

    // Owner only (section 14, owner-specific permissions) - GroupPolicy@update /
    // @delete / @manageMembers, chosen by the ability passed after the colon.
    Route::match(['put', 'patch'], '/groups/{group}', [GroupController::class, 'update'])
        ->middleware('group.owner:update');
    Route::delete('/groups/{group}', [GroupController::class, 'destroy'])
        ->middleware('group.owner:delete');
    Route::post('/groups/{group}/members', [GroupMemberController::class, 'store'])
        ->middleware('group.owner:manageMembers');
    Route::delete('/groups/{group}/members/{user}', [GroupMemberController::class, 'destroy'])
        ->middleware('group.owner:manageMembers');
});