<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    protected $fillable = ['product_id', 'url', 'public_id', 'position'];

    public function product() { return $this->belongsTo(Product::class); }
}
