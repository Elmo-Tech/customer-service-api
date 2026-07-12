<?php

namespace App\Http\Controllers\Api\Private\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\User\AllUserCollection;
use App\Http\Resources\User\AllUserDataResource;
use App\Models\User;
use App\Services\Export\ResourceCsvExporter;
use App\Services\User\TeamMemberCreationService;
use App\Services\User\UserService;
use App\Utils\PaginateCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $userService,
        private readonly ResourceCsvExporter $csv,
        private readonly TeamMemberCreationService $teamMembers,
    ) {
        $this->middleware('auth:api');
        $this->middleware('permission:all_users')->only('allUsers');
        $this->middleware('permission:create_user')->only('create');
        $this->middleware('permission:edit_user')->only('edit');
        $this->middleware('permission:update_user')->only('update');
        $this->middleware('permission:delete_user')->only('delete');
        $this->middleware('permission:change_user_status')->only('changeStatus');
        $this->middleware('permission:export_users|all_users')->only('export');
    }

    public function export(Request $request)
    {
        $users = $this->userService->allUsers($request->user())->load(['company', 'branch', 'roles']);

        return $this->csv->response('users', ['Name', 'Username', 'Email', 'Status', 'Company', 'Branch', 'Role'],
            $users->map(fn (User $user) => [
                $user->name, $user->username, $user->email, $user->status->value,
                $user->company?->name, $user->branch?->name, $user->roles->first()?->name,
            ]),
        );
    }

    public function allUsers(Request $request): JsonResponse
    {
        $users = $this->userService->allUsers($request->user());

        return response()->json(new AllUserCollection(
            PaginateCollection::paginate($users, $request->integer('pageSize', 10)),
        ));
    }

    public function create(CreateUserRequest $request): JsonResponse
    {
        $invited = $this->teamMembers->create($request->user(), $request->validated());

        return response()->json([
            'message' => $invited ? 'Invitation queued.' : 'تم اضافة مستخدم جديد بنجاح',
            'invitationQueued' => $invited,
        ]);
    }

    public function edit(Request $request): JsonResponse
    {
        $user = $this->userService->editUser($request->user(), $request->integer('userId'));

        return response()->json(new AllUserDataResource($user));
    }

    public function update(UpdateUserRequest $request): JsonResponse
    {
        DB::transaction(fn () => $this->userService->updateUser($request->user(), $request->validated()));

        return response()->json(['message' => 'تم تحديث بيانات المستخدم!']);
    }

    public function delete(Request $request): JsonResponse
    {
        DB::transaction(fn () => $this->userService->deleteUser($request->user(), $request->integer('userId')));

        return response()->json(['message' => 'تم حذف المستخدم بنجاح!']);
    }

    public function changeStatus(Request $request): JsonResponse
    {
        $statusFields = $request->validate(['userId' => 'required|integer', 'status' => 'required|integer|in:0,1']);
        DB::transaction(fn () => $this->userService->changeUserStatus(
            $request->user(),
            (int) $statusFields['userId'],
            (int) $statusFields['status'],
        ));

        return response()->json(['message' => 'تم تغيير حالة المستخدم!']);
    }
}
