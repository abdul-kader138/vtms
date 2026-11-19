<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'tax_calculations',
        'inps_calculations',
        'inail_calculations',
        'fattura_tra_privati_calculations',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->boolean('is_manually_adjusted')->default(false);
                $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('adjusted_at')->nullable();
                $table->text('adjustment_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('adjusted_by');
                $table->dropColumn(['is_manually_adjusted', 'adjusted_at', 'adjustment_reason']);
            });
        }
    }
};
