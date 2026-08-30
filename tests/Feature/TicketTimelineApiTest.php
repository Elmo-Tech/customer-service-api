<?php

namespace Tests\Feature;

use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\Company\CustomerStatus;
use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\Tiket\Ticket;
use App\Models\Tiket\TicketTimelineLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketTimelineApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_status_update_creates_one_numeric_timeline_status_log(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $ticket = $this->ticket();

        $this->actingAs($admin, 'api')
            ->withoutMiddleware()
            ->putJson('/api/v1/admin/tickets/update', $this->updatePayload($ticket, TicketStatus::IN_PROGRESS->value))
            ->assertOk();

        $this->assertDatabaseHas('ticket_timeline_logs', [
            'ticket_id' => $ticket->id,
            'type' => TicketTimelineLog::TYPE_STATUS_CHANGE,
            'actor_type' => TicketTimelineLog::ACTOR_ADMIN,
            'user_id' => $admin->id,
            'user_name' => $admin->name,
            'old_status' => TicketStatus::OPENED->value,
            'new_status' => TicketStatus::IN_PROGRESS->value,
        ]);

        $this->actingAs($admin, 'api')
            ->withoutMiddleware()
            ->putJson('/api/v1/admin/tickets/update', $this->updatePayload($ticket->fresh(), TicketStatus::IN_PROGRESS->value))
            ->assertOk();

        $this->assertSame(1, TicketTimelineLog::where('ticket_id', $ticket->id)->count());

        $this->actingAs($admin, 'api')
            ->withoutMiddleware()
            ->getJson("/api/v1/admin/tickets/timeline?ticketId={$ticket->id}")
            ->assertOk()
            ->assertJsonPath('result.ticket.priority', TicketImportanceStatus::GREEN->value)
            ->assertJsonPath('result.ticket.status', TicketStatus::IN_PROGRESS->value)
            ->assertJsonPath('result.ticketMessages.0.type', TicketTimelineLog::TYPE_STATUS_CHANGE)
            ->assertJsonPath('result.ticketMessages.0.oldStatus', TicketStatus::OPENED->value)
            ->assertJsonPath('result.ticketMessages.0.newStatus', TicketStatus::IN_PROGRESS->value)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('pagination.perPage', 10)
            ->assertJsonPath('pagination.currentPage', 1);
    }

    public function test_customer_message_creates_timeline_message_with_attachment_metadata(): void
    {
        Storage::fake('public');

        $ticket = $this->ticket();

        $this->post('/api/v1/tickets/messages', [
            'ticketId' => $ticket->id,
            'token' => $ticket->timeline_token,
            'message' => 'Still seeing the same issue.',
            'attachments' => [
                UploadedFile::fake()->create('error.png', 12, 'image/png'),
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', TicketTimelineLog::TYPE_MESSAGE)
            ->assertJsonPath('data.actorType', TicketTimelineLog::ACTOR_CUSTOMER)
            ->assertJsonPath('data.userId', $ticket->customer_id)
            ->assertJsonPath('data.message', 'Still seeing the same issue.')
            ->assertJsonPath('data.attachments.0.fileName', 'error.png');

        $this->assertDatabaseHas('ticket_timeline_logs', [
            'ticket_id' => $ticket->id,
            'type' => TicketTimelineLog::TYPE_MESSAGE,
            'actor_type' => TicketTimelineLog::ACTOR_CUSTOMER,
            'message' => 'Still seeing the same issue.',
        ]);

        $this->assertDatabaseHas('ticket_timeline_log_attachments', [
            'file_name' => 'error.png',
            'mime_type' => 'image/png',
        ]);

        $this->getJson("/api/v1/tickets/timeline?ticketId={$ticket->id}&token={$ticket->timeline_token}")
            ->assertOk()
            ->assertJsonPath('result.ticket.ticketNumber', $ticket->ticket_number)
            ->assertJsonPath('result.ticketMessages.0.type', TicketTimelineLog::TYPE_MESSAGE)
            ->assertJsonPath('result.ticketMessages.0.actorType', TicketTimelineLog::ACTOR_CUSTOMER)
            ->assertJsonPath('pagination.total', 1);
    }

    private function updatePayload(Ticket $ticket, int $status): array
    {
        return [
            'ticketId' => $ticket->id,
            'status' => $status,
            'importance' => $ticket->importance,
            'description' => $ticket->description,
            'companyId' => $ticket->company_id,
            'branchId' => $ticket->branch_id,
            'customerId' => $ticket->customer_id,
        ];
    }

    private function ticket(): Ticket
    {
        $customer = $this->customer();
        $branch = $customer->company->branches()->first();

        return Ticket::create([
            'company_id' => $customer->company_id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'status' => TicketStatus::OPENED->value,
            'importance' => TicketImportanceStatus::GREEN->value,
            'description' => 'Printer issue',
            'timeline_token' => 'timeline-token',
        ]);
    }

    private function customer(): Customer
    {
        User::query()->firstOrCreate([
            'id' => 1,
        ], [
            'name' => 'System Admin',
            'username' => 'system.admin',
            'email' => 'system.admin@example.com',
            'password' => 'password',
        ]);

        $company = Company::create([
            'name' => 'Acme',
            'status' => CompanyStatus::ACTIVE->value,
            'legacy_ticket_enabled' => true,
        ]);

        $company->branches()->create([
            'name' => 'Main',
            'status' => BranchStatus::ACTIVE->value,
        ]);

        return Customer::create([
            'firstname' => 'Mario',
            'lastname' => 'Rossi',
            'username' => 'mario.rossi',
            'pin' => '1234',
            'status' => CustomerStatus::ACTIVE->value,
            'company_id' => $company->id,
            'email' => 'customer@example.com',
        ]);
    }

    private function admin(): User
    {
        $admin = User::query()->firstOrCreate([
            'id' => 1,
        ], [
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        foreach (['all_tickets', 'edit_ticket', 'update_ticket'] as $permission) {
            Permission::findOrCreate($permission, 'api');
        }

        $admin->givePermissionTo(['all_tickets', 'edit_ticket', 'update_ticket']);

        return $admin;
    }
}
