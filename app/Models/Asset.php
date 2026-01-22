<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'code',
        'name',
        'category',
        'description',
        'brand',
        'model',
        'serial_number',
        'purchase_date',
        'purchase_price',
        'current_value',
        'condition',
        'status',
        'location',
        'warranty_expiry',
        'last_maintenance_date',
        'next_maintenance_date',
        'photo',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'warranty_expiry' => 'date',
        'last_maintenance_date' => 'date',
        'next_maintenance_date' => 'date',
        'purchase_price' => 'decimal:2',
        'current_value' => 'decimal:2',
    ];

    // Category constants
    const CATEGORY_EQUIPMENT = 'equipment';
    const CATEGORY_TOOL = 'tool';
    const CATEGORY_VEHICLE = 'vehicle';
    const CATEGORY_FURNITURE = 'furniture';
    const CATEGORY_ELECTRONICS = 'electronics';

    // Condition constants
    const CONDITION_EXCELLENT = 'excellent';
    const CONDITION_GOOD = 'good';
    const CONDITION_FAIR = 'fair';
    const CONDITION_POOR = 'poor';
    const CONDITION_BROKEN = 'broken';

    // Status constants
    const STATUS_ACTIVE = 'active';
    const STATUS_IN_MAINTENANCE = 'in_maintenance';
    const STATUS_DISPOSED = 'disposed';
    const STATUS_SOLD = 'sold';

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function maintenances()
    {
        return $this->hasMany(AssetMaintenance::class);
    }

    public function getCategoryLabelAttribute()
    {
        return match($this->category) {
            self::CATEGORY_EQUIPMENT => 'Peralatan',
            self::CATEGORY_TOOL => 'Alat',
            self::CATEGORY_VEHICLE => 'Kendaraan',
            self::CATEGORY_FURNITURE => 'Furniture',
            self::CATEGORY_ELECTRONICS => 'Elektronik',
            default => $this->category,
        };
    }

    public function getConditionLabelAttribute()
    {
        return match($this->condition) {
            self::CONDITION_EXCELLENT => 'Sangat Baik',
            self::CONDITION_GOOD => 'Baik',
            self::CONDITION_FAIR => 'Cukup',
            self::CONDITION_POOR => 'Buruk',
            self::CONDITION_BROKEN => 'Rusak',
            default => $this->condition,
        };
    }

    public function getStatusLabelAttribute()
    {
        return match($this->status) {
            self::STATUS_ACTIVE => 'Aktif',
            self::STATUS_IN_MAINTENANCE => 'Dalam Perbaikan',
            self::STATUS_DISPOSED => 'Dibuang',
            self::STATUS_SOLD => 'Dijual',
            default => $this->status,
        };
    }
}
