<?php

declare(strict_types=1);

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Base model for every collection in this project.
 *
 * MongoDB ids are ObjectId values, but Eloquent and every API layer work with
 * them as strings. All reference fields in this application store the string
 * form, so all equality checks must use it too: use stringId().
 */
abstract class BaseModel extends Model
{
    protected $connection = 'mongodb';

    /**
     * The primary key as the 24-character hexadecimal string used by all
     * reference fields in this application.
     */
    public function stringId(): string
    {
        return (string) $this->getKey();
    }
}