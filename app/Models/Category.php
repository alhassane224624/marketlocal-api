<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = ['nom'];

    public function products()
    {
        return $this->belongsToMany(Product::class)->withTimestamps();
    }

    // Produits dont c'est la catégorie principale (colonne products.category_id).
    public function mainProducts()
    {
        return $this->hasMany(Product::class);
    }
}
