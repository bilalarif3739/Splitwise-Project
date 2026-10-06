<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupBalance;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Group balances (sections 20, 21).
 *
 * Balances are materialised in group_balances for read speed, and this service
 * can always rebuild them from the source data (expenses + settlements), so a
 * partially failed write can never leave permanently wrong balances.
 *
 * Convention: positive = the user should receive money, negative = owes money.
 */
final class BalanceService
{
    /**
     * Net balance of every member of the group, highest first so creditors
     * appear before debtors.
     *
     * @return array{group_id: string, balances: list<array{user_id: string, name: string, balance: float}>}
     */
    public function forGroup(Group $group): array
    {
        $groupId = $group->stringId();
        $memberIds = array_values((array) $group->member_ids);

        $rows = GroupBalance::where('group_id', $groupId)->get()->keyBy(
            static fn(GroupBalance $row): string => (string) $row->user_id,
        );
        $users = User::whereIn('_id', $memberIds)->get()->keyBy(
            static fn(User $user): string => $user->stringId(),
        );

        $balances = [];

        foreach ($memberIds as $memberId) {
            $row = $rows->get((string) $memberId);
            $user = $users->get((string) $memberId);

            $balances[] = [
                'user_id' => (string) $memberId,
                'name' => $user?->name ?? 'Unknown user',
                'balance' => round((float) ($row->net_balance ?? 0), 2),
            ];
        }

        usort($balances, static fn(array $a, array $b): int => $b['balance'] <=> $a['balance']);

        return ['group_id' => $groupId, 'balances' => $balances];
    }

    /**
     * Rebuild group_balances from the source data.
     *
     * Expenses:  payer + full amount, every participant - their own share.
     * Settlements: payer + amount (he has paid off that much of what he owed),
     *              receiver - amount (he is no longer owed that much).
     */
    public function recompute(Group $group): void
    {
        $groupId = $group->stringId();
        $totals = [];

        foreach (Expense::where('group_id', $groupId)->get() as $expense) {
            $payer = (string) $expense->paid_by;
            $totals[$payer] = ($totals[$payer] ?? 0.0) + round((float) $expense->amount, 2);

            foreach ((array) $expense->participants as $participant) {
                $participant = (array) $participant;
                $userId = (string) ($participant['user_id'] ?? '');

                if ($userId === '') {
                    continue;
                }

                $totals[$userId] = ($totals[$userId] ?? 0.0)
                    - round((float) ($participant['amount'] ?? 0), 2);
            }
        }

        foreach (Settlement::where('group_id', $groupId)->get() as $settlement) {
            $from = (string) $settlement->paid_by;
            $to = (string) $settlement->paid_to;
            $amount = round((float) $settlement->amount, 2);

            $totals[$from] = ($totals[$from] ?? 0.0) + $amount;
            $totals[$to] = ($totals[$to] ?? 0.0) - $amount;
        }

        // Replace the stored rows with the freshly computed ones, so rows for
        // users who left the group cannot linger.
        GroupBalance::where('group_id', $groupId)->delete();

        foreach ($totals as $userId => $net) {
            GroupBalance::create([
                'group_id' => $groupId,
                'user_id' => (string) $userId,
                'net_balance' => round($net, 2),
            ]);
        }

        Log::info('Group balances recomputed.', ['group_id' => $groupId]);
    }
}