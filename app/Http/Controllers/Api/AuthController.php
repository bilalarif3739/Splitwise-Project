<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\Token;
use App\Services\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $authService,
    ) {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        [$user, $token] = $this->authService->register(
            $data['name'],
            $data['email'],
            $data['password'],
        );

        return ApiResponse::success([
            'user' => new UserResource($user),
            'token' => $token,
        ], 'Registration successful.', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        [$user, $token] = $this->authService->login($data['email'], $data['password']);

        return ApiResponse::success([
            'user' => new UserResource($user),
            'token' => $token,
        ], 'Login successful.');
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var Token $token */
        $token = $request->attributes->get('api_token');

        $this->authService->logout($token);

        return ApiResponse::success(null, 'Logged out successfully.');
    }
}