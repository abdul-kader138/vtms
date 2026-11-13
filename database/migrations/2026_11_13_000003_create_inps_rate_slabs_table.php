<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inps_rate_slabs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('slab_year');
            $table->enum('customer_type', ['gestione_separata', 'commercianti', 'artigiani'])->nullable();
            $table->decimal('income_from', 15, 2);
            $table->decimal('income_to', 15, 2)->nullable();
            $table->decimal('inps_rate', 5, 2);
            $table->decimal('fixed_amount', 15, 2)->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['slab_year', 'customer_type', 'income_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inps_rate_slabs');
    }
};
