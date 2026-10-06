<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupBalance;
use App\Models\Settlement;
use App\Models\Token;
use App\Models\User;
use App\Services\ExpenseService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Realistic demo data (section 37).
 *
 * Expenses are created through ExpenseService rather than inserted directly,
 * so every share and every group_balance row is produced by exactly the same
 * code the API uses - seeded data can never disagree with runtime data.
 */
final class DemoDataSeeder extends Seeder
{
    public function __construct(
        private readonly ExpenseService $expenseService,
    ) {
    }

    public function run(): void
    {
        $this->resetCollections();

        // --- users (12 in total: 5 named + 7 generated) ------------------
        $ali = User::factory()->create(['name' => 'Ali', 'email' => 'ali@example.com']);
        $ahmed = User::factory()->create(['name' => 'Ahmed', 'email' => 'ahmed@example.com']);
        $usman = User::factory()->create(['name' => 'Usman', 'email' => 'usman@example.com']);
        $bilal = User::factory()->create(['name' => 'Bilal', 'email' => 'bilal@example.com']);
        $sara = User::factory()->create(['name' => 'Sara', 'email' => 'sara@example.com']);

        $extras = User::factory()->count(7)->create();

        // --- groups (4, each with several members) ----------------------
        $dubai = $this->createGroup('Dubai Trip', 'Expenses for the Dubai trip', [
            $ali,
            $ahmed,
            $usman,
            $bilal,
        ]);

        $office = $this->createGroup('Office Lunch', 'Shared lunches at the office', [
            $ali,
            $ahmed,
            $usman,
        ]);

        $apartment = $this->createGroup('Apartment Expenses', 'Rent and utilities', [
            $ali,
            $bilal,
            $sara,
        ]);

        $weekend = $this->createGroup('Friends Weekend Trip', 'Weekend getaway costs', [
            $ahmed,
            $bilal,
            $sara,
            $extras[0],
        ]);

        // --- expenses (12, covering all three split types) --------------

        // Dubai Trip
        $this->addExpense(
            $dubai,
            $ali,
            'Dinner',
            12000,
            Expense::SPLIT_EQUAL,
            $this->equal($ali, $ahmed, $usman, $bilal)
        );

        $this->addExpense(
            $dubai,
            $ali,
            'Hotel',
            40000,
            Expense::SPLIT_EXACT,
            $this->exact([[$ali, 20000], [$ahmed, 10000], [$usman, 6000], [$bilal, 4000]])
        );

        $this->addExpense(
            $dubai,
            $ahmed,
            'Taxi',
            5000,
            Expense::SPLIT_PERCENTAGE,
            $this->percentage([[$ali, 40], [$ahmed, 30], [$usman, 20], [$bilal, 10]])
        );

        // Office Lunch
        $this->addExpense(
            $office,
            $usman,
            'Lunch',
            3600,
            Expense::SPLIT_EQUAL,
            $this->equal($ali, $ahmed, $usman)
        );

        $this->addExpense(
            $office,
            $ali,
            'Coffee',
            900,
            Expense::SPLIT_PERCENTAGE,
            $this->percentage([[$ali, 50], [$ahmed, 30], [$usman, 20]])
        );

        $this->addExpense(
            $office,
            $usman,
            'Parking',
            400,
            Expense::SPLIT_EQUAL,
            $this->equal($ali, $ahmed, $usman)
        );

        // Apartment Expenses
        $this->addExpense(
            $apartment,
            $ali,
            'Rent',
            60000,
            Expense::SPLIT_EQUAL,
            $this->equal($ali, $bilal, $sara)
        );

        $this->addExpense(
            $apartment,
            $sara,
            'Internet',
            5400,
            Expense::SPLIT_EXACT,
            $this->exact([[$ali, 1800], [$bilal, 1800], [$sara, 1800]])
        );

        $this->addExpense(
            $apartment,
            $ali,
            'Groceries',
            2750,
            Expense::SPLIT_EQUAL,
            $this->equal($ali, $bilal, $sara)
        );

        // Friends Weekend Trip
        $this->addExpense(
            $weekend,
            $bilal,
            'Fuel',
            8000,
            Expense::SPLIT_EQUAL,
            $this->equal($ahmed, $bilal, $sara, $extras[0])
        );

        $this->addExpense(
            $weekend,
            $ahmed,
            'Snacks',
            1500,
            Expense::SPLIT_EXACT,
            $this->exact([[$ahmed, 500], [$bilal, 500], [$sara, 300], [$extras[0], 200]])
        );

        $this->addExpense(
            $weekend,
            $sara,
            'Tickets',
            6000,
            Expense::SPLIT_PERCENTAGE,
            $this->percentage([[$ahmed, 50], [$bilal, 25], [$sara, 15], [$extras[0], 10]])
        );

        // --- settlements (3) --------------------------------------------
        $this->addSettlement($dubai, $bilal, $ali, 1000, 'Part of the dinner settlement');
        $this->addSettlement($office, $ahmed, $usman, 500, 'Lunch share');
        $this->addSettlement($apartment, $bilal, $ali, 5000, 'Rent share');

        $this->command->info('Demo data seeded:');
        $this->command->info('  users: ' . User::query()->count() . '  (password for all: password123)');
        $this->command->info('  groups: ' . Group::query()->count());
        $this->command->info('  expenses: ' . Expense::query()->count());
        $this->command->info('  settlements: ' . Settlement::query()->count());
        $this->command->info('  balance rows: ' . GroupBalance::query()->count());
        $this->command->info('  log in as ali@example.com / password123');
    }

    /**
     * @param list<User> $users
     */
    private function createGroup(string $name, string $description, array $users): Group
    {
        return Group::factory()->create([
            'name' => $name,
            'description' => $description,
            'owner_id' => $users[0]->stringId(),
            'member_ids' => array_map(static fn(User $user): string => $user->stringId(), $users),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $participants
     */
    private function addExpense(
        Group $group,
        User $payer,
        string $description,
        float $amount,
        string $splitType,
        array $participants,
    ): Expense {
        return $this->expenseService->create($group, $payer, [
            'description' => $description,
            'amount' => $amount,
            'paid_by' => $payer->stringId(),
            'split_type' => $splitType,
            'participants' => $participants,
        ]);
    }

    /**
     * @return list<array{user_id: string}>
     */
    private function equal(User ...$users): array
    {
        return array_map(static fn(User $user): array => ['user_id' => $user->stringId()], $users);
    }

    /**
     * @param  list<array{0: User, 1: float}> $pairs
     * @return list<array{user_id: string, amount: float}>
     */
    private function exact(array $pairs): array
    {
        return array_map(static fn(array $pair): array => [
            'user_id' => $pair[0]->stringId(),
            'amount' => $pair[1],
        ], $pairs);
    }

    /**
     * @param  list<array{0: User, 1: float}> $pairs
     * @return list<array{user_id: string, percentage: float}>
     */
    private function percentage(array $pairs): array
    {
        return array_map(static fn(array $pair): array => [
            'user_id' => $pair[0]->stringId(),
            'percentage' => $pair[1],
        ], $pairs);
    }

    /**
     * Records a payment and moves the money in group_balances:
     * the payer owes less, the receiver is owed less.
     * (Phase 6's SettlementService implements the same rule.)
     */
    private function addSettlement(Group $group, User $payer, User $receiver, float $amount, string $note): void
    {
        Settlement::create([
            'group_id' => $group->stringId(),
            'paid_by' => $payer->stringId(),
            'paid_to' => $receiver->stringId(),
            'amount' => $amount,
            'note' => $note,
        ]);

        $this->adjustBalance($group->stringId(), $payer->stringId(), $amount);
        $this->adjustBalance($group->stringId(), $receiver->stringId(), -$amount);
    }

    private function adjustBalance(string $groupId, string $userId, float $delta): void
    {
        $balance = GroupBalance::where('group_id', $groupId)->where('user_id', $userId)->first();

        if ($balance === null) {
            GroupBalance::create([
                'group_id' => $groupId,
                'user_id' => $userId,
                'net_balance' => $delta,
            ]);

            return;
        }

        $balance->net_balance = round((float) $balance->net_balance + $delta, 2);
        $balance->save();
    }

    /**
     * Makes the seeder repeatable. Collections are emptied with a raw
     * deleteMany so the indexes created by the migrations stay in place.
     */
    private function resetCollections(): void
    {
        $connection = DB::connection('mongodb');

        foreach (['tokens', 'group_balances', 'settlements', 'expenses', 'groups', 'users'] as $collection) {
            $connection->getCollection($collection)->deleteMany([]);
        }
    }
}