<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Roll one migration back and forward again, over rows the test already made.
     *
     * RefreshDatabase migrates an empty database, so a backfill never sees test
     * data. This lets a test build the "before" state and then assert what the
     * real migration makes of it.
     */
    protected function rerunMigration(string $name): void
    {
        $migration = require database_path("migrations/{$name}.php");

        $migration->down();
        $migration->up();
    }
}
