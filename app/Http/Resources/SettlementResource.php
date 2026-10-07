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
            'attachment' => $this->attachment(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }

    /**
     * The embedded attachment metadata, or null when the settlement has none.
     * The GridFS id stays internal - clients use the url.
     *
     * @return array<string, mixed>|null
     */
    private function attachment(): ?array
    {
        $attachment = (array) ($this->resource->attachment ?? []);

        if ($attachment === []) {
            return null;
        }

        return [
            'original_name' => (string) ($attachment['original_name'] ?? ''),
            'mime_type' => (string) ($attachment['mime_type'] ?? 'application/octet-stream'),
            'size' => (int) ($attachment['size'] ?? 0),
            'uploaded_by' => (string) ($attachment['uploaded_by'] ?? ''),
            'uploaded_at' => (string) ($attachment['uploaded_at'] ?? ''),
            'url' => url('/api/settlements/' . $this->resource->stringId() . '/attachment'),
        ];
    }
}