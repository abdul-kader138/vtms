<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fattura_tra_privati_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedSmallInteger('tax_year');
            $table->unsignedInteger('total_invoices');
            $table->decimal('total_sales_amount', 15, 2);
            $table->decimal('payment_per_invoice', 10, 2);
            $table->decimal('total_payment_amount', 15, 2);
            $table->decimal('minimum_invoice_amount', 10, 2);
            $table->timestamps();

            $table->unique(['customer_id', 'tax_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fattura_tra_privati_calculations');
    }
};
