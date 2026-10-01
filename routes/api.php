<?php

use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    return response()->json([
        'success' => true,
        'message' => 'API is alive.',
        'data'    => [
            'laravel' => app()->version(),
            'driver'  => DB::connection()->getDriverName(),
        ],
    ]);
});