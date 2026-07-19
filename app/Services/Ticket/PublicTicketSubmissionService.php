<?php

namespace App\Services\Ticket;

use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\Company\CustomerStatus;
use App\Enums\Ticket\TicketStatus;
use App\Models\Company\Branch;
use App\Models\Company\Customer;
use App\Models\Parameter\parameterValue;
use App\Models\Tiket\Ticket;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class PublicTicketSubmissionService
{
    private const TOKEN_LIFETIME_MINUTES = 15;

    public function __construct(private readonly TicketService $tickets) {}

    public function identify(string $username, string $pin): array
    {
        $customer = Customer::query()
            ->where('username', $username)
            ->where('status', CustomerStatus::ACTIVE->value)
            ->first();

        if (! $customer || ! $this->pinMatches($customer->pin, $pin)) {
            $this->pinMatches('invalid-public-ticket-pin', $pin);
            $this->invalidCredentials();
        }

        $branchId = $this->activeBranchId($customer);

        return [
            'name' => $customer->getFullName(),
            'ticketToken' => $this->token($customer, $branchId),
            'tags' => parameterValue::query()
                ->where('parameter_id', 1)
                ->get(['id as value', 'parameter_value as label'])
                ->all(),
        ];
    }

    public function create(string $token, array $ticketData): Ticket
    {
        $identity = $this->identityFromToken($token);
        $customer = Customer::query()
            ->whereKey($identity['customerId'])
            ->where('status', CustomerStatus::ACTIVE->value)
            ->first();

        if (! $customer || $this->activeBranchId($customer) !== $identity['branchId']) {
            $this->invalidToken();
        }

        return $this->tickets->createTicket([
            ...$ticketData,
            'companyId' => $customer->company_id,
            'customerId' => $customer->id,
            'branchId' => $identity['branchId'],
            'status' => TicketStatus::OPENED->value,
        ]);
    }

    private function activeBranchId(Customer $customer): int
    {
        $companyIsActive = $customer->company()
            ->where('status', CompanyStatus::ACTIVE->value)
            ->exists();
        $branchId = Branch::query()
            ->where('company_id', $customer->company_id)
            ->where('status', BranchStatus::ACTIVE->value)
            ->orderBy('id')
            ->value('id');

        if (! $companyIsActive || ! $branchId) {
            $this->invalidCredentials();
        }

        return (int) $branchId;
    }

    private function pinMatches(string $storedPin, string $providedPin): bool
    {
        return hash_equals(hash('sha256', $storedPin), hash('sha256', $providedPin));
    }

    private function token(Customer $customer, int $branchId): string
    {
        return Crypt::encryptString(json_encode([
            'customerId' => $customer->id,
            'branchId' => $branchId,
            'expiresAt' => now()->addMinutes(self::TOKEN_LIFETIME_MINUTES)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    private function identityFromToken(string $token): array
    {
        try {
            $identity = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            $this->invalidToken();
        }

        if (! is_array($identity)
            || ! isset($identity['customerId'], $identity['branchId'], $identity['expiresAt'])
            || $identity['expiresAt'] < now()->timestamp) {
            $this->invalidToken();
        }

        return [
            'customerId' => (int) $identity['customerId'],
            'branchId' => (int) $identity['branchId'],
        ];
    }

    private function invalidCredentials(): never
    {
        throw ValidationException::withMessages([
            'credentials' => 'Username o PIN non validi.',
        ]);
    }

    private function invalidToken(): never
    {
        throw ValidationException::withMessages([
            'ticketToken' => 'Sessione non valida o scaduta. Accedi di nuovo.',
        ]);
    }
}
