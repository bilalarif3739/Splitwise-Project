<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Expense;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Sections 15-19, 26-29 - the three split types, their invalid cases, the
 * history filters and pagination, and the balance effect of update and delete.
 */
final class ExpenseTest extends TestCase
{
    /**
     * @return array{0: string, 1: list<array{id: string, token: string}>}
     */
    private function groupWithThreeMembers(): array
    {
        $users = [
            $this->makeUser('one@example.com', 'One'),
            $this->makeUser('two@example.com', 'Two'),
            $this->makeUser('three@example.com', 'Three'),
        ];

        $groupId = $this->makeGroup($users[0], [$users[1], $users[2]]);

        return [$groupId, $users];
    }

    /**
     * @param array{id: string, token: string} $actor
     * @param array<string, mixed> $payload
     */
    private function createExpense(string $groupId, array $actor, array $payload): TestResponse
    {
        return $this->withToken($actor['token'])
            ->postJson("/api/groups/{$groupId}/expenses", $payload);
    }

    private function expenseId(TestResponse $response): string
    {
        $id = (string) ($response->json('data.id') ?? '');

        $this->assertNotSame('', $id, 'The expense id should be returned at data.id.');

        return $id;
    }

    private function shareOf(string $expenseId, string $userId): float
    {
        $expense = Expense::find($expenseId);

        $this->assertNotNull($expense);

        foreach ((array) $expense->participants as $participant) {
            $participant = (array) $participant;

            if ((string) $participant['user_id'] === $userId) {
                return round((float) $participant['amount'], 2);
            }
        }

        return 0.0;
    }

    /**
     * @param array{id: string, token: string} $actor
     */
    private function balanceOf(string $groupId, array $actor, string $userId): float
    {
        $balances = $this->withToken($actor['token'])
            ->getJson("/api/groups/{$groupId}/balances")
            ->assertOk()
            ->json('data.balances');

        foreach ($balances as $row) {
            if ($row['user_id'] === $userId) {
                return round((float) $row['balance'], 2);
            }
        }

        return 0.0;
    }

    // -----------------------------------------------------------------------
    // Split types (section 16)
    // -----------------------------------------------------------------------

    public function test_an_equal_split_divides_the_amount_evenly(): void
    {
        [$groupId, $users] = $this->groupWithThreeMembers();

        $response = $this->createExpense($groupId, $users[0], [
            'description' => 'Dinner',
            'amount' => 100,
            'paid_by' => $users[0]['id'],
            'split_type' => 'equal',
            'participants' => [
                ['user_id' => $users[0]['id']],
                ['user_id' => $users[1]['id']],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data']);

        $expenseId = $this->expenseId($response);

        $this->assertEqualsWithDelta(50.0, $this->shareOf($expenseId, $users[0]['id']), 0.001);
        $this->assertEqualsWithDelta(50.0, $this->shareOf($expenseId, $users[1]['id']), 0.001);
    }

    public function test_an_equal_split_distributes_the_leftover_cent(): void
    {
        [$groupId, $users] = $this->groupWithThreeMembers();

        $response = $this->createExpense($groupId, $users[0], [
            'description' => 'Groceries',
            'amount' => 100,
            'paid_by' => $users[0]['id'],
            'split_type' => 'equal',
            'participants' => [
                ['user_id' => $users[0]['id']],
                ['user_id' => $users[1]['id']],
                ['user_id' => $users[2]['id']],
            ],
        ]);

        $response->assertStatus(201);

        $expenseId = $this->expenseId($response);

        $this->assertEqualsWithDelta(33.34, $this->shareOf($expenseId, $users[0]['id']), 0.001);
        $this->assertEqualsWithDelta(33.33, $this->shareOf($expenseId, $users[1]['id']), 0.001);
        $this->assertEqualsWithDelta(33.33, $this->shareOf($expenseId, $users[2]['id']), 0.001);

        $total = $this->shareOf($expenseId, $users[0]['id'])
            + $this->shareOf($expenseId, $users[1]['id'])
            + $this->shareOf($expenseId, $users[2]['id']);

        $this->assertEqualsWithDelta(100.0, $total, 0.001);
    }

    public function test_an_exact_split_accepts_shares_that_add_up(): void
    {
        [$groupId, $users] = $this->groupWithThreeMembers();

        $response = $this->createExpense($groupId, $users[0], [
            'description' => 'Hotel',
            'amount' => 1000,
            'paid_by' => $users[0]['id'],
            'split_type' => 'exact',
            'participants' => [
                ['user_id' => $users[1]['id'], 'amount' => 700],
                ['user_id' => $users[2]['id'], 'amount' => 300],
            ],
        ]);

        $response->assertStatus(201);

        $expenseId = $this->expenseId($response);

        $this->assertEqualsWithDelta(700.0, $this->shareOf($expenseId, $users[1]['id']), 0.001);
        $this->assertEqualsWithDelta(300.0, $this->shareOf($expenseId, $users[2]['id']), 0.001);
    }

    public function test_an_exact_split_rejects_shares_that_do_not_add_up(): void
    {
        [$groupId, $users] = $this->groupWithThreeMembers();

        $this->createExpense($groupId, $users[0], [
            'description' => 'Hotel',
            'amount' => 1000,
            'paid_by' => $users[0]['id'],
            'split_type' => 'exact',
            'participants' => [
                ['user_id' => $users[1]['id'], 'amount' => 700],
                ['user_id' => $users[2]['id'], 'amount' => 200],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors' => ['participants']]);
    }

    public function test_a_percentage_split_derives_the_amounts(): void
    {
        [$groupId, $users] = $this->groupWithThreeMembers();

        $response = $this->createExpense($groupId, $users[0], [
            'description' => 'Rent',
            'amount' => 5000,
            'paid_by' => $users[0]['id'],
            'split_type' => 'percentage',
            'participants' => [
                ['user_id' => $users[0]['id'], 'percentage' => 60],
                ['user_id' => $users[1]['id'], 'percentage' => 40],
            ],
        ]);

        $response->assertStatus(201);

        $expenseId = $this->expenseId($response);

        $this->assertEqualsWithDelta(3000.0, $this->shareOf($expenseId, $users[0]['id']), 0.001);
        $this->assertEqualsWithDelta(2000.0, $this->shareOf($expenseId, $users[1]['id']), 0.001);
    }

    public function test_a_percentage_split_must_add_up_to_one_hundred(): void
    {
        [$groupId, $users] = $this->groupWithThreeMembers();

        $this->createExpense($groupId, $users[0], [
            'description' => 'Rent',
            'amount' => 5000,
            'paid_by' => $users[0]['id'],
            'split_type' => 'percentage',
            'participants' => [
                ['user_id' => $users[0]['id'], 'percentage' => 60],
                ['user_id' => $users[1]['id'], 'percentage' => 30],
            ],
        ])->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['participants']]);
    }

    // -----------------------------------------------------------------------
    // Validation (sections 18, 30)
    // -----------------------------------------------------------------------

    public function test_the_amount_must_be_greater_than_zero(): void
    {
        [$groupId, $users] = $this->groupWithThreeMembers();

        $this->createExpense($groupId, $users[0], [
            'description' => 'Free lunch',
            'amount' => 0,
            'paid_by' => $users[0]['id'],
            'split_type' => 'equal',
            'participants' => [['user_id' => $users[0]['id']]],
        ])->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['amount']]);
    }

    public function test_an_invalid_split_type_is_rejected(): void
    {
        [$groupId, $users] = $this->groupWithThreeMembers();

        $this->createExpense($groupId, $users[0], [
            'description' => 'Mystery split',
            'amount' => 100,
            'paid_by' => $users[0]['id'],
            'split_type' => 'weighted',
            'participants' => [['user_id' => $users[0]['id']]],
        ])->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['split_type']]);
    }

    public function test_the_payer_must_be_a_group_member(): void
    {
        [$groupId, $users] = $this->groupWithThreeMembers();
        $outsider = $this->makeUser('outsider@example.com');

        $this->createExpense($groupId, $users[0], [
            'description' => 'Dinner',
            'amount' => 100,
            'paid_by' => $outsider['id'],
            'split_type' => 'equal',
            'participants' => [['user_id' => $users[0]['id']]],
        ])->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['paid_by']]);
    }

    public function test_every_participant_must_be_a_group_member(): void
    {
        [$groupId, $users] = $this->groupWithThreeMembers();
        $outsider = $this->makeUser('outsider@example.com');

        $response = $this->createExpense($groupId, $users[0], [
            'description' => 'Dinner',
            'amount' => 100,
            'paid_by' => $users[0]['id'],
            'split_type' => 'equal',
            'participants' => [
                ['user_id' => $users[0]['id']],
                ['user_id' => $outsider['id']],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('success', false);

        // The service reports the offending row as participants.<index>.user_id.
        $this->assertArrayHasKey(
            'participants.1.user_id',
            (array) $response->json('errors'),
            'Expected a participants.* validation error, got: ' . json_encode($response->json('errors')),
        );
    }

    public function test_a_non_member_cannot_create_an_expense_in_the_group(): void
    {
        [$groupId, $users] = $this->groupWithThreeMembers();
        $outsider = $this->makeUser('outsider@example.com');

        $this->createExpense($groupId, $outsider, [
            'description' => 'Dinner',
            'amount' => 100,
            'paid_by' => $outsider['id'],
            'split_type' => 'equal',
            'participants' => [['user_id' => $outsider['id']]],
        ])->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // History: filtering and pagination (sections 26, 37, 38)
    // -----------------------------------------------------------------------

    public function test_expenses_can_be_filtered_by_payer_and_split_type(): void
    {
        [$groupId, $users] = $this->groupWithThreeMembers();

        $this->createExpense($groupId, $users[0], [
            'description' => 'Paid by one',
            'amount' => 100,
            'paid_by' => $users[0]['id'],
            'split_type' => 'equal',
            'participants' => [['user_id' => $users[0]['id']]],
        ])->assertStatus(201);

        $this->createExpense($groupId, $users[0], [
            'description' => 'Paid by two',
            'amount' => 200,
            'paid_by' => $users[1]['id'],
            'split_type' => 'exact',
            'participants' => [['user_id' => $users[1]['id'], 'amount' => 200]],
        ])->assertStatus(201);

        $byPayer = $this->withToken($users[0]['token'])
            ->getJson("/api/groups/{$groupId}/expenses?payer={$users[0]['id']}")
            ->assertOk();

        $this->assertCount(1, $byPayer->json('data.items'));
        $this->assertSame($users[0]['id'], $byPayer->json('data.items.0.paid_by'));

        $bySplitType = $this->withToken($users[0]['token'])
            ->getJson("/api/groups/{$groupId}/expenses?split_type=exact")
            ->assertOk();

        $this->assertCount(1, $bySplitType->json('data.items'));
        $this->assertSame('exact', $bySplitType->json('data.items.0.split_type'));
    }

    public function test_expense_history_is_paginated(): void
    {
        [$groupId, $users] = $this->groupWithThreeMembers();

        foreach ([100, 200, 300] as $amount) {
            $this->createExpense($groupId, $users[0], [
                'description' => 'Expense ' . $amount,
                'amount' => $amount,
                'paid_by' => $users[0]['id'],
                'split_type' => 'equal',
                'participants' => [['user_id' => $users[0]['id']]],
            ])->assertStatus(201);
        }

        $firstPage = $this->withToken($users[0]['token'])
            ->getJson("/api/groups/{$groupId}/expenses?page=1&per_page=2")
            ->assertOk();

        $this->assertCount(2, $firstPage->json('data.items'));
        $this->assertSame(3, $firstPage->json('data.pagination.total'));
        $this->assertSame(2, $firstPage->json('data.pagination.last_page'));
        $this->assertSame(1, $firstPage->json('data.pagination.current_page'));

        $secondPage = $this->withToken($users[0]['token'])
            ->getJson("/api/groups/{$groupId}/expenses?page=2&per_page=2")
            ->assertOk();

        $this->assertCount(1, $secondPage->json('data.items'));
    }

    public function test_an_expense_can_be_fetched_by_id(): void
    {
        [$groupId, $users] = $this->groupWithThreeMembers();

        $created = $this->createExpense($groupId, $users[0], [
            'description' => 'Dinner',
            'amount' => 100,
            'paid_by' => $users[0]['id'],
            'split_type' => 'equal',
            'participants' => [['user_id' => $users[0]['id']]],
        ])->assertStatus(201);

        $expenseId = $this->expenseId($created);

        $response = $this->withToken($users[0]['token'])
            ->getJson("/api/expenses/{$expenseId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        // The detail endpoint may return the expense directly or as data.expense.
        $expense = (array) ($response->json('data.expense') ?? $response->json('data'));

        $this->assertSame($expenseId, (string) ($expense['id'] ?? ''), 'Response: ' . $response->getContent());
        $this->assertSame('Dinner', (string) ($expense['description'] ?? ''));
    }

    // -----------------------------------------------------------------------
    // Update and delete (sections 28, 29)
    // -----------------------------------------------------------------------

    public function test_updating_an_expense_recalculates_shares_and_balances(): void
    {
        [$groupId, $users] = $this->groupWithThreeMembers();

        $created = $this->createExpense($groupId, $users[0], [
            'description' => 'Dinner',
            'amount' => 100,
            'paid_by' => $users[0]['id'],
            'split_type' => 'equal',
            'participants' => [
                ['user_id' => $users[0]['id']],
                ['user_id' => $users[1]['id']],
            ],
        ])->assertStatus(201);

        $expenseId = $this->expenseId($created);

        $this->assertEqualsWithDelta(50.0, $this->balanceOf($groupId, $users[0], $users[0]['id']), 0.001);
        $this->assertEqualsWithDelta(-50.0, $this->balanceOf($groupId, $users[0], $users[1]['id']), 0.001);

        $this->withToken($users[0]['token'])
            ->patchJson("/api/expenses/{$expenseId}", ['amount' => 200])
            ->assertOk();

        $this->assertEqualsWithDelta(100.0, $this->shareOf($expenseId, $users[0]['id']), 0.001);
        $this->assertEqualsWithDelta(100.0, $this->shareOf($expenseId, $users[1]['id']), 0.001);

        $this->assertEqualsWithDelta(100.0, $this->balanceOf($groupId, $users[0], $users[0]['id']), 0.001);
        $this->assertEqualsWithDelta(-100.0, $this->balanceOf($groupId, $users[0], $users[1]['id']), 0.001);
    }

    public function test_deleting_an_expense_reverses_its_effect(): void
    {
        [$groupId, $users] = $this->groupWithThreeMembers();

        $created = $this->createExpense($groupId, $users[0], [
            'description' => 'Dinner',
            'amount' => 100,
            'paid_by' => $users[0]['id'],
            'split_type' => 'equal',
            'participants' => [
                ['user_id' => $users[0]['id']],
                ['user_id' => $users[1]['id']],
            ],
        ])->assertStatus(201);

        $expenseId = $this->expenseId($created);

        $this->withToken($users[0]['token'])
            ->deleteJson("/api/expenses/{$expenseId}")
            ->assertOk();

        $this->assertNull(Expense::find($expenseId));
        $this->assertEqualsWithDelta(0.0, $this->balanceOf($groupId, $users[0], $users[0]['id']), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->balanceOf($groupId, $users[0], $users[1]['id']), 0.001);

        $this->withToken($users[0]['token'])
            ->getJson("/api/expenses/{$expenseId}")
            ->assertNotFound();
    }

    public function test_only_the_payer_or_the_owner_can_update_an_expense(): void
    {
        [$groupId, $users] = $this->groupWithThreeMembers();

        $created = $this->createExpense($groupId, $users[1], [
            'description' => 'Dinner',
            'amount' => 100,
            'paid_by' => $users[1]['id'],
            'split_type' => 'equal',
            'participants' => [
                ['user_id' => $users[1]['id']],
                ['user_id' => $users[2]['id']],
            ],
        ])->assertStatus(201);

        $expenseId = $this->expenseId($created);

        // Three is a member but neither the payer nor the owner.
        $this->withToken($users[2]['token'])
            ->patchJson("/api/expenses/{$expenseId}", ['amount' => 500])
            ->assertForbidden();

        // The payer may.
        $this->withToken($users[1]['token'])
            ->patchJson("/api/expenses/{$expenseId}", ['amount' => 500])
            ->assertOk();

        // The owner may as well.
        $this->withToken($users[0]['token'])
            ->patchJson("/api/expenses/{$expenseId}", ['amount' => 600])
            ->assertOk();
    }

    public function test_expense_routes_require_authentication(): void
    {
        $this->getJson('/api/expenses/000000000000000000000000')->assertStatus(401);
    }
}