<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateExpenseRequest;
use App\Http\Requests\ExpenseFilterRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Http\Resources\GroupResource;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\Expense;
use App\Models\Group;
use App\Models\User;
use App\Services\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenseService,
    ) {
    }

    public function index(ExpenseFilterRequest $request): JsonResponse
    {
        /** @var Group $group */
        $group = $request->attributes->get('group');

        $result = $this->expenseService->history(
            $group->stringId(),
            $request->filters(),
            $request->page(),
            $request->perPage(),
        );

        $items = $result['items']
            ->map(fn(Expense $expense): array => (new ExpenseResource($expense))->resolve($request))
            ->values()
            ->all();

        return ApiResponse::success([
            'items' => $items,
            'pagination' => [
                'current_page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'last_page' => $result['last_page'],
            ],
        ], 'Expenses retrieved successfully.');
    }

    public function store(CreateExpenseRequest $request): JsonResponse
    {
        /** @var Group $group */
        $group = $request->attributes->get('group');

        $expense = $this->expenseService->create($group, $request->user(), $request->validated());

        return ApiResponse::success(new ExpenseResource($expense), 'Expense created successfully.', 201);
    }

    /** Section 27 wants the expense, its group, the payer and the individual shares. */
    public function show(Request $request): JsonResponse
    {
        [$expense, $group] = $this->expenseService->resolveForUser(
            (string) $request->route('expense'),
            $request->user(),
        );

        $payer = User::find($expense->paid_by);

        return ApiResponse::success([
            'expense' => (new ExpenseResource($expense))->resolve($request),
            'group' => (new GroupResource($group))->resolve($request),
            'payer' => $payer !== null ? (new UserResource($payer))->resolve($request) : null,
        ], 'Expense retrieved successfully.');
    }

    public function update(UpdateExpenseRequest $request): JsonResponse
    {
        [$expense, $group] = $this->expenseService->resolveForUser(
            (string) $request->route('expense'),
            $request->user(),
        );

        $expense = $this->expenseService->update($expense, $group, $request->validated(), $request->user());

        return ApiResponse::success(new ExpenseResource($expense), 'Expense updated successfully.');
    }

    public function destroy(Request $request): JsonResponse
    {
        [$expense, $group] = $this->expenseService->resolveForUser(
            (string) $request->route('expense'),
            $request->user(),
        );

        $this->expenseService->delete($expense, $group, $request->user());

        return ApiResponse::success(null, 'Expense deleted successfully.');
    }
}