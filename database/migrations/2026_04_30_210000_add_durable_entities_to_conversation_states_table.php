<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_states', function (Blueprint $table): void {
            $table->string('customer_name', 128)->nullable()->after('active_goal');
            $table->string('event_type', 64)->nullable()->after('customer_name');
            $table->string('service_interest', 128)->nullable()->after('event_type');
            $table->string('package_interest', 191)->nullable()->after('service_interest');
            $table->string('selected_package', 191)->nullable()->after('package_interest');
            $table->string('pending_action', 64)->nullable()->after('selected_package');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_states', function (Blueprint $table): void {
            $table->dropColumn([
                'customer_name',
                'event_type',
                'service_interest',
                'package_interest',
                'selected_package',
                'pending_action',
            ]);
        });
    }
};
