<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Artisan;

/**
 * Tâches planifiées déclenchées par une URL (pour les hébergeurs sans processus de fond,
 * ex. Wasmer Edge) : à appeler toutes les 10-15 min avec cron-job.org.
 * L'URL contient un jeton dérivé de APP_KEY : `php artisan cron:url` l'affiche.
 */
class CronController extends Controller
{
    public static function token(): string
    {
        return substr(hash_hmac('sha256', 'marketlocal-cron', (string) config('app.key')), 0, 32);
    }

    // GET /api/cron/{token}
    public function run(string $token)
    {
        if (! hash_equals(self::token(), $token)) {
            abort(404);
        }

        Artisan::call('orders:cancel-expired');
        $commandes = trim(Artisan::output());
        Artisan::call('deliveries:auto-confirm');
        $livraisons = trim(Artisan::output());

        return response()->json(['ok' => true, 'commandes' => $commandes, 'livraisons' => $livraisons]);
    }
}
