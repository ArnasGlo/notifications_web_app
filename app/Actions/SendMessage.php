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

        $templateId = $attributes['template_id'] ?? null;
        $body = $attributes['body'] ?? null;

        // A template with no body override sends verbatim; this is what the web
        // compose wizard does, and what "send this canned response" means for the API.
        if (blank($body)) {
            $body = MessageTemplate::findOrFail($templateId)->body;
        }

        return Message::create([
            'sender_number_id' => $sender->id,
            'receiver_number_id' => $receiver->id,
            'template_id' => $templateId,
            'body' => $body,
            'status' => $receiver->user->status === 'busy' ? 'queued' : 'sent',
        ]);
    }
}
