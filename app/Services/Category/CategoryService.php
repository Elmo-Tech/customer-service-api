<?php

namespace App\Services\Category;

use App\Filters\Category\FilterCategory;
use App\Models\Category\Category;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CategoryService{

    private $category;
    public function __construct(Category $category)
    {
        $this->category = $category;
    }

    public function allCountries()
    {
        $user = QueryBuilder::for(Category::class)
            ->allowedFilters([
                AllowedFilter::custom('search', new FilterCategory()), // Add a custom search filter
            ])->get();

        return $user;

    }

    public function createCategory(array $categoryData): Category
    {

        $category = Category::create([
            'name' => $categoryData['name'],
            'path' => $categoryData['path'],
        ]);

        $category->country()->attach($categoryData['countryIds']);

        return $category;

    }

    public function editCategory(int $categoryId)
    {
        return Category::find($categoryId);
    }

    public function updateCategory(array $categoryData): Category
    {

        $category = Category::find($categoryData['categoryId']);

        $category->update([
            'name' => $categoryData['name'],
            'path' => $categoryData['path'],
        ]);

        $category->country()->attach($categoryData['countryIds']);

        return $category;


    }


    public function deleteCategory(int $categoryId)
    {

        return Category::find($categoryId)->delete();

    }

}
