<?php

namespace Tests\Feature\Tenancy;

use App\Enums\Company\CompanyStatus;
use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
use App\Enums\User\AccountType;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\Tiket\Ticket;
use App\Models\Tiket\TicketReviewCapability;
use App\Models\User;
use App\Services\Ticket\TicketReviewCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TicketReviewCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    private Ticket $otherTicket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user();
        $this->ticket = $this->ticket('Primary');
        $this->otherTicket = $this->ticket('Other');
    }

    public function test_capability_view_attachment_and_submission_succeed_without_secret_disclosure(): void
    {
        Storage::fake('ticket_attachments');
        $secret = app(TicketReviewCapabilityService::class)->issue($this->ticket);
        $path = "tickets/{$this->ticket->id}/review.txt";
        Storage::disk('ticket_attachments')->put($path, 'review attachment');
        $attachment = $this->ticket->attachments()->create(['path' => $path, 'storage_disk' => 'private']);

        $view = $this->getJson("/api/v1/public/ticket?ticketId={$this->ticket->id}&token={$secret}")->assertOk();
        $view->assertJsonMissingPath('data.token');
        $this->assertStringNotContainsString($secret, $view->getContent());
        $this->assertStringNotContainsString(TicketReviewCapability::firstOrFail()->secret_hash, $view->getContent());
        $cookie = $view->headers->getCookies()[0];
        $this->assertTrue($cookie->isHttpOnly());
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->get("/api/v1/public/tickets/{$this->ticket->id}/attachments/{$attachment->id}")
            ->assertOk()->assertStreamedContent('review attachment');

        $this->postJson('/api/v1/ticket-logs', [
            'ticketId' => $this->ticket->id,
            'token' => $secret,
            'status' => TicketStatus::REOPENED->value,
            'text' => 'Still broken',
        ])->assertOk();
        $this->assertNotNull(TicketReviewCapability::firstOrFail()->fresh()->consumed_at);
        $this->assertSame(TicketStatus::REOPENED->value, $this->ticket->fresh()->status);
        $this->assertDatabaseHas('ticket_logs', ['ticket_id' => $this->ticket->id, 'token' => null]);
        $this->getJson("/api/v1/public/ticket?ticketId={$this->ticket->id}&token={$secret}")->assertNotFound();
    }

    public function test_wrong_ticket_purpose_expiry_revocation_consumption_and_altered_secrets_fail(): void
    {
        foreach (['wrong_ticket', 'wrong_purpose', 'expired', 'revoked', 'consumed', 'altered'] as $case) {
            $secret = "{$case}-".str_repeat('x', 48);
            $capability = $this->capability($secret, $this->ticket, $case);
            $consumedAt = $capability->consumed_at;
            $ticketId = $case === 'wrong_ticket' ? $this->otherTicket->id : $this->ticket->id;
            $submittedSecret = $case === 'altered' ? $secret.'changed' : $secret;

            $this->getJson("/api/v1/public/ticket?ticketId={$ticketId}&token={$submittedSecret}")->assertNotFound();
            $this->postJson('/api/v1/ticket-logs', [
                'ticketId' => $ticketId,
                'token' => $submittedSecret,
                'status' => TicketStatus::DONE->value,
                'text' => 'Rejected attempt',
            ])->assertNotFound();
            $this->assertEquals($consumedAt, $capability->fresh()->consumed_at);
        }
    }

    public function test_capability_cannot_read_another_tickets_attachment_and_replay_fails(): void
    {
        Storage::fake('ticket_attachments');
        $secret = app(TicketReviewCapabilityService::class)->issue($this->ticket);
        $attachment = $this->otherTicket->attachments()->create([
            'path' => "tickets/{$this->otherTicket->id}/other.txt",
            'storage_disk' => 'private',
        ]);
        Storage::disk('ticket_attachments')->put($attachment->path, 'other');

        $this->getJson(
            "/api/v1/public/tickets/{$this->ticket->id}/attachments/{$attachment->id}?token={$secret}",
        )->assertNotFound();
        $payload = [
            'ticketId' => $this->ticket->id,
            'token' => $secret,
            'status' => TicketStatus::DONE->value,
            'text' => 'Approved',
        ];
        $this->postJson('/api/v1/ticket-logs', $payload)->assertOk();
        $this->postJson('/api/v1/ticket-logs', $payload)->assertNotFound();
    }

    public function test_legacy_audit_is_read_only_and_never_prints_tokens(): void
    {
        $this->ticket->token = 'legacy-secret-never-print';
        $this->ticket->save();

        $this->artisan('review-capabilities:audit-legacy')
            ->expectsOutputToContain('legacy rows have no authoritative purpose or expiry')
            ->doesntExpectOutputToContain('legacy-secret-never-print')
            ->assertSuccessful();
        $this->assertSame('legacy-secret-never-print', $this->ticket->fresh()->token);
        $this->assertDatabaseCount('ticket_review_capabilities', 0);
    }

    private function capability(string $secret, Ticket $ticket, string $case): TicketReviewCapability
    {
        return TicketReviewCapability::create([
            'ticket_id' => $ticket->id,
            'secret_hash' => hash('sha256', $secret),
            'purpose' => $case === 'wrong_purpose' ? 'another_purpose' : config('review_capabilities.purpose'),
            'expires_at' => $case === 'expired' ? now()->subMinute() : now()->addHour(),
            'revoked_at' => $case === 'revoked' ? now() : null,
            'consumed_at' => $case === 'consumed' ? now() : null,
        ]);
    }

    private function ticket(string $name): Ticket
    {
        $company = Company::create(['name' => "{$name} Company", 'status' => CompanyStatus::ACTIVE->value]);
        $customer = Customer::create([
            'firstname' => $name,
            'lastname' => 'Customer',
            'pin' => '1234',
            'company_id' => $company->id,
            'status' => 1,
        ]);

        return Ticket::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'status' => TicketStatus::DONE->value,
            'importance' => TicketImportanceStatus::GREEN->value,
            'description' => "{$name} ticket",
            'closed_at' => now(),
        ]);
    }

    private function user(): User
    {
        return User::create([
            'id' => 1,
            'name' => 'Audit',
            'username' => 'audit',
            'email' => 'audit@example.com',
            'password' => 'Password1!',
            'status' => 1,
            'account_type' => AccountType::INTERNAL,
        ]);
    }
}
