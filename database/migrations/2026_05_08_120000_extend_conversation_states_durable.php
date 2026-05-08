<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_states', function (Blueprint $table): void {
            $table->dropColumn('pending_action');
        });

        Schema::table('conversation_states', function (Blueprint $table): void {
            $table->json('pending_action')->nullable()->after('selected_package');
            $table->string('location', 128)->nullable()->after('event_date_iso');
            $table->decimal('budget_min', 12, 2)->nullable()->after('location');
            $table->decimal('budget_max', 12, 2)->nullable()->after('budget_min');
            $table->string('event_date_raw', 64)->nullable()->after('event_date_iso');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_states', function (Blueprint $table): void {
            $table->dropColumn([
                'pending_action',
                'location',
                'budget_min',
                'budget_max',
                'event_date_raw',
            ]);
        });

        Schema::table('conversation_states', function (Blueprint $table): void {
            $table->string('pending_action', 64)->nullable()->after('selected_package');
        });
    }
};
