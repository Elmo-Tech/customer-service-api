<?php

namespace App\Services\Ticket\Import;

use App\Enums\User\AccountType;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\Parameter\parameterValue;
use App\Models\Tiket\LegacyTicketImport;
use App\Models\Tiket\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;

class LegacyTicketTargetValidator
{
    private array $companies = [];

    private array $branches = [];

    private array $customers = [];

    private array $users = [];

    private array $tags = [];

    private array $imports = [];

    private array $ticketNumbers = [];

    private array $seenTicketNumbers = [];

    public function __construct(private readonly LegacyTicketRecordMapper $mapper) {}

    public function load(LegacyTicketImportMapping $mapping): void
    {
        $this->companies = Company::whereIn('id', $mapping->targetIds('companies'))->get()->keyBy('id')->all();
        $this->branches = Branch::whereIn('id', $mapping->targetIds('branches'))->get()->keyBy('id')->all();
        $this->customers = Customer::whereIn('id', $mapping->targetIds('customers'))->get()->keyBy('id')->all();
        $this->users = User::whereIn('id', $mapping->targetIds('users'))->get()->keyBy('id')->all();
        $this->tags = parameterValue::whereIn('id', $mapping->targetIds('tags'))->get()->keyBy('id')->all();
        $this->imports = LegacyTicketImport::with('ticket')->where('source_key', $this->sourceKey())
            ->get()->keyBy('source_ticket_id')->all();
        $this->ticketNumbers = Ticket::query()->pluck('id', 'ticket_number')->all();
        $this->seenTicketNumbers = [];
    }

    public function alreadyImported(array $record): bool
    {
        $import = $this->imports[(int) $record['ticket']['id']] ?? null;
        if (! $import) {
            return false;
        }
        if (! hash_equals($import->source_hash, $this->mapper->sourceHash($record)) || ! $import->ticket) {
            throw new InvalidArgumentException('Previously imported source data changed or its ticket is missing.');
        }

        return true;
    }

    public function validate(array $record, array $attributes, LegacyTicketImportMapping $mapping): int
    {
        $companyId = $attributes['company_id'];
        if (! isset($this->companies[$companyId])) {
            throw new InvalidArgumentException('Mapped company does not exist.');
        }
        if (! $this->companies[$companyId]->uses_branches && $attributes['branch_id'] !== null) {
            throw new InvalidArgumentException('Mapped branch is not allowed for a branchless company.');
        }
        $this->assertBelongsToCompany($this->branches, $attributes['branch_id'], $companyId, 'branch');
        $this->assertBelongsToCompany($this->customers, $attributes['customer_id'], $companyId, 'customer');
        foreach (['opened_by_user_id' => 'opener', 'assigned_to_user_id' => 'assignee', 'created_by' => 'creator', 'updated_by' => 'updater'] as $field => $label) {
            $this->assertUserScope($attributes[$field], $companyId, $label);
        }
        if ($attributes['tag_id'] !== null && ! isset($this->tags[$attributes['tag_id']])) {
            throw new InvalidArgumentException('Mapped tag does not exist.');
        }
        if (isset($this->ticketNumbers[$attributes['ticket_number']])) {
            throw new InvalidArgumentException('Ticket number already exists without an import ledger entry.');
        }
        if (isset($this->seenTicketNumbers[$attributes['ticket_number']])) {
            throw new InvalidArgumentException('Ticket number is duplicated inside the legacy source.');
        }
        $this->seenTicketNumbers[$attributes['ticket_number']] = true;
        $this->validateChildren($record, $mapping, $companyId);

        return $this->missingAttachmentFiles($record['attachments']);
    }

    private function validateChildren(array $record, LegacyTicketImportMapping $mapping, int $companyId): void
    {
        foreach ([...$record['attachments'], ...$record['logs']] as $childRow) {
            $audit = $this->mapper->auditAttributes($childRow, $mapping);
            $this->assertUserScope($audit['created_by'], $companyId, 'child creator');
            $this->assertUserScope($audit['updated_by'], $companyId, 'child updater');
        }
        foreach ($record['logs'] as $log) {
            if (! in_array((int) $log['status'], [1, 3], true)) {
                throw new InvalidArgumentException('Legacy ticket log status is unsupported.');
            }
        }
    }

    private function assertBelongsToCompany(array $records, ?int $recordId, int $companyId, string $label): void
    {
        if ($recordId !== null && (! isset($records[$recordId]) || (int) $records[$recordId]->company_id !== $companyId)) {
            throw new InvalidArgumentException("Mapped {$label} is outside the mapped company.");
        }
    }

    private function assertUserScope(?int $userId, int $companyId, string $label): void
    {
        if ($userId === null) {
            return;
        }
        $user = $this->users[$userId] ?? null;
        $valid = $user && ($user->account_type === AccountType::INTERNAL || (int) $user->company_id === $companyId);
        if (! $valid) {
            throw new InvalidArgumentException("Mapped {$label} is outside the mapped company.");
        }
    }

    private function missingAttachmentFiles(array $attachments): int
    {
        return collect($attachments)->filter(function (array $attachment): bool {
            $path = (string) $attachment['path'];

            return ! $this->safePath($path) || ! $this->attachmentExists($path);
        })->count();
    }

    private function attachmentExists(string $path): bool
    {
        try {
            return Storage::disk(config('legacy_ticket_import.attachment_disk'))->exists($path);
        } catch (FilesystemException) {
            return false;
        }
    }

    private function safePath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '\\')
            && ! in_array('..', explode('/', $path), true);
    }

    private function sourceKey(): string
    {
        return (string) config('legacy_ticket_import.source_key');
    }
}
