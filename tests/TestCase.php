<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests never touch the development database.
     *
     * MongoDB transactions require a replica set, which the local standalone
     * server is not, so a clean slate is achieved by wiping this dedicated
     * database before every test instead of rolling back a transaction.
     */
    private const TEST_DATABASE = 'splitwise_test';

    protected function setUp(): void
    {
        parent::setUp();

        // bcrypt is deliberately slow; its cost factor plays no part in what
        // these tests prove, so registration stays fast.
        config(['hashing.bcrypt.rounds' => 4]);

        config(['database.connections.mongodb.database' => self::TEST_DATABASE]);
        DB::purge('mongodb');

        $database = DB::connection('mongodb')->getDatabase(self::TEST_DATABASE);

        if ($database->getDatabaseName() !== self::TEST_DATABASE) {
            throw new RuntimeException(
                'Refusing to run tests against "' . $database->getDatabaseName() . '".'
            );
        }

        foreach ($database->listCollectionNames() as $name) {
            if (!str_starts_with($name, 'system.')) {
                $database->dropCollection($name);
            }
        }
    }

    /**
     * Register a user through the public endpoint and return his id and token.
     *
     * Tests authenticate the way real clients do - with a bearer token - rather
     * than disabling the authentication layer.
     *
     * @return array{id: string, token: string}
     */
    protected function makeUser(string $email, string $name = 'Test User'): array
    {
        $response = $this->postJson('/api/register', [
            'name' => $name,
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(201);

        return [
            'id' => (string) User::where('email', $email)->firstOrFail()->stringId(),
            'token' => (string) $response->json('data.token'),
        ];
    }

    /**
     * Create a group owned by $owner and add every user in $members to it.
     *
     * @param  array{id: string, token: string}  $owner
     * @param  list<array{id: string, token: string}>  $members
     */
    protected function makeGroup(array $owner, array $members = [], string $name = 'Test Group'): string
    {
        $response = $this->withToken($owner['token'])
            ->postJson('/api/groups', ['name' => $name, 'description' => 'Created by tests']);

        $response->assertSuccessful();

        $groupId = (string) ($response->json('data.id') ?? '');

        $this->assertNotSame('', $groupId, 'The group id should be returned at data.id.');

        foreach ($members as $member) {
            $this->withToken($owner['token'])
                ->postJson("/api/groups/{$groupId}/members", ['user_id' => $member['id']])
                ->assertSuccessful();
        }

        return $groupId;
    }

    /**
     * A three-member scenario that exercises all three split types:
     *
     *   Alice pays 300, equal three ways            -> 100 each
     *   Bob   pays 160, exact (Alice 60, Carol 100)
     *   Carol pays  90, percentage 50/50 Alice/Bob  -> 45 each
     *
     * Resulting balances: Alice +95, Bob +15, Carol -110.
     * Raw debts: Bob->Alice 40, Carol->Alice 55, Carol->Bob 55.
     *
     * @return array{group_id: string, alice: array{id: string, token: string}, bob: array{id: string, token: string}, carol: array{id: string, token: string}}
     */
    protected function balanceScenario(): array
    {
        $alice = $this->makeUser('alice@example.com', 'Alice');
        $bob = $this->makeUser('bob@example.com', 'Bob');
        $carol = $this->makeUser('carol@example.com', 'Carol');

        $groupId = $this->makeGroup($alice, [$bob, $carol]);

        $this->withToken($alice['token'])->postJson("/api/groups/{$groupId}/expenses", [
            'description' => 'Team dinner',
            'amount' => 300,
            'paid_by' => $alice['id'],
            'split_type' => 'equal',
            'participants' => [
                ['user_id' => $alice['id']],
                ['user_id' => $bob['id']],
                ['user_id' => $carol['id']],
            ],
        ])->assertStatus(201);

        $this->withToken($bob['token'])->postJson("/api/groups/{$groupId}/expenses", [
            'description' => 'Taxi',
            'amount' => 160,
            'paid_by' => $bob['id'],
            'split_type' => 'exact',
            'participants' => [
                ['user_id' => $alice['id'], 'amount' => 60],
                ['user_id' => $carol['id'], 'amount' => 100],
            ],
        ])->assertStatus(201);

        $this->withToken($carol['token'])->postJson("/api/groups/{$groupId}/expenses", [
            'description' => 'Snacks',
            'amount' => 90,
            'paid_by' => $carol['id'],
            'split_type' => 'percentage',
            'participants' => [
                ['user_id' => $alice['id'], 'percentage' => 50],
                ['user_id' => $bob['id'], 'percentage' => 50],
            ],
        ])->assertStatus(201);

        return ['group_id' => $groupId, 'alice' => $alice, 'bob' => $bob, 'carol' => $carol];
    }

    /**
     * @param  array{id: string, token: string}  $actor
     * @return array<string, float> user id => net balance
     */
    protected function balancesOf(string $groupId, array $actor): array
    {
        $rows = $this->withToken($actor['token'])
            ->getJson("/api/groups/{$groupId}/balances")
            ->assertOk()
            ->json('data.balances');

        $byUser = [];

        foreach ($rows as $row) {
            $byUser[(string) $row['user_id']] = round((float) $row['balance'], 2);
        }

        return $byUser;
    }

    /**
     * @param  array{id: string, token: string}  $actor
     * @return array{debts: array<int, mixed>, simplified: array<int, mixed>}
     */
    protected function debtsOf(string $groupId, array $actor): array
    {
        $response = $this->withToken($actor['token'])
            ->getJson("/api/groups/{$groupId}/debts")
            ->assertOk();

        return [
            'debts' => (array) $response->json('data.debts'),
            'simplified' => (array) $response->json('data.simplified'),
        ];
    }

    /**
     * The amount of the edge going from $fromId to $toId, or 0.0 when absent.
     *
     * @param  array<int, mixed>  $edges
     */
    protected function amountBetween(array $edges, string $fromId, string $toId): float
    {
        foreach ($edges as $edge) {
            if ($edge['from']['user_id'] === $fromId && $edge['to']['user_id'] === $toId) {
                return round((float) $edge['amount'], 2);
            }
        }

        return 0.0;
    }
}