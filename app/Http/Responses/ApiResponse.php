<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * The one and only place where API response bodies are shaped.
 *
 * Success: { "success": true,  "message": "...", "data": { ... } }
 * Error:   { "success": false, "message": "...", "errors": { ... } }
 */
final class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data ?? (object) [],
        ], $status);
    }

    public static function error(string $message, mixed $errors = null, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors ?? (object) [],
        ], $status);
    }
}