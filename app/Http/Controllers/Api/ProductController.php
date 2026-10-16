<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ListProductsRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\CloudinaryService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductController extends Controller
{
    public function index(ListProductsRequest $request)
    {
        $query = Product::with(['shop:id,nom,logo', 'category:id,nom', 'categories:id,nom'])
            ->withAvg('reviews', 'note')
            ->withCount('reviews')
            ->visible();

        if ($request->filled('category_id')) {
            $categoryId = (int) $request->input('category_id');
            // Catégorie principale ou secondaire.
            $query->where(fn ($q) => $q->where('category_id', $categoryId)
                ->orWhereHas('categories', fn ($c) => $c->whereKey($categoryId)));
        }
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
        $product->load([
            'shop:id,nom,logo,statut,user_id', 'category:id,nom', 'categories:id,nom', 'images',
            // Auteur de chaque avis (nom affiché sur la fiche produit), plus récents d'abord.
            'reviews' => fn ($q) => $q->with('user:id,name')->latest(),
        ])->loadAvg('reviews', 'note')->loadCount('reviews');
        $user = $request->user();
        $isOwner = $user && $user->role === 'vendeur' && (int) $product->shop?->user_id === (int) $user->id;
        $isAdmin = $user && $user->role === 'admin';

        $hidden = ! $product->en_vente || ($product->shop && $product->shop->statut !== 'valide');
        if ($hidden && ! $isOwner && ! $isAdmin) {
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

        $files = $this->uploadedFiles($request);
        if (count($files) > Product::MAX_IMAGES) {
            return response()->json(['message' => 'Un produit peut avoir au maximum '.Product::MAX_IMAGES.' images.'], 422);
        }

        $data = $request->safe()->only(['nom', 'description', 'prix', 'stock', 'en_vente']);
        $data['shop_id'] = $shop->id;
        $categoryIds = $this->requestedCategoryIds($request);
        $data['category_id'] = $categoryIds[0];

        $uploaded = [];
        try {
            $uploaded = $this->uploadAll($files, $images);

            $product = DB::transaction(function () use ($data, $categoryIds, $uploaded) {
                $product = Product::create($data);
                $product->categories()->attach($categoryIds);
                foreach ($uploaded as $position => $image) {
                    $product->images()->create([
                        'url' => $image['url'],
                        'public_id' => $image['public_id'],
                        'position' => $position,
                    ]);
                }
                $product->syncCoverAndMainCategory();

                return $product;
            });
        } catch (Throwable $e) {
            report($e);
            $this->deleteFiles($uploaded, $images);
            return response()->json(['message' => "Impossible d'enregistrer les images du produit."], 422);
        }

        return response()->json(
            ProductResource::make($product->fresh(['category', 'categories', 'images']))->resolve(),
            201
        );
    }

    public function update(UpdateProductRequest $request, Product $product, CloudinaryService $images)
    {
        $data = $request->safe()->only(['nom', 'description', 'prix', 'stock', 'en_vente']);

        $removeIds = array_map('intval', $request->input('remove_image_ids', []));
        $toRemove = $product->images()->whereIn('id', $removeIds)->get();
        $files = $this->uploadedFiles($request);

        $remaining = $product->images()->reorder()->count() - $toRemove->count() + count($files);
        if ($remaining > Product::MAX_IMAGES) {
            return response()->json(['message' => 'Un produit peut avoir au maximum '.Product::MAX_IMAGES.' images.'], 422);
        }

        $categoryIds = $request->hasAny(['category_ids', 'category_id'])
            ? $this->requestedCategoryIds($request)
            : null;

        $uploaded = [];
        try {
            $uploaded = $this->uploadAll($files, $images);

            DB::transaction(function () use ($product, $data, $categoryIds, $toRemove, $uploaded, $request) {
                $product->update($data);

                if ($categoryIds !== null) {
                    // detach + attach (plutôt que sync) pour respecter l'ordre demandé :
                    // la 1re catégorie devient la principale.
                    $product->categories()->detach();
                    $product->categories()->attach($categoryIds);
                }

                ProductImage::whereIn('id', $toRemove->pluck('id'))->delete();

                $next = (int) $product->images()->reorder()->max('position') + 1;
                foreach ($uploaded as $i => $image) {
                    $product->images()->create([
                        'url' => $image['url'],
                        'public_id' => $image['public_id'],
                        'position' => $next + $i,
                    ]);
                }

                $this->reorderImages($product, $request->integer('cover_image_id') ?: null);
                $product->syncCoverAndMainCategory();
            });
        } catch (Throwable $e) {
            report($e);
            $this->deleteFiles($uploaded, $images);
            return response()->json(['message' => 'Impossible de mettre à jour les images du produit.'], 422);
        }

        // Fichiers supprimés seulement une fois la base à jour.
        $this->deleteFiles($toRemove->map(fn ($image) => ['url' => $image->url, 'public_id' => $image->public_id])->all(), $images);

        return response()->json(
            ProductResource::make($product->fresh(['category', 'categories', 'images']))->resolve()
        );
    }

    // PUT /products/{product}/vente : retirer de la vente / remettre en vente (vendeur propriétaire).
    public function toggleSale(Request $request, Product $product)
    {
        $shopId = $request->user()->shop?->id;
        if ($shopId === null || (int) $product->shop_id !== (int) $shopId) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $data = $request->validate(['en_vente' => ['required', 'boolean']]);
        $product->update(['en_vente' => (bool) $data['en_vente']]);

        return response()->json(
            ProductResource::make($product->fresh(['category', 'categories', 'images']))->resolve()
        );
    }

    public function destroy(Request $request, Product $product, CloudinaryService $images)
    {
        $shopId = $request->user()->shop?->id;
        if ($shopId === null || (int) $product->shop_id !== (int) $shopId) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $files = $product->images->map(fn ($image) => ['url' => $image->url, 'public_id' => $image->public_id])->all();
        if ($product->image && ! collect($files)->contains('url', $product->image)) {
            $files[] = ['url' => $product->image, 'public_id' => $product->image_public_id];
        }

        $product->delete();
        $this->deleteFiles($files, $images);

        return response()->json(['message' => 'Produit supprimé']);
    }

    /** @return UploadedFile[] */
    private function uploadedFiles(Request $request): array
    {
        $files = array_values(array_filter((array) $request->file('images', [])));
        if ($request->hasFile('image')) {
            $files[] = $request->file('image');
        }

        return $files;
    }

    /** @return int[] */
    private function requestedCategoryIds(Request $request): array
    {
        $ids = $request->input('category_ids') ?: [$request->input('category_id')];

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Upload de toutes les images ; si l'une échoue, les précédentes sont supprimées.
     */
    private function uploadAll(array $files, CloudinaryService $images): array
    {
        $uploaded = [];
        try {
            foreach ($files as $file) {
                $uploaded[] = $images->upload($file, 'products');
            }
        } catch (Throwable $e) {
            $this->deleteFiles($uploaded, $images);
            throw $e;
        }

        return $uploaded;
    }

    /**
     * Renumérote les positions 0..n ; l'image $coverId (si elle appartient au produit) passe en tête.
     */
    private function reorderImages(Product $product, ?int $coverId): void
    {
        $ordered = $product->images()->get();
        if ($coverId && ($cover = $ordered->firstWhere('id', $coverId))) {
            $ordered = $ordered->reject(fn ($image) => $image->id === $cover->id)->prepend($cover);
        }

        foreach ($ordered->values() as $position => $image) {
            if ((int) $image->position !== $position) {
                $image->update(['position' => $position]);
            }
        }
    }

    private function deleteFiles(array $files, CloudinaryService $images): void
    {
        foreach ($files as $file) {
            try {
                $images->delete($file['public_id'] ?? null);
                $this->deleteLocalImage($file['url'] ?? null);
            } catch (Throwable $e) {
                report($e);
            }
        }
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
