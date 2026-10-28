<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('mot de passe oublié : lien vers le frontend, nouveau mot de passe, sessions fermées', function () {
    Notification::fake();
    config(['app.frontend_url' => 'https://front.test']);
    $user = User::create(['name' => 'A', 'email' => 'reset@example.com', 'password' => Hash::make('ancien-mdp'), 'role' => 'acheteur']);
    $user->createToken('test');

    // Même réponse pour un e-mail inconnu : on ne révèle pas les comptes existants.
    $inconnu = $this->postJson('/api/mot-de-passe/oublie', ['email' => 'personne@example.com'])->assertOk()->json('message');
    $this->postJson('/api/mot-de-passe/oublie', ['email' => ' Reset@Example.com '])->assertOk()->assertJsonPath('message', $inconnu);

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user, &$token) {
        $token = $n->token;
        $mail = $n->toMail($user);

        return str_starts_with($mail->actionUrl, 'https://front.test/reset-password?token=')
            && str_contains($mail->actionUrl, 'email=reset%40example.com');
    });

    $this->postJson('/api/mot-de-passe/reinitialiser', [
        'token' => 'faux', 'email' => 'reset@example.com', 'password' => 'nouveau-mdp', 'password_confirmation' => 'nouveau-mdp',
    ])->assertStatus(422);

    $this->postJson('/api/mot-de-passe/reinitialiser', [
        'token' => $token, 'email' => 'reset@example.com', 'password' => 'nouveau-mdp', 'password_confirmation' => 'nouveau-mdp',
    ])->assertOk();

    expect($user->tokens()->count())->toBe(0);
    $this->postJson('/api/login', ['email' => 'reset@example.com', 'password' => 'ancien-mdp'])->assertStatus(401);
    $this->postJson('/api/login', ['email' => 'reset@example.com', 'password' => 'nouveau-mdp'])->assertOk();

    // Le lien ne sert qu'une fois.
    $this->postJson('/api/mot-de-passe/reinitialiser', [
        'token' => $token, 'email' => 'reset@example.com', 'password' => 'autre-mdp1', 'password_confirmation' => 'autre-mdp1',
    ])->assertStatus(422);
});
