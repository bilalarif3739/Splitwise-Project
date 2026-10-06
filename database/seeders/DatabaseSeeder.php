<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // One command seeds everything (section 37).
        $this->call(DemoDataSeeder::class);
    }
}
