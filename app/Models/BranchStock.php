<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchStock extends Model
{
    protected $fillable = [
        'tenant_id',
        'product_id',
        'branch_id',
        'stock',
        'reserved_quantity',
        'min_stock',
        'selling_price',
        'purchase_price',
    ];

    protected $casts = [
        'stock'              => 'decimal:2',
        'reserved_quantity'  => 'decimal:2',
        'min_stock'          => 'integer',
        'selling_price'      => 'decimal:2',
        'purchase_price'     => 'decimal:2',
    ];

    // ── Relationships ──

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    // ── Scopes ──

    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeLowStock($query)
    {
        return $query->whereColumn('stock', '<=', 'min_stock')
                     ->where('min_stock', '>', 0);
    }

    // ── Helpers ──

    /**
     * Get effective selling price (branch override or master fallback).
     */
    public function getEffectiveSellingPriceAttribute(): float
    {
        return $this->selling_price ?? $this->product->selling_price ?? 0;
    }

    /**
     * Get effective purchase price (branch override or master fallback).
     */
    public function getEffectivePurchasePriceAttribute(): float
    {
        return $this->purchase_price ?? $this->product->purchase_price ?? 0;
    }

    /**
     * Available stock = physical stock minus reserved.
     */
    public function getAvailableStockAttribute(): float
    {
        return max(0, (float) $this->stock - (float) $this->reserved_quantity);
    }
}
