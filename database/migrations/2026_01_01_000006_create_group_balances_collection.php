<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('group_balances', function (Blueprint $collection) {
            // One balance row per (group, user) pair: the unique compound index
            // is what makes the transactional upsert safe under concurrency.
            $collection->unique(
                ['group_id' => 1, 'user_id' => 1],
                options: ['name' => 'group_balances_group_user_unique'],
            );
        });
    }

    public function down(): void
    {
        Schema::drop('group_balances');
    }
};