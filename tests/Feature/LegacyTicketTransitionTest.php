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
use App\Services\Company\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LegacyTicketTransitionTest extends TestCase
{
    use RefreshDatabase;

    private Company $legacyCompany;

    private Company $newCompany;

    private Branch $legacyBranch;

    private Customer $legacyCustomer;

    private Customer $newCustomer;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        User::create([
            'id' => 1,
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $this->legacyCompany = $this->company('Legacy Company', true);
        $this->newCompany = $this->company('New Company', false);
        $this->legacyBranch = $this->branch($this->legacyCompany, 'Legacy-main');
        $this->branch($this->newCompany, 'New-main');
        $this->legacyCustomer = $this->customer($this->legacyCompany, 'legacy.customer');
        $this->newCustomer = $this->customer($this->newCompany, 'new.customer');
    }

    public function test_legacy_options_only_expose_enabled_companies_and_their_customers(): void
    {
        $response = $this->getJson('/api/v1/public/legacy-ticket-options')->assertOk();
        $companies = collect($response->json())->firstWhere('label', 'companies')['options'];
        $customers = collect($response->json())->firstWhere('label', 'customers')['options'];

        $this->assertSame([$this->legacyCompany->id], collect($companies)->pluck('value')->all());
        $this->assertSame([$this->legacyCustomer->id], collect($customers)->pluck('value')->all());
    }

    public function test_disabled_company_branches_are_not_exposed(): void
    {
        $this->getJson("/api/v1/public/legacy-ticket-options/branches?companyId={$this->newCompany->id}")
            ->assertOk()
            ->assertJsonPath('0.options', []);

        $this->getJson("/api/v1/public/legacy-ticket-options/branches?companyId={$this->legacyCompany->id}")
            ->assertOk()
            ->assertJsonPath('0.options.0.value', $this->legacyBranch->id);
    }

    public function test_legacy_creation_rejects_disabled_company_and_derives_enabled_company(): void
    {
        $newBranch = $this->newCompany->branches()->first();
        $this->postJson('/api/v1/tickets/create', $this->ticketPayload($this->newCustomer, $newBranch))
            ->assertUnprocessable();

        $this->postJson('/api/v1/tickets/create', [
            ...$this->ticketPayload($this->legacyCustomer, $this->legacyBranch),
            'companyId' => $this->newCompany->id,
            'status' => TicketStatus::DONE->value,
        ])->assertOk();

        $this->assertDatabaseHas('tickets', [
            'customer_id' => $this->legacyCustomer->id,
            'company_id' => $this->legacyCompany->id,
            'branch_id' => $this->legacyBranch->id,
            'status' => TicketStatus::OPENED->value,
        ]);
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_new_companies_default_to_legacy_disabled_and_can_be_enabled_later(): void
    {
        $company = app(CompanyService::class)->createCompany([
            'name' => 'Created Later',
            'status' => CompanyStatus::ACTIVE->value,
        ]);

        $this->assertFalse($company->legacy_ticket_enabled);

        $updated = app(CompanyService::class)->updateCompany([
            'companyId' => $company->id,
            'name' => $company->name,
            'status' => CompanyStatus::ACTIVE->value,
            'legacyTicketEnabled' => true,
        ]);
        $this->assertTrue($updated->legacy_ticket_enabled);
    }

    private function company(string $name, bool $legacyEnabled): Company
    {
        return Company::create([
            'name' => $name,
            'status' => CompanyStatus::ACTIVE->value,
            'legacy_ticket_enabled' => $legacyEnabled,
        ]);
    }

    private function branch(Company $company, string $name): Branch
    {
        return $company->branches()->create([
            'name' => $name,
            'status' => BranchStatus::ACTIVE->value,
        ]);
    }

    private function customer(Company $company, string $username): Customer
    {
        return Customer::create([
            'firstname' => ucfirst(strtok($username, '.')),
            'lastname' => 'Customer',
            'username' => $username,
            'pin' => '1234',
            'status' => CustomerStatus::ACTIVE->value,
            'company_id' => $company->id,
            'email' => "{$username}@example.com",
        ]);
    }

    private function ticketPayload(Customer $customer, Branch $branch): array
    {
        return [
            'status' => TicketStatus::OPENED->value,
            'importance' => TicketImportanceStatus::GREEN->value,
            'description' => 'Legacy ticket',
            'customerId' => $customer->id,
            'pin' => $customer->pin,
            'companyId' => $customer->company_id,
            'branchId' => $branch->id,
        ];
    }
}
