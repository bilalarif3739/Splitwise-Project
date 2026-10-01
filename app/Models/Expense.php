<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class Expense extends BaseModel
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    public const SPLIT_EQUAL = 'equal';
    public const SPLIT_EXACT = 'exact';
    public const SPLIT_PERCENTAGE = 'percentage';

    /** @var list<string> */
    public const SPLIT_TYPES = [
        self::SPLIT_EQUAL,
        self::SPLIT_EXACT,
        self::SPLIT_PERCENTAGE,
    ];

    protected $fillable = [
        'group_id',
        'description',
        'amount',
        'paid_by',
        'split_type',
        'participants',
        'created_by',
    ];

    protected $casts = [
        'amount'     => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}