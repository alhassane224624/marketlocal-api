<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ListProductsRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\CloudinaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductController extends Controller
{
    public function index(ListProductsRequest $request)
    {
        $query = Product::with(['shop:id,nom,logo', 'category:id,nom'])
            ->whereHas('shop', fn ($q) => $q->where('statut', 'valide'));

        if ($request->filled('category_id')) $query->where('category_id', $request->input('category_id'));
        if ($request->filled('shop_id')) $query->where('shop_id', $request->input('shop_id'));
        if ($request->filled('prix_min')) $query->where('prix', '>=', $request->input('prix_min'));
        if ($request->filled('prix_max')) $query->where('prix', '<=', $request->input('prix_max'));
        if ($request->filled('q')) {
            $term = addcslashes($request->input('q'), '\\%_');
            $query->where('nom', 'like', "%{$term}%");
        }

        return response()->json(
            $query->latest()->paginate(12)->withQueryString()
                ->through(fn (Product $product) => ProductResource::make($product)->resolve())
        );
    }

    public function show(Request $request, Product $product)
    {
        $product->load(['shop:id,nom,logo,statut,user_id', 'category:id,nom', 'reviews']);
        $user = $request->user();
        $isOwner = $user && $user->role === 'vendeur' && (int) $product->shop?->user_id === (int) $user->id;
        $isAdmin = $user && $user->role === 'admin';

        if ($product->shop && $product->shop->statut !== 'valide' && ! $isOwner && ! $isAdmin) {
            return response()->json(['message' => 'Produit indisponible'], 404);
        }

        return response()->json(ProductResource::make($product)->resolve());
    }

    public function store(StoreProductRequest $request, CloudinaryService $images)
    {
        $shop = $request->user()->shop;
        if (! $shop) return response()->json(['message' => "Vous devez d'abord créer une boutique"], 403);
        if ($shop->statut !== 'valide') {
            return response()->json(['message' => 'Votre boutique doit être validée avant de publier des produits.'], 409);
        }

        $data = $request->safe()->only(['nom', 'description', 'prix', 'stock', 'category_id']);
        $data['shop_id'] = $shop->id;

        try {
            if ($request->hasFile('image')) {
                $uploaded = $images->upload($request->file('image'), 'products');
                $data['image'] = $uploaded['url'];
                $data['image_public_id'] = $uploaded['public_id'];
            }
            $product = Product::create($data);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => "Impossible d'enregistrer l'image du produit."], 422);
        }

        return response()->json(ProductResource::make($product)->resolve(), 201);
    }

    public function update(UpdateProductRequest $request, Product $product, CloudinaryService $images)
    {
        $data = $request->safe()->only(['nom', 'description', 'prix', 'stock', 'category_id']);

        try {
            if ($request->hasFile('image')) {
                $uploaded = $images->upload($request->file('image'), 'products');
                $images->delete($product->image_public_id);
                $this->deleteLocalImage($product->image);
                $data['image'] = $uploaded['url'];
                $data['image_public_id'] = $uploaded['public_id'];
            }
            $product->update($data);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => "Impossible de mettre à jour l'image du produit."], 422);
        }

        return response()->json(ProductResource::make($product->fresh())->resolve());
    }

    public function destroy(Request $request, Product $product, CloudinaryService $images)
    {
        $shopId = $request->user()->shop?->id;
        if ($shopId === null || (int) $product->shop_id !== (int) $shopId) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $images->delete($product->image_public_id);
        $this->deleteLocalImage($product->image);
        $product->delete();

        return response()->json(['message' => 'Produit supprimé']);
    }

    private function deleteLocalImage(?string $url): void
    {
        if (! $url) return;
        $marker = '/storage/';
        $pos = strpos($url, $marker);
        if ($pos === false) return;
        $relativePath = substr($url, $pos + strlen($marker));
        if (Storage::disk('public')->exists($relativePath)) Storage::disk('public')->delete($relativePath);
    }
}
