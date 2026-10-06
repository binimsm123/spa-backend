<?php

namespace Tests;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $database = $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: null;

        if ($database !== 'testing') {
            throw new \LogicException('Tests must use the testing database.');
        }

        parent::setUp();
    }

    /**
     * Roles/permissions are required by almost every flow; seed them for
     * each test (RefreshDatabase handles the refresh).
     */
    protected bool $seed = true;

    protected string $seeder = RolePermissionSeeder::class;
}
