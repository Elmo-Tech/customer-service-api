<?php

namespace App\Http\Controllers\Api\Private\Role;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\CreateRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\Role\AllRoleCollection;
use App\Http\Resources\Role\RoleResource;
use App\Services\Role\RoleService;
use App\Utils\PaginateCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roleService)
    {
        $this->middleware(['auth:api', 'internal']);
        $this->middleware('permission:all_roles')->only('allRoles');
        $this->middleware('permission:create_role')->only('create');
        $this->middleware('permission:edit_role')->only('edit');
        $this->middleware('permission:update_role')->only('update');
        $this->middleware('permission:delete_role')->only('delete');
        $this->middleware('permission:all_roles')->only('matrix');
    }

    public function allRoles(Request $request): JsonResponse
    {
        $roles = $this->roleService->allRoles();
        $pageSize = $request->integer('pageSize', 10);

        return response()->json(
            new AllRoleCollection(PaginateCollection::paginate($roles, $pageSize)),
        );
    }

    public function create(CreateRoleRequest $request): JsonResponse
    {
        DB::transaction(fn () => $this->roleService->createRole($request->validated()));

        return response()->json(['message' => 'new role has been added']);
    }

    public function edit(Request $request): JsonResponse
    {
        return response()->json(new RoleResource(
            $this->roleService->editRole($request->integer('roleId')),
        ));
    }

    public function update(UpdateRoleRequest $request): JsonResponse
    {
        DB::transaction(fn () => $this->roleService->updateRole($request->validated()));

        return response()->json(['message' => 'تم تحديث بيانات البلد!']);
    }

    public function delete(Request $request): JsonResponse
    {
        DB::transaction(fn () => $this->roleService->deleteRole($request->integer('roleId')));

        return response()->json(['message' => 'تم حذف البلد بنجاح!']);
    }

    public function matrix(): JsonResponse
    {
        return response()->json(['data' => $this->roleService->matrix()]);
    }
}
