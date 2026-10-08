<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;

/**
 * Sections 14, 27, 28 - who may read an expense, and who may change it.
 *
 * Members of the expense's group may read it; only its payer or the group owner
 * may update or delete it.
 */
final class ExpensePolicy
{
    public function view(User $user, Expense $expense): bool
    {
        return $this->groupOf($expense)?->hasMember($user->stringId()) ?? false;
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->mayModify($user, $expense);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->mayModify($user, $expense);
    }

    private function mayModify(User $user, Expense $expense): bool
    {
        $group = $this->groupOf($expense);

        if ($group === null || !$group->hasMember($user->stringId())) {
            return false;
        }

        // The payer and the group owner may change or remove an expense.
        return (string) $expense->paid_by === $user->stringId()
            || $group->isOwnedBy($user->stringId());
    }

    private function groupOf(Expense $expense): ?Group
    {
        return Group::find((string) $expense->group_id);
    }
}