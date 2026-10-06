<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateGroupRequest;
use App\Http\Requests\UpdateGroupRequest;
use App\Http\Resources\GroupResource;
use App\Http\Responses\ApiResponse;
use App\Models\Group;
use App\Services\GroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GroupController extends Controller
{
    public function __construct(
        private readonly GroupService $groupService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);
        $page = max($request->integer('page', 1), 1);

        $result = $this->groupService->listForUser($request->user()->stringId(), $perPage, $page);

        return ApiResponse::success([
            'items' => GroupResource::collection($result['items']),
            'pagination' => [
                'current_page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'last_page' => $result['last_page'],
            ],
        ], 'Groups retrieved successfully.');
    }

    public function store(CreateGroupRequest $request): JsonResponse
    {
        $data = $request->validated();

        $group = $this->groupService->create(
            $request->user()->stringId(),
            $data['name'],
            $data['description'] ?? null,
        );

        return ApiResponse::success(
            new GroupResource($group),
            'Group created successfully.',
            201,
        );
    }

    public function show(Request $request): JsonResponse
    {
        /** @var Group $group */
        $group = $request->attributes->get('group');

        return ApiResponse::success(
            new GroupResource($group),
            'Group retrieved successfully.',
        );
    }

    public function update(UpdateGroupRequest $request): JsonResponse
    {
        /** @var Group $group */
        $group = $request->attributes->get('group');

        $group = $this->groupService->update($group, $request->validated());

        return ApiResponse::success(
            new GroupResource($group),
            'Group updated successfully.',
        );
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var Group $group */
        $group = $request->attributes->get('group');

        $this->groupService->delete($group);

        return ApiResponse::success(null, 'Group deleted successfully.');
    }
}