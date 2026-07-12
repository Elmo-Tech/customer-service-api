<?php

namespace App\Services\Company;

use App\DTOs\InvitationSecret;
use App\DTOs\OnboardingContext;
use App\Enums\Company\BranchStatus;
use App\Enums\User\AccountType;
use App\Enums\User\TenantRole;
use App\Enums\User\UserStatus;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\User;
use App\Services\Auth\AccountInvitationService;
use App\Services\Tenancy\TenantAuditLogger;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class CompanyOnboardingService
{
    public function __construct(
        private readonly AccountInvitationService $invitations,
        private readonly TenantAuditLogger $audit,
    ) {}

    public function onboard(User $actor, array $fields): array
    {
        $this->assertInternal($actor);

        return DB::transaction(function () use ($actor, $fields): array {
            $company = Company::create([
                'name' => $fields['company']['name'],
                'status' => $fields['company']['status'],
                'uses_branches' => $fields['company']['usesBranches'],
            ]);
            $this->audit->record($actor, 'company.created', $company);
            $branches = $this->createBranches($actor, $company, $fields['branches'] ?? []);
            $context = new OnboardingContext($actor, $company, $branches);
            $invitations = [$this->createOwner($context, $fields['owner'])];
            foreach ($fields['accounts'] ?? [] as $accountFields) {
                $invitations[] = $this->createTeamAccount($context, $accountFields);
            }

            return ['company' => $company, 'invitations' => $invitations];
        });
    }

    private function createBranches(User $actor, Company $company, array $branchFields): array
    {
        if (! $company->uses_branches && $branchFields !== []) {
            throw ValidationException::withMessages(['branches' => 'Branchless companies cannot include branches.']);
        }
        $branches = [];
        foreach ($branchFields as $branchField) {
            $branch = $company->branches()->create([
                'name' => $branchField['name'],
                'status' => BranchStatus::ACTIVE->value,
            ]);
            $branches[$branchField['key']] = $branch;
            $this->audit->record($actor, 'branch.created', $branch, ['companyId' => $company->id]);
        }

        return $branches;
    }

    private function createOwner(OnboardingContext $context, array $fields): InvitationSecret
    {
        $role = $this->tenantRole((int) $fields['roleId']);
        if ($role->name !== TenantRole::COMPANY_OWNER->value) {
            throw ValidationException::withMessages(['owner.roleId' => 'The owner must use the company owner role.']);
        }

        return $this->persistAccount($context, $fields, $role);
    }

    private function createTeamAccount(OnboardingContext $context, array $fields): InvitationSecret
    {
        $role = $this->tenantRole((int) $fields['roleId']);
        if ($role->name === TenantRole::COMPANY_OWNER->value) {
            throw ValidationException::withMessages(['accounts.roleId' => 'Additional owners are not allowed.']);
        }

        return $this->persistAccount($context, $fields, $role);
    }

    private function persistAccount(
        OnboardingContext $context,
        array $accountFields,
        Role $role,
    ): InvitationSecret {
        $branch = $this->accountBranch($context->company, $context->branches, $accountFields, $role);
        $user = User::create([
            'name' => $accountFields['name'],
            'username' => $accountFields['username'],
            'email' => $accountFields['email'],
            'password' => bin2hex(random_bytes(32)),
            'status' => UserStatus::INACTIVE->value,
            'account_type' => AccountType::TENANT,
            'company_id' => $context->company->id,
            'branch_id' => $branch?->id,
        ]);
        $user->assignRole($role);
        $this->audit->record($context->actor, 'role.assigned', $user, ['roleId' => $role->id]);
        $invitation = $this->invitations->issue($user, $context->actor);
        $this->audit->record($context->actor, 'account.invited', $user, ['invitationId' => $invitation->invitationId]);

        return $invitation;
    }

    private function accountBranch(Company $company, array $branches, array $fields, Role $role): ?Branch
    {
        $branchKey = $fields['branchKey'] ?? null;
        if (! $company->uses_branches && $branchKey !== null) {
            throw ValidationException::withMessages(['branchKey' => 'Branchless accounts cannot reference a branch.']);
        }
        $branchRequired = in_array($role->name, [TenantRole::BRANCH_MANAGER->value, TenantRole::EMPLOYEE->value], true);
        if ($company->uses_branches && $branchRequired && $branchKey === null) {
            throw ValidationException::withMessages(['branchKey' => 'This role requires a branch.']);
        }
        if ($branchKey !== null && ! isset($branches[$branchKey])) {
            throw ValidationException::withMessages(['branchKey' => 'Branch must be created in this onboarding request.']);
        }

        return $branchKey === null ? null : $branches[$branchKey];
    }

    private function tenantRole(int $roleId): Role
    {
        $role = Role::query()->where('guard_name', 'api')->findOrFail($roleId);
        $allowed = array_column(TenantRole::cases(), 'value');
        if (! in_array($role->name, $allowed, true)) {
            throw new AuthorizationException('Platform roles cannot be assigned to tenant accounts.');
        }

        return $role;
    }

    private function assertInternal(User $actor): void
    {
        if (! (new TenantContext($actor))->isInternal()) {
            throw new AuthorizationException;
        }
    }
}
