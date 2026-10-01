<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restreint une route à un ou plusieurs rôles.
 *
 * Usage : ->middleware('role:admin')  |  ->middleware('role:vendeur,admin')
 * À placer APRÈS auth:sanctum (l'utilisateur doit déjà être authentifié).
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        if (! in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'Accès non autorisé pour votre rôle'], 403);
        }

        return $next($request);
    }
}
