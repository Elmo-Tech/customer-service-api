<?php

namespace App\Services\User;

use App\Enums\User\AccountType;
use App\Enums\User\TenantRole;
use App\Enums\User\UserStatus;
use App\Filters\User\FilterUser;
use App\Filters\User\FilterUserRole;
use App\Models\Company\Branch;
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
        $users = (new TenantContext($caller))->scopeUsers(User::query());

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
        $user = User::create([
            ...$this->profileAttributes($userFields),
            ...$tenantFields,
            'avatar' => $this->avatarPath($userFields),
            'password' => $userFields['password'],
            'status' => UserStatus::from($userFields['status'])->value,
        ]);
        $user->assignRole($role);

        return $user;
    }

    public function editUser(User $caller, int $userId): User
    {
        return (new TenantContext($caller))->scopeUsers(User::query())
            ->with('roles')->findOrFail($userId);
    }

    public function updateUser(User $caller, array $userFields): User
    {
        $context = $this->managementContext($caller);
        $user = $context->scopeUsers(User::query())->findOrFail($userFields['userId']);
        $role = $this->roleValidator->role($caller, (int) $userFields['roleId']);
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
            return ['account_type' => AccountType::INTERNAL, 'company_id' => null, 'branch_id' => null];
        }

        return [
            'account_type' => AccountType::TENANT,
            'company_id' => $context->tenantCompanyId(),
            'branch_id' => $this->authorizedBranchId($caller, $role, $userFields['branchId'] ?? null),
        ];
    }

    private function updatedBranchId(User $caller, User $user, Role $role, array $userFields): ?int
    {
        if ((new TenantContext($caller))->isInternal()) {
            return $user->branch_id;
        }

        return $this->authorizedBranchId($caller, $role, $userFields['branchId'] ?? $user->branch_id);
    }

    private function authorizedBranchId(User $caller, Role $role, ?int $requestedBranchId): ?int
    {
        $context = new TenantContext($caller);
        $branchId = $caller->hasRole(TenantRole::BRANCH_MANAGER->value)
            ? $context->tenantBranchId()
            : $requestedBranchId;
        if (! $caller->company->uses_branches && $branchId !== null) {
            throw ValidationException::withMessages(['branchId' => 'This company does not use branches.']);
        }
        $branchRequired = $caller->company->uses_branches && in_array($role->name, [
            TenantRole::BRANCH_MANAGER->value,
            TenantRole::EMPLOYEE->value,
        ], true);

        if ($branchRequired && $branchId === null) {
            throw ValidationException::withMessages(['branchId' => 'A branch is required for this role.']);
        }

        return $this->validatedBranchId($context, $branchId);
    }

    private function validatedBranchId(TenantContext $context, ?int $branchId): ?int
    {
        if ($branchId === null) {
            return null;
        }

        $validBranch = Branch::query()->whereKey($branchId)
            ->where('company_id', $context->tenantCompanyId())->where('status', 1)->exists();

        if (! $validBranch) {
            throw ValidationException::withMessages(['branchId' => 'Branch is outside the authorized company.']);
        }

        return $branchId;
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
