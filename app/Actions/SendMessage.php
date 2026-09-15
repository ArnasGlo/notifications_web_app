<?php

namespace App\Actions;

use App\Exceptions\CannotSendMessage;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Number;
use App\Models\User;

/**
 * The send sequence both MessageController@store methods used to duplicate:
 * sender ownership -> both numbers active -> blocking/DND -> busy routing.
 *
 * Not a general service layer — just the orchestration that has two callers.
 * The entity predicates it leans on (Number::canReceiveFrom) stay on the model.
 */
class SendMessage
{
    /**
     * @param  array<string, mixed>  $attributes  validated sender/receiver/template ids
     *
     * @throws CannotSendMessage
     */
    public function __invoke(User $actor, array $attributes): Message
    {
        $sender = Number::findOrFail($attributes['sender_number_id']);
        $receiver = Number::findOrFail($attributes['receiver_number_id']);

        if ($sender->user_id !== $actor->id) {
            throw CannotSendMessage::notTheSendersOwner();
        }

        if ($sender->status !== 'active' || $receiver->status !== 'active') {
            throw CannotSendMessage::numberInactive();
        }

        if (! $receiver->canReceiveFrom($sender)) {
            throw CannotSendMessage::undeliverable();
        }

        $template = isset($attributes['template_id'])
            ? MessageTemplate::findOrFail($attributes['template_id'])
            : null;

        // Both composers let the user edit an inserted template; contentFrom()
        // decides whether the result is still that template or typed text, here
        // rather than in either client.
        return Message::create(Message::contentFrom($template, $attributes['body'] ?? null) + [
            'sender_number_id' => $sender->id,
            'receiver_number_id' => $receiver->id,
            'status' => $receiver->user->status === 'busy' ? 'queued' : 'sent',
        ]);
    }
}
