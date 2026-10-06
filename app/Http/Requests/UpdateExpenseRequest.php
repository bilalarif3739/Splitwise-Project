<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // payer/owner rule is enforced in ExpenseService
    }

    /** Every field is optional: an update may change one thing or all of them. */
    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'paid_by' => ['sometimes', 'required', 'string', 'regex:/^[a-f0-9]{24}$/i'],
            'split_type' => ['sometimes', 'required', Rule::in(Expense::SPLIT_TYPES)],

            'participants' => ['sometimes', 'required', 'array', 'min:1'],
            'participants.*.user_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/i', 'distinct'],
            'participants.*.amount' => ['nullable', 'numeric', 'gt:0'],
            'participants.*.percentage' => ['nullable', 'numeric', 'gt:0', 'lte:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.gt' => 'The expense amount must be greater than zero.',
            'participants.*.user_id.distinct' => 'The same user cannot appear twice in the split.',
        ];
    }
}