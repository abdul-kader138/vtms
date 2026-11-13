<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diritto_annuale_brackets', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('bracket_year');
            $table->decimal('revenue_from', 15, 2);
            $table->decimal('revenue_to', 15, 2)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['bracket_year', 'revenue_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diritto_annuale_brackets');
    }
};
