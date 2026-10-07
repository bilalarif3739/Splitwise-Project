<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Liveness check - infrastructure route, not part of any module.
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

require __DIR__ . '/api/auth.php';
require __DIR__ . '/api/groups.php';
require __DIR__ . '/api/expenses.php';
require __DIR__ . '/api/balances.php';
require __DIR__ . '/api/settlements.php';