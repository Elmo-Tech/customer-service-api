<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('due_at')->nullable()->after('real_closed_at')->index();
            $table->timestamp('escalated_at')->nullable()->after('due_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['escalated_at']);
            $table->dropIndex(['due_at']);
            $table->dropColumn(['due_at', 'escalated_at']);
        });
    }
};
