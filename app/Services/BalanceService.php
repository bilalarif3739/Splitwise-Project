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
    /**
     * Section 22: who owes whom, plus the same debts reduced to the fewest
     * practical number of payments.
     *
     * @return array{group_id: string, debts: list<array<string, mixed>>, simplified: list<array<string, mixed>>}
     */
    public function debts(Group $group): array
    {
        $groupId = $group->stringId();
        $balances = $this->forGroup($group)['balances'];
        $names = array_column($balances, 'name', 'user_id');

        // Actual debts: every participant owes the payer their share.
        $owed = [];

        foreach (Expense::where('group_id', $groupId)->get() as $expense) {
            $payer = (string) $expense->paid_by;

            foreach ((array) $expense->participants as $participant) {
                $participant = (array) $participant;
                $userId = (string) ($participant['user_id'] ?? '');
                $amount = round((float) ($participant['amount'] ?? 0), 2);

                if ($userId !== '' && $userId !== $payer && $amount > 0) {
                    $owed[$userId][$payer] = round(($owed[$userId][$payer] ?? 0) + $amount, 2);
                }
            }
        }

        // A settlement pays off what its payer owed the receiver.
        foreach (Settlement::where('group_id', $groupId)->get() as $settlement) {
            $from = (string) $settlement->paid_by;
            $to = (string) $settlement->paid_to;

            $owed[$from][$to] = round(($owed[$from][$to] ?? 0) - (float) $settlement->amount, 2);
        }

        // Report each pair once, in the direction that is still owed.
        $debts = [];

        foreach ($owed as $debtor => $creditors) {
            foreach ($creditors as $creditor => $amount) {
                $amount = round($amount - ($owed[$creditor][$debtor] ?? 0), 2);

                if ($amount > 0) {
                    $debts[] = [
                        'from' => ['user_id' => (string) $debtor, 'name' => $names[$debtor] ?? 'Unknown user'],
                        'to' => ['user_id' => (string) $creditor, 'name' => $names[$creditor] ?? 'Unknown user'],
                        'amount' => $amount,
                    ];
                }
            }
        }

        // Simplify: repeatedly match the largest debtor with the largest creditor.
        $debtors = [];
        $creditors = [];

        foreach ($balances as $row) {
            if ($row['balance'] < 0) {
                $debtors[] = ['user_id' => $row['user_id'], 'name' => $row['name'], 'amount' => round(abs($row['balance']), 2)];
            } elseif ($row['balance'] > 0) {
                $creditors[] = ['user_id' => $row['user_id'], 'name' => $row['name'], 'amount' => $row['balance']];
            }
        }

        usort($debtors, static fn(array $a, array $b): int => $b['amount'] <=> $a['amount']);
        usort($creditors, static fn(array $a, array $b): int => $b['amount'] <=> $a['amount']);

        $simplified = [];

        while ($debtors !== [] && $creditors !== []) {
            $pay = round(min($debtors[0]['amount'], $creditors[0]['amount']), 2);

            $simplified[] = [
                'from' => ['user_id' => $debtors[0]['user_id'], 'name' => $debtors[0]['name']],
                'to' => ['user_id' => $creditors[0]['user_id'], 'name' => $creditors[0]['name']],
                'amount' => $pay,
            ];

            $debtors[0]['amount'] = round($debtors[0]['amount'] - $pay, 2);
            $creditors[0]['amount'] = round($creditors[0]['amount'] - $pay, 2);

            if ($debtors[0]['amount'] < 0.01) {
                array_shift($debtors);
            }

            if ($creditors[0]['amount'] < 0.01) {
                array_shift($creditors);
            }
        }

        return ['group_id' => $groupId, 'debts' => $debts, 'simplified' => $simplified];
    }
}