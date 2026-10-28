<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/**
 * Mot de passe oublié : un lien par e-mail (valable 60 min, voir config/auth.php)
 * ouvre la page /reset-password du frontend, qui renvoie ici le nouveau mot de passe.
 */
class PasswordResetController extends Controller
{
    // POST /api/mot-de-passe/oublie
    public function forgot(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $request->validate(['email' => ['required', 'email']]);

        // Réponse identique que le compte existe ou non : on ne révèle pas quels e-mails sont inscrits.
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'Si un compte existe avec cette adresse, un e-mail de réinitialisation vient d’être envoyé.',
        ]);
    }

    // POST /api/mot-de-passe/reinitialiser
    public function reset(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->update(['password' => Hash::make($password)]);
            // Toutes les sessions ouvertes sont fermées (ex. compte volé).
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => $status === Password::RESET_THROTTLED
                    ? 'Trop de tentatives. Réessayez dans une minute.'
                    : 'Ce lien est invalide ou a expiré. Faites une nouvelle demande.',
            ], 422);
        }

        return response()->json(['message' => 'Mot de passe modifié. Vous pouvez vous connecter.']);
    }
}
