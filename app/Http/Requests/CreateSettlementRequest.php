<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // group.member handles membership; the service enforces the rest
    }

    public function rules(): array
    {
        return [
            // The authenticated user is always the payer (section 23), so no
            // paid_by field is accepted from the client.
            'paid_to' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/i'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'paid_to.regex' => 'The receiver must be a valid user id.',
            'amount.gt' => 'The settlement amount must be greater than zero.',
        ];
    }
}