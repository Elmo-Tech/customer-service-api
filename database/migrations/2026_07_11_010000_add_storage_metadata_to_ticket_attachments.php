<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->string('storage_disk', 16)->default('public')->after('path');
            $table->string('original_name')->nullable()->after('storage_disk');
            $table->unsignedBigInteger('file_size')->nullable()->after('original_name');
            $table->string('checksum', 64)->nullable()->after('file_size');
            $table->index('storage_disk');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->dropIndex(['storage_disk']);
            $table->dropColumn(['storage_disk', 'original_name', 'file_size', 'checksum']);
        });
    }
};
