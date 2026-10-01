<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\SaveCategoryRequest;
use App\Models\Category;

class CategoryController extends Controller
{
    // GET /api/categories (public)
    public function index()
    {
        return Category::all();
    }

    // POST /api/admin/categories
    public function store(SaveCategoryRequest $request)
    {
        $category = Category::create(['nom' => $request->nom]);

        return response()->json($category, 201);
    }

    // PUT /api/admin/categories/{category}
    public function update(SaveCategoryRequest $request, Category $category)
    {
        $category->update(['nom' => $request->nom]);

        return response()->json($category);
    }

    // DELETE /api/admin/categories/{category}
    public function destroy(Category $category)
    {
        if ($category->products()->exists()) {
            return response()->json([
                'message' => 'Impossible de supprimer une catégorie contenant des produits',
            ], 409);
        }

        $category->delete();

        return response()->json(['message' => 'Catégorie supprimée']);
    }
}
