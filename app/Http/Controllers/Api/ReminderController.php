<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reminder;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReminderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Reminder::query()->with('task')->orderBy('scheduled_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return response()->json($query->paginate(50));
    }

    public function show(Reminder $reminder): JsonResponse
    {
        return response()->json($reminder->load('task'));
    }

    public function update(Request $request, Reminder $reminder): JsonResponse
    {
        $data = $request->validate([
            'task_id' => 'nullable|integer|exists:tasks,id',
            'type' => 'nullable|string|max:50',
            'scheduled_at' => 'sometimes|date',
            'chat_id' => 'nullable|string|max:100',
            'message' => 'nullable|string|max:4000',
            'status' => 'sometimes|in:pending,sent,failed,cancelled',
        ]);
        $payload = $reminder->payload ?? [];
        if (array_key_exists('chat_id',$data)) $payload['chat_id']=$data['chat_id'];
        if (array_key_exists('message',$data)) $payload['message']=$data['message'];
        unset($data['chat_id'],$data['message']);
        if ($payload) $data['payload']=$payload;
        $reminder->update($data);
        return response()->json($reminder->refresh()->load('task'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'task_id' => 'nullable|integer|exists:tasks,id',
            'type' => 'nullable|string|max:50',
            'scheduled_at' => 'required|date',
            'chat_id' => 'required|string|max:100',
            'message' => 'nullable|string|max:4000',
        ]);

        $reminder = Reminder::create([
            'task_id' => $data['task_id'] ?? null,
            'type' => $data['type'] ?? 'telegram',
            'scheduled_at' => $data['scheduled_at'],
            'status' => 'pending',
            'payload' => [
                'chat_id' => $data['chat_id'],
                'message' => $data['message'] ?? null,
            ],
        ]);

        return response()->json($reminder->load('task'), 201);
    }

    public function destroy(Reminder $reminder): JsonResponse
    {
        $reminder->delete();
        return response()->json(['deleted' => true]);
    }

    public function cancel(Reminder $reminder): JsonResponse
    {
        $reminder->update(['status' => 'cancelled']);
        return response()->json($reminder->refresh());
    }
}
