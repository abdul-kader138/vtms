<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foreign_service_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->date('purchase_date');
            $table->string('provider', 255);
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 15, 2);
            $table->unsignedSmallInteger('tax_year');
            $table->timestamps();

            $table->index(['customer_id', 'tax_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foreign_service_purchases');
    }
};
