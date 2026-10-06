<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // api.token handles guests, group.member handles membership
    }

    /**
     * Shape and type rules only. The sum checks (exact = total, percentage =
     * 100) depend on the split type and need arithmetic, so they are enforced
     * in ExpenseService where the split is actually calculated.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'paid_by' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/i'],
            'split_type' => ['required', Rule::in(Expense::SPLIT_TYPES)],

            'participants' => ['required', 'array', 'min:1'],
            'participants.*.user_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/i', 'distinct'],
            'participants.*.amount' => ['nullable', 'numeric', 'gt:0'],
            'participants.*.percentage' => ['nullable', 'numeric', 'gt:0', 'lte:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.gt' => 'The expense amount must be greater than zero.',
            'paid_by.regex' => 'The payer must be a valid user id.',
            'participants.*.user_id.regex' => 'Each participant must be a valid user id.',
            'participants.*.user_id.distinct' => 'The same user cannot appear twice in the split.',
            'participants.*.amount.gt' => 'Each participant amount must be greater than zero.',
            'participants.*.percentage.lte' => 'Each percentage must be at most 100.',
        ];
    }
}