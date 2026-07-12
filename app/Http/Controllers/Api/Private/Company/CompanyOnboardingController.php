<?php

namespace App\Http\Controllers\Api\Private\Company;

use App\Enums\User\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\OnboardCompanyRequest;
use App\Services\Auth\AccountInvitationDelivery;
use App\Services\Company\CompanyOnboardingService;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

class CompanyOnboardingController extends Controller
{
    public function __construct(
        private readonly CompanyOnboardingService $onboarding,
        private readonly AccountInvitationDelivery $delivery,
    ) {
        $this->middleware(['auth:api', 'internal', 'permission:onboard_company']);
    }

    public function store(OnboardCompanyRequest $request): JsonResponse
    {
        $onboarding = $this->onboarding->onboard($request->user(), $request->validated());
        foreach ($onboarding['invitations'] as $invitation) {
            $this->delivery->queue($invitation);
        }

        return response()->json([
            'message' => 'Company onboarding completed.',
            'companyId' => $onboarding['company']->id,
            'invitationCount' => count($onboarding['invitations']),
        ], 201);
    }

    public function options(): JsonResponse
    {
        $roles = Role::query()->where('guard_name', 'api')
            ->whereIn('name', array_column(TenantRole::cases(), 'value'))
            ->get(['id as value', 'name as label']);

        return response()->json(['roles' => $roles]);
    }
}
