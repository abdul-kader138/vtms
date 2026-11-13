<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diritto_annuale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('diritto_annuale_bracket_id')->nullable()->constrained('diritto_annuale_brackets')->nullOnDelete();
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
        Schema::dropIfExists('diritto_annuale_payments');
    }
};
