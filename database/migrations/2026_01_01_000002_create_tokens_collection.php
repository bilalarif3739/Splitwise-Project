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
        Schema::create('tokens', function (Blueprint $collection) {
            // Every authenticated request resolves its token with this index,
            // and uniqueness guarantees a token can never identify two sessions.
            $collection->unique('api_token', options: ['name' => 'tokens_api_token_unique']);
        });
    }

    public function down(): void
    {
        Schema::drop('tokens');
    }
};