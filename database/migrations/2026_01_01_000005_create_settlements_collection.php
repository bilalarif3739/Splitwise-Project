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
        Schema::create('settlements', function (Blueprint $collection) {
            // Balance calculation reads every settlement of a group, and the
            // settlement history endpoint paginates them newest first.
            $collection->index(
                ['group_id' => 1, 'created_at' => -1],
                options: ['name' => 'settlements_group_created_idx'],
            );
        });
    }

    public function down(): void
    {
        Schema::drop('settlements');
    }
};