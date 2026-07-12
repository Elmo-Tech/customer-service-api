<?php

namespace App\Services\User;

use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\User\AccountType;
use App\Enums\User\TenantRole;
use App\Enums\User\UserStatus;
use App\Filters\User\FilterUser;
use App\Filters\User\FilterUserRole;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\User;
use App\Services\Auth\RefreshSessionService;
use App\Services\Tenancy\TenantContext;
use App\Services\Upload\UploadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class UserService
{
    public function __construct(
        private readonly UploadService $uploadService,
        private readonly UserRoleAssignmentValidator $roleValidator,
        private readonly RefreshSessionService $refreshSessions,
    ) {}

    public function allUsers(User $caller)
    {
        $users = (new TenantContext($caller))->scopeUsers(
            User::query()->with(['company', 'branch', 'roles', 'pendingInvitation']),
        );

        return QueryBuilder::for($users)->allowedFilters([
            AllowedFilter::custom('search', new FilterUser),
            AllowedFilter::exact('status'),
            AllowedFilter::custom('role', new FilterUserRole),
        ])->get();
    }

    public function createUser(User $caller, array $userFields): User
    {
        $context = $this->managementContext($caller);
        $role = $this->roleValidator->role($caller, (int) $userFields['roleId']);
        $tenantFields = $this->newAccountTenantFields($caller, $context, $role, $userFields);
        $this->assertInvitationAllowed($tenantFields, (bool) ($userFields['invite'] ?? false));
        $user = User::create([
            ...$this->profileAttributes($userFields),
            ...$tenantFields,
            'avatar' => $this->avatarPath($userFields),
            'password' => $userFields['password'] ?? bin2hex(random_bytes(32)),
            'status' => ($userFields['invite'] ?? false)
                ? UserStatus::INACTIVE->value
                : UserStatus::from($userFields['status'])->value,
        ]);
        $user->assignRole($role);

        return $user;
    }

    public function editUser(User $caller, int $userId): User
    {
        return (new TenantContext($caller))->scopeUsers(User::query())
            ->with(['company', 'branch', 'roles', 'pendingInvitation'])->findOrFail($userId);
    }

    public function updateUser(User $caller, array $userFields): User
    {
        $context = $this->managementContext($caller);
        $user = $context->scopeUsers(User::query())->findOrFail($userFields['userId']);
        $role = $this->roleValidator->role($caller, (int) $userFields['roleId']);
        $this->assertRoleMatchesAccountType($user, $role);
        $this->assertCompanyIsImmutable($user, $userFields['companyId'] ?? null);
        $user->fill($this->profileAttributes($userFields));
        $user->status = UserStatus::from($userFields['status'])->value;
        $user->branch_id = $this->updatedBranchId($caller, $user, $role, $userFields);

        if (! empty($userFields['password'])) {
            $user->password = $userFields['password'];
        }

        if ($avatarPath = $this->avatarPath($userFields)) {
            $user->avatar = $avatarPath;
        }

        $user->save();
        $user->syncRoles($role);

        return $user;
    }

    public function deleteUser(User $caller, int $userId): bool
    {
        $context = $this->managementContext($caller);
        $user = $context->scopeUsers(User::query())->findOrFail($userId);
        $this->refreshSessions->revokeUser($user);

        return (bool) $user->delete();
    }

    public function changeUserStatus(User $caller, int $userId, int $status): bool
    {
        $context = $this->managementContext($caller);
        $user = $context->scopeUsers(User::query())->findOrFail($userId);

        $changed = $user->update(['status' => UserStatus::from($status)->value]);

        if ($status === UserStatus::INACTIVE->value) {
            $this->refreshSessions->revokeUser($user);
        }

        return $changed;
    }

    private function managementContext(User $caller): TenantContext
    {
        $context = new TenantContext($caller);

        if (! $context->canManageBranchAccounts()) {
            throw new AuthorizationException;
        }

        return $context;
    }

    private function newAccountTenantFields(
        User $caller,
        TenantContext $context,
        Role $role,
        array $userFields
    ): array {
        if ($context->isInternal()) {
            if (! $this->isTenantRole($role)) {
                return ['account_type' => AccountType::INTERNAL, 'company_id' => null, 'branch_id' => null];
            }

            return $this->tenantAccountFields(
                $caller,
                $this->activeCompany($userFields['companyId'] ?? null),
                $role,
                $userFields,
            );
        }

        return $this->tenantAccountFields($caller, $caller->company, $role, $userFields);
    }

    private function updatedBranchId(User $caller, User $user, Role $role, array $userFields): ?int
    {
        if ($user->account_type === AccountType::INTERNAL) {
            return null;
        }

        return $this->authorizedBranchId(
            $caller,
            $user->company,
            $role,
            $userFields['branchId'] ?? $user->branch_id,
        );
    }

    private function tenantAccountFields(User $caller, Company $company, Role $role, array $userFields): array
    {
        return [
            'account_type' => AccountType::TENANT,
            'company_id' => $company->id,
            'branch_id' => $this->authorizedBranchId($caller, $company, $role, $userFields['branchId'] ?? null),
        ];
    }

    private function authorizedBranchId(User $caller, Company $company, Role $role, ?int $requestedBranchId): ?int
    {
        $branchId = $caller->hasRole(TenantRole::BRANCH_MANAGER->value)
            ? (new TenantContext($caller))->tenantBranchId()
            : $requestedBranchId;
        if (! $company->uses_branches && $branchId !== null) {
            throw ValidationException::withMessages(['branchId' => 'This company does not use branches.']);
        }
        $branchRequired = $company->uses_branches && in_array($role->name, [
            TenantRole::BRANCH_MANAGER->value,
            TenantRole::EMPLOYEE->value,
        ], true);

        if ($branchRequired && $branchId === null) {
            throw ValidationException::withMessages(['branchId' => 'A branch is required for this role.']);
        }

        return $this->validatedBranchId($company->id, $branchId);
    }

    private function validatedBranchId(int $companyId, ?int $branchId): ?int
    {
        if ($branchId === null) {
            return null;
        }

        $validBranch = Branch::query()->whereKey($branchId)
            ->where('company_id', $companyId)->where('status', BranchStatus::ACTIVE->value)->exists();

        if (! $validBranch) {
            throw ValidationException::withMessages(['branchId' => 'Branch is outside the authorized company.']);
        }

        return $branchId;
    }

    private function activeCompany(?int $companyId): Company
    {
        if ($companyId === null) {
            throw ValidationException::withMessages(['companyId' => 'A company is required for tenant roles.']);
        }

        $company = Company::query()->whereKey($companyId)
            ->where('status', CompanyStatus::ACTIVE->value)->first();
        if (! $company) {
            throw ValidationException::withMessages(['companyId' => 'The selected company is inactive or missing.']);
        }

        return $company;
    }

    private function assertRoleMatchesAccountType(User $user, Role $role): void
    {
        if (($user->account_type === AccountType::TENANT) !== $this->isTenantRole($role)) {
            throw ValidationException::withMessages(['roleId' => 'The role cannot change the account type.']);
        }
    }

    private function assertCompanyIsImmutable(User $user, ?int $companyId): void
    {
        if ($companyId !== null && $companyId !== $user->company_id) {
            throw ValidationException::withMessages(['companyId' => 'The account company cannot be changed.']);
        }
    }

    private function assertInvitationAllowed(array $tenantFields, bool $invited): void
    {
        if ($invited && $tenantFields['account_type'] !== AccountType::TENANT) {
            throw ValidationException::withMessages(['invite' => 'Invitations are only available for tenant accounts.']);
        }
    }

    private function isTenantRole(Role $role): bool
    {
        return in_array($role->name, array_column(TenantRole::cases(), 'value'), true);
    }

    private function profileAttributes(array $userFields): array
    {
        return [
            'name' => $userFields['name'],
            'username' => $userFields['username'],
            'email' => $userFields['email'],
            'phone' => $userFields['phone'] ?? null,
            'address' => $userFields['address'] ?? null,
        ];
    }

    private function avatarPath(array $userFields): ?string
    {
        return isset($userFields['avatar']) && $userFields['avatar'] instanceof UploadedFile
            ? $this->uploadService->uploadFile($userFields['avatar'], 'avatars')
            : null;
    }
}
