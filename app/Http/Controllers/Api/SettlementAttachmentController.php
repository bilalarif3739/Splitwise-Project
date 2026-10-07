<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadSettlementAttachmentRequest;
use App\Http\Resources\SettlementResource;
use App\Http\Responses\ApiResponse;
use App\Services\SettlementAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SettlementAttachmentController extends Controller
{
    public function __construct(
        private readonly SettlementAttachmentService $attachmentService,
    ) {
    }

    public function store(UploadSettlementAttachmentRequest $request, string $settlement): JsonResponse
    {
        $model = $this->attachmentService->resolveForUser($settlement, $request->user());

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');

        $this->attachmentService->store($model, $file, $request->user());

        return ApiResponse::success(
            new SettlementResource($model),
            'Attachment uploaded successfully.',
            201,
        );
    }

    public function show(Request $request, string $settlement): StreamedResponse
    {
        $model = $this->attachmentService->resolveForUser($settlement, $request->user());

        return $this->attachmentService->download($model);
    }

    public function destroy(Request $request, string $settlement): JsonResponse
    {
        $model = $this->attachmentService->resolveForUser($settlement, $request->user());

        $this->attachmentService->delete($model);

        return ApiResponse::success((object) [], 'Attachment deleted successfully.');
    }
}