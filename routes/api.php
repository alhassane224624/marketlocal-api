<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\StripeConnectController;
use Illuminate\Support\Facades\Route;

// Webhook Stripe : appelé par Stripe (signature vérifiée dans le contrôleur), hors limitation.
Route::post('/stripe/webhook', [PaymentController::class, 'webhook']);

// ---------------------------------------------------------------------------
// Routes publiques
// ---------------------------------------------------------------------------
Route::middleware('throttle:api')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/shops', [ShopController::class, 'index']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show'])->whereNumber('product');
    Route::get('/products/{product}/reviews', [ReviewController::class, 'index'])->whereNumber('product');
});

// ---------------------------------------------------------------------------
// Routes authentifiées (token Sanctum)
// ---------------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    // --- Tous les rôles ---
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me', [AuthController::class, 'updateProfile']);

    // Adresses de livraison (aucune restriction de rôle : chacun gère les siennes)
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::put('/addresses/{address}', [AddressController::class, 'update'])->whereNumber('address');
    Route::delete('/addresses/{address}', [AddressController::class, 'destroy'])->whereNumber('address');
    Route::put('/addresses/{address}/defaut', [AddressController::class, 'setDefault'])->whereNumber('address');

    // --- Acheteur ---
    Route::middleware('role:acheteur')->group(function () {
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/mine', [OrderController::class, 'mine']); // avant /orders/{order}
        Route::post('/orders/{order}/payer', [PaymentController::class, 'createPaymentIntent'])
            ->whereNumber('order')
            ->middleware('throttle:sensitive');
        Route::post('/products/{product}/reviews', [ReviewController::class, 'store'])->whereNumber('product');
    });

    // Détail d'une commande : acheteur, vendeur concerné ou admin (contrôlé dans le contrôleur)
    Route::get('/orders/{order}', [OrderController::class, 'show'])->whereNumber('order');

    // --- Vendeur ---
    Route::middleware('role:vendeur')->group(function () {
        Route::post('/shops', [ShopController::class, 'store']);
        Route::get('/shops/mine', [ShopController::class, 'mine']);
        Route::put('/shops/mine', [ShopController::class, 'update']);

        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{product}', [ProductController::class, 'update'])->whereNumber('product');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->whereNumber('product');

        Route::get('/shop/orders', [OrderController::class, 'shopOrders']);
        Route::get('/shop/stats', [OrderController::class, 'shopStats']);
        Route::get('/shop/reviews', [ReviewController::class, 'shopReviews']);

        Route::post('/shop/stripe/onboard', [StripeConnectController::class, 'onboard']);
        Route::get('/shop/stripe/status', [StripeConnectController::class, 'status']);
        Route::post('/shop/stripe/settle-pending', [StripeConnectController::class, 'settlePendingTransfers']);
    });

    // --- Vendeur ou admin : changer le statut d'une commande ---
    Route::put('/orders/{order}/statut', [OrderController::class, 'updateStatus'])
        ->whereNumber('order')
        ->middleware('role:vendeur,admin');

    // --- Admin ---
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/stats', [AdminController::class, 'stats']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/orders', [OrderController::class, 'adminIndex']);
        Route::post('/orders/{order}/rembourser', [PaymentController::class, 'refund'])->whereNumber('order');

        Route::get('/shops', [ShopController::class, 'adminIndex']);
        Route::put('/shops/{shop}/valider', [ShopController::class, 'validate'])->whereNumber('shop');
        Route::put('/shops/{shop}/refuser', [ShopController::class, 'refuse'])->whereNumber('shop');
        Route::put('/shops/{shop}/commission', [ShopController::class, 'updateCommission'])->whereNumber('shop');

        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->whereNumber('category');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->whereNumber('category');
    });
});