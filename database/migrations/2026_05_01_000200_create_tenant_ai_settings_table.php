<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->string('ai_tone', 50)->default('professional');
            $table->unsignedSmallInteger('reply_delay_seconds')->default(2);
            $table->boolean('followup_enabled')->default(true);
            $table->unsignedSmallInteger('followup_delay_hours')->default(24);
            $table->string('pricelist_mode', 20)->default('text_first');
            $table->string('pricelist_min_requirement', 20)->default('name_only');
            $table->boolean('pricelist_file_enabled')->default(true);
            $table->boolean('out_of_hours_auto_reply')->default(true);
            $table->text('out_of_hours_message')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ai_settings');
    }
};
