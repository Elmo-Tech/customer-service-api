<?php

namespace Tests\Feature\Tenancy;

use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\Company\CustomerStatus;
use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
use App\Enums\User\AccountType;
use App\Enums\User\UserStatus;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LegacyTicketImportTest extends TestCase
{
    use RefreshDatabase;

    private User $internal;

    private Company $company;

    private Branch $branch;

    private Customer $customer;

    private string $mappingPath;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        config(['database.connections.legacy' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('legacy');
        $this->createLegacySchema();
        $this->internal = $this->user('importer', AccountType::INTERNAL);
        $this->company = Company::create([
            'name' => 'Imported Company', 'status' => CompanyStatus::ACTIVE->value, 'uses_branches' => true,
        ]);
        $this->branch = $this->company->branches()->create([
            'name' => 'Imported Branch', 'status' => BranchStatus::ACTIVE->value,
        ]);
        $this->customer = Customer::create([
            'firstname' => 'Legacy', 'lastname' => 'Customer', 'pin' => 'preserved',
            'status' => CustomerStatus::ACTIVE->value, 'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);
        $this->mappingPath = tempnam(sys_get_temp_dir(), 'legacy-ticket-map-');
        file_put_contents($this->mappingPath, json_encode($this->mapping(), JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        DB::purge('legacy');
        if (is_file($this->mappingPath)) {
            unlink($this->mappingPath);
        }
        parent::tearDown();
    }

    public function test_dry_run_execute_and_repeat_are_non_destructive_and_idempotent(): void
    {
        $this->legacyTicket();
        Storage::disk('public')->put('tickets/legacy.pdf', 'legacy attachment');

        $this->artisan('tickets:audit-legacy')->assertSuccessful();
        Storage::disk('local')->assertExists('tenancy/legacy-ticket-audit.json');
        $audit = json_decode(Storage::disk('local')->get('tenancy/legacy-ticket-audit.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([10], $audit['source']['companyIds']);
        $this->assertArrayHasKey('10', $audit['mappingSkeleton']['companies']);
        $this->assertStringNotContainsString('Imported historical request', json_encode($audit, JSON_THROW_ON_ERROR));

        $this->artisan('tickets:import-legacy', ['mapping' => $this->mappingPath])->assertSuccessful();
        $this->assertDatabaseMissing('tickets', ['ticket_number' => 'LEG-100']);
        $this->artisan('tickets:import-legacy', [
            'mapping' => $this->mappingPath, '--execute' => true,
        ])->assertFailed();
        $this->assertDatabaseMissing('tickets', ['ticket_number' => 'LEG-100']);

        $this->artisan('tickets:import-legacy', [
            'mapping' => $this->mappingPath, '--execute' => true, '--confirm' => true,
        ])->assertSuccessful();
        $this->assertDatabaseHas('tickets', [
            'ticket_number' => 'LEG-100', 'company_id' => $this->company->id,
            'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
        ]);
        $this->assertDatabaseCount('ticket_attachments', 1);
        $this->assertDatabaseCount('ticket_logs', 1);
        $this->assertDatabaseCount('legacy_ticket_imports', 1);

        $this->artisan('tickets:import-legacy', [
            'mapping' => $this->mappingPath, '--execute' => true, '--confirm' => true,
        ])->assertSuccessful();
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('legacy_ticket_imports', 1);
    }

    public function test_cross_company_mapping_blocks_the_entire_import(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other', 'status' => CompanyStatus::ACTIVE->value, 'uses_branches' => true,
        ]);
        $otherBranch = $otherCompany->branches()->create(['name' => 'Other Branch', 'status' => 1]);
        $mapping = $this->mapping();
        $mapping['branches']['20'] = $otherBranch->id;
        file_put_contents($this->mappingPath, json_encode($mapping, JSON_THROW_ON_ERROR));
        $this->legacyTicket(false);

        $this->artisan('tickets:import-legacy', [
            'mapping' => $this->mappingPath, '--execute' => true, '--confirm' => true,
        ])->assertFailed();
        $this->assertDatabaseMissing('tickets', ['ticket_number' => 'LEG-100']);
        $this->assertDatabaseCount('legacy_ticket_imports', 0);
    }

    private function legacyTicket(bool $withChildren = true): void
    {
        $legacy = DB::connection('legacy');
        $timestamps = ['created_at' => '2025-01-01 08:00:00', 'updated_at' => '2025-01-02 09:00:00'];
        $legacy->table('tickets')->insert([
            'id' => 100, 'ticket_number' => 'LEG-100', 'status' => 0, 'importance' => 1,
            'description' => 'Imported historical request', 'customer_id' => 30,
            'company_id' => 10, 'branch_id' => 20, 'created_by' => 40, 'updated_by' => 40,
            'deleted_at' => null, ...$timestamps,
        ]);
        if (! $withChildren) {
            return;
        }
        $legacy->table('ticket_attachments')->insert([
            'id' => 200, 'path' => 'tickets/legacy.pdf', 'ticket_id' => 100,
            'created_by' => 40, 'updated_by' => 40, 'deleted_at' => null, ...$timestamps,
        ]);
        $legacy->table('ticket_logs')->insert([
            'id' => 300, 'ticket_id' => 100, 'status' => 3, 'text' => 'Legacy reply',
            'created_by' => 40, 'updated_by' => 40, 'deleted_at' => null, ...$timestamps,
        ]);
    }

    private function mapping(): array
    {
        return [
            'companies' => ['10' => $this->company->id],
            'branches' => ['20' => $this->branch->id],
            'customers' => ['30' => $this->customer->id],
            'users' => ['40' => $this->internal->id],
            'tags' => [],
            'statuses' => ['0' => TicketStatus::OPENED->value],
            'importances' => ['1' => TicketImportanceStatus::RED->value],
        ];
    }

    private function user(string $username, AccountType $accountType): User
    {
        return User::create([
            'name' => $username, 'username' => $username, 'email' => "{$username}@example.com",
            'password' => 'Password1!', 'status' => UserStatus::ACTIVE->value, 'account_type' => $accountType,
        ]);
    }

    private function createLegacySchema(): void
    {
        $schema = Schema::connection('legacy');
        $schema->create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('ticket_number');
            $table->integer('status');
            $table->integer('importance');
            $table->text('description');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $this->auditColumns($table);
        });
        $schema->create('ticket_attachments', function (Blueprint $table): void {
            $table->id();
            $table->string('path');
            $table->unsignedBigInteger('ticket_id');
            $this->auditColumns($table);
        });
        $schema->create('ticket_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->integer('status');
            $table->text('text')->nullable();
            $this->auditColumns($table);
        });
    }

    private function auditColumns(Blueprint $table): void
    {
        $table->unsignedBigInteger('created_by')->nullable();
        $table->unsignedBigInteger('updated_by')->nullable();
        $table->softDeletes();
        $table->timestamps();
    }
}
