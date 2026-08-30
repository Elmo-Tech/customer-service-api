<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('timeline_token')->nullable()->unique()->after('token');
        });

        DB::table('tickets')
            ->whereNull('timeline_token')
            ->orderBy('id')
            ->chunkById(100, function ($tickets): void {
                foreach ($tickets as $ticket) {
                    DB::table('tickets')
                        ->where('id', $ticket->id)
                        ->update(['timeline_token' => (string) Str::uuid()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['timeline_token']);
            $table->dropColumn('timeline_token');
        });
    }
};
