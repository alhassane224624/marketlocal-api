<?php

namespace App\Providers;

use App\Http\Middleware\EnsureRole;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Alias du middleware de rôle : role:admin, role:vendeur,admin ...
        Route::aliasMiddleware('role', EnsureRole::class);

        // Les Resources renvoient l'objet directement (sans enveloppe "data").
        JsonResource::withoutWrapping();

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return Limit::perMinute(5)
                ->by($email . '|' . $request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Trop de tentatives de connexion. Réessayez dans une minute.',
                ], 429));
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });

        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });
    }
}