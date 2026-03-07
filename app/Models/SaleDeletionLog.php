<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleDeletionLog extends Model
{
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'invoice_number',
        'sale_data',
        'items_data',
        'total_amount',
        'customer_name',
        'deleted_by',
        'deletion_reason',
        'original_sale_date',
    ];

    protected $casts = [
        'sale_data' => 'array',
        'items_data' => 'array',
        'total_amount' => 'decimal:2',
        'original_sale_date' => 'datetime',
    ];

    /**
     * Get the tenant that owns this log.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the branch where the deleted sale originated.
     */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Get the user who deleted the sale.
     */
    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
