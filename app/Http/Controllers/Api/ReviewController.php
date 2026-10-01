<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Review\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    // GET /api/products/{product}/reviews (public)
    public function index(Product $product)
    {
        return ReviewResource::collection(
            $product->reviews()->with('user:id,name')->latest()->get()
        );
    }

    // POST /api/products/{product}/reviews (acheteur ayant acheté le produit)
    public function store(StoreReviewRequest $request, Product $product)
    {
        $user = $request->user();

        $hasPurchased = $user->orders()
            ->whereIn('statut', ['payee', 'expediee', 'livree'])
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))
            ->exists();

        if (! $hasPurchased) {
            return response()->json(['message' => 'Vous devez avoir acheté ce produit pour laisser un avis'], 403);
        }

        $alreadyReviewed = Review::where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($alreadyReviewed) {
            return response()->json(['message' => 'Vous avez déjà laissé un avis pour ce produit'], 409);
        }

        try {
            $review = Review::create([
                'product_id' => $product->id,
                'user_id' => $user->id,
                'note' => $request->note,
                'commentaire' => $request->commentaire,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            return response()->json(['message' => 'Vous avez déjà laissé un avis pour ce produit'], 409);
        }

        return response()->json(ReviewResource::make($review)->resolve(), 201);
    }

    // GET /api/shop/reviews (vendeur — avis reçus sur sa boutique)
    public function shopReviews(Request $request)
    {
        $shop = $request->user()->shop;

        if (! $shop) {
            return response()->json(['message' => "Vous n'avez pas de boutique"], 404);
        }

        return ReviewResource::collection(
            Review::whereHas('product', fn ($q) => $q->where('shop_id', $shop->id))
                ->with(['product:id,nom', 'user:id,name'])
                ->latest()
                ->get()
        );
    }
}
