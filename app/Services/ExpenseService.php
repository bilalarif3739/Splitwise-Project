<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidExpenseSplitException;
use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupBalance;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Expense business rules. Commit 1: creation with the three split types.
 * Update, delete and history are added in commit 2.
 */
final class ExpenseService
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(Group $group, User $creator, array $data): Expense
    {
        $this->assertPayerIsMember($group, (string) $data['paid_by']);
        $this->assertParticipantsAreMembers($group, $data['participants']);

        $amount = round((float) $data['amount'], 2);
        $shares = $this->calculateShares((string) $data['split_type'], $amount, $data['participants']);

        $expense = Expense::create([
            'group_id' => $group->stringId(),
            'description' => $data['description'],
            'amount' => $amount,
            'paid_by' => (string) $data['paid_by'],
            'split_type' => $data['split_type'],
            'participants' => $shares,
            'created_by' => $creator->stringId(),
        ]);

        $this->applyBalanceDelta($group->stringId(), $expense);

        Log::info('Expense created.', [
            'expense_id' => $expense->stringId(),
            'group_id' => $group->stringId(),
        ]);

        return $expense;
    }

    // -----------------------------------------------------------------------
    // Splitting (section 16)
    // -----------------------------------------------------------------------

    /**
     * @param  list<array{user_id: string, amount?: mixed, percentage?: mixed}> $participants
     * @return list<array{user_id: string, amount: float, percentage: float|null}>
     */
    private function calculateShares(string $splitType, float $total, array $participants): array
    {
        return match ($splitType) {
            Expense::SPLIT_EQUAL => $this->calculateEqual($total, $participants),
            Expense::SPLIT_EXACT => $this->calculateExact($total, $participants),
            Expense::SPLIT_PERCENTAGE => $this->calculatePercentage($total, $participants),
            default => throw new InvalidExpenseSplitException(
                'The selected split type is invalid.',
                ['split_type' => ['Valid split types: ' . implode(', ', Expense::SPLIT_TYPES) . '.']],
            ),
        };
    }

    /**
     * total / participants, with the leftover cents handed out one by one so the
     * shares still add up to the total (100 / 3 => 33.34, 33.33, 33.33).
     *
     * @param  list<array{user_id: string}> $participants
     * @return list<array{user_id: string, amount: float, percentage: float|null}>
     */
    private function calculateEqual(float $total, array $participants): array
    {
        if ($participants === []) {
            throw new InvalidExpenseSplitException(
                'An equal split requires at least one participant.',
                ['participants' => ['At least one participant is required.']],
            );
        }

        $count = count($participants);
        $totalCents = (int) round($total * 100);
        $baseCents = intdiv($totalCents, $count);
        $remainder = $totalCents - ($baseCents * $count);

        $shares = [];
        foreach (array_values($participants) as $index => $participant) {
            $shares[] = [
                'user_id' => (string) $participant['user_id'],
                'amount' => ($baseCents + ($index < $remainder ? 1 : 0)) / 100,
                'percentage' => null,
            ];
        }

        return $shares;
    }

    /**
     * The caller states each share; the sum must equal the total (section 16).
     *
     * @param  list<array{user_id: string, amount?: mixed}> $participants
     * @return list<array{user_id: string, amount: float, percentage: float|null}>
     */
    private function calculateExact(float $total, array $participants): array
    {
        if ($participants === []) {
            throw new InvalidExpenseSplitException(
                'An expense requires at least one participant.',
                ['participants' => ['At least one participant is required.']],
            );
        }

        $shares = [];
        $sum = 0.0;

        foreach (array_values($participants) as $index => $participant) {
            $amount = isset($participant['amount']) && is_numeric($participant['amount'])
                ? round((float) $participant['amount'], 2)
                : 0.0;

            if ($amount <= 0) {
                throw new InvalidExpenseSplitException(
                    'Every participant needs an amount greater than zero for an exact split.',
                    ["participants.{$index}.amount" => ['A positive amount is required.']],
                );
            }

            $sum += $amount;
            $shares[] = ['user_id' => (string) $participant['user_id'], 'amount' => $amount, 'percentage' => null];
        }

        if (abs($sum - $total) > 0.01) {
            throw new InvalidExpenseSplitException(
                'The participant amounts must add up to the expense total.',
                [
                    'participants' => [
                        'The shares add up to ' . number_format($sum, 2)
                        . ' but the expense total is ' . number_format($total, 2) . '.',
                    ]
                ],
            );
        }

        return $shares;
    }

    /**
     * The percentages must add up to 100 (section 16). Amounts are derived from
     * the total and any leftover cents go to the largest shares.
     *
     * @param  list<array{user_id: string, percentage?: mixed}> $participants
     * @return list<array{user_id: string, amount: float, percentage: float|null}>
     */
    private function calculatePercentage(float $total, array $participants): array
    {
        if ($participants === []) {
            throw new InvalidExpenseSplitException(
                'A percentage split requires at least one participant.',
                ['participants' => ['At least one participant is required.']],
            );
        }

        $percentages = [];
        $sum = 0.0;

        foreach (array_values($participants) as $index => $participant) {
            $percentage = isset($participant['percentage']) && is_numeric($participant['percentage'])
                ? round((float) $participant['percentage'], 4)
                : 0.0;

            if ($percentage <= 0 || $percentage > 100) {
                throw new InvalidExpenseSplitException(
                    'Each percentage must be greater than zero and at most 100.',
                    ["participants.{$index}.percentage" => ['A percentage between 0 and 100 is required.']],
                );
            }

            $percentages[] = $percentage;
            $sum += $percentage;
        }

        if (abs($sum - 100.0) > 0.01) {
            throw new InvalidExpenseSplitException(
                'The participant percentages must add up to 100%.',
                ['participants' => ['The percentages add up to ' . rtrim(rtrim(number_format($sum, 2, '.', ''), '0'), '.') . '% instead of 100%.']],
            );
        }

        $totalCents = (int) round($total * 100);
        $shares = [];
        $allocated = 0;

        foreach ($percentages as $index => $percentage) {
            $cents = (int) round($totalCents * $percentage / 100);
            $shares[$index] = [
                'user_id' => (string) $participants[$index]['user_id'],
                'amount' => $cents / 100,
                'percentage' => $percentage,
            ];
            $allocated += $cents;
        }

        $leftover = $totalCents - $allocated;

        if ($leftover !== 0) {
            $order = array_keys($shares);
            usort($order, static fn(int $a, int $b): int => $shares[$b]['amount'] <=> $shares[$a]['amount']);

            for ($i = 0; $i < abs($leftover); $i++) {
                $index = $order[$i % count($order)];
                $shares[$index]['amount'] = round($shares[$index]['amount'] + ($leftover > 0 ? 0.01 : -0.01), 2);
            }
        }

        return array_values($shares);
    }

    // -----------------------------------------------------------------------
    // Membership (section 18)
    // -----------------------------------------------------------------------

    private function assertPayerIsMember(Group $group, string $userId): void
    {
        if (!$group->hasMember($userId)) {
            throw ValidationException::withMessages(['paid_by' => ['The payer is not a member of this group.']]);
        }
    }

    /**
     * @param list<array{user_id: string}> $participants
     */
    private function assertParticipantsAreMembers(Group $group, array $participants): void
    {
        foreach (array_values($participants) as $index => $participant) {
            if (!$group->hasMember((string) ($participant['user_id'] ?? ''))) {
                throw ValidationException::withMessages([
                    "participants.{$index}.user_id" => ['Every participant must be a member of this group.'],
                ]);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Balances (section 20)
    // -----------------------------------------------------------------------

    /**
     * Payer + amount, each participant - their share.
     * Positive = should receive money, negative = owes money.
     * $sign = -1 reverses the effect (used by update and delete in commit 2).
     */
    private function applyBalanceDelta(string $groupId, Expense $expense, int $sign = 1): void
    {
        $deltas = [];

        $paidBy = (string) $expense->paid_by;
        $deltas[$paidBy] = ($deltas[$paidBy] ?? 0.0) + ($sign * round((float) $expense->amount, 2));

        foreach ((array) $expense->participants as $participant) {
            $participant = (array) $participant;
            $userId = (string) ($participant['user_id'] ?? '');

            if ($userId !== '') {
                $deltas[$userId] = ($deltas[$userId] ?? 0.0) - ($sign * round((float) ($participant['amount'] ?? 0), 2));
            }
        }

        foreach ($deltas as $userId => $delta) {
            $this->incrementBalance($groupId, (string) $userId, round($delta, 2));
        }
    }

    private function incrementBalance(string $groupId, string $userId, float $delta): void
    {
        $balance = GroupBalance::where('group_id', $groupId)->where('user_id', $userId)->first();

        if ($balance === null) {
            GroupBalance::create(['group_id' => $groupId, 'user_id' => $userId, 'net_balance' => $delta]);

            return;
        }

        $balance->net_balance = round((float) $balance->net_balance + $delta, 2);
        $balance->save();
    }
}