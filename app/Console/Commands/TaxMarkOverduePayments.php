<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Services\Tax\PaymentRegistry;
use Illuminate\Console\Command;

class TaxMarkOverduePayments extends Command
{
    protected $signature = 'tax:mark-overdue';

    protected $description = 'Flip pending tax/INPS/INAIL/Diritto Annuale/stamp-duty payments past their due date to overdue';

    public function handle(): int
    {
        $total = 0;

        foreach (PaymentRegistry::MODELS as $type => $model) {
            $count = $model::where('status', PaymentStatus::Pending)
                ->whereDate('due_date', '<', today())
                ->update(['status' => PaymentStatus::Overdue, 'updated_at' => now()]);

            $total += $count;
            $this->line("{$type}: {$count}");
        }

        $this->info("Marked {$total} payment(s) overdue.");

        return self::SUCCESS;
    }
}
