<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    protected $fillable = [
        'tenant_id',
        'customer_id',
        'plate_number',
        'brand',
        'model',
        'year',
        'color',
        'vin',
        'notes',
    ];

    protected $casts = [
        'year' => 'integer',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }
}
