<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetMaintenance extends Model
{
    protected $fillable = [
        'asset_id',
        'maintenance_type',
        'maintenance_date',
        'completed_date',
        'status',
        'performed_by',
        'cost',
        'description',
        'findings',
        'actions_taken',
        'next_maintenance',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'maintenance_date' => 'date',
        'completed_date' => 'date',
        'next_maintenance' => 'date',
        'cost' => 'decimal:2',
    ];

    // Type constants
    const TYPE_ROUTINE = 'routine';
    const TYPE_REPAIR = 'repair';
    const TYPE_UPGRADE = 'upgrade';
    const TYPE_INSPECTION = 'inspection';

    // Status constants
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getTypeLabelAttribute()
    {
        return match($this->maintenance_type) {
            self::TYPE_ROUTINE => 'Rutin',
            self::TYPE_REPAIR => 'Perbaikan',
            self::TYPE_UPGRADE => 'Upgrade',
            self::TYPE_INSPECTION => 'Inspeksi',
            default => $this->maintenance_type,
        };
    }

    public function getStatusLabelAttribute()
    {
        return match($this->status) {
            self::STATUS_SCHEDULED => 'Terjadwal',
            self::STATUS_IN_PROGRESS => 'Dalam Proses',
            self::STATUS_COMPLETED => 'Selesai',
            self::STATUS_CANCELLED => 'Dibatalkan',
            default => $this->status,
        };
    }
}
