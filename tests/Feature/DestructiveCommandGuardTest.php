<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class DestructiveCommandGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::prohibitDestructiveCommands(false);
        parent::tearDown();
    }

    private function applyGuard(): void
    {
        $method = new ReflectionMethod(AppServiceProvider::class, 'guardDestructiveDatabaseCommands');
        $method->invoke($this->app->getProvider(AppServiceProvider::class));
    }

    public function test_wipe_and_fresh_are_refused_on_a_server_database(): void
    {
        // Unreachable host: if the guard ever failed, the command errors on
        // connect instead of touching anything real.
        config([
            'database.connections.guard_probe' => ['driver' => 'pgsql', 'host' => '203.0.113.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => 'x'],
            'database.default' => 'guard_probe',
        ]);
        $this->applyGuard();

        foreach (['db:wipe', 'migrate:fresh', 'migrate:reset', 'migrate:rollback'] as $command) {
            $this->artisan($command, ['--force' => true])
                ->expectsOutputToContain('prohibited')
                ->assertFailed();
        }
    }

    public function test_explicit_override_and_sqlite_are_allowed(): void
    {
        config(['database.connections.guard_probe' => ['driver' => 'pgsql'], 'database.default' => 'guard_probe', 'database.allow_destructive' => true]);
        $this->applyGuard();
        $this->assertFalse($this->isProhibited());

        config(['database.default' => 'sqlite', 'database.allow_destructive' => false]);
        $this->applyGuard();
        $this->assertFalse($this->isProhibited());
    }

    private function isProhibited(): bool
    {
        $property = new \ReflectionProperty(WipeCommand::class, 'prohibitedFromRunning');

        return (bool) $property->getValue();
    }
}
