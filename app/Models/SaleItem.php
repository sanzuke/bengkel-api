<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'product_id',
        'product_type',
        'description',
        'quantity',
        'unit_price',
        'original_price',
        'price_adjusted',
        'adjustment_reason',
        'discount_amount',
        'subtotal',
        'warranty_days',
        'warranty_expires_at',
    ];

    protected $casts = [
        'quantity'            => 'decimal:2',
        'unit_price'          => 'decimal:2',
        'original_price'      => 'decimal:2',
        'total_price'         => 'decimal:2',
        'price_adjusted'      => 'boolean',
        'warranty_days'       => 'integer',
        'warranty_expires_at' => 'date',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Is this item still under warranty?
     */
    public function isUnderWarranty(): bool
    {
        if (!$this->warranty_expires_at) {
            return false;
        }
        return now()->lt($this->warranty_expires_at);
    }
}
