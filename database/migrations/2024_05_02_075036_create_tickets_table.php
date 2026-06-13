<?php

use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number');
            $table->tinyInteger('status')->default(TicketStatus::OPENED->value);
            $table->tinyInteger('importance')->default(TicketImportanceStatus::GREEN->value);
            $table->text('description');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onUpdate('cascade')->onDelete('cascade');
            $table->foreignId('company_id')->nullable()->constrained('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->onUpdate('cascade')->onDelete('cascade');
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
        Schema::dropIfExists('tickets');
    }
};
