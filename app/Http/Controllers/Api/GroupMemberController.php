<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddGroupMemberRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\Group;
use App\Models\User;
use App\Services\GroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GroupMemberController extends Controller
{
    public function __construct(
        private readonly GroupService $groupService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var Group $group */
        $group = $request->attributes->get('group');

        $members = $this->groupService->members($group)->map(
            fn(User $user): array => [
                'id' => $user->stringId(),
                'name' => $user->name,
                'email' => $user->email,
                'role' => $group->isOwnedBy($user->stringId()) ? 'owner' : 'member',
            ],
        )->values();

        return ApiResponse::success($members, 'Group members retrieved successfully.');
    }

    public function store(AddGroupMemberRequest $request): JsonResponse
    {
        /** @var Group $group */
        $group = $request->attributes->get('group');

        $user = $this->groupService->addMember($group, $request->validated()['user_id']);

        return ApiResponse::success(
            new UserResource($user),
            'Group member added successfully.',
            201,
        );
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var Group $group */
        $group = $request->attributes->get('group');

        $this->groupService->removeMember($group, (string) $request->route('user'));

        return ApiResponse::success(null, 'Group member removed successfully.');
    }
}