<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Sections 23-25: the authenticated user is the payer, both sides must be group
 * members, the amount must be positive and may never exceed the outstanding
 * debt, and a settlement shifts both balances and the who-owes-whom output.
 */
final class SettlementTest extends TestCase
{
    /**
     * Carol owes Alice 55 in the standard scenario; she settles it.
     */
    public function test_a_member_can_settle_what_he_owes(): void
    {
        $scenario = $this->balanceScenario();
        $groupId = $scenario['group_id'];

        $response = $this->withToken($scenario['carol']['token'])
            ->postJson("/api/groups/{$groupId}/settlements", [
                'paid_to' => $scenario['alice']['id'],
                'amount' => 55,
                'note' => 'Paid my share of the dinner',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.paid_by', $scenario['carol']['id'])
            ->assertJsonPath('data.paid_to', $scenario['alice']['id']);

        $this->assertEqualsWithDelta(55.0, (float) $response->json('data.amount'), 0.001);
        $this->assertNull($response->json('data.attachment'));

        // Carol paid off 55, so she owes 55 less; Alice is owed 55 less.
        $balances = $this->balancesOf($groupId, $scenario['alice']);

        $this->assertEqualsWithDelta(40.0, $balances[$scenario['alice']['id']], 0.001);
        $this->assertEqualsWithDelta(15.0, $balances[$scenario['bob']['id']], 0.001);
        $this->assertEqualsWithDelta(-55.0, $balances[$scenario['carol']['id']], 0.001);
        $this->assertEqualsWithDelta(0.0, array_sum($balances), 0.001);
    }

    public function test_a_settlement_updates_the_who_owes_whom_output(): void
    {
        $scenario = $this->balanceScenario();
        $groupId = $scenario['group_id'];

        $this->withToken($scenario['carol']['token'])
            ->postJson("/api/groups/{$groupId}/settlements", [
                'paid_to' => $scenario['alice']['id'],
                'amount' => 55,
            ])->assertStatus(201);

        $debts = $this->debtsOf($groupId, $scenario['alice']);

        // Carol -> Alice is settled and disappears; the other two remain.
        $this->assertEqualsWithDelta(
            0.0,
            $this->amountBetween($debts['debts'], $scenario['carol']['id'], $scenario['alice']['id']),
            0.001,
        );
        $this->assertEqualsWithDelta(
            40.0,
            $this->amountBetween($debts['debts'], $scenario['bob']['id'], $scenario['alice']['id']),
            0.001,
        );

        // And the simplified set is rebuilt from the new balances.
        $this->assertCount(2, $debts['simplified']);
        $this->assertEqualsWithDelta(
            40.0,
            $this->amountBetween($debts['simplified'], $scenario['carol']['id'], $scenario['alice']['id']),
            0.001,
        );
        $this->assertEqualsWithDelta(
            15.0,
            $this->amountBetween($debts['simplified'], $scenario['carol']['id'], $scenario['bob']['id']),
            0.001,
        );
    }

    public function test_a_settlement_cannot_exceed_the_outstanding_debt(): void
    {
        $scenario = $this->balanceScenario();
        $groupId = $scenario['group_id'];

        // Carol owes Alice 55; 60 is one cent too many.
        $this->withToken($scenario['carol']['token'])
            ->postJson("/api/groups/{$groupId}/settlements", [
                'paid_to' => $scenario['alice']['id'],
                'amount' => 60,
            ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors' => ['amount']]);

        // Nothing was written.
        $balances = $this->balancesOf($groupId, $scenario['alice']);
        $this->assertEqualsWithDelta(-110.0, $balances[$scenario['carol']['id']], 0.001);
    }

    public function test_a_member_cannot_settle_with_someone_he_owes_nothing(): void
    {
        $scenario = $this->balanceScenario();
        $groupId = $scenario['group_id'];

        // Bob owes Alice, not Carol.
        $this->withToken($scenario['bob']['token'])
            ->postJson("/api/groups/{$groupId}/settlements", [
                'paid_to' => $scenario['carol']['id'],
                'amount' => 10,
            ])->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['paid_to']]);
    }

    public function test_a_member_cannot_settle_with_himself(): void
    {
        $scenario = $this->balanceScenario();

        $this->withToken($scenario['carol']['token'])
            ->postJson("/api/groups/{$scenario['group_id']}/settlements", [
                'paid_to' => $scenario['carol']['id'],
                'amount' => 10,
            ])->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['paid_to']]);
    }

    public function test_the_receiver_must_be_a_group_member(): void
    {
        $scenario = $this->balanceScenario();
        $outsider = $this->makeUser('outsider@example.com');

        $this->withToken($scenario['carol']['token'])
            ->postJson("/api/groups/{$scenario['group_id']}/settlements", [
                'paid_to' => $outsider['id'],
                'amount' => 10,
            ])->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['paid_to']]);
    }

    public function test_the_amount_must_be_greater_than_zero(): void
    {
        $scenario = $this->balanceScenario();

        $this->withToken($scenario['carol']['token'])
            ->postJson("/api/groups/{$scenario['group_id']}/settlements", [
                'paid_to' => $scenario['alice']['id'],
                'amount' => 0,
            ])->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['amount']]);
    }

    public function test_a_non_member_cannot_create_a_settlement(): void
    {
        $scenario = $this->balanceScenario();
        $outsider = $this->makeUser('outsider@example.com');

        $this->withToken($outsider['token'])
            ->postJson("/api/groups/{$scenario['group_id']}/settlements", [
                'paid_to' => $scenario['alice']['id'],
                'amount' => 10,
            ])->assertForbidden();
    }

    public function test_settlement_history_is_paginated_and_newest_first(): void
    {
        $scenario = $this->balanceScenario();
        $groupId = $scenario['group_id'];

        $this->withToken($scenario['carol']['token'])->postJson("/api/groups/{$groupId}/settlements", [
            'paid_to' => $scenario['alice']['id'],
            'amount' => 55,
        ])->assertStatus(201);

        $this->withToken($scenario['carol']['token'])->postJson("/api/groups/{$groupId}/settlements", [
            'paid_to' => $scenario['bob']['id'],
            'amount' => 15,
        ])->assertStatus(201);

        $response = $this->withToken($scenario['alice']['token'])
            ->getJson("/api/groups/{$groupId}/settlements?page=1&per_page=10")
            ->assertOk();

        $this->assertCount(2, $response->json('data.items'));
        $this->assertSame(2, $response->json('data.pagination.total'));
        $this->assertSame(1, $response->json('data.pagination.current_page'));

        // Newest first: the 15 settlement was written last.
        $this->assertEqualsWithDelta(15.0, (float) $response->json('data.items.0.amount'), 0.001);
        $this->assertEqualsWithDelta(55.0, (float) $response->json('data.items.1.amount'), 0.001);

        // Carol settled 55 of the 110 she owed: her debt to Alice is cleared,
        // 15 of the 55 owed to Bob is paid, so 40 is still outstanding.
        $balances = $this->balancesOf($groupId, $scenario['alice']);

        $this->assertEqualsWithDelta(40.0, $balances[$scenario['alice']['id']], 0.001);
        $this->assertEqualsWithDelta(0.0, $balances[$scenario['bob']['id']], 0.001);
        $this->assertEqualsWithDelta(-40.0, $balances[$scenario['carol']['id']], 0.001);
        $this->assertEqualsWithDelta(0.0, array_sum($balances), 0.001);
    }
}