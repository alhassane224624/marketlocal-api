<?php

namespace App\Providers;

use App\Http\Middleware\EnsureRole;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Notifications\Messages\MailMessage;
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
        $this->configurePasswordResetMail();
    }

    /** Lien vers la page du frontend (et non une route Laravel), e-mail en français. */
    private function configurePasswordResetMail(): void
    {
        $url = fn ($user, string $token) => config('app.frontend_url')
            .'/reset-password?token='.$token.'&email='.urlencode($user->email);

        ResetPassword::toMailUsing(fn ($user, string $token) => (new MailMessage)
            ->subject('Réinitialisation de votre mot de passe MarketLocal')
            ->greeting('Bonjour '.$user->name.',')
            ->line('Vous avez demandé à réinitialiser le mot de passe de votre compte MarketLocal.')
            ->action('Choisir un nouveau mot de passe', $url($user, $token))
            ->line('Ce lien est valable 60 minutes.')
            ->line('Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail : votre mot de passe reste inchangé.')
            ->salutation('L’équipe MarketLocal'));
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

        // Mot de passe oublié : limite l'envoi d'e-mails en rafale et les essais de jetons.
        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinutes(10, 5)->by($request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Trop de demandes. Réessayez dans quelques minutes.',
                ], 429));
        });

        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });
    }
}