<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Expense */
final class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->stringId(),
            'group_id' => $this->resource->group_id,
            'description' => $this->resource->description,
            'amount' => (float) $this->resource->amount,
            'paid_by' => $this->resource->paid_by,
            'split_type' => $this->resource->split_type,
            'participants' => $this->mapParticipants(),
            'created_by' => $this->resource->created_by,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Amounts are cast to float and ids to string so the JSON never contains a
     * BSON type or a stringified number.
     *
     * @return list<array{user_id: string, amount: float, percentage: float|null}>
     */
    private function mapParticipants(): array
    {
        return array_values(array_map(static function ($participant): array {
            $participant = (array) $participant;

            return [
                'user_id' => (string) ($participant['user_id'] ?? ''),
                'amount' => (float) ($participant['amount'] ?? 0),
                'percentage' => isset($participant['percentage']) ? (float) $participant['percentage'] : null,
            ];
        }, (array) ($this->resource->participants ?? [])));
    }
}