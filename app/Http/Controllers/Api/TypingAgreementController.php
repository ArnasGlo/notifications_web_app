<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TypingAgreementRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TypingAllowedRequest;
use App\Http\Resources\TypingStateResource;
use App\Models\Conversation;
use App\Models\Number;
use Illuminate\Http\Request;

/**
 * Typing agreements: whether free text may be sent in a conversation, and the
 * owner's request/accept/remove actions. Every action answers with the new state.
 * The rules live on Conversation, shared with the web's TypingAgreementController.
 */
class TypingAgreementController extends Controller
{
    public function show(Request $request, Conversation $conversation)
    {
        return new TypingStateResource($this->forViewer($request, $conversation));
    }

    public function store(Request $request, Conversation $conversation)
    {
        $conversation = $this->forViewer($request, $conversation);

        $this->attempt(fn () => $conversation->requestTyping($request->user()));

        return (new TypingStateResource($conversation))->response()->setStatusCode(201);
    }

    public function accept(Request $request, Conversation $conversation)
    {
        $conversation = $this->forViewer($request, $conversation);

        $this->attempt(fn () => $conversation->acceptTyping($request->user()));

        return new TypingStateResource($conversation);
    }

    public function destroy(Request $request, Conversation $conversation)
    {
        $conversation = $this->forViewer($request, $conversation);

        $this->attempt(fn () => $conversation->removeTyping($request->user()));

        return new TypingStateResource($conversation);
    }

    /** For the compose screen, where the two numbers may not have a conversation yet. */
    public function allowed(TypingAllowedRequest $request)
    {
        $sender = Number::findOrFail($request->validated('sender_number_id'));
        $receiver = Number::findOrFail($request->validated('receiver_number_id'));

        abort_unless($sender->user_id === $request->user()->id, 403);

        return response()->json(['data' => [
            'typing_allowed' => Conversation::typingAllowedBetween($sender, $receiver),
        ]]);
    }

    private function forViewer(Request $request, Conversation $conversation): Conversation
    {
        abort_unless($conversation->isAccessibleBy($request->user()), 403);

        return $conversation->setRelation(
            'myNumber',
            $conversation->myNumberFor($request->user()->accessibleNumberIds()),
        );
    }

    private function attempt(callable $change): void
    {
        try {
            $change();
        } catch (TypingAgreementRefused $e) {
            abort($e->status, $e->getMessage());
        }
    }
}
