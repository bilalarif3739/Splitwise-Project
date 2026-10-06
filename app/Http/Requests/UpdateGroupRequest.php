<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is enforced by the group.owner middleware
    }

    /**
     * "sometimes" so a request may update only the name or only the
     * description; if a field is sent it must be valid.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}