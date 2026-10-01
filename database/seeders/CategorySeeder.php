<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Artisanat',
            'Alimentation locale',
            'Cosmétiques naturels',
            'Vêtements & textiles',
            'Décoration',
        ];

        foreach ($categories as $nom) {
            Category::create(['nom' => $nom]);
        }
    }
}