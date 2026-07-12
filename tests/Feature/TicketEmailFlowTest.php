<?php

namespace Tests\Feature;

use App\Enums\Company\CompanyStatus;
use App\Enums\Company\CustomerStatus;
use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
use App\Enums\User\AccountType;
use App\Mail\ClosedTicketDetails;
use App\Mail\TicketDetails;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\Tiket\Ticket;
use App\Models\Tiket\TicketReviewCapability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TicketEmailFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_closing_ticket_sends_expected_emails(): void
    {
        Mail::fake();

        $ticket = $this->makeTicket();
        $ticket->attachments()->create(['path' => "tickets/{$ticket->id}/screenshot.png"]);

        $this->actingAs(User::findOrFail(1), 'api')
            ->withoutMiddleware()
            ->putJson('/api/v1/admin/tickets/update', [
                'ticketId' => $ticket->id,
                'status' => TicketStatus::DONE->value,
                'importance' => TicketImportanceStatus::GREEN->value,
                'description' => $ticket->description,
                'companyId' => $ticket->company_id,
                'branchId' => $ticket->branch_id,
                'customerId' => $ticket->customer_id,
            ])
            ->assertOk();

        Mail::assertSent(TicketDetails::class, 3);
        Mail::assertSent(TicketDetails::class, fn ($mail) => $mail->hasTo('it-arca@arcagroup.eu') && count($mail->attachments()) === 1);
        Mail::assertSent(TicketDetails::class, fn ($mail) => $mail->hasTo('mr10dev10@gmail.com'));
        Mail::assertSent(TicketDetails::class, fn ($mail) => $mail->hasTo('s.mohamed@elmotech.it'));

        Mail::assertSent(ClosedTicketDetails::class, 2);
        Mail::assertSent(ClosedTicketDetails::class, fn ($mail) => $mail->hasTo('mr10dev10@gmail.com') && count($mail->attachments()) === 1);
        Mail::assertSent(ClosedTicketDetails::class, fn ($mail) => $mail->hasTo('customer@example.com'));

        $this->assertNull($ticket->fresh()->token);
        $this->assertSame(1, TicketReviewCapability::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_created_ticket_emails_include_uploaded_attachments(): void
    {
        Mail::fake();
        Storage::fake('ticket_attachments');

        $customer = $this->makeCustomer();

        $this->post('/api/v1/tickets/create', [
            'status' => TicketStatus::OPENED->value,
            'importance' => TicketImportanceStatus::GREEN->value,
            'description' => 'Printer issue',
            'customerId' => $customer->id,
            'pin' => '1234',
            'companyId' => $customer->company_id,
            'branchId' => $customer->company->branches()->first()->id,
            'attachments' => [
                UploadedFile::fake()->create('screenshot.png', 10, 'image/png'),
            ],
        ])
            ->assertOk();

        Mail::assertSent(TicketDetails::class, 5);
        Mail::assertSent(TicketDetails::class, fn ($mail) => $mail->hasTo('it-arca@arcagroup.eu') && count($mail->attachments()) === 1);
        $attachment = Ticket::query()->latest('id')->firstOrFail()->attachments()->firstOrFail();
        $this->assertSame('private', $attachment->storage_disk);
        Storage::disk('ticket_attachments')->assertExists($attachment->path);
    }

    public function test_reopened_ticket_sends_expected_internal_emails(): void
    {
        Mail::fake();

        $ticket = $this->makeTicket([
            'status' => TicketStatus::DONE->value,
            'closed_at' => now(),
            'token' => 'review-token',
        ]);

        $this->postJson('/api/v1/ticket-logs', [
            'ticketId' => $ticket->id,
            'token' => 'review-token',
            'status' => TicketStatus::REOPENED->value,
            'text' => 'Still not fixed',
        ])
            ->assertOk();

        Mail::assertSent(TicketDetails::class, 4);
        Mail::assertSent(TicketDetails::class, fn ($mail) => $mail->hasTo('it-arca@arcagroup.eu'));
        Mail::assertSent(TicketDetails::class, fn ($mail) => $mail->hasTo('ms5325749@gmail.com'));
        Mail::assertSent(TicketDetails::class, fn ($mail) => $mail->hasTo('mr10dev10@gmail.com'));
        Mail::assertSent(TicketDetails::class, fn ($mail) => $mail->hasTo('s.mohamed@elmotech.it'));

        $ticket->refresh();

        $this->assertSame(TicketStatus::REOPENED->value, $ticket->status);
        $this->assertNull($ticket->token);
        $this->assertNull($ticket->closed_at);
    }

    public function test_open_filter_includes_reopened_tickets(): void
    {
        $open = $this->makeTicket(['status' => TicketStatus::OPENED->value]);
        $reopened = $this->makeTicket(['status' => TicketStatus::REOPENED->value]);
        $this->makeTicket(['status' => TicketStatus::DONE->value]);
        $this->makeTicket(['status' => TicketStatus::IN_PROGRESS->value]);

        $response = $this->actingAs(User::findOrFail(1), 'api')
            ->withoutMiddleware()
            ->getJson('/api/v1/admin/tickets?filter[status]=0')
            ->assertOk();

        $ticketIds = collect($response->json('result.tickets'))->pluck('ticketId')->all();

        $this->assertContains($open->id, $ticketIds);
        $this->assertContains($reopened->id, $ticketIds);
        $this->assertCount(2, $ticketIds);
    }

    private function makeTicket(array $ticketOverrides = []): Ticket
    {
        $customer = $this->makeCustomer();
        $company = $customer->company;
        $branch = $company->branches()->first();

        $ticket = Ticket::create(array_merge([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'status' => TicketStatus::OPENED->value,
            'importance' => TicketImportanceStatus::GREEN->value,
            'description' => 'Printer issue',
        ], $ticketOverrides));

        if (array_key_exists('token', $ticketOverrides)) {
            $ticket->token = $ticketOverrides['token'];
            $ticket->save();
        }

        return $ticket;
    }

    private function makeCustomer(): Customer
    {
        User::query()->firstOrCreate([
            'id' => 1,
        ], [
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => 'password',
            'account_type' => AccountType::INTERNAL,
        ]);

        $company = Company::create(['name' => 'Acme', 'status' => CompanyStatus::ACTIVE->value]);
        $company->branches()->create(['name' => 'Main', 'status' => 1]);

        return Customer::create([
            'firstname' => 'Mario',
            'lastname' => 'Rossi',
            'pin' => '1234',
            'company_id' => $company->id,
            'email' => 'customer@example.com',
            'status' => CustomerStatus::ACTIVE->value,
        ]);
    }
}
