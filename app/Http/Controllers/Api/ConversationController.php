<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ConversationMessagesRequest;
use App\Http\Requests\Api\ConversationUpdatesRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
            ->withListData($accessibleIds)
            ->orderByDesc('last_message_at')
            ->paginate(20)
            ->appends($request->only('q'));

        $this->attachViewerSides($conversations->getCollection(), $accessibleIds);

        return ConversationResource::collection($conversations);
    }

    public function show(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->isAccessibleBy($request->user()), 403);

        $accessibleIds = $request->user()->accessibleNumberIds();

        $conversation->markInboundRead($accessibleIds);

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

    // ── Incremental updates ──────────────────────────────────────────────────
    // What a client polls (and what a push notification would tell it to fetch).
    // Both carry meta.server_time: hand it back as `since` next time so the
    // client's own clock never decides what counts as new.

    /** Threads whose activity moved since the client last synced. */
    public function updates(ConversationUpdatesRequest $request)
    {
        $accessibleIds = $request->user()->accessibleNumberIds();

        $conversations = Conversation::accessibleTo($accessibleIds)
            ->updatedSince($request->validated('since'))
            ->withListData($accessibleIds)
            ->orderByDesc('last_message_at')
            ->limit(50)
            ->get();

        $this->attachViewerSides($conversations, $accessibleIds);

        return ConversationResource::collection($conversations)
            ->additional(['meta' => ['server_time' => now()->toIso8601String()]]);
    }

    /**
     * Messages in one thread newer than the client's cursor.
     *
     * Marks inbound messages read, exactly as show() does — asking for the live
     * end of an open thread is the same signal as opening it.
     */
    public function messages(ConversationMessagesRequest $request, Conversation $conversation)
    {
        abort_unless($conversation->isAccessibleBy($request->user()), 403);

        $accessibleIds = $request->user()->accessibleNumberIds();

        $conversation->markInboundRead($accessibleIds);

        $messages = $conversation->messagesAfter((int) $request->validated('after_id'))
            ->with(['sender', 'receiver', 'template.category'])
            ->get();

        return MessageResource::collection($messages)
            ->additional(['meta' => ['server_time' => now()->toIso8601String()]]);
    }

    /**
     * Which side is "theirs" depends on the viewer, so resolve it once per row
     * here instead of letting the resource re-query for every item.
     */
    private function attachViewerSides(Collection $conversations, Collection $accessibleIds): void
    {
        $conversations->each(function (Conversation $conversation) use ($accessibleIds) {
            $conversation->setRelation('counterpart', $conversation->counterpartFor($accessibleIds));
            $conversation->setRelation('myNumber', $conversation->myNumberFor($accessibleIds));
        });
    }
}
