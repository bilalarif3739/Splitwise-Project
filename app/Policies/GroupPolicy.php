<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Group;
use App\Models\User;

/**
 * Sections 11, 13, 14 - who may see a group, and who may change it.
 *
 * Members may read the group and everything inside it; only the owner may
 * change the group itself or its membership.
 */
final class GroupPolicy
{
    public function view(User $user, Group $group): bool
    {
        return $group->hasMember($user->stringId());
    }

    public function update(User $user, Group $group): bool
    {
        return $this->isOwner($user, $group);
    }

    public function delete(User $user, Group $group): bool
    {
        return $this->isOwner($user, $group);
    }

    public function manageMembers(User $user, Group $group): bool
    {
        return $this->isOwner($user, $group);
    }

    private function isOwner(User $user, Group $group): bool
    {
        return (string) $group->owner_id === $user->stringId();
    }
}