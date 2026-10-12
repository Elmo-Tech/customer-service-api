<?php

namespace Tests\Feature;

use App\Enums\Company\CustomerStatus;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\Tiket\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerTicketDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Customer $otherCustomer;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        User::create([
            'id' => 1,
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);
        $company = Company::create(['name' => 'Company', 'status' => 1]);
        $this->branchId = $company->branches()->create(['name' => 'Main', 'status' => 1])->id;
        $this->customer = Customer::create([
            'firstname' => 'Mario',
            'lastname' => 'Rossi',
            'username' => 'laborazioni',
            'pin' => '1595',
            'status' => CustomerStatus::ACTIVE->value,
            'company_id' => $company->id,
        ]);
        $this->otherCustomer = Customer::create([
            'firstname' => 'Other',
            'lastname' => 'Customer',
            'username' => 'other',
            'pin' => '0705',
            'status' => CustomerStatus::ACTIVE->value,
            'company_id' => $company->id,
        ]);
    }

    public function test_index_scopes_to_customer_and_paginates_newest_first(): void
    {
        $oldest = $this->ticket($this->customer);
        $newest = $this->ticket($this->customer);
        $this->ticket($this->otherCustomer);
        $this->ticket($this->customer)->delete();
        $query = [
            'token' => $this->identify()['token'],
            'pageSize' => 1,
            'customerId' => $this->otherCustomer->id,
        ];

        $response = $this->getJson('/api/v1/customer/tickets?'.http_build_query($query))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('result.tickets.0.ticketId', $newest->id)
            ->assertJsonCount(1, 'result.tickets')
            ->assertJsonPath('pagination', [
                'total' => 2,
                'count' => 1,
                'perPage' => 1,
                'currentPage' => 1,
                'totalPages' => 2,
            ]);
        $this->assertSame([
            'ticketId', 'ticketNumber', 'customerName', 'companyName', 'branchName',
            'status', 'importance', 'description', 'createdAt', 'closedAt',
        ], array_keys($response->json('result.tickets.0')));

        $this->getJson('/api/v1/customer/tickets?'.http_build_query([...$query, 'page' => 2]))
            ->assertOk()
            ->assertJsonPath('result.tickets.0.ticketId', $oldest->id)
            ->assertJsonPath('pagination.currentPage', 2);
    }

    public function test_customer_without_tickets_gets_an_empty_list(): void
    {
        $this->ticket($this->otherCustomer);

        $this->getJson('/api/v1/customer/tickets?'.http_build_query(['token' => $this->identify()['token']]))
            ->assertOk()
            ->assertJsonPath('result.tickets', [])
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonPath('pagination.perPage', 10);
    }

    public function test_show_returns_own_ticket_with_compact_attachments(): void
    {
        $ticket = $this->ticket($this->customer);
        $attachment = $ticket->attachments()->create(['path' => "tickets/{$ticket->id}/screenshot.png"]);

        $response = $this->show($this->identify()['token'], $ticket->id)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('ticketId', $ticket->id)
            ->assertJsonPath('timelineToken', $ticket->timeline_token)
            ->assertJsonPath('attachments.0', [
                'id' => $attachment->id,
                'fileName' => 'screenshot.png',
                'url' => Storage::disk('public')->url($attachment->path),
            ]);
        $this->assertSame([
            'ticketId', 'ticketNumber', 'customerId', 'companyId', 'branchId',
            'status', 'importance', 'description', 'attachments', 'tagId', 'closedAt', 'timelineToken',
        ], array_keys($response->json()));
    }

    public function test_show_hides_other_customers_missing_and_deleted_tickets(): void
    {
        $otherTicket = $this->ticket($this->otherCustomer);
        $deletedTicket = $this->ticket($this->customer);
        $deletedTicket->delete();
        $token = $this->identify()['token'];

        $this->show($token, $otherTicket->id)->assertNotFound();
        $this->show($token, $deletedTicket->id)->assertNotFound();
        $this->show($token, 999999)->assertNotFound();
    }

    public function test_ticket_tokens_and_tampered_dashboard_tokens_are_rejected_on_both_routes(): void
    {
        $identity = $this->identify();
        $ticket = $this->ticket($this->customer);

        foreach ([$identity['ticketToken'], $ticket->timeline_token, $identity['token'].'tampered'] as $token) {
            $this->getJson('/api/v1/customer/tickets?'.http_build_query(['token' => $token]))
                ->assertUnprocessable()->assertJsonValidationErrors('token');
            $this->show($token, $ticket->id)
                ->assertUnprocessable()->assertJsonValidationErrors('token');
        }

        $this->postJson('/api/v1/public/tickets/create', [
            'ticketToken' => $identity['token'],
            'description' => 'Wrong token purpose',
            'importance' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('ticketToken');
    }

    public function test_dashboard_token_expires_after_24_hours(): void
    {
        $this->travelTo(now()->startOfSecond());
        $token = $this->identify()['token'];
        $this->travel(23)->hours();
        $this->getJson('/api/v1/customer/tickets?'.http_build_query(['token' => $token]))->assertOk();
        $this->travel(1)->hours();
        $this->getJson('/api/v1/customer/tickets?'.http_build_query(['token' => $token]))
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->travelBack();
    }

    public function test_changed_pin_inactive_customer_or_company_revokes_access(): void
    {
        $token = $this->identify()['token'];
        $url = '/api/v1/customer/tickets?'.http_build_query(['token' => $token]);
        $this->customer->update(['pin' => '9999']);
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->customer->update(['pin' => '1595', 'status' => CustomerStatus::INACTIVE->value]);
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->customer->update(['status' => CustomerStatus::ACTIVE->value]);
        $this->customer->company->update(['status' => 0]);
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_missing_fields_and_invalid_pagination_are_rejected(): void
    {
        $this->getJson('/api/v1/customer/tickets')->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->getJson('/api/v1/customer/tickets/show')->assertUnprocessable()
            ->assertJsonValidationErrors(['token', 'ticketId']);
        $token = $this->identify()['token'];

        foreach ([['page' => 0], ['pageSize' => 0], ['pageSize' => 101]] as $pagination) {
            $this->getJson('/api/v1/customer/tickets?'.http_build_query(['token' => $token, ...$pagination]))
                ->assertUnprocessable()->assertJsonValidationErrors(array_keys($pagination));
        }
    }

    public function test_status_filter_matches_admin_status_semantics(): void
    {
        $token = $this->identify()['token'];
        $tickets = [];
        foreach ([0, 1, 2, 3] as $status) {
            $tickets[$status] = $this->ticket($this->customer);
            $tickets[$status]->update(['status' => $status]);
            $this->ticket($this->otherCustomer)->update(['status' => $status]);
        }

        foreach ([0 => [3, 0], 1 => [1], 2 => [2], 3 => [3]] as $status => $expected) {
            $response = $this->getJson('/api/v1/customer/tickets?'.http_build_query([
                'token' => $token,
                'filter' => ['status' => (string) $status],
            ]))->assertOk()->assertJsonPath('pagination.total', count($expected));

            $this->assertSame(
                array_map(fn ($value) => $tickets[$value]->id, $expected),
                array_column($response->json('result.tickets'), 'ticketId'),
            );
        }
    }

    public function test_description_filter_combines_with_status_before_pagination(): void
    {
        $token = $this->identify()['token'];
        $older = $this->ticket($this->customer);
        $older->update(['description' => 'Office printer is broken', 'status' => 1]);
        $newer = $this->ticket($this->customer);
        $newer->update(['description' => 'The printer needs ink', 'status' => 1]);
        $this->ticket($this->customer)->update(['description' => 'Another printer issue', 'status' => 2]);
        $this->ticket($this->customer)->update(['description' => 'Network issue', 'status' => 1]);
        $this->ticket($this->otherCustomer)->update(['description' => 'Office printer is broken', 'status' => 1]);
        $query = ['token' => $token, 'filter' => ['description' => 'printer']];

        $this->getJson('/api/v1/customer/tickets?'.http_build_query($query))
            ->assertOk()->assertJsonPath('pagination.total', 3);

        $query['filter']['status'] = '1';
        $query['pageSize'] = 1;
        $this->getJson('/api/v1/customer/tickets?'.http_build_query($query))
            ->assertOk()->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('pagination.totalPages', 2)
            ->assertJsonCount(1, 'result.tickets')
            ->assertJsonPath('result.tickets.0.ticketId', $newer->id);
        $this->getJson('/api/v1/customer/tickets?'.http_build_query([...$query, 'page' => 2]))
            ->assertOk()->assertJsonPath('result.tickets.0.ticketId', $older->id);
    }

    public function test_empty_filters_zero_description_and_quoted_text(): void
    {
        $token = $this->identify()['token'];
        $ticket = $this->ticket($this->customer);
        $ticket->update(['description' => "Printer's error 0", 'status' => 1]);
        $this->ticket($this->customer)->update(['description' => 'Different issue']);

        $this->getJson('/api/v1/customer/tickets?'.http_build_query([
            'token' => $token, 'filter' => ['status' => '', 'description' => ''],
        ]))->assertOk()->assertJsonPath('pagination.total', 2);

        foreach (["Printer's", '0'] as $description) {
            $this->getJson('/api/v1/customer/tickets?'.http_build_query([
                'token' => $token, 'filter' => ['description' => $description],
            ]))->assertOk()->assertJsonPath('pagination.total', 1)
                ->assertJsonPath('result.tickets.0.ticketId', $ticket->id);
        }

        $this->getJson('/api/v1/customer/tickets?'.http_build_query([
            'token' => $token, 'filter' => ['description' => "' OR 1=1 --"],
        ]))->assertOk()->assertJsonPath('result.tickets', [])->assertJsonPath('pagination.total', 0);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $token = $this->identify()['token'];
        foreach ([['status' => '4'], ['status' => 'open'], ['status' => ['1']], ['description' => ['printer']]] as $filter) {
            $this->getJson('/api/v1/customer/tickets?'.http_build_query(compact('token', 'filter')))
                ->assertUnprocessable()->assertJsonValidationErrors('filter.'.array_key_first($filter));
        }
    }

    private function identify(): array
    {
        return $this->postJson('/api/v1/public/tickets/identify', [
            'username' => $this->customer->username,
            'pin' => $this->customer->pin,
        ])->assertOk()->json('data');
    }

    private function ticket(Customer $customer): Ticket
    {
        return Ticket::create([
            'customer_id' => $customer->id,
            'company_id' => $customer->company_id,
            'branch_id' => $this->branchId,
            'description' => 'Test ticket',
            'status' => 0,
            'importance' => 0,
            'timeline_token' => (string) Str::uuid(),
        ]);
    }

    private function show(string $token, int $ticketId)
    {
        return $this->getJson('/api/v1/customer/tickets/show?'.http_build_query(compact('token', 'ticketId')));
    }
}
