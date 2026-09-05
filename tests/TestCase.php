<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but a throwaway database.
     *
     * phpunit.xml points the suite at sqlite `:memory:`, and for a long time
     * that was taken as sufficient. It is not. A cached configuration file -
     * `php artisan config:cache`, which this project now offers as a button so
     * it can be run on a server with no terminal - is loaded *instead of* the
     * environment, so bootstrap/cache/config.php silently overrides every
     * <env> in phpunit.xml. The suite then points at the real MySQL database,
     * and the first test using RefreshDatabase runs `migrate:fresh` on it.
     *
     * That is not a hypothetical: it happened here, and it emptied a working
     * development database. Nothing about the run looked wrong - the tests
     * passed, because they had a perfectly good database to work in. It was
     * simply the wrong one.
     *
     * So the check is made on every test, against the connection actually in
     * force at that moment rather than against what any file says it should
     * be, and the run is stopped before a single migration is applied.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->guardAgainstRunningOnARealDatabase();
    }

    private function guardAgainstRunningOnARealDatabase(): void
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $database = config("database.connections.{$connection}.database");

        // The only shapes a test database may take: sqlite in memory, or a
        // sqlite file. Anything else is somebody's real data.
        $isThrowaway = $driver === 'sqlite'
            && ($database === ':memory:' || str_contains((string) $database, 'test'));

        if ($isThrowaway) {
            return;
        }

        $cachedConfig = file_exists(base_path('bootstrap/cache/config.php'))
            ? ' A cached config file is present at bootstrap/cache/config.php, which overrides phpunit.xml'
                . ' - delete it, or run `php artisan config:clear`, and try again.'
            : '';

        throw new RuntimeException(
            'Refusing to run the test suite against the [' . $connection . '] connection'
            . ' (driver ' . $driver . ', database ' . $database . ').'
            . ' Tests use RefreshDatabase, which would drop every table in it.'
            . $cachedConfig
        );
    }
}
