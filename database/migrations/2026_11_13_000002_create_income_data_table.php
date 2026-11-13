<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->enum('source', ['sdi_fattura', 'privati_device']);
            $table->date('transaction_date');
            $table->decimal('sales_amount', 15, 2);
            $table->decimal('taxable_sales', 15, 2);
            $table->decimal('sale_taxes', 15, 2)->default(0);
            $table->string('reference_no', 100)->nullable();
            $table->string('external_id', 100)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('tax_year');
            $table->timestamps();

            $table->index(['customer_id', 'tax_year']);
            // SQLite (and MySQL/Postgres) treat NULL as distinct in unique indexes,
            // so rows with a null external_id never collide — only genuine
            // duplicate (source, external_id) pairs are rejected.
            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_data');
    }
};
