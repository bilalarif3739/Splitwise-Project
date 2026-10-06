<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Http\Responses\ApiResponse;
use App\Models\Group;
use App\Services\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenseService,
    ) {
    }

    public function store(CreateExpenseRequest $request): JsonResponse
    {
        /** @var Group $group */
        $group = $request->attributes->get('group');

        $expense = $this->expenseService->create($group, $request->user(), $request->validated());

        return ApiResponse::success(
            new ExpenseResource($expense),
            'Expense created successfully.',
            201,
        );
    }
}