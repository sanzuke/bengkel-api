<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'customer_code',
        'customer_type',
        'discount_percentage',
        'name',
        'phone',
        'email',
        'address',
        'notes',
    ];

    protected $casts = [
        'discount_percentage' => 'decimal:2',
    ];

    /**
     * Valid customer types
     */
    public const TYPES = [
        'walk_in'  => 'Walk-in',
        'regular'  => 'Regular',
        'reseller' => 'Reseller',
        'member'   => 'Member',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }
}
