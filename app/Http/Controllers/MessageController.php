<?php

namespace App\Http\Controllers;

use App\Actions\ReplyToMessage;
use App\Actions\SendMessage;
use App\Exceptions\CannotSendMessage;
use App\Models\Message;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use Illuminate\Http\Request;

/**
 * Composing and sending. Reading happens in ConversationController — the chat
 * page replaced the per-message thread view, so there is no web `show` here.
 * Replies are sent through store() with a parent_id, from the chat composer's
 * reply mode.
 */
class MessageController extends Controller
{
    public function compose()
    {
        $myNumbers = auth()->user()->numbers()->where('status', 'active')->get();
        $categories = MessageCategory::composePayload();
        return view('messages.compose', compact('myNumbers', 'categories'));
    }

    public function store(Request $request, SendMessage $send, ReplyToMessage $reply)
    {
        $data = $request->validate([
            // A reply's sender and receiver come from the message it answers.
            'parent_id' => 'nullable|integer|exists:messages,id',
            'sender_number_id' => 'required_without:parent_id|nullable|exists:numbers,id',
            'receiver_number_id' => 'required_without:parent_id|nullable|exists:numbers,id|different:sender_number_id',
            'body' => 'required_without:template_id|nullable|string|max:255',
            'template_id' => 'nullable|exists:message_templates,id',
        ]);

        try {
            $message = empty($data['parent_id'])
                ? $send(auth()->user(), $data)
                : $reply(
                    auth()->user(),
                    Message::findOrFail($data['parent_id']),
                    empty($data['template_id']) ? null : MessageTemplate::findOrFail($data['template_id']),
                    $data['body'] ?? null,
                );
        } catch (CannotSendMessage $e) {
            abort_if($e->status === 403, 403);

            // Keep what was typed. The page re-renders from current state, and
            // reply mode is only restored if the message can still be replied to.
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Land in the thread the message just joined — right for every entry
        // point: the compose wizard, the chat composer and its reply mode.
        return redirect()->route('conversations.show', $message->conversation_id)
            ->with('success', $message->parent_id ? 'Reply sent!' : 'Message sent!');
    }
}
