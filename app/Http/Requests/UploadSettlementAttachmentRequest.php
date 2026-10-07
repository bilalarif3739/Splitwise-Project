<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UploadSettlementAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the attachment service resolves the settlement and checks membership
    }

    public function rules(): array
    {
        return [
            // A screenshot or a PDF receipt, at most 5 MB.
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please choose a file to upload.',
            'file.mimes' => 'The receipt must be a JPG, PNG, WEBP or PDF file.',
            'file.max' => 'The receipt may not be larger than 5 MB.',
        ];
    }
}