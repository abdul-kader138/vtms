<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * Removes every customer seeded by DemoTaxDataSeeder — tagged with a
 * "DEMO%" VAT number — and the portal logins created for them, so a
 * production demo/walkthrough can be cleaned up afterwards.
 *
 * forceDelete() (not delete()): Customer uses SoftDeletes, and the
 * customer_id foreign keys on its income/tax/payment tables are
 * cascadeOnDelete — a soft delete would leave every row behind. Portal
 * logins are deleted explicitly first: users.customer_id is nullOnDelete,
 * so deleting the customer alone would leave a live login (password
 * "password") orphaned rather than removed.
 */
class DemoDataRemove extends Command
{
    use ConfirmableTrait;

    protected $signature = 'demo:remove {--dry-run : List what would be removed without deleting anything} {--force : Skip the production confirmation prompt}';

    protected $description = 'Remove demo customers (and their data) seeded by DemoTaxDataSeeder';

    public function handle(): int
    {
        $customers = Customer::withTrashed()->where('vat_no', 'like', 'DEMO%')->get();

        if ($customers->isEmpty()) {
            $this->info('No demo customers found.');

            return self::SUCCESS;
        }

        $this->table(['Customer', 'VAT number'], $customers->map(fn (Customer $c) => [$c->company ?: $c->name, $c->vat_no]));

        if ($this->option('dry-run')) {
            $this->info("{$customers->count()} demo customer(s) would be removed, along with every row that references them. Run without --dry-run to remove them.");

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        DB::transaction(function () use ($customers) {
            User::whereIn('customer_id', $customers->pluck('id'))->delete();
            $customers->each(fn (Customer $c) => $c->forceDelete());
        });

        $this->info("{$customers->count()} demo customer(s) and their data removed.");

        return self::SUCCESS;
    }
}
