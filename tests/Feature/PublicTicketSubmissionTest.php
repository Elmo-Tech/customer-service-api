<?php

namespace Tests\Feature;

use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\Company\CustomerStatus;
use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicTicketSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('public');

        User::create([
            'id' => 1,
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);
        $this->company = Company::create([
            'name' => 'Public Company',
            'status' => CompanyStatus::ACTIVE->value,
        ]);
        $this->branch = $this->company->branches()->create([
            'name' => 'Public Company-main',
            'status' => BranchStatus::ACTIVE->value,
        ]);
        $this->customer = Customer::create([
            'firstname' => 'Mario',
            'lastname' => 'Rossi',
            'username' => 'mario.rossi',
            'pin' => '2468',
            'status' => CustomerStatus::ACTIVE->value,
            'company_id' => $this->company->id,
            'email' => 'mario@example.com',
        ]);
    }

    public function test_customer_can_identify_without_company_data_being_exposed(): void
    {
        $response = $this->identify()->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.name', 'Mario Rossi');

        $this->assertIsString($response->json('data.ticketToken'));
        $this->assertArrayNotHasKey('companyId', $response->json('data'));
        $this->assertArrayNotHasKey('customerId', $response->json('data'));
        $this->assertArrayNotHasKey('branchId', $response->json('data'));
    }

    public function test_unknown_username_and_wrong_pin_return_the_same_error(): void
    {
        $wrongPin = $this->postJson('/api/v1/public/tickets/identify', [
            'username' => $this->customer->username,
            'pin' => 'wrong',
        ])->assertUnprocessable();
        $unknownUser = $this->postJson('/api/v1/public/tickets/identify', [
            'username' => 'unknown-user',
            'pin' => 'wrong',
        ])->assertUnprocessable();

        $this->assertSame(
            $wrongPin->json('errors.credentials'),
            $unknownUser->json('errors.credentials'),
        );
    }

    public function test_public_creation_derives_identity_and_stores_attachments(): void
    {
        $otherCompany = Company::create([
            'name' => 'Injected Company',
            'status' => CompanyStatus::ACTIVE->value,
        ]);
        $token = $this->identify()->json('data.ticketToken');

        $this->post('/api/v1/public/tickets/create', [
            'ticketToken' => $token,
            'importance' => TicketImportanceStatus::GREEN->value,
            'description' => 'Public username ticket',
            'companyId' => $otherCompany->id,
            'customerId' => 999999,
            'branchId' => null,
            'status' => TicketStatus::DONE->value,
            'attachments' => [UploadedFile::fake()->image('proof.png')->size(100)],
        ])->assertCreated();

        $this->assertDatabaseHas('tickets', [
            'description' => 'Public username ticket',
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'status' => TicketStatus::OPENED->value,
        ]);
        $this->assertDatabaseCount('ticket_attachments', 1);
        Mail::assertSentCount(5);
    }

    public function test_expired_or_tampered_ticket_token_is_rejected(): void
    {
        Carbon::setTestNow('2026-07-19 10:00:00');
        $token = $this->identify()->json('data.ticketToken');
        Carbon::setTestNow('2026-07-19 10:16:00');

        $this->createTicket($token)->assertUnprocessable();
        $this->createTicket($token.'tampered')->assertUnprocessable();

        $this->assertDatabaseCount('tickets', 0);
        Carbon::setTestNow();
    }

    public function test_inactive_customer_company_or_branch_cannot_identify(): void
    {
        $this->customer->update(['status' => CustomerStatus::INACTIVE->value]);
        $this->identify()->assertUnprocessable();

        $this->customer->update(['status' => CustomerStatus::ACTIVE->value]);
        $this->company->update(['status' => CompanyStatus::INACTIVE->value]);
        $this->identify()->assertUnprocessable();

        $this->company->update(['status' => CompanyStatus::ACTIVE->value]);
        $this->branch->update(['status' => BranchStatus::INACTIVE->value]);
        $this->identify()->assertUnprocessable();
    }

    private function identify()
    {
        return $this->postJson('/api/v1/public/tickets/identify', [
            'username' => $this->customer->username,
            'pin' => $this->customer->pin,
        ]);
    }

    private function createTicket(string $token)
    {
        return $this->postJson('/api/v1/public/tickets/create', [
            'ticketToken' => $token,
            'importance' => TicketImportanceStatus::GREEN->value,
            'description' => 'Token test',
        ]);
    }
}
