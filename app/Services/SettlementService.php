<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidSettlementException;
use App\Models\Group;
use App\Models\GroupBalance;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Settlement rules (sections 23, 24, 25).
 *
 * A settlement is a payment between two members of the same group. The
 * authenticated user is always the payer, and the amount can never exceed what
 * that payer actually owes the receiver in that group.
 */
final class SettlementService
{
    public function __construct(
        private readonly BalanceService $balanceService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Group $group, User $payer, array $data): Settlement
    {
        $groupId = $group->stringId();
        $payerId = $payer->stringId();
        $receiverId = (string) $data['paid_to'];
        $amount = round((float) $data['amount'], 2);
        try {

            // Both users must belong to the group (section 24).
            if (! $group->hasMember($payerId)) {
                throw ValidationException::withMessages([
                    'paid_by' => ['The payer is not a member of this group.'],
                ]);
            }

            $receiver = User::find($receiverId);

            if ($receiver === null) {
                throw ValidationException::withMessages(['paid_to' => ['The selected user does not exist.']]);
            }

            if (! $group->hasMember($receiverId)) {
                throw ValidationException::withMessages([
                    'paid_to' => ['The selected user is not a member of this group.'],
                ]);
            }

            // Payer and receiver must be different people (section 24).
            if ($payerId === $receiverId) {
                throw new InvalidSettlementException(
                    'A member cannot settle with themselves.',
                    ['paid_to' => ['The payer and receiver must be different users.']],
                );
            }

            // Cannot settle more than the outstanding debt (section 24). The
            // pairwise amount is read from the same debt calculation the debts
            // endpoint serves, so both always agree.
            $owed = $this->outstandingBetween($group, $payerId, $receiverId);

            if ($owed <= 0.0) {
                throw new InvalidSettlementException(
                    'There is nothing to settle with this member.',
                    ['paid_to' => ['You do not owe '.$receiver->name.' anything in this group.']],
                );
            }

            if ($amount > $owed + 0.01) {
                throw new InvalidSettlementException(
                    'The settlement amount exceeds the outstanding debt.',
                    ['amount' => ['You owe '.$receiver->name.' '.number_format($owed, 2).' in this group.']],
                );
            }
        } catch (\Throwable $e) {
            // Section 39: a rejected settlement creation is logged with the reason.
            Log::warning('Settlement creation failed.', [
                'group_id' => $groupId,
                'paid_by' => $payerId,
                'paid_to' => $receiverId,
                'amount' => $amount,
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        }
        $settlement = Settlement::create([
            'group_id' => $groupId,
            'paid_by' => $payerId,
            'paid_to' => $receiverId,
            'amount' => $amount,
            'note' => $data['note'] ?? null,
        ]);

        // The payer has paid off that much of his debt; the receiver is no
        // longer owed that much.
        $this->adjustBalance($groupId, $payerId, $amount);
        $this->adjustBalance($groupId, $receiverId, -$amount);

        Log::info('Settlement created.', [
            'settlement_id' => $settlement->stringId(),
            'group_id' => $groupId,
            'amount' => $amount,
        ]);

        return $settlement;
    }

    /**
     * Settlement history for a group, newest first (section 26 pattern, section 38 pagination).
     *
     * @return array{items: Collection<int, Settlement>, total: int, page: int, per_page: int, last_page: int}
     */
    public function history(string $groupId, int $page, int $perPage): array
    {
        $total = Settlement::where('group_id', $groupId)->count();

        $items = Settlement::where('group_id', $groupId)
            ->orderBy('created_at', 'desc')
            ->skip(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /** How much $debtorId still owes $creditorId in this group. */
    private function outstandingBetween(Group $group, string $debtorId, string $creditorId): float
    {
        foreach ($this->balanceService->debts($group)['debts'] as $debt) {
            if ($debt['from']['user_id'] === $debtorId && $debt['to']['user_id'] === $creditorId) {
                return round((float) $debt['amount'], 2);
            }
        }

        return 0.0;
    }

    private function adjustBalance(string $groupId, string $userId, float $delta): void
    {
        $balance = GroupBalance::where('group_id', $groupId)->where('user_id', $userId)->first();

        if ($balance === null) {
            GroupBalance::create([
                'group_id' => $groupId,
                'user_id' => $userId,
                'net_balance' => round($delta, 2),
            ]);

            return;
        }

        $balance->net_balance = round((float) $balance->net_balance + $delta, 2);
        $balance->save();
    }
}
