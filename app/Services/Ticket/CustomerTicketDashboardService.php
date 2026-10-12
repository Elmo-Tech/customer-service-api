<?php

namespace App\Services\Ticket;

use App\Enums\Company\CompanyStatus;
use App\Enums\Company\CustomerStatus;
use App\Enums\Ticket\TicketStatus;
use App\Models\Company\Customer;
use App\Models\Tiket\Ticket;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class CustomerTicketDashboardService
{
    private const TOKEN_PURPOSE = 'customer-dashboard';

    public function token(Customer $customer): string
    {
        return Crypt::encryptString(json_encode([
            'purpose' => self::TOKEN_PURPOSE,
            'customerId' => (int) $customer->id,
            'pinHash' => hash('sha256', $customer->pin),
            'expiresAt' => now()->addDay()->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public function allTickets(string $token, array $filters = []): Collection
    {
        $customer = $this->customerFromToken($token);

        $query = Ticket::query()
            ->where('customer_id', $customer->id)
            ->with(['customer', 'company', 'branch']);

        if (isset($filters['status'])) {
            $status = (int) $filters['status'];
            $query->whereIn('status', $status === TicketStatus::OPENED->value
                ? [TicketStatus::OPENED->value, TicketStatus::REOPENED->value]
                : [$status]);
        }

        if (isset($filters['description']) && $filters['description'] !== '') {
            $query->where('description', 'like', '%'.$filters['description'].'%');
        }

        return $query
            ->orderByDesc('id')
            ->get();
    }

    public function show(string $token, int $ticketId): Ticket
    {
        $customer = $this->customerFromToken($token);

        return Ticket::query()
            ->where('customer_id', $customer->id)
            ->with('attachments')
            ->findOrFail($ticketId);
    }

    private function customerFromToken(string $token): Customer
    {
        try {
            $identity = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            $this->invalidToken();
        }

        if (! is_array($identity)
            || ($identity['purpose'] ?? null) !== self::TOKEN_PURPOSE
            || ! is_int($identity['customerId'] ?? null)
            || ! is_string($identity['pinHash'] ?? null)
            || ! is_int($identity['expiresAt'] ?? null)
            || $identity['expiresAt'] <= now()->timestamp) {
            $this->invalidToken();
        }

        $customer = Customer::query()
            ->whereKey($identity['customerId'])
            ->where('status', CustomerStatus::ACTIVE->value)
            ->whereHas('company', fn ($query) => $query->where('status', CompanyStatus::ACTIVE->value))
            ->first();

        if (! $customer || ! hash_equals($identity['pinHash'], hash('sha256', $customer->pin))) {
            $this->invalidToken();
        }

        return $customer;
    }

    private function invalidToken(): never
    {
        throw ValidationException::withMessages([
            'token' => 'Sessione non valida o scaduta. Accedi di nuovo.',
        ]);
    }
}
