<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class Group extends BaseModel
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'owner_id',
        'member_ids',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function hasMember(string $userId): bool
    {
        return in_array($userId, (array) $this->member_ids, true);
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->owner_id === $userId;
    }
}