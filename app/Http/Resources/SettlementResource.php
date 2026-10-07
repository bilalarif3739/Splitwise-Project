<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Settlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Settlement */
final class SettlementResource extends JsonResource
{
    /** Section 25's document shape, ids only - names come from the members endpoint. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->stringId(),
            'group_id' => $this->resource->group_id,
            'paid_by' => $this->resource->paid_by,
            'paid_to' => $this->resource->paid_to,
            'amount' => (float) $this->resource->amount,
            'note' => $this->resource->note,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}