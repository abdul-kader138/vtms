<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer self-service in the portal:
 *  - users.calendar_token: secret for the personal deadlines calendar feed
 *    (/calendar/{token}.ics), reset from the account page;
 *  - users.tax_reminders_enabled: the customer's own on/off for reminder
 *    emails (tax:send-reminders);
 *  - reported_paid_*: on every payment table, what the customer said they
 *    paid ("I've paid", with the receipt in uploaded_pdf) — waiting for the
 *    office to confirm, which records the real payment as before.
 */
return new class extends Migration
{
    private const PAYMENT_TABLES = ['tax_payments', 'inps_payments', 'inail_payments', 'diritto_annuale_payments', 'fattura_tra_privati_payments'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('calendar_token', 64)->nullable()->unique()->after('device_lock_exempt');
            $table->boolean('tax_reminders_enabled')->default(true)->after('calendar_token');
        });

        foreach (self::PAYMENT_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('reported_paid_at')->nullable();
                $table->date('reported_paid_date')->nullable();
                $table->decimal('reported_paid_amount', 15, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::PAYMENT_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['reported_paid_at', 'reported_paid_date', 'reported_paid_amount']);
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['calendar_token']);
            $table->dropColumn(['calendar_token', 'tax_reminders_enabled']);
        });
    }
};
