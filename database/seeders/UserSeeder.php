<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Comptes de démonstration (mot de passe : « password »).
 * Les trois premiers sont proposés en un clic sur la page de connexion.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['Admin MarketLocal', 'admin@marketlocal.test', 'admin'],
            ['Amina El Idrissi', 'vendeur@marketlocal.test', 'vendeur'],
            ['Karim Benali', 'acheteur@marketlocal.test', 'acheteur', '0612345678', '12 rue des Orangers', 'Casablanca'],
            ['Youssef Amrani', 'youssef@marketlocal.test', 'vendeur'],
            ['Salma Tazi', 'salma@marketlocal.test', 'vendeur'],
            ['Omar Chraibi', 'omar@marketlocal.test', 'vendeur'],
            ['Lina Mansouri', 'lina@marketlocal.test', 'acheteur', '0698765432', '5 avenue Hassan II', 'Rabat'],
        ];

        foreach ($users as $u) {
            User::create([
                'name' => $u[0],
                'email' => $u[1],
                'password' => Hash::make('password'),
                'role' => $u[2],
                'telephone' => $u[3] ?? null,
                'adresse' => $u[4] ?? null,
                'ville' => $u[5] ?? null,
            ]);
        }

        foreach (User::where('role', 'acheteur')->get() as $buyer) {
            $buyer->addresses()->create([
                'libelle' => 'Domicile',
                'nom_destinataire' => $buyer->name,
                'telephone' => $buyer->telephone,
                'adresse' => $buyer->adresse,
                'ville' => $buyer->ville,
                'est_par_defaut' => true,
            ]);
        }
    }
}
