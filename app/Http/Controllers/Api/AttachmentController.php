<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Services\Planner\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentService $attachments) {}

    public function index(Request $request, string $type, int $id): JsonResponse
    {
        $owner = $this->attachments->owner($type, $id, $request->user());
        return response()->json(['data' => Attachment::query()->where('attachable_type', $type)->where('attachable_id', $owner->getKey())->latest('id')->get()]);
    }

    public function store(Request $request, string $type, int $id): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);
        return response()->json($this->attachments->store($request->user(), $type, $id, $request->file('file')), 201);
    }

    public function download(Attachment $attachment): StreamedResponse
    {
        return $this->attachments->download($attachment);
    }

    public function destroy(Attachment $attachment): JsonResponse
    {
        $this->attachments->delete($attachment);
        return response()->json(null, 204);
    }
}
