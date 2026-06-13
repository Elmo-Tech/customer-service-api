<?php

namespace App\Http\Controllers\Api\Private\Category;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\CreateCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\Category\AllCategoryCollection;
use App\Http\Resources\Category\CategoryResource;
use App\Utils\PaginateCollection;
use App\Services\Category\CategoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class CategoryController extends Controller
{
    protected $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->middleware('auth:api');
        $this->middleware('permission:all_users', ['only' => ['allUsers']]);
        $this->middleware('permission:create_user', ['only' => ['create']]);
        $this->middleware('permission:edit_user', ['only' => ['edit']]);
        $this->middleware('permission:update_user', ['only' => ['update']]);
        $this->middleware('permission:delete_user', ['only' => ['delete']]);
        $this->middleware('permission:change_user_status', ['only' => ['changeStatus']]);
        $this->categoryService = $categoryService;
    }

    /**
     * Display a listing of the resource.
     */
    public function allCountries(Request $request)
    {
        $allCountries = $this->categoryService->allCountries();

        return response()->json(
            new AllCategoryCollection(PaginateCollection::paginate($allCountries, $request->pageSize?$request->pageSize:10))
        , 200);

    }

    /**
     * Show the form for creating a new resource.
     */

    public function create(CreateCategoryRequest $createCategoryRequest)
    {

        try {
            DB::beginTransaction();

            $this->categoryService->createCategory($createCategoryRequest->validated());

            DB::commit();

            return response()->json([
                'message' => 'تم اضافة بلد جديد بنجاح'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }


    }

    /**
     * Show the form for editing the specified resource.
     */

    public function edit(Request $request)
    {
        $category  =  $this->categoryService->editCategory($request->categoryId);

        return response()->json(
            new CategoryResource($category)//new UserResource($user)
        ,200);

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCategoryRequest $updateCategoryRequest)
    {

        try {
            DB::beginTransaction();
            $this->categoryService->updateCategory($updateCategoryRequest->validated());
            DB::commit();
            return response()->json([
                 'message' => 'تم تحديث بيانات البلد!'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }


    }

    /**
     * Remove the specified resource from storage.
     */
    public function delete(Request $request)
    {

        try {
            DB::beginTransaction();
            $this->categoryService->deleteCategory($request->categoryId);
            DB::commit();
            return response()->json([
                'message' => 'تم حذف البلد بنجاح!'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }


    }

    public function changeStatus(Request $request)
    {

        try {
            DB::beginTransaction();
            $this->categoryService->changeCategoryStatus($request->categoryId, $request->status);
            DB::commit();

            return response()->json([
                'message' => 'تم تغيير حالة البلد!'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

    }

}
