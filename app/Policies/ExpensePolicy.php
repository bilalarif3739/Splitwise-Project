<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;

/**
 * Section 14 - an expense may only be read or changed by members of its group.
 *
 * This is exactly the rule the expense service enforced inline; it now lives in
 * one place that belongs to the framework's authorization layer.
 */
final class ExpensePolicy
{
    public function view(User $user, Expense $expense): bool
    {
        return $this->isGroupMember($user, $expense);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->isGroupMember($user, $expense);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->isGroupMember($user, $expense);
    }

    private function isGroupMember(User $user, Expense $expense): bool
    {
        $group = Group::find((string) $expense->group_id);

        return $group !== null && $group->hasMember($user->stringId());
    }
}