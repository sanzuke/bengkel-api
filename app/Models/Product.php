<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'tenant_id',
        'category_id',
        'supplier_id',
        'sku',
        'barcode',
        'name',
        'description',
        'type',
        'unit',
        'purchase_price',
        'selling_price',
        'image',
        'is_active',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    // ── Relationships ──

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branchStocks()
    {
        return $this->hasMany(BranchStock::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    // ── Helpers ──

    /**
     * Get stock at a specific branch.
     */
    public function stockAt(int $branchId): float
    {
        return $this->branchStocks()
            ->where('branch_id', $branchId)
            ->value('stock') ?? 0;
    }

    /**
     * Get total stock across all branches.
     */
    public function totalStock(): float
    {
        return $this->branchStocks()->sum('stock');
    }

    /**
     * Get effective selling price for a branch.
     * Falls back to master price if branch has no override.
     */
    public function sellingPriceAt(int $branchId): float
    {
        $branchPrice = $this->branchStocks()
            ->where('branch_id', $branchId)
            ->value('selling_price');

        return $branchPrice ?? $this->selling_price ?? 0;
    }

    /**
     * Get effective purchase price for a branch.
     */
    public function purchasePriceAt(int $branchId): float
    {
        $branchPrice = $this->branchStocks()
            ->where('branch_id', $branchId)
            ->value('purchase_price');

        return $branchPrice ?? $this->purchase_price ?? 0;
    }
}
