<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\SaveCategoryRequest;
use App\Models\AdminLog;
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
        AdminLog::record('categorie_creee', $category, "Catégorie « {$category->nom} » créée");

        return response()->json($category, 201);
    }

    // PUT /api/admin/categories/{category}
    public function update(SaveCategoryRequest $request, Category $category)
    {
        $ancien = $category->nom;
        $category->update(['nom' => $request->nom]);
        AdminLog::record('categorie_modifiee', $category, "Catégorie « {$ancien} » renommée en « {$category->nom} »");

        return response()->json($category);
    }

    // DELETE /api/admin/categories/{category}
    public function destroy(Category $category)
    {
        if ($category->products()->exists() || $category->mainProducts()->exists()) {
            return response()->json([
                'message' => 'Impossible de supprimer une catégorie contenant des produits',
            ], 409);
        }

        $category->delete();
        AdminLog::record('categorie_supprimee', $category, "Catégorie « {$category->nom} » supprimée");

        return response()->json(['message' => 'Catégorie supprimée']);
    }
}
