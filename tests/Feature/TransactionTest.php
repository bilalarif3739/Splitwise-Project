<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Expense;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Section 19: the deployment this runs against is a replica set, so
 * multi-document transactions really commit - and a failure mid-write leaves
 * nothing behind.
 */
final class TransactionTest extends TestCase
{
    public function test_a_failed_transaction_rolls_back_every_write(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $groupId = $this->makeGroup($owner);

        $threw = false;

        try {
            DB::connection('mongodb')->transaction(function () use ($groupId, $owner): void {
                Expense::create([
                    'group_id'     => $groupId,
                    'description'  => 'Rolled back',
                    'amount'       => 100,
                    'paid_by'      => $owner['id'],
                    'split_type'   => 'equal',
                    'participants' => [['user_id' => $owner['id'], 'amount' => 100]],
                    'created_by'   => $owner['id'],
                ]);

                throw new RuntimeException('Forced failure to prove the rollback.');
            });
        } catch (RuntimeException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'The forced failure should have propagated.');
        $this->assertSame(0, Expense::where('group_id', $groupId)->count());
    }
}