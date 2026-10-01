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
        Schema::create('users', function (Blueprint $collection) {
            // Login and the duplicate-email check both look users up by email.
            $collection->unique('email', options: ['name' => 'users_email_unique']);
        });
    }

    public function down(): void
    {
        Schema::drop('users');
    }
};