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
        Schema::create('expenses', function (Blueprint $collection) {
            // Compound index: serves the paginated, date-sorted group expense
            // history (group_id equality + created_at sort/date filters).
            $collection->index(
                ['group_id' => 1, 'created_at' => -1],
                options: ['name' => 'expenses_group_created_idx'],
            );

            // Supports the ?payer= filter on expense history.
            $collection->index('paid_by', options: ['name' => 'expenses_paid_by_idx']);

            // Supports the ?split_type= filter on expense history.
            $collection->index('split_type', options: ['name' => 'expenses_split_type_idx']);
        });
    }

    public function down(): void
    {
        Schema::drop('expenses');
    }
}; 