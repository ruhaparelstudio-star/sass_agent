<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('value_string')->nullable();
            $table->integer('value_int')->nullable();
            $table->boolean('value_bool')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};

