<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SettlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class Settlement extends BaseModel
{
    /** @use HasFactory<SettlementFactory> */
    use HasFactory;

    protected $fillable = [
        'group_id',
        'paid_by',
        'paid_to',
        'amount',
        'note',
    ];

    protected $casts = [
        'amount'     => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}