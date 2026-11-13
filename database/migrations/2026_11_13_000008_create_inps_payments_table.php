<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inps_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('inps_calculation_id')->constrained('inps_calculations')->cascadeOnDelete();
            $table->unsignedTinyInteger('installment_number');
            $table->unsignedSmallInteger('payment_year');
            $table->string('payment_method', 100)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'paid', 'overdue', 'cancelled'])->default('pending');
            $table->decimal('amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->date('paid_date')->nullable();
            $table->string('uploaded_pdf')->nullable();
            $table->date('due_date');
            $table->timestamps();

            $table->unique(['customer_id', 'payment_year', 'installment_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inps_payments');
    }
};
