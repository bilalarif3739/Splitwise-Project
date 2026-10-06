<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\UnauthorizedGroupAccessException;
use App\Models\Group;
use App\Services\GroupService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the group owner may manage the group itself or its membership
 * (section 14: "the group owner/admin should have additional permissions").
 */
final class EnsureGroupOwner
{
    public function __construct(
        private readonly GroupService $groupService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $group = $this->groupService->findOrFail((string) $request->route('group'));

        $userId = $request->user()->stringId();

        if (!$group->isOwnedBy($userId)) {
            Log::warning('Group access denied.', [
                'reason' => 'not_the_owner',
                'group_id' => $group->stringId(),
                'user_id' => $userId,
            ]);

            throw new UnauthorizedGroupAccessException(
                'Only the group owner can perform this action.'
            );
        }

        $request->attributes->set('group', $group);

        return $next($request);
    }
}