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
        Schema::create('groups', function (Blueprint $collection) {
            // Multikey index: MongoDB indexes each element of the array, which
            // makes "every group this user belongs to" a covered lookup.
            $collection->index('member_ids', options: ['name' => 'groups_member_ids_idx']);
        });
    }

    public function down(): void
    {
        Schema::drop('groups');
    }
};