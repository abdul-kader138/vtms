<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's own confirmation that a month's register is final — purely
 * a marker for the Prediction feature's year-over-year math
 * (IncomePredictionService). Never locks or filters income_data, and the
 * real tax/INPS calculation engine (TaxCalculationOrchestrator) never
 * reads this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->timestamp('closed_at');
            $table->timestamps();

            $table->unique(['customer_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_closings');
    }
};
