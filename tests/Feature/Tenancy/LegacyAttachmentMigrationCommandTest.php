<?php

namespace Tests\Feature\Tenancy;

use App\Enums\Company\CompanyStatus;
use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
use App\Enums\User\AccountType;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\Tiket\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LegacyAttachmentMigrationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_writes_nothing_and_execute_is_verified_idempotent_and_non_destructive(): void
    {
        Storage::fake('public');
        Storage::fake('ticket_attachments');
        $ticket = $this->ticket();
        $migratable = $ticket->attachments()->create(['path' => 'tickets/good.txt']);
        $missing = $ticket->attachments()->create(['path' => 'tickets/missing.txt']);
        $mismatched = $ticket->attachments()->create(['path' => 'tickets/mismatched.txt']);
        Storage::disk('public')->put($migratable->path, 'verified legacy file');
        Storage::disk('public')->put($mismatched->path, 'legacy source');
        Storage::disk('ticket_attachments')->put($mismatched->path, 'different destination');

        $this->artisan('attachments:migrate-private')->assertFailed();
        $this->assertSame('public', $migratable->fresh()->storage_disk);
        Storage::disk('ticket_attachments')->assertMissing($migratable->path);

        $this->artisan('attachments:migrate-private', ['--execute' => true])->assertFailed();
        $migratable->refresh();
        $this->assertSame('private', $migratable->storage_disk);
        $this->assertSame(strlen('verified legacy file'), $migratable->file_size);
        $this->assertSame(hash('sha256', 'verified legacy file'), $migratable->checksum);
        Storage::disk('public')->assertExists($migratable->path);
        Storage::disk('ticket_attachments')->assertExists($migratable->path);
        $this->assertSame('public', $missing->fresh()->storage_disk);
        $this->assertSame('public', $mismatched->fresh()->storage_disk);
        $this->assertSame('different destination', Storage::disk('ticket_attachments')->get($mismatched->path));

        $this->artisan('attachments:migrate-private', ['--execute' => true])->assertFailed();
        $this->assertSame('private', $migratable->fresh()->storage_disk);
        Storage::disk('public')->assertExists($migratable->path);
    }

    private function ticket(): Ticket
    {
        User::create([
            'id' => 1,
            'name' => 'Audit',
            'username' => 'audit',
            'email' => 'audit@example.com',
            'password' => 'Password1!',
            'status' => 1,
            'account_type' => AccountType::INTERNAL,
        ]);
        $company = Company::create([
            'name' => 'Migration Company',
            'status' => CompanyStatus::ACTIVE->value,
            'uses_branches' => false,
        ]);
        $customer = Customer::create([
            'firstname' => 'Migration',
            'lastname' => 'Customer',
            'pin' => '1234',
            'company_id' => $company->id,
            'status' => 1,
        ]);

        return Ticket::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'status' => TicketStatus::OPENED->value,
            'importance' => TicketImportanceStatus::GREEN->value,
            'description' => 'Legacy migration ticket',
        ]);
    }
}
