<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSettlementRequest;
use App\Http\Resources\SettlementResource;
use App\Http\Responses\ApiResponse;
use App\Models\Group;
use App\Models\Settlement;
use App\Services\SettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SettlementController extends Controller
{
    public function __construct(
        private readonly SettlementService $settlementService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var Group $group */
        $group = $request->attributes->get('group');

        $perPage = min(max($request->integer('per_page', 15), 1), 100);
        $page = max($request->integer('page', 1), 1);

        $result = $this->settlementService->history($group->stringId(), $page, $perPage);

        $items = $result['items']
            ->map(fn(Settlement $settlement): array => (new SettlementResource($settlement))->resolve($request))
            ->values()
            ->all();

        return ApiResponse::success([
            'items' => $items,
            'pagination' => [
                'current_page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'last_page' => $result['last_page'],
            ],
        ], 'Settlements retrieved successfully.');
    }

    public function store(CreateSettlementRequest $request): JsonResponse
    {
        /** @var Group $group */
        $group = $request->attributes->get('group');

        $settlement = $this->settlementService->create($group, $request->user(), $request->validated());

        return ApiResponse::success(
            new SettlementResource($settlement),
            'Settlement created successfully.',
            201,
        );
    }
}