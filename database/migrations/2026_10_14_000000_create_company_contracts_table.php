<?php

use App\Traits\CreatedUpdatedByMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use CreatedUpdatedByMigration;

    public function up(): void
    {
        Schema::create('company_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->date('start_date');
            $table->date('end_date');
            $this->createdUpdatedByRelationship($table);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'deleted_at', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_contracts');
    }
};
