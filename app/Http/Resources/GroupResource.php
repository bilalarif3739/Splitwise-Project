<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Group */
final class GroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $memberIds = array_values((array) $this->resource->member_ids);
        $currentUserId = $request->user()?->stringId();

        return [
            'id' => $this->resource->stringId(),
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'owner_id' => $this->resource->owner_id,
            'member_ids' => $memberIds,
            'members_count' => count($memberIds),
            // Convenience flag so the client can show/hide owner-only actions.
            'is_owner' => $currentUserId !== null && $this->resource->isOwnedBy($currentUserId),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}