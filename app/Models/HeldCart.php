<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HeldCart extends Model
{
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'customer_id',
        'items',
        'label',
        'notes',
        'held_by',
    ];

    protected $casts = [
        'items' => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function heldByUser()
    {
        return $this->belongsTo(User::class, 'held_by');
    }
}
