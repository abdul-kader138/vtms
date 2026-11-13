<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Backups;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BackupsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShieldSeeder::class);
        Storage::fake('backups');
        config(['backup.backup.destination.disks' => ['backups']]);
    }

    private function admin(): User
    {
        return User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail();
    }

    public function test_lists_backups_and_downloads_one(): void
    {
        $name = config('backup.backup.name');
        Storage::disk('backups')->put("{$name}/2026-11-13-01-30-00.zip", 'zip-bytes');

        $this->actingAs($this->admin());

        Livewire::test(Backups::class)
            ->assertOk()
            ->assertSee('2026-11-13-01-30-00.zip')
            ->assertSee(__('Backups are up to date.'))
            ->assertSee(__('Backups are only stored on this server. Add an off-site disk (for example S3) to BACKUP_DISKS so a backup survives losing the server.'))
            ->call('download', 'backups', "{$name}/2026-11-13-01-30-00.zip")
            ->assertFileDownloaded('2026-11-13-01-30-00.zip');
    }

    public function test_download_only_serves_listed_backups(): void
    {
        Storage::disk('backups')->put('secret.txt', 'x');
        $this->actingAs($this->admin());

        Livewire::test(Backups::class)->call('download', 'backups', 'secret.txt')->assertStatus(404);
        Livewire::test(Backups::class)->call('download', 'local', 'anything.zip')->assertStatus(404);
    }

    public function test_warns_when_there_is_no_backup(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Backups::class)->assertSee('There is no backup yet', false);
    }

    public function test_only_super_admins_can_open_it(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']));

        $this->actingAs($staff)->get(Backups::getUrl())->assertForbidden();
    }
}
