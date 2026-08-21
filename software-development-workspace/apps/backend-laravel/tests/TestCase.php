<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but the test database.
     *
     * `RefreshDatabase` drops every table before it starts. That is correct for
     * a test database and catastrophic for any other, and the only thing
     * standing between the two used to be an `<env>` entry in phpunit.xml —
     * which PHPUnit will not apply over a variable the shell has already set.
     *
     * So a `DB_DATABASE=hrms` exported to run a migration, left in the shell,
     * silently redirected the whole suite at production and dropped 60,000
     * rows. Adding `force="true"` to the XML did not stop it happening a
     * second time.
     *
     * This does. It runs before any test touches a connection, it cannot be
     * overridden by the environment, and it fails loudly with the name of the
     * database it was about to destroy.
     */
    protected function setUp(): void
    {
        /*
         * Every source is checked, and one disagreement is enough.
         *
         * This has to run before the application boots — by the time config
         * exists, RefreshDatabase has already dropped the tables — so it reads
         * the environment directly. The catch is that there are three of them
         * and they do not agree: PHPUnit's `force` rewrites what `getenv()`
         * returns, while a variable exported in the shell also lands in
         * `$_SERVER`, and `$_SERVER` is what Laravel resolves the connection
         * from. Checking the first source that answers is how a guard reads
         * "hrms_test" while the suite quietly connects to production.
         */
        $expected = 'hrms_test';
        $safe     = [$expected, ':memory:'];

        foreach (['getenv' => getenv('DB_DATABASE'),
                  '$_ENV' => $_ENV['DB_DATABASE'] ?? null,
                  '$_SERVER' => $_SERVER['DB_DATABASE'] ?? null] as $where => $value) {
            if ($value === false || $value === null || in_array($value, $safe, true)) {
                continue;
            }

            throw new RuntimeException(
                "Refusing to run: {$where} names the database '{$value}'. The suite drops "
                . "every table before it starts, and only '{$expected}' is safe to do that "
                . 'to. Something is pointing the connection elsewhere — most likely a '
                . 'DB_DATABASE left exported in this shell after a migration.'
            );
        }

        parent::setUp();
    }
}
