<?php

use App\Traits\CreatedUpdatedByMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use CreatedUpdatedByMigration;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ticket_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('ticket_id')->index();
            $table->tinyInteger('status')->default(3);
            $table->text('text')->nullable();

            $table->foreign('ticket_id')
                ->references('id')
                ->on('tickets')
                ->onDelete('cascade')
                ->onUpdate('cascade');

            $this->CreatedUpdatedByRelationship($table);

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_logs');
    }
};
