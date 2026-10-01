<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /** Durée de validité d'un token Sanctum, en jours. */
    private const TOKEN_TTL_DAYS = 7;

    // POST /api/register
    public function register(RegisterRequest $request)
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
        ]);

        return response()->json([
            'user' => UserResource::make($user)->resolve(),
            'token' => $this->issueToken($user),
        ], 201);
    }

    // POST /api/login
    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Identifiants incorrects'], 401);
        }

        return response()->json([
            'user' => UserResource::make($user)->resolve(),
            'token' => $this->issueToken($user),
        ]);
    }

    // POST /api/logout
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnexion réussie']);
    }

    // GET /api/me
    public function me(Request $request)
    {
        return response()->json(UserResource::make($request->user())->resolve());
    }

    // PUT /api/me
    public function updateProfile(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json(UserResource::make($user)->resolve());
    }

    private function issueToken(User $user): string
    {
        return $user
            ->createToken('auth_token', ['*'], now()->addDays(self::TOKEN_TTL_DAYS))
            ->plainTextToken;
    }
}
