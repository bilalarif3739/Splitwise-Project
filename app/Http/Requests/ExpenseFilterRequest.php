<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validates the query string of GET /api/groups/{group}/expenses (section 26). */
final class ExpenseFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payer' => ['sometimes', 'string', 'regex:/^[a-f0-9]{24}$/i'],
            'split_type' => ['sometimes', 'string', Rule::in(Expense::SPLIT_TYPES)],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'sort_by' => ['sometimes', 'string', Rule::in(['created_at', 'amount'])],
            'sort_dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'payer' => $this->input('payer'),
            'split_type' => $this->input('split_type'),
            'date_from' => $this->input('date_from'),
            'date_to' => $this->input('date_to'),
            'sort_by' => $this->input('sort_by', 'created_at'),
            'sort_dir' => $this->input('sort_dir', 'desc'),
        ];
    }

    public function page(): int
    {
        return max($this->integer('page', 1), 1);
    }

    public function perPage(): int
    {
        return min(max($this->integer('per_page', 15), 1), 100);
    }
}