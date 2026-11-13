<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fattura_tra_privati_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            // Explicit name: the generated one is 71 chars, over MySQL's 64 limit.
            $table->foreignId('fattura_tra_privati_calculation_id')->constrained('fattura_tra_privati_calculations', 'id', 'ftp_payments_calculation_id_foreign')->cascadeOnDelete();
            $table->unsignedSmallInteger('payment_year');
            $table->enum('status', ['pending', 'paid', 'overdue', 'cancelled'])->default('pending');
            $table->decimal('amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->date('paid_date')->nullable();
            $table->string('uploaded_pdf')->nullable();
            $table->date('due_date');
            $table->timestamps();

            $table->unique(['customer_id', 'payment_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fattura_tra_privati_payments');
    }
};
