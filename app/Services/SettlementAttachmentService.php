<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Group;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\GridFS\Bucket;
use MongoDB\GridFS\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settlement receipts stored in MongoDB GridFS.
 *
 * The binary lives in the GridFS bucket "attachments" - GridFS splits it into
 * chunks, so the 16 MB BSON document limit does not apply to it. The small
 * metadata that is always read together with the settlement is embedded in the
 * settlement document itself.
 */
final class SettlementAttachmentService
{
    private const BUCKET = 'attachments';

    /** Resolve a settlement by id and make sure the user may see it (section 14). */
    public function resolveForUser(string $settlementId, User $user): Settlement
    {
        // A malformed id can never match a document, so it is a 404 - not a 500.
        if (preg_match('/^[a-f0-9]{24}$/i', $settlementId) !== 1) {
            abort(404, 'Settlement not found.');
        }

        $settlement = Settlement::find($settlementId);

        if ($settlement === null) {
            abort(404, 'Settlement not found.');
        }

        $group = Group::find((string) $settlement->group_id);

        if ($group === null || !$group->hasMember($user->stringId())) {
            abort(403, 'You do not have access to this settlement.');
        }

        return $settlement;
    }

    public function store(Settlement $settlement, UploadedFile $file, User $user): void
    {
        // One attachment per settlement: drop the previous binary instead of
        // leaving an orphan behind in the bucket.
        $this->deleteBinary((array) ($settlement->attachment ?? []));

        $stream = fopen($file->getRealPath(), 'rb');

        try {
            $fileId = $this->bucket()->uploadFromStream($file->getClientOriginalName(), $stream, [
                'metadata' => [
                    'settlement_id' => $settlement->stringId(),
                    'group_id' => (string) $settlement->group_id,
                    'uploaded_by' => $user->stringId(),
                ],
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $settlement->attachment = [
            'gridfs_id' => (string) $fileId,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => (int) $file->getSize(),
            'uploaded_by' => $user->stringId(),
            'uploaded_at' => now()->toIso8601String(),
        ];

        $settlement->save();
    }

    public function download(Settlement $settlement): StreamedResponse
    {
        $attachment = (array) ($settlement->attachment ?? []);

        if ($attachment === []) {
            abort(404, 'This settlement has no attachment.');
        }

        try {
            $stream = $this->bucket()->openDownloadStream(new ObjectId((string) $attachment['gridfs_id']));
        } catch (FileNotFoundException) {
            abort(404, 'The attachment file is missing.');
        }

        return response()->stream(
            function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => (string) ($attachment['mime_type'] ?? 'application/octet-stream'),
                'Content-Disposition' => 'inline; filename="' . (string) ($attachment['original_name'] ?? 'attachment') . '"',
            ],
        );
    }

    public function delete(Settlement $settlement): void
    {
        $attachment = (array) ($settlement->attachment ?? []);

        if ($attachment === []) {
            return;
        }

        $this->deleteBinary($attachment);

        $settlement->attachment = null;
        $settlement->save();
    }

    /**
     * Remove the binary from the bucket, keeping the settlement document alone.
     *
     * @param array<string, mixed> $attachment
     */
    private function deleteBinary(array $attachment): void
    {
        if ($attachment === [] || ($attachment['gridfs_id'] ?? '') === '') {
            return;
        }

        try {
            $this->bucket()->delete(new ObjectId((string) $attachment['gridfs_id']));
        } catch (FileNotFoundException) {
            // The binary is already gone - nothing left to clean up.
        }
    }

    private function bucket(): Bucket
    {
        // getClient()/getDatabase(): getMongoClient() is deprecated since 5.2.
        return DB::connection('mongodb')
            ->getDatabase()
            ->selectGridFSBucket(['bucketName' => self::BUCKET]);
    }
}