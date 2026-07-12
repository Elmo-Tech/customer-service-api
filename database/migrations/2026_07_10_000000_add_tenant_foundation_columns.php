<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addCompanyFields();
        $this->addUserFields();
        $this->addCustomerFields();
        $this->addTicketFields();
    }

    public function down(): void
    {
        $this->dropTicketFields();
        $this->dropCustomerFields();
        $this->dropUserFields();

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('uses_branches');
        });
    }

    private function addCompanyFields(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('uses_branches')->default(true)->after('status');
        });
    }

    private function addUserFields(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_type')->nullable()->after('status')->index();
            $table->foreignId('company_id')->nullable()->after('account_type')
                ->constrained('companies')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('company_id')
                ->constrained('branches')->nullOnDelete();
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'branch_id', 'status']);
        });
    }

    private function addCustomerFields(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('company_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    private function addTicketFields(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('opened_by_user_id')->nullable()->after('customer_id')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->after('opened_by_user_id')
                ->constrained('users')->nullOnDelete();
            $table->index(['company_id', 'branch_id', 'status']);
            $table->index(['company_id', 'opened_by_user_id', 'status']);
            $table->index(['company_id', 'assigned_to_user_id', 'status']);
        });
    }

    private function dropTicketFields(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'branch_id', 'status']);
            $table->dropIndex(['company_id', 'opened_by_user_id', 'status']);
            $table->dropIndex(['company_id', 'assigned_to_user_id', 'status']);
            $this->dropForeignId($table, 'assigned_to_user_id');
            $this->dropForeignId($table, 'opened_by_user_id');
        });
    }

    private function dropCustomerFields(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $this->dropForeignId($table, 'user_id');
        });
    }

    private function dropUserFields(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'branch_id', 'status']);
            $table->dropIndex(['company_id', 'status']);
            $this->dropForeignId($table, 'branch_id');
            $this->dropForeignId($table, 'company_id');
            $table->dropIndex(['account_type']);
            $table->dropColumn('account_type');
        });
    }

    private function dropForeignId(Blueprint $table, string $column): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $table->dropColumn($column);

            return;
        }

        $table->dropConstrainedForeignId($column);
    }
};
