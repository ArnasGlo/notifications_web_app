<?php

namespace App\Http\Controllers;

use App\Actions\SendMessage;
use App\Exceptions\CannotSendMessage;
use App\Models\MessageCategory;
use Illuminate\Http\Request;

/**
 * Composing and sending. Reading happens in ConversationController — the chat
 * page replaced the per-message thread view, so there is no web `show`/`reply`
 * here any more; the API keeps both (ANDROID_APP_CONTEXT.md §6 rows 16-17).
 */
class MessageController extends Controller
{
    public function compose()
    {
        $myNumbers = auth()->user()->numbers()->where('status', 'active')->get();
        $categories = MessageCategory::composePayload();
        return view('messages.compose', compact('myNumbers', 'categories'));
    }

    public function store(Request $request, SendMessage $send)
    {
        $data = $request->validate([
            'sender_number_id' => 'required|exists:numbers,id',
            'receiver_number_id' => 'required|exists:numbers,id|different:sender_number_id',
            'body' => 'required_without:template_id|nullable|string|max:255',
            'template_id' => 'nullable|exists:message_templates,id',
        ]);

        try {
            $message = $send(auth()->user(), $data);
        } catch (CannotSendMessage $e) {
            abort_if($e->status === 403, 403);

            return back()->with('error', $e->getMessage());
        }

        // Land in the thread the message just joined — right for both entry
        // points: the compose wizard and the chat composer.
        return redirect()->route('conversations.show', $message->conversation_id)
            ->with('success', 'Message sent!');
    }
}
