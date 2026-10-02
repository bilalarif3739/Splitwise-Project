<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // login is a public endpoint
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Deliberately no "exists" rule: whether the account exists is
            // decided in the service and always answered with the same 401,
            // so this endpoint cannot be used to enumerate registered emails.
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}