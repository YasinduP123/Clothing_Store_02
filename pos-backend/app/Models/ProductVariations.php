<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariations extends Model
{
    protected $table = 'product_variations';

    protected $fillable = [
        'product_id',
        'color',
        'size',
        'price',
        'quantity',
        'barcode'
    ];
    public $timestamps = true;

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
