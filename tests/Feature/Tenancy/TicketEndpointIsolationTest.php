<?php

namespace Tests\Feature\Tenancy;

use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
use App\Enums\User\AccountType;
use App\Enums\User\TenantRole;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\Tiket\Ticket;
use App\Models\Tiket\TicketAttachment;
use App\Models\Tiket\TicketLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketEndpointIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenantCompany;

    private Company $otherCompany;

    private Branch $tenantBranch;

    private Branch $otherTenantBranch;

    private Branch $otherCompanyBranch;

    private Customer $tenantCustomer;

    private Customer $otherCustomer;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->createPermissionsAndRoles();
        $this->user('audit', AccountType::INTERNAL, ['id' => 1]);
        $this->tenantCompany = $this->company('Tenant A');
        $this->otherCompany = $this->company('Tenant B');
        $this->tenantBranch = $this->branch($this->tenantCompany, 'A1');
        $this->otherTenantBranch = $this->branch($this->tenantCompany, 'A2');
        $this->otherCompanyBranch = $this->branch($this->otherCompany, 'B1');
        $this->tenantCustomer = $this->customer($this->tenantCompany, 'Tenant');
        $this->otherCustomer = $this->customer($this->otherCompany, 'Other');
    }

    public function test_company_owner_list_and_altered_company_filter_stay_tenant_scoped(): void
    {
        $owner = $this->tenantUser('owner', TenantRole::COMPANY_OWNER, $this->tenantBranch);
        $owner->givePermissionTo('all_tickets');
        $tenantTicket = $this->ticket($this->tenantCompany, $this->tenantBranch, $this->tenantCustomer, $owner);
        $this->ticket($this->otherCompany, $this->otherCompanyBranch, $this->otherCustomer);

        $tickets = $this->actingAs($owner, 'api')->getJson('/api/v1/admin/tickets')->assertOk();
        $this->assertSame([$tenantTicket->id], collect($tickets->json('result.tickets'))->pluck('ticketId')->all());

        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/admin/tickets?filter[company]={$this->otherCompany->id}")
            ->assertOk()
            ->assertJsonCount(0, 'result.tickets');
    }

    public function test_direct_id_show_update_and_delete_attacks_return_not_found(): void
    {
        $owner = $this->tenantUser('owner-actions', TenantRole::COMPANY_OWNER, $this->tenantBranch);
        $owner->givePermissionTo(['edit_ticket', 'update_ticket', 'delete_ticket']);
        $otherTicket = $this->ticket($this->otherCompany, $this->otherCompanyBranch, $this->otherCustomer);

        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/admin/tickets/edit?ticketId={$otherTicket->id}")
            ->assertNotFound();
        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/admin/tickets/update', $this->updatePayload($otherTicket))
            ->assertNotFound();
        $this->actingAs($owner, 'api')
            ->deleteJson("/api/v1/admin/tickets/delete?ticketId={$otherTicket->id}")
            ->assertNotFound();

        $this->assertNotNull($otherTicket->fresh());
    }

    public function test_ticket_update_rejects_company_customer_and_branch_reassignment(): void
    {
        $owner = $this->tenantUser('update-owner', TenantRole::COMPANY_OWNER, $this->tenantBranch);
        $owner->givePermissionTo('update_ticket');
        $ticket = $this->ticket($this->tenantCompany, $this->tenantBranch, $this->tenantCustomer);

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/admin/tickets/update', [
                ...$this->updatePayload($ticket),
                'companyId' => $this->otherCompany->id,
            ])->assertUnprocessable();
        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/admin/tickets/update', [
                ...$this->updatePayload($ticket),
                'customerId' => $this->otherCustomer->id,
            ])->assertUnprocessable();
        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/admin/tickets/update', [
                ...$this->updatePayload($ticket),
                'branchId' => $this->otherCompanyBranch->id,
            ])->assertUnprocessable();

        $this->assertSame($this->tenantCompany->id, $ticket->fresh()->company_id);
    }

    public function test_branch_manager_only_lists_assigned_branch(): void
    {
        $manager = $this->tenantUser('branch-manager', TenantRole::BRANCH_MANAGER, $this->tenantBranch);
        $manager->givePermissionTo('all_tickets');
        $assignedTicket = $this->ticket($this->tenantCompany, $this->tenantBranch, $this->tenantCustomer);
        $this->ticket($this->tenantCompany, $this->otherTenantBranch, $this->tenantCustomer);

        $response = $this->actingAs($manager, 'api')->getJson('/api/v1/admin/tickets')->assertOk();

        $this->assertSame([$assignedTicket->id], collect($response->json('result.tickets'))->pluck('ticketId')->all());
    }

    public function test_employee_only_lists_tickets_they_opened(): void
    {
        $employee = $this->tenantUser('employee', TenantRole::EMPLOYEE, $this->tenantBranch);
        $otherEmployee = $this->tenantUser('other-employee', TenantRole::EMPLOYEE, $this->tenantBranch);
        $employee->givePermissionTo('all_tickets');
        $ownTicket = $this->ticket($this->tenantCompany, $this->tenantBranch, $this->tenantCustomer, $employee);
        $this->ticket($this->tenantCompany, $this->tenantBranch, $this->tenantCustomer, $otherEmployee);

        $response = $this->actingAs($employee, 'api')->getJson('/api/v1/admin/tickets')->assertOk();

        $this->assertSame([$ownTicket->id], collect($response->json('result.tickets'))->pluck('ticketId')->all());
    }

    public function test_employee_authenticated_submission_derives_tenant_and_opener(): void
    {
        Carbon::setTestNow('2026-07-12 10:00:00');
        $employee = $this->tenantUser('submitting-employee', TenantRole::EMPLOYEE, $this->tenantBranch);
        $employee->givePermissionTo('all_tickets');

        $this->actingAs($employee, 'api')->postJson('/api/v1/admin/tickets/create', [
            'importance' => TicketImportanceStatus::GREEN->value,
            'description' => 'Authenticated submission',
            'branchId' => $this->otherTenantBranch->id,
        ])->assertCreated();

        $this->assertDatabaseHas('tickets', [
            'description' => 'Authenticated submission',
            'company_id' => $this->tenantCompany->id,
            'branch_id' => $this->tenantBranch->id,
            'customer_id' => null,
            'opened_by_user_id' => $employee->id,
            'status' => TicketStatus::OPENED->value,
            'due_at' => '2026-07-15 10:00:00',
        ]);
        Carbon::setTestNow();
    }

    public function test_authenticated_submission_ignores_client_customer_identity(): void
    {
        $owner = $this->tenantUser('submitting-owner', TenantRole::COMPANY_OWNER, $this->tenantBranch);
        $owner->givePermissionTo('all_tickets');

        $this->actingAs($owner, 'api')->postJson('/api/v1/admin/tickets/create', [
            'importance' => TicketImportanceStatus::GREEN->value,
            'description' => 'Cross tenant submission',
            'customerId' => $this->otherCustomer->id,
        ])->assertCreated();

        $this->assertDatabaseHas('tickets', [
            'description' => 'Cross tenant submission',
            'customer_id' => null,
            'opened_by_user_id' => $owner->id,
        ]);
    }

    public function test_branchless_employee_can_submit_authenticated_ticket(): void
    {
        $company = $this->company('Employee Branchless', false);
        $employee = $this->tenantUser('branchless-employee', TenantRole::EMPLOYEE, null, $company);
        $employee->givePermissionTo('all_tickets');
        $this->actingAs($employee, 'api')->postJson('/api/v1/admin/tickets/create', [
            'importance' => TicketImportanceStatus::GREEN->value,
            'description' => 'Authenticated branchless submission',
        ])->assertCreated();

        $this->assertDatabaseHas('tickets', [
            'description' => 'Authenticated branchless submission',
            'company_id' => $company->id,
            'branch_id' => null,
            'opened_by_user_id' => $employee->id,
        ]);
    }

    public function test_branchless_ticket_serializes_without_branch_access(): void
    {
        $branchlessCompany = $this->company('Branchless', false);
        $branchlessCustomer = $this->customer($branchlessCompany, 'Branchless');
        $owner = $this->tenantUser('branchless-owner', TenantRole::COMPANY_OWNER, null, $branchlessCompany);
        $owner->givePermissionTo('all_tickets');
        $ticket = $this->ticket($branchlessCompany, null, $branchlessCustomer, $owner);

        $response = $this->actingAs($owner, 'api')->getJson('/api/v1/admin/tickets')->assertOk();

        $this->assertSame($ticket->id, $response->json('result.tickets.0.ticketId'));
        $this->assertSame('', $response->json('result.tickets.0.branchName'));
    }

    public function test_ticket_logs_inherit_ticket_scope_and_require_authentication(): void
    {
        $owner = $this->tenantUser('log-owner', TenantRole::COMPANY_OWNER, $this->tenantBranch);
        $owner->givePermissionTo('all_tickets');
        $tenantTicket = $this->ticket($this->tenantCompany, $this->tenantBranch, $this->tenantCustomer);
        $otherTicket = $this->ticket($this->otherCompany, $this->otherCompanyBranch, $this->otherCustomer);
        TicketLog::create(['ticket_id' => $tenantTicket->id, 'status' => 1, 'text' => 'Tenant log']);
        TicketLog::create(['ticket_id' => $otherTicket->id, 'status' => 1, 'text' => 'Other log']);

        $this->getJson("/api/v1/ticket-logs?ticketId={$tenantTicket->id}")->assertUnauthorized();
        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/ticket-logs?ticketId={$otherTicket->id}")
            ->assertNotFound();
        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/ticket-logs?ticketId={$tenantTicket->id}")
            ->assertOk()
            ->assertJsonPath('data.0.text', 'Tenant log');
    }

    public function test_selects_require_auth_and_cannot_cross_tenant_boundaries(): void
    {
        $owner = $this->tenantUser('select-owner', TenantRole::COMPANY_OWNER, $this->tenantBranch);

        $this->getJson('/api/v1/selects?allSelects=companies,customers')->assertUnauthorized();

        $response = $this->actingAs($owner, 'api')->getJson(
            "/api/v1/selects?allSelects=companies,customers,branches={$this->otherCompany->id},users"
        )->assertOk();

        $this->assertSame([$this->tenantCompany->id], collect($response->json('0.options'))->pluck('value')->all());
        $this->assertSame([$this->tenantCustomer->id], collect($response->json('1.options'))->pluck('value')->all());
        $this->assertSame([], $response->json('2.options'));
        $this->assertNotContains(
            $this->otherCompany->id,
            collect($response->json('0.options'))->pluck('value')->all(),
        );
    }

    public function test_public_pin_submission_is_disabled(): void
    {
        $this->postJson('/api/v1/tickets/create', [
            'pin' => '1234',
        ])->assertNotFound();
    }

    public function test_role_management_requires_internal_account_and_permission(): void
    {
        $owner = $this->tenantUser('role-owner', TenantRole::COMPANY_OWNER, $this->tenantBranch);
        $owner->givePermissionTo('all_roles');
        $internal = User::findOrFail(1);
        $internal->givePermissionTo('all_roles');

        $this->getJson('/api/v1/admin/roles')->assertUnauthorized();
        $this->actingAs($owner, 'api')->getJson('/api/v1/admin/roles')->assertForbidden();
        $this->actingAs($internal, 'api')->getJson('/api/v1/admin/roles')->assertOk();
    }

    public function test_authorized_attachment_download_is_scoped_through_its_parent_ticket(): void
    {
        Storage::fake('ticket_attachments');
        $owner = $this->tenantUser('attachment-owner', TenantRole::COMPANY_OWNER, $this->tenantBranch);
        $owner->givePermissionTo('edit_ticket');
        $ticket = $this->ticket($this->tenantCompany, $this->tenantBranch, $this->tenantCustomer);
        $otherTicket = $this->ticket($this->otherCompany, $this->otherCompanyBranch, $this->otherCustomer);
        $attachment = $this->privateAttachment($ticket, 'tenant file');
        $otherAttachment = $this->privateAttachment($otherTicket, 'other file');

        $this->getJson($this->attachmentUrl($ticket, $attachment))->assertUnauthorized();
        $this->actingAs($owner, 'api')->get($this->attachmentUrl($ticket, $attachment))
            ->assertOk()->assertStreamedContent('tenant file');
        $this->actingAs($owner, 'api')->getJson($this->attachmentUrl($otherTicket, $otherAttachment))
            ->assertNotFound();
        $this->actingAs($owner, 'api')->getJson($this->attachmentUrl($ticket, $otherAttachment))
            ->assertNotFound();
        $this->actingAs($owner, 'api')->getJson($this->attachmentUrl($otherTicket, $attachment))
            ->assertNotFound();

        $unsafeAttachment = $ticket->attachments()->create([
            'path' => '../private.txt',
            'storage_disk' => 'private',
        ]);
        $this->actingAs($owner, 'api')->getJson($this->attachmentUrl($ticket, $unsafeAttachment))
            ->assertNotFound();
    }

    public function test_employee_without_parent_ticket_access_cannot_download_attachment(): void
    {
        Storage::fake('ticket_attachments');
        $employee = $this->tenantUser('attachment-employee', TenantRole::EMPLOYEE, $this->tenantBranch);
        $employee->givePermissionTo('edit_ticket');
        $otherEmployee = $this->tenantUser('attachment-opener', TenantRole::EMPLOYEE, $this->tenantBranch);
        $ticket = $this->ticket($this->tenantCompany, $this->tenantBranch, $this->tenantCustomer, $otherEmployee);
        $attachment = $this->privateAttachment($ticket, 'restricted');

        $this->actingAs($employee, 'api')->getJson($this->attachmentUrl($ticket, $attachment))
            ->assertNotFound();
    }

    public function test_deleted_ticket_or_attachment_cannot_be_downloaded(): void
    {
        Storage::fake('ticket_attachments');
        $owner = $this->tenantUser('deleted-attachment-owner', TenantRole::COMPANY_OWNER, $this->tenantBranch);
        $owner->givePermissionTo('edit_ticket');
        $ticket = $this->ticket($this->tenantCompany, $this->tenantBranch, $this->tenantCustomer);
        $attachment = $this->privateAttachment($ticket, 'deleted attachment');
        $attachment->delete();

        $this->actingAs($owner, 'api')->getJson($this->attachmentUrl($ticket, $attachment))->assertNotFound();

        $activeAttachment = $this->privateAttachment($ticket, 'deleted ticket');
        $ticket->delete();
        $this->actingAs($owner, 'api')->getJson($this->attachmentUrl($ticket, $activeAttachment))->assertNotFound();
    }

    public function test_branchless_ticket_attachment_download_succeeds(): void
    {
        Storage::fake('ticket_attachments');
        $company = $this->company('Attachment Branchless', false);
        $customer = $this->customer($company, 'Attachment Branchless');
        $owner = $this->tenantUser('attachment-branchless-owner', TenantRole::COMPANY_OWNER, null, $company);
        $owner->givePermissionTo('edit_ticket');
        $ticket = $this->ticket($company, null, $customer, $owner);
        $attachment = $this->privateAttachment($ticket, 'branchless file');

        $this->actingAs($owner, 'api')->get($this->attachmentUrl($ticket, $attachment))
            ->assertOk()->assertStreamedContent('branchless file');
    }

    public function test_review_token_only_downloads_attachments_from_its_ticket(): void
    {
        Storage::fake('ticket_attachments');
        $ticket = $this->ticket($this->tenantCompany, $this->tenantBranch, $this->tenantCustomer);
        $ticket->token = 'valid-review-token';
        $ticket->save();
        $otherTicket = $this->ticket($this->otherCompany, $this->otherCompanyBranch, $this->otherCustomer);
        $attachment = $this->privateAttachment($ticket, 'review file');
        $otherAttachment = $this->privateAttachment($otherTicket, 'other review file');

        $this->get($this->reviewAttachmentUrl($ticket, $attachment, 'valid-review-token'))
            ->assertOk()->assertStreamedContent('review file');
        $this->getJson($this->reviewAttachmentUrl($ticket, $attachment, 'wrong-token'))->assertNotFound();
        $this->getJson($this->reviewAttachmentUrl($ticket, $otherAttachment, 'valid-review-token'))->assertNotFound();
        $this->getJson($this->reviewAttachmentUrl($otherTicket, $attachment, 'valid-review-token'))->assertNotFound();

        $review = $this->getJson("/api/v1/public/ticket?ticketId={$ticket->id}&token=valid-review-token")
            ->assertOk();
        $this->assertStringContainsString('/api/v1/public/tickets/', $review->json('data.attachments.0.url'));
        $this->assertStringNotContainsString('/storage/', $review->json('data.attachments.0.url'));

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::DONE->value,
            'text' => 'Consumed',
            'token' => 'valid-review-token',
        ]);
        $this->getJson($this->reviewAttachmentUrl($ticket, $attachment, 'valid-review-token'))->assertNotFound();
    }

    private function createPermissionsAndRoles(): void
    {
        foreach (['all_tickets', 'edit_ticket', 'update_ticket', 'delete_ticket', 'all_roles'] as $permissionName) {
            Permission::create(['name' => $permissionName, 'guard_name' => 'api']);
        }

        foreach (TenantRole::cases() as $tenantRole) {
            Role::create(['name' => $tenantRole->value, 'guard_name' => 'api']);
        }
    }

    private function company(string $name, bool $usesBranches = true): Company
    {
        return Company::create([
            'name' => $name,
            'status' => CompanyStatus::ACTIVE->value,
            'uses_branches' => $usesBranches,
        ]);
    }

    private function branch(Company $company, string $name): Branch
    {
        return $company->branches()->create([
            'name' => $name,
            'status' => BranchStatus::ACTIVE->value,
        ]);
    }

    private function customer(Company $company, string $name): Customer
    {
        return Customer::create([
            'firstname' => $name,
            'lastname' => 'Customer',
            'pin' => '1234',
            'company_id' => $company->id,
            'status' => 1,
        ]);
    }

    private function tenantUser(
        string $username,
        TenantRole $role,
        ?Branch $branch,
        ?Company $company = null
    ): User {
        $tenantCompany = $company ?? $this->tenantCompany;
        $tenant = $this->user($username, AccountType::TENANT, [
            'company_id' => $tenantCompany->id,
            'branch_id' => $branch?->id,
        ]);
        $tenant->assignRole($role->value);

        return $tenant;
    }

    private function user(string $username, AccountType $accountType, array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => $username,
            'username' => $username,
            'email' => "{$username}@example.com",
            'password' => 'Password1!',
            'account_type' => $accountType,
            'status' => 1,
        ], $overrides));
    }

    private function ticket(
        Company $company,
        ?Branch $branch,
        Customer $customer,
        ?User $openedBy = null
    ): Ticket {
        return Ticket::create([
            'company_id' => $company->id,
            'branch_id' => $branch?->id,
            'customer_id' => $customer->id,
            'opened_by_user_id' => $openedBy?->id,
            'status' => TicketStatus::OPENED->value,
            'importance' => TicketImportanceStatus::GREEN->value,
            'description' => 'Isolation ticket',
        ]);
    }

    private function updatePayload(Ticket $ticket): array
    {
        return [
            'ticketId' => $ticket->id,
            'status' => $ticket->status,
            'importance' => $ticket->importance,
            'description' => $ticket->description,
            'companyId' => $ticket->company_id,
            'branchId' => $ticket->branch_id,
            'customerId' => $ticket->customer_id,
        ];
    }

    private function privateAttachment(Ticket $ticket, string $contents): TicketAttachment
    {
        $path = "tickets/{$ticket->id}/".md5($contents).'.txt';
        Storage::disk('ticket_attachments')->put($path, $contents);

        return $ticket->attachments()->create([
            'path' => $path,
            'storage_disk' => 'private',
            'original_name' => "file-{$ticket->id}.txt",
        ]);
    }

    private function attachmentUrl(Ticket $ticket, TicketAttachment $attachment): string
    {
        return "/api/v1/admin/tickets/{$ticket->id}/attachments/{$attachment->id}";
    }

    private function reviewAttachmentUrl(Ticket $ticket, TicketAttachment $attachment, string $token): string
    {
        return "/api/v1/public/tickets/{$ticket->id}/attachments/{$attachment->id}?token={$token}";
    }
}
