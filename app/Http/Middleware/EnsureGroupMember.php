<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\UnauthorizedGroupAccessException;
use App\Services\GroupService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the group from the route and refuses anyone the policy does not let
 * view it (section 14).
 *
 * The rule itself lives in GroupPolicy; this middleware only finds the group,
 * asks the policy, and attaches the resolved group to the request so the
 * controller never queries it again.
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
        $user = $request->user();

        if (!Gate::forUser($user)->allows('view', $group)) {
            Log::warning('Group access denied.', [
                'reason' => 'not_a_member',
                'group_id' => $group->stringId(),
                'user_id' => $user->stringId(),
            ]);

            throw new UnauthorizedGroupAccessException();
        }

        $request->attributes->set('group', $group);

        return $next($request);
    }
}