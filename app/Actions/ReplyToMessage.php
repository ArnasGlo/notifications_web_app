<?php

namespace App\Actions;

use App\Exceptions\CannotSendMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;

/**
 * The reply sequence shared by the web composer (MessageController@store with a
 * parent_id) and the API (Api\MessageController@reply).
 *
 * A reply is a mapped template, typed text, or a template edited into typed text.
 * Which of those it is decides the delivery checks:
 *
 *  - Template replies skip the DND and block checks. Their content is
 *    admin-controlled — only answers mapped to the original prompt — so the
 *    original sender can't receive anything they didn't in effect ask for.
 *  - Free-text replies need typing to be allowed between the two numbers, and
 *    enforce DND and blocks. A message can be replied to any number of times,
 *    so an exempt free-text path would be an unlimited way to message someone
 *    who is on DND or has blocked you, and would make both unenforceable.
 *  - Every reply skips the busy queue and is stored as 'sent' — a documented
 *    asymmetry with SendMessage (ANDROID_APP_CONTEXT.md §3).
 */
class ReplyToMessage
{
    /**
     * @throws CannotSendMessage
     */
    public function __invoke(User $actor, Message $message, ?MessageTemplate $template, ?string $body = null): Message
    {
        if ($refusal = $message->replyRefusal($actor->accessibleNumberIds())) {
            throw $refusal;
        }

        $content = Message::contentFrom($template, $body);

        if (is_null($content['template_id'])) {
            // Free text: the original sender's number is the recipient here.
            if (! Conversation::typingAllowedBetween($message->receiver, $message->sender)) {
                throw CannotSendMessage::typingNotAllowed();
            }

            if (! $message->sender->canReceiveFrom($message->receiver)) {
                throw CannotSendMessage::undeliverable();
            }
        } elseif (! $message->canBeRepliedWith($template)) {
            throw CannotSendMessage::templateNotValidAsReply();
        }

        return Message::create($content + [
            'sender_number_id' => $message->receiver_number_id,
            'receiver_number_id' => $message->sender_number_id,
            'parent_id' => $message->id,
            'status' => 'sent',
        ]);
    }
}
