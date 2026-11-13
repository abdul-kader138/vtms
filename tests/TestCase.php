<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests refresh the database. Refuse to start (before any trait
     * touches the database) if the connection is
     * anything but in-memory SQLite, whatever the shell environment says.
     */
    protected function setUpTraits()
    {
        $default = config('database.default');
        $connection = config("database.connections.{$default}");

        if (($connection['driver'] ?? null) !== 'sqlite' || ($connection['database'] ?? null) !== ':memory:') {
            throw new RuntimeException("Tests must run on in-memory SQLite, not [{$default}: ".($connection['database'] ?? '?').'].');
        }

        // RefreshDatabase etc. run inside here — only after the check.
        return parent::setUpTraits();
    }
}
