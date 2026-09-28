<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\Attachment;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private attachments:
 *  - the file type is decided by sniffing the content (finfo), never by the client's MIME;
 *    the extension must be on the allow-list and match the detected type;
 *  - files are stored on a private disk under a random name with no extension;
 *  - downloads always go through ownership checks and are sent as attachments with nosniff.
 */
final class AttachmentService
{
    public function __construct(private readonly Entitlements $entitlements, private readonly ActivityLogger $activity) {}

    public function disk(): string
    {
        return (string) config('planner.attachments.disk', 'attachments');
    }

    public function owner(string $type, int $id, User $user): Model
    {
        $class = Attachment::TYPES[$type] ?? null;
        abort_if($class === null, 404);
        return $class::query()->ownedBy($user->id)->findOrFail($id);
    }

    public function store(User $user, string $type, int $id, UploadedFile $file): Attachment
    {
        $this->entitlements->ensureFeature($user, 'attachments');
        $owner = $this->owner($type, $id, $user);

        if (!$file->isValid()) {
            throw ValidationException::withMessages(['file' => __('attachments.invalid')]);
        }
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > (int) config('planner.attachments.max_kb', 10240) * 1024) {
            throw ValidationException::withMessages(['file' => __('attachments.too_large', ['mb' => (int) round(config('planner.attachments.max_kb', 10240) / 1024)])]);
        }
        $ext = strtolower((string) pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
        $types = (array) config('planner.attachments.types', []);
        $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (!isset($types[$ext]) || !in_array($detected, $types[$ext], true)) {
            throw ValidationException::withMessages(['file' => __('attachments.type_not_allowed')]);
        }
        $this->entitlements->ensureStorageFor($user, $size);

        $path = $user->id.'/'.now()->format('Y/m').'/'.Str::random(40);
        $stream = fopen($file->getRealPath(), 'rb');
        Storage::disk($this->disk())->put($path, $stream);
        if (is_resource($stream)) fclose($stream);

        try {
            $a = Attachment::create([
                'user_id' => $user->id, 'attachable_type' => $type, 'attachable_id' => $owner->getKey(), 'disk' => $this->disk(), 'path' => $path,
                'original_name' => self::safeName((string) $file->getClientOriginalName(), $ext), 'mime' => $detected, 'extension' => $ext,
                'size' => $size, 'sha256' => hash_file('sha256', $file->getRealPath()),
            ]);
        } catch (\Throwable $e) {
            Storage::disk($this->disk())->delete($path);
            throw $e;
        }
        $this->activity->log('attachment_added', get_class($owner), $owner->getKey(), null, ['file' => $a->original_name, 'size' => $size]);
        return $a;
    }

    public function download(Attachment $a): StreamedResponse
    {
        $disk = Storage::disk($a->disk);
        abort_unless($disk->exists($a->path), 404);
        $name = $a->original_name;
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', Str::ascii($name)) ?: 'file.'.$a->extension;
        return response()->stream(function () use ($disk, $a): void {
            $s = $disk->readStream($a->path);
            fpassthru($s);
            if (is_resource($s)) fclose($s);
        }, 200, [
            'Content-Type' => $a->mime,
            'Content-Length' => (string) $a->size,
            'Content-Disposition' => 'attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function delete(Attachment $a): void
    {
        DB::transaction(function () use ($a): void {
            $class = Attachment::TYPES[$a->attachable_type] ?? null;
            $a->delete();
            if ($class) {
                $this->activity->log('attachment_deleted', $class, $a->attachable_id, ['file' => $a->original_name], null);
            }
        });
        Storage::disk($a->disk)->delete($a->path);
    }

    /** Remove every attachment of a deleted task/project (or of a deleted account). */
    public function purge(?string $type, ?int $id, ?int $userId = null): void
    {
        $q = Attachment::withoutGlobalScopes();
        if ($userId !== null) $q->where('user_id', $userId);
        if ($type !== null) $q->where('attachable_type', $type)->where('attachable_id', $id);
        foreach ($q->get() as $a) {
            try { Storage::disk($a->disk)->delete($a->path); } catch (\Throwable) {}
            $a->delete();
        }
    }

    public static function safeName(string $name, string $ext): string
    {
        $base = pathinfo(str_replace(['\\', "\0"], ['/', ''], $name), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', ' ', $base));
        $base = mb_substr($base !== '' ? $base : 'file', 0, 150);
        return $base.'.'.$ext;
    }
}
