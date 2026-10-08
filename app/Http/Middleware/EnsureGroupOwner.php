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
 * Only the group owner may manage the group itself or its membership
 * (section 14: "the group owner/admin should have additional permissions").
 *
 * The route passes which owner ability is required - "group.owner:update",
 * "group.owner:delete" or "group.owner:manageMembers" - and the decision is
 * made by the matching GroupPolicy method. The parameter has no default on
 * purpose: a route that forgot it should fail loudly rather than fall back to
 * a weaker check.
 */
final class EnsureGroupOwner
{
    public function __construct(
        private readonly GroupService $groupService,
    ) {
    }

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $group = $this->groupService->findOrFail((string) $request->route('group'));
        $user = $request->user();

        if (!Gate::forUser($user)->allows($ability, $group)) {
            Log::warning('Group access denied.', [
                'reason' => 'not_the_owner',
                'ability' => $ability,
                'group_id' => $group->stringId(),
                'user_id' => $user->stringId(),
            ]);

            throw new UnauthorizedGroupAccessException(
                'Only the group owner can perform this action.'
            );
        }

        $request->attributes->set('group', $group);

        return $next($request);
    }
}