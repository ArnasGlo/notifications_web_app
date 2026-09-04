<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use Illuminate\Http\Request;

/**
 * Conversation-oriented endpoints so a client never has to rebuild threads from
 * a flat message list. GET /api/messages is untouched and still works.
 */
class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $accessibleIds = $request->user()->accessibleNumberIds();
        $search = trim((string) $request->input('q'));

        $conversations = Conversation::accessibleTo($accessibleIds)
            ->when($search !== '', fn ($q) => $q->withNumber($search))
            ->with(['numberOne', 'numberTwo', 'latestMessage'])
            ->withCount(['messages as unread_count' => function ($q) use ($accessibleIds) {
                $q->whereIn('receiver_number_id', $accessibleIds)->where('status', 'sent');
            }])
            ->orderByDesc('last_message_at')
            ->paginate(20)
            ->appends($request->only('q'));

        // Which side is "theirs" depends on the viewer, so resolve it once per
        // row here instead of letting the resource re-query for every item.
        $conversations->getCollection()->each(function (Conversation $conversation) use ($accessibleIds) {
            $conversation->setRelation('counterpart', $conversation->counterpartFor($accessibleIds));
            $conversation->setRelation('myNumber', $conversation->myNumberFor($accessibleIds));
        });

        return ConversationResource::collection($conversations);
    }

    public function show(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->isAccessibleBy($request->user()), 403);

        $accessibleIds = $request->user()->accessibleNumberIds();

        $conversation->messages()
            ->whereIn('receiver_number_id', $accessibleIds)
            ->where('status', 'sent')
            ->update(['status' => 'read', 'read_at' => now()]);

        // Replies included — the whole point of a thread view. Newest-first
        // pagination so page 1 is the live end, re-sorted within the page so the
        // payload still reads oldest to newest.
        $messages = $conversation->messages()
            ->with(['sender', 'receiver', 'template.category'])
            ->latest()
            ->paginate(50);

        $messages->setCollection($messages->getCollection()->sortBy('created_at')->values());

        return MessageResource::collection($messages);
    }
}
