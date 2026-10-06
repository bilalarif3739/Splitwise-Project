<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Group;
use App\Services\BalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BalanceController extends Controller
{
    public function __construct(
        private readonly BalanceService $balanceService,
    ) {
    }

    /** Section 21: GET /api/groups/{group}/balances */
    public function index(Request $request): JsonResponse
    {
        /** @var Group $group */
        $group = $request->attributes->get('group');

        return ApiResponse::success(
            $this->balanceService->forGroup($group),
            'Group balances retrieved successfully.',
        );
    }
}