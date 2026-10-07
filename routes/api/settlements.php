<?php

declare(strict_types=1);

use App\Http\Controllers\Api\SettlementAttachmentController;
use App\Http\Controllers\Api\SettlementController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.token')->group(function () {
    // Settlements - members only (sections 23, 24)
    Route::middleware('group.member')->group(function () {
        Route::get('/groups/{group}/settlements', [SettlementController::class, 'index']);
        Route::post('/groups/{group}/settlements', [SettlementController::class, 'store']);
    });

    // Attachments - authenticated; the attachment service resolves the settlement
    // and checks the membership of its group, like the /expenses/{expense} routes.
    Route::post('/settlements/{settlement}/attachment', [SettlementAttachmentController::class, 'store']);
    Route::get('/settlements/{settlement}/attachment', [SettlementAttachmentController::class, 'show']);
    Route::delete('/settlements/{settlement}/attachment', [SettlementAttachmentController::class, 'destroy']);
});