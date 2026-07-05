<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasRealClosedAt = Schema::hasColumn('tickets', 'real_closed_at');
        $hasToken = Schema::hasColumn('tickets', 'token');

        Schema::table('tickets', function (Blueprint $table) use ($hasRealClosedAt, $hasToken) {
            if (!$hasRealClosedAt) {
                $table->timestamp('real_closed_at')->nullable()->after('closed_at');
            }

            if (!$hasToken) {
                $table->string('token')->nullable()->after('real_closed_at');
            }
        });

        $hasLogToken = Schema::hasColumn('ticket_logs', 'token');

        Schema::table('ticket_logs', function (Blueprint $table) use ($hasLogToken) {
            if (!$hasLogToken) {
                $table->string('token')->nullable()->after('text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ticket_logs', function (Blueprint $table) {
            if (Schema::hasColumn('ticket_logs', 'token')) {
                $table->dropColumn('token');
            }
        });

        Schema::table('tickets', function (Blueprint $table) {
            if (Schema::hasColumn('tickets', 'token')) {
                $table->dropColumn('token');
            }

            if (Schema::hasColumn('tickets', 'real_closed_at')) {
                $table->dropColumn('real_closed_at');
            }
        });
    }
};
