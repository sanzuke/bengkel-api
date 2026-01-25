<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'user_id',
        'nik',
        'name',
        'email',
        'phone',
        'address',
        'position',
        'department',
        'join_date',
        'termination_date',
        'status',
        'photo_url',
        'pin_code',
        'face_descriptor',
    ];

    protected $casts = [
        'join_date' => 'date',
        'termination_date' => 'date',
    ];

    protected $hidden = [
        'pin_code',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
