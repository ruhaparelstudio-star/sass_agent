<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('lead_profile_id')->constrained('lead_profiles')->cascadeOnDelete();
            $table->integer('score_value')->default(0);
            $table->string('score_label', 64)->default('unscored');
            $table->timestamps();

            $table->unique(['tenant_id', 'lead_profile_id']);
            $table->index('lead_profile_id');
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_scores');
    }
};
