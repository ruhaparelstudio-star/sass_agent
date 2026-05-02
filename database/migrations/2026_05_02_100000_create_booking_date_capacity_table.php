<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_date_capacity', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('booking_date');
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'booking_date']);
            $table->index(['tenant_id', 'booking_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_date_capacity');
    }
};
