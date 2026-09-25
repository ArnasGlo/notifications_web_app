<?php

namespace App\Http\Controllers;

use App\Exceptions\TypingAgreementRefused;
use App\Models\Conversation;
use App\Models\Number;
use Illuminate\Http\Request;

/**
 * The chat page's typing-agreement buttons, and the compose page's check of
 * whether it may offer free text. The rules live on Conversation, shared with
 * Api\TypingAgreementController.
 */
class TypingAgreementController extends Controller
{
    public function store(Conversation $conversation)
    {
        return $this->change($conversation, function (Conversation $conversation) {
            $agreement = $conversation->requestTyping(auth()->user());

            return $agreement->isAccepted()
                ? 'Typing is now allowed in this conversation.'
                : 'Typing request sent.';
        });
    }

    public function accept(Conversation $conversation)
    {
        return $this->change($conversation, function (Conversation $conversation) {
            $conversation->acceptTyping(auth()->user());

            return 'Typing is now allowed in this conversation.';
        });
    }

    public function destroy(Conversation $conversation)
    {
        return $this->change($conversation, function (Conversation $conversation) {
            $conversation->removeTyping(auth()->user());

            return 'Typing agreement removed. Only templates can be sent now.';
        });
    }

    /** For the compose page, where the two numbers may not have a conversation yet. */
    public function allowed(Request $request)
    {
        $data = $request->validate([
            'sender_number_id' => ['required', 'integer', 'exists:numbers,id'],
            'receiver_number_id' => ['required', 'integer', 'exists:numbers,id'],
        ]);

        $sender = Number::findOrFail($data['sender_number_id']);
        $receiver = Number::findOrFail($data['receiver_number_id']);

        abort_unless($sender->user_id === auth()->id(), 403);

        return response()->json(['typing_allowed' => Conversation::typingAllowedBetween($sender, $receiver)]);
    }

    private function change(Conversation $conversation, callable $change)
    {
        abort_unless($conversation->isAccessibleBy(auth()->user()), 403);

        try {
            $success = $change($conversation);
        } catch (TypingAgreementRefused $e) {
            abort_if($e->status === 403, 403);

            return redirect()->route('conversations.show', $conversation)->with('error', $e->getMessage());
        }

        return redirect()->route('conversations.show', $conversation)->with('success', $success);
    }
}
