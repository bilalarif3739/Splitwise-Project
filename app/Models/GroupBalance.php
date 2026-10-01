<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Materialised net balance per user per group.
 *
 * Positive value  -> the user should receive money.
 * Negative value  -> the user owes money.
 *
 * Written inside the same MongoDB session/transaction as the expense or
 * settlement that changes it (Phase 4/6), and rebuildable from source data
 * (Phase 5 artisan command), so it can never silently drift.
 */
final class GroupBalance extends BaseModel
{
    protected $fillable = [
        'group_id',
        'user_id',
        'net_balance',
    ];

    protected $casts = [
        'net_balance' => 'float',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];
}