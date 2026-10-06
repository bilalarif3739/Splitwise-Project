<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ExpenseNotFoundException;
use App\Exceptions\GroupNotFoundException;
use App\Exceptions\InvalidExpenseSplitException;
use App\Exceptions\UnauthorizedGroupAccessException;
use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupBalance;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * All expense business rules: the three split types, history with filtering,
 * update and delete - each keeping group_balances consistent.
 */
final class ExpenseService
{
    /** Tolerance when comparing money values (one cent). */
    private const MONEY_EPSILON = 0.01;

    // -----------------------------------------------------------------------
    // Create / update / delete
    // -----------------------------------------------------------------------

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
            'group_id'     => $group->stringId(),
            'description'  => $data['description'],
            'amount'       => $amount,
            'paid_by'      => (string) $data['paid_by'],
            'split_type'   => $data['split_type'],
            'participants' => $shares,
            'created_by'   => $creator->stringId(),
        ]);

        $this->applyBalanceDelta($group->stringId(), $expense);

        Log::info('Expense created.', ['expense_id' => $expense->stringId(), 'group_id' => $group->stringId()]);

        return $expense;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(Expense $expense, Group $group, array $data, User $actor): Expense
    {
        $this->assertCanModify($expense, $group, $actor);

        $groupId      = $group->stringId();
        $amount       = isset($data['amount']) ? round((float) $data['amount'], 2) : (float) $expense->amount;
        $splitType    = (string) ($data['split_type'] ?? $expense->split_type);
        $paidBy       = (string) ($data['paid_by'] ?? $expense->paid_by);
        $participants = $data['participants'] ?? $this->participantsAsInput($expense);

        $this->assertPayerIsMember($group, $paidBy);
        $this->assertParticipantsAreMembers($group, $participants);

        // Section 28: recalculate the shares before anything is written.
        $shares = $this->calculateShares($splitType, $amount, $participants);

        // Reverse the old effect first, so the balance ends up reflecting only
        // the new state of the expense.
        $this->applyBalanceDelta($groupId, $expense, -1);

        $expense->fill([
            'description'  => $data['description'] ?? $expense->description,
            'amount'       => $amount,
            'paid_by'      => $paidBy,
            'split_type'   => $splitType,
            'participants' => $shares,
        ])->save();

        $this->applyBalanceDelta($groupId, $expense);

        Log::info('Expense updated.', ['expense_id' => $expense->stringId(), 'group_id' => $groupId]);

        return $expense;
    }

    public function delete(Expense $expense, Group $group, User $actor): void
    {
        $this->assertCanModify($expense, $group, $actor);

        $groupId = $group->stringId();

        // Section 29: deleting must affect balances correctly and leave no stale data.
        $this->applyBalanceDelta($groupId, $expense, -1);

        $expense->delete();

        Log::info('Expense deleted.', ['expense_id' => $expense->stringId(), 'group_id' => $groupId]);
    }

    // -----------------------------------------------------------------------
    // Reading (sections 26, 27)
    // -----------------------------------------------------------------------

    /**
     * Paginated, filterable expense history for a group.
     *
     * @param  array<string, mixed> $filters
     * @return array{items: Collection<int, Expense>, total: int, page: int, per_page: int, last_page: int}
     */
    public function history(string $groupId, array $filters, int $page, int $perPage): array
    {
        $build = fn () => $this->applyFilters(Expense::where('group_id', $groupId), $filters);

        $total = $build()->count();

        $items = $build()
            ->orderBy((string) ($filters['sort_by'] ?? 'created_at'), (string) ($filters['sort_dir'] ?? 'desc'))
            ->skip(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Resolve an expense addressed directly by id (section 27) and confirm the
     * caller belongs to its group, so a non-member gets 403 and a stranger's
     * expense is never readable.
     *
     * @return array{0: Expense, 1: Group}
     */
    public function resolveForUser(string $expenseId, User $user): array
    {
        $expense = Expense::find($expenseId);

        if ($expense === null) {
            throw new ExpenseNotFoundException();
        }

        $group = Group::find($expense->group_id);

        if ($group === null) {
            throw new GroupNotFoundException('The group for this expense no longer exists.');
        }

        if (! $group->hasMember($user->stringId())) {
            throw new UnauthorizedGroupAccessException();
        }

        return [$expense, $group];
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
            Expense::SPLIT_EQUAL      => $this->calculateEqual($total, $participants),
            Expense::SPLIT_EXACT      => $this->calculateExact($total, $participants),
            Expense::SPLIT_PERCENTAGE => $this->calculatePercentage($total, $participants),
            default                   => throw new InvalidExpenseSplitException(
                'The selected split type is invalid.',
                ['split_type' => ['Valid split types: '.implode(', ', Expense::SPLIT_TYPES).'.']],
            ),
        };
    }

    /**
     * total / participants, leftover cents handed out one by one so the shares
     * still add up to the total (100 / 3 => 33.34, 33.33, 33.33).
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

        $count      = count($participants);
        $totalCents = (int) round($total * 100);
        $baseCents  = intdiv($totalCents, $count);
        $remainder  = $totalCents - ($baseCents * $count);

        $shares = [];
        foreach (array_values($participants) as $index => $participant) {
            $shares[] = [
                'user_id'    => (string) $participant['user_id'],
                'amount'     => ($baseCents + ($index < $remainder ? 1 : 0)) / 100,
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
        $sum    = 0.0;

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

        if (abs($sum - $total) > self::MONEY_EPSILON) {
            throw new InvalidExpenseSplitException(
                'The participant amounts must add up to the expense total.',
                ['participants' => [
                    'The shares add up to '.number_format($sum, 2)
                    .' but the expense total is '.number_format($total, 2).'.',
                ]],
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
        $sum         = 0.0;

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

        if (abs($sum - 100.0) > self::MONEY_EPSILON) {
            throw new InvalidExpenseSplitException(
                'The participant percentages must add up to 100%.',
                ['participants' => ['The percentages add up to '.rtrim(rtrim(number_format($sum, 2, '.', ''), '0'), '.').'% instead of 100%.']],
            );
        }

        $totalCents = (int) round($total * 100);
        $shares     = [];
        $allocated  = 0;

        foreach ($percentages as $index => $percentage) {
            $cents = (int) round($totalCents * $percentage / 100);
            $shares[$index] = [
                'user_id'    => (string) $participants[$index]['user_id'],
                'amount'     => $cents / 100,
                'percentage' => $percentage,
            ];
            $allocated += $cents;
        }

        $leftover = $totalCents - $allocated;

        if ($leftover !== 0) {
            $order = array_keys($shares);
            usort($order, static fn (int $a, int $b): int => $shares[$b]['amount'] <=> $shares[$a]['amount']);

            for ($i = 0; $i < abs($leftover); $i++) {
                $index = $order[$i % count($order)];
                $shares[$index]['amount'] = round($shares[$index]['amount'] + ($leftover > 0 ? 0.01 : -0.01), 2);
            }
        }

        return array_values($shares);
    }

    // -----------------------------------------------------------------------
    // Authorization and membership (sections 14, 18, 28)
    // -----------------------------------------------------------------------

    private function assertCanModify(Expense $expense, Group $group, User $actor): void
    {
        $actorId = $actor->stringId();

        // The payer and the group owner may change or remove an expense.
        if ((string) $expense->paid_by !== $actorId && ! $group->isOwnedBy($actorId)) {
            throw new UnauthorizedGroupAccessException(
                'Only the payer or the group owner can modify this expense.'
            );
        }
    }

    private function assertPayerIsMember(Group $group, string $userId): void
    {
        if (! $group->hasMember($userId)) {
            throw ValidationException::withMessages(['paid_by' => ['The payer is not a member of this group.']]);
        }
    }

    /**
     * @param list<array{user_id: string}> $participants
     */
    private function assertParticipantsAreMembers(Group $group, array $participants): void
    {
        foreach (array_values($participants) as $index => $participant) {
            if (! $group->hasMember((string) ($participant['user_id'] ?? ''))) {
                throw ValidationException::withMessages([
                    "participants.{$index}.user_id" => ['Every participant must be a member of this group.'],
                ]);
            }
        }
    }

    /**
     * Rebuild the split input from a stored expense when an update omits
     * participants. Only the field the split type actually needs is supplied,
     * so changing the amount of an equal split re-divides it instead of
     * tripping the sum check with the previously stored amounts.
     *
     * @return list<array<string, mixed>>
     */
    private function participantsAsInput(Expense $expense): array
    {
        $splitType = (string) $expense->split_type;
        $rows      = [];

        foreach ((array) $expense->participants as $participant) {
            $participant = (array) $participant;

            $row = ['user_id' => (string) ($participant['user_id'] ?? '')];

            if ($splitType === Expense::SPLIT_EXACT) {
                $row['amount'] = (float) ($participant['amount'] ?? 0);
            } elseif ($splitType === Expense::SPLIT_PERCENTAGE) {
                $row['percentage'] = (float) ($participant['percentage'] ?? 0);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed> $filters
     * @return \Illuminate\Database\Eloquent\Builder<Expense>
     */
    private function applyFilters($builder, array $filters)
    {
        if (! empty($filters['payer'])) {
            $builder->where('paid_by', (string) $filters['payer']);
        }

        if (! empty($filters['split_type'])) {
            $builder->where('split_type', (string) $filters['split_type']);
        }

        if (! empty($filters['date_from'])) {
            $builder->where('created_at', '>=', $filters['date_from'].' 00:00:00');
        }

        if (! empty($filters['date_to'])) {
            $builder->where('created_at', '<=', $filters['date_to'].' 23:59:59');
        }

        return $builder;
    }

    // -----------------------------------------------------------------------
    // Balances (section 20)
    // -----------------------------------------------------------------------

    /**
     * Payer + amount, each participant - their share.
     * Positive = should receive money, negative = owes money.
     * $sign = -1 reverses the effect (update and delete).
     */
    private function applyBalanceDelta(string $groupId, Expense $expense, int $sign = 1): void
    {
        $deltas = [];

        $paidBy = (string) $expense->paid_by;
        $deltas[$paidBy] = ($deltas[$paidBy] ?? 0.0) + ($sign * round((float) $expense->amount, 2));

        foreach ((array) $expense->participants as $participant) {
            $participant = (array) $participant;
            $userId      = (string) ($participant['user_id'] ?? '');

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