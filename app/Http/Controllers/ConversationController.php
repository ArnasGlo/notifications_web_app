<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageCategory;
use App\Models\Number;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    /** The Messages page: one row per thread, newest activity first. */
    public function index(Request $request)
    {
        $accessibleIds = auth()->user()->accessibleNumberIds();
        $search = trim((string) $request->input('q'));

        // Captured before the query, not after rendering: a message committed
        // while the page builds is then re-delivered by the first poll rather
        // than falling into the gap between the two.
        $syncedAt = now();

        $conversations = Conversation::accessibleTo($accessibleIds)
            ->when($search !== '', fn ($q) => $q->withNumber($search))
            ->withListData($accessibleIds)
            ->orderByDesc('last_message_at')
            ->paginate(20)
            ->appends($request->only('q'));

        // Powers the Step 4 quick-jump; small by nature, so no pagination.
        $jumpList = Conversation::accessibleTo($accessibleIds)
            ->with(['numberOne', 'numberTwo'])
            ->orderByDesc('last_message_at')
            ->get();

        return view('messages.index', compact('conversations', 'jumpList', 'accessibleIds', 'syncedAt'));
    }

    /** Conversation list scoped to a single number the viewer owns or assists. */
    public function forNumber(Number $number)
    {
        abort_unless($number->isAccessibleBy(auth()->user()), 403);

        // "Mine" is this one number here, so the counterpart resolves against it
        // rather than against every number the viewer can reach.
        $accessibleIds = collect([$number->id]);

        $syncedAt = now();

        $conversations = Conversation::accessibleTo($accessibleIds)
            ->withListData($accessibleIds)
            ->orderByDesc('last_message_at')
            ->paginate(20);

        return view('messages.number_inbox', [
            'number' => $number,
            'conversations' => $conversations,
            'accessibleIds' => $accessibleIds,
            'isOwner' => $number->user_id === auth()->id(),
            'syncedAt' => $syncedAt,
        ]);
    }

    /** The chat page: full chronological history for one number pair. */
    public function show(Conversation $conversation)
    {
        abort_unless($conversation->isAccessibleBy(auth()->user()), 403);

        $accessibleIds = auth()->user()->accessibleNumberIds();

        // Opening the thread reads everything inbound in it, in one statement.
        $conversation->markInboundRead($accessibleIds);

        // Replies are included deliberately: they are ~40% of all messages and the
        // flat inbox hid them behind its whereNull('parent_id') filter.
        //
        // Paginated newest-first so page 1 is the live end of the thread, then
        // re-sorted within the page so it still reads top-to-bottom — the "load
        // older" shape every chat client uses.
        $messages = $conversation->messages()
            ->with(['sender', 'receiver'])
            ->latest()
            ->paginate(50);

        $messages->setCollection($messages->getCollection()->sortBy('created_at')->values());

        $counterpart = $conversation->counterpartFor($accessibleIds);

        return view('conversations.show', [
            'conversation' => $conversation,
            'messages' => $messages,
            'myNumber' => $conversation->myNumberFor($accessibleIds),
            'counterpart' => $counterpart,
            'counterpartFavorite' => $counterpart
                ? auth()->user()->favorites()->where('number_id', $counterpart->id)->first()
                : null,
            'accessibleIds' => $accessibleIds,
            'categories' => MessageCategory::composePayload(),
        ]);
    }

    // ── Polling endpoints ────────────────────────────────────────────────────
    // The open page asks "what changed since X?" every few seconds. Both return
    // JSON carrying server-rendered Blade partials, so a bubble or a row looks
    // the same whether the full page or a poll produced it. The JSON-for-Android
    // equivalents live in Api\ConversationController and share the model methods
    // below them — only the trigger would change if this ever becomes push.

    /** New messages in one open thread, as rendered bubbles. */
    public function updates(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->isAccessibleBy(auth()->user()), 403);

        $data = $request->validate(['after_id' => ['nullable', 'integer', 'min:0']]);
        $afterId = (int) ($data['after_id'] ?? 0);

        $accessibleIds = auth()->user()->accessibleNumberIds();

        // Polling only runs while the tab is visible and the thread is open, so
        // this is the same "the viewer has seen it" signal as opening the page.
        $conversation->markInboundRead($accessibleIds);

        $messages = $conversation->messagesAfter($afterId)
            ->with(['sender', 'receiver'])
            ->get();

        return response()->json([
            'messages' => $messages->map(fn (Message $message) => [
                'id' => $message->id,
                'html' => view('partials.message-bubble', [
                    'message' => $message,
                    'outbound' => $accessibleIds->contains($message->sender_number_id),
                ])->render(),
            ])->values(),
            'last_id' => $messages->max('id') ?? $afterId,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /** Threads whose activity moved since the list was last synced, as rendered rows. */
    public function listUpdates(Request $request)
    {
        $data = $request->validate([
            'since' => ['required', 'date'],
            'number_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string'],
        ]);

        $accessibleIds = auth()->user()->accessibleNumberIds();

        // The per-number inbox scopes "mine" to that one number, exactly as
        // forNumber() does, so its rows resolve the same counterpart and count
        // the same unread messages.
        if (! empty($data['number_id'])) {
            $number = Number::find($data['number_id']);
            abort_unless($number && $number->isAccessibleBy(auth()->user()), 403);

            $accessibleIds = collect([$number->id]);
        }

        $search = trim((string) ($data['q'] ?? ''));

        $conversations = Conversation::accessibleTo($accessibleIds)
            ->updatedSince($data['since'])
            ->when($search !== '', fn ($q) => $q->withNumber($search))
            ->withListData($accessibleIds)
            ->orderByDesc('last_message_at')
            ->limit(20)
            ->get();

        return response()->json([
            'conversations' => $conversations->map(fn (Conversation $conversation) => [
                'id' => $conversation->id,
                'html' => view('partials.conversation-row', [
                    'conversation' => $conversation,
                    'accessibleIds' => $accessibleIds,
                ])->render(),
            ])->values(),
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
