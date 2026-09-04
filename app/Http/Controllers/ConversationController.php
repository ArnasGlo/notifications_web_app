<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
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

        $conversations = Conversation::accessibleTo($accessibleIds)
            ->when($search !== '', fn ($q) => $q->withNumber($search))
            ->with(['numberOne', 'numberTwo', 'latestMessage'])
            ->withCount(['messages as unread_count' => function ($q) use ($accessibleIds) {
                $q->whereIn('receiver_number_id', $accessibleIds)->where('status', 'sent');
            }])
            ->orderByDesc('last_message_at')
            ->paginate(20)
            ->appends($request->only('q'));

        // Powers the Step 4 quick-jump; small by nature, so no pagination.
        $jumpList = Conversation::accessibleTo($accessibleIds)
            ->with(['numberOne', 'numberTwo'])
            ->orderByDesc('last_message_at')
            ->get();

        return view('messages.index', compact('conversations', 'jumpList', 'accessibleIds'));
    }

    /** Conversation list scoped to a single number the viewer owns or assists. */
    public function forNumber(Number $number)
    {
        abort_unless($number->isAccessibleBy(auth()->user()), 403);

        // "Mine" is this one number here, so the counterpart resolves against it
        // rather than against every number the viewer can reach.
        $accessibleIds = collect([$number->id]);

        $conversations = Conversation::accessibleTo($accessibleIds)
            ->with(['numberOne', 'numberTwo', 'latestMessage'])
            ->withCount(['messages as unread_count' => function ($q) use ($number) {
                $q->where('receiver_number_id', $number->id)->where('status', 'sent');
            }])
            ->orderByDesc('last_message_at')
            ->paginate(20);

        return view('messages.number_inbox', [
            'number' => $number,
            'conversations' => $conversations,
            'accessibleIds' => $accessibleIds,
            'isOwner' => $number->user_id === auth()->id(),
        ]);
    }

    /** The chat page: full chronological history for one number pair. */
    public function show(Conversation $conversation)
    {
        abort_unless($conversation->isAccessibleBy(auth()->user()), 403);

        $accessibleIds = auth()->user()->accessibleNumberIds();

        // Opening the thread reads everything inbound in it, in one statement.
        $conversation->messages()
            ->whereIn('receiver_number_id', $accessibleIds)
            ->where('status', 'sent')
            ->update(['status' => 'read', 'read_at' => now()]);

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
}
