<?php

declare(strict_types=1);

namespace App\Models;

/**
 * One document per active session.
 *
 * A separate collection (instead of a token column on the user) means:
 * multiple devices stay logged in independently, logging out deletes only the
 * token that was used, and the middleware's lookup is a single indexed query.
 */
final class Token extends BaseModel
{
    protected $fillable = [
        'user_id',
        'api_token',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}