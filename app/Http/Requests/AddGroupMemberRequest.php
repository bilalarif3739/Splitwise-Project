<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AddGroupMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is enforced by the group.owner middleware
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // A MongoDB id is 24 hex characters. Checking the shape here means
            // a malformed id is a clear 422 instead of a silent no-match query.
            'user_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.regex' => 'The user id must be a valid 24-character MongoDB id.',
        ];
    }
}