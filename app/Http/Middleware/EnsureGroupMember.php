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
 * Resolves the group from the route and refuses anyone who is not a member.
 *
 * The resolved group is attached to the request, so the controller never
 * queries it again and can never accidentally act on a different group.
 */
final class EnsureGroupMember
{
    public function __construct(
        private readonly GroupService $groupService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $group = $this->groupService->findOrFail((string) $request->route('group'));

        $userId = $request->user()->stringId();

        if (!$group->hasMember($userId)) {
            Log::warning('Group access denied.', [
                'reason' => 'not_a_member',
                'group_id' => $group->stringId(),
                'user_id' => $userId,
            ]);

            throw new UnauthorizedGroupAccessException();
        }

        $request->attributes->set('group', $group);

        return $next($request);
    }
}