<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Expense;
use Tests\TestCase;

/**
 * Sections 20-22: net balances per member, who owes whom, and the simplified
 * set of payments. Every expected number here was worked out by hand from the
 * three expenses, so the test proves the app agrees with arithmetic rather
 * than with itself.
 */
final class BalanceTest extends TestCase
{
    public function test_balances_reflect_expenses_across_all_split_types(): void
    {
        $scenario = $this->balanceScenario();

        $balances = $this->balancesOf($scenario['group_id'], $scenario['alice']);

        // Alice: +300 - 100 - 60 - 45
        $this->assertEqualsWithDelta(95.0, $balances[$scenario['alice']['id']], 0.001);

        // Bob: +160 - 100 - 45
        $this->assertEqualsWithDelta(15.0, $balances[$scenario['bob']['id']], 0.001);

        // Carol: +90 - 100 - 100
        $this->assertEqualsWithDelta(-110.0, $balances[$scenario['carol']['id']], 0.001);
    }

    public function test_balances_always_sum_to_zero(): void
    {
        $scenario = $this->balanceScenario();

        $balances = $this->balancesOf($scenario['group_id'], $scenario['alice']);

        $this->assertEqualsWithDelta(0.0, array_sum($balances), 0.001);
    }

    public function test_who_owes_whom_nets_every_pair(): void
    {
        $scenario = $this->balanceScenario();
        $debts = $this->debtsOf($scenario['group_id'], $scenario['alice'])['debts'];

        // Dinner and taxi and snacks, netted pairwise:
        //   Bob owes Alice 100, Alice owes Bob 60        -> Bob -> Alice 40
        //   Carol owes Alice 100, Alice owes Carol 45    -> Carol -> Alice 55
        //   Carol owes Bob 100, Bob owes Carol 45        -> Carol -> Bob 55
        $this->assertCount(3, $debts);
        $this->assertEqualsWithDelta(
            40.0,
            $this->amountBetween($debts, $scenario['bob']['id'], $scenario['alice']['id']),
            0.001,
        );
        $this->assertEqualsWithDelta(
            55.0,
            $this->amountBetween($debts, $scenario['carol']['id'], $scenario['alice']['id']),
            0.001,
        );
        $this->assertEqualsWithDelta(
            55.0,
            $this->amountBetween($debts, $scenario['carol']['id'], $scenario['bob']['id']),
            0.001,
        );

        // A pair is reported once, in the direction that is still owed.
        $this->assertEqualsWithDelta(
            0.0,
            $this->amountBetween($debts, $scenario['alice']['id'], $scenario['bob']['id']),
            0.001,
        );
    }

    public function test_debt_simplification_uses_the_fewest_payments(): void
    {
        $scenario = $this->balanceScenario();
        $simplified = $this->debtsOf($scenario['group_id'], $scenario['alice'])['simplified'];

        // Three debts become two payments: Carol covers Alice's whole credit,
        // then the remainder she owes goes to Bob.
        $this->assertCount(2, $simplified);
        $this->assertEqualsWithDelta(
            95.0,
            $this->amountBetween($simplified, $scenario['carol']['id'], $scenario['alice']['id']),
            0.001,
        );
        $this->assertEqualsWithDelta(
            15.0,
            $this->amountBetween($simplified, $scenario['carol']['id'], $scenario['bob']['id']),
            0.001,
        );

        // Every debtor pays exactly his net balance, and nobody appears twice.
        $paidByUser = [];

        foreach ($simplified as $edge) {
            $paidByUser[$edge['from']['user_id']] =
                ($paidByUser[$edge['from']['user_id']] ?? 0) + (float) $edge['amount'];
        }

        $this->assertEqualsWithDelta(110.0, $paidByUser[$scenario['carol']['id']], 0.001);
        $this->assertArrayNotHasKey($scenario['alice']['id'], $paidByUser);
        $this->assertArrayNotHasKey($scenario['bob']['id'], $paidByUser);
    }

    public function test_deleting_an_expense_updates_balances_and_debts(): void
    {
        $scenario = $this->balanceScenario();
        $groupId = $scenario['group_id'];

        $dinner = Expense::where('group_id', $groupId)->where('description', 'Team dinner')->first();
        $this->assertNotNull($dinner);

        // Alice paid that expense, so she may delete it.
        $this->withToken($scenario['alice']['token'])
            ->deleteJson('/api/expenses/' . $dinner->stringId())
            ->assertOk();

        // Removing a 300 payment split three ways shifts everyone by 200 / +100 / +100.
        $balances = $this->balancesOf($groupId, $scenario['alice']);

        $this->assertEqualsWithDelta(-105.0, $balances[$scenario['alice']['id']], 0.001);
        $this->assertEqualsWithDelta(115.0, $balances[$scenario['bob']['id']], 0.001);
        $this->assertEqualsWithDelta(-10.0, $balances[$scenario['carol']['id']], 0.001);
        $this->assertEqualsWithDelta(0.0, array_sum($balances), 0.001);

        // The remaining debts: Alice -> Bob 60, Alice -> Carol 45, Carol -> Bob 55.
        $debts = $this->debtsOf($groupId, $scenario['alice'])['debts'];

        $this->assertCount(3, $debts);
        $this->assertEqualsWithDelta(
            60.0,
            $this->amountBetween($debts, $scenario['alice']['id'], $scenario['bob']['id']),
            0.001,
        );
        $this->assertEqualsWithDelta(
            45.0,
            $this->amountBetween($debts, $scenario['alice']['id'], $scenario['carol']['id']),
            0.001,
        );
    }

    public function test_balances_and_debts_require_group_membership(): void
    {
        $scenario = $this->balanceScenario();
        $outsider = $this->makeUser('outsider@example.com');
        $groupId = $scenario['group_id'];

        $this->withToken($outsider['token'])
            ->getJson("/api/groups/{$groupId}/balances")
            ->assertForbidden();

        $this->withToken($outsider['token'])
            ->getJson("/api/groups/{$groupId}/debts")
            ->assertForbidden();
    }

    public function test_balances_and_debts_require_authentication(): void
    {
        $scenario = $this->balanceScenario();
        $groupId = $scenario['group_id'];

        // withToken() sets a default header for the whole test method, so it
        // must be cleared before asserting what an unauthenticated caller gets.
        $this->flushHeaders();

        $this->getJson("/api/groups/{$groupId}/balances")->assertStatus(401);
        $this->getJson("/api/groups/{$groupId}/debts")->assertStatus(401);
    }
}