<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Setting;
use App\Notifications\Tax\PaymentDueReminder;
use App\Services\Tax\PaymentRegistry;
use Illuminate\Console\Command;

class TaxSendPaymentReminders extends Command
{
    protected $signature = 'tax:send-reminders {--days= : Override the lead time from Tax Settings}';

    protected $description = 'Email linked customer-portal users about tax payments falling due in N days';

    /**
     * Runs daily and only targets payments due exactly N days from today,
     * so each payment gets one reminder without tracking a "sent" flag.
     */
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? Setting::get('payment_reminder_days', 7));

        if ($days <= 0) {
            $this->info('Payment reminders are disabled.');

            return self::SUCCESS;
        }

        $dueDate = today()->addDays($days);
        $sent = 0;

        foreach (PaymentRegistry::MODELS as $type => $model) {
            $payments = $model::with('customer.users')
                ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Overdue])
                ->whereDate('due_date', $dueDate)
                ->get();

            foreach ($payments as $payment) {
                // Nothing left to pay, or the customer already reported paying it.
                if ((float) $payment->amount - (float) $payment->paid_amount <= 0 || $payment->reported_paid_at !== null) {
                    continue;
                }

                foreach ($payment->customer?->users ?? [] as $user) {
                    // Customers switch reminder emails off on their account page.
                    if (! $user->hasVerifiedEmail() || $user->tax_reminders_enabled === false) {
                        continue;
                    }

                    $user->notify(new PaymentDueReminder($type, $payment));
                    $sent++;
                }
            }
        }

        $this->info("Sent {$sent} reminder(s) for payments due {$dueDate->toDateString()}.");

        return self::SUCCESS;
    }
}
