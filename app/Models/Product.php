<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    public const MAX_IMAGES = 8;
    public const MAX_CATEGORIES = 5;

    protected $fillable = [
        'shop_id', 'category_id', 'nom', 'description', 'prix', 'stock', 'image', 'image_public_id',
    ];

    public function shop() { return $this->belongsTo(Shop::class); }

    // Catégorie principale (= première de la liste), conservée pour compatibilité.
    public function category() { return $this->belongsTo(Category::class); }
    public function categories() { return $this->belongsToMany(Category::class)->withTimestamps()->orderByPivot('id'); }

    public function images() { return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id'); }
    public function reviews() { return $this->hasMany(Review::class); }
    public function orderItems() { return $this->hasMany(OrderItem::class); }

    /**
     * Recopie la 1re image (couverture) et la 1re catégorie dans les colonnes
     * historiques products.image / products.category_id.
     */
    public function syncCoverAndMainCategory(): void
    {
        $cover = $this->images()->first();
        $mainCategoryId = $this->categories()->value('categories.id');

        $this->update([
            'image' => $cover?->url,
            'image_public_id' => $cover?->public_id,
            'category_id' => $mainCategoryId ?? $this->category_id,
        ]);
    }
}
