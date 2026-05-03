<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_states', function (Blueprint $table) {
            $table->string('event_date_iso')->nullable()->after('selected_package');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_states', function (Blueprint $table) {
            $table->dropColumn('event_date_iso');
        });
    }
};
