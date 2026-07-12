<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_ticket_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key', 100);
            $table->unsignedBigInteger('source_ticket_id');
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('source_hash', 64);
            $table->timestamps();
            $table->unique(['source_key', 'source_ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_ticket_imports');
    }
};
