<?php

declare(strict_types=1);

namespace Tests;

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
}