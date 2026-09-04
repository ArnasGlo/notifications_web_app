<?php

namespace App\Actions;

use App\Exceptions\CannotSendMessage;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;

/**
 * The reply sequence both MessageController@reply methods used to duplicate.
 *
 * Replies deliberately bypass the DND / blocking / busy checks that SendMessage
 * applies and are always stored as 'sent' — a documented asymmetry
 * (ANDROID_APP_CONTEXT.md §3), not an oversight.
 */
class ReplyToMessage
{
    /**
     * @throws CannotSendMessage
     */
    public function __invoke(User $actor, Message $message, MessageTemplate $template): Message
    {
        if (! $actor->accessibleNumberIds()->contains($message->receiver_number_id)) {
            throw CannotSendMessage::notOnTheReceivingSide();
        }

        if ($message->isReply()) {
            throw CannotSendMessage::cannotReplyToAReply();
        }

        if ($message->hasReply()) {
            throw CannotSendMessage::alreadyReplied();
        }

        if (! $message->canBeRepliedWith($template)) {
            throw CannotSendMessage::templateNotValidAsReply();
        }

        return Message::create([
            'sender_number_id' => $message->receiver_number_id,
            'receiver_number_id' => $message->sender_number_id,
            'template_id' => $template->id,
            'body' => $template->body,
            'parent_id' => $message->id,
            'status' => 'sent',
        ]);
    }
}
