<?php

namespace App\Exceptions;

use Exception;

/**
 * A send or reply was refused by a domain rule.
 *
 * Carries the HTTP status each refusal maps to, so the wording lives in one
 * place while the two controllers keep their own response styles (the API
 * aborts with JSON, the web app flashes and redirects).
 */
class CannotSendMessage extends Exception
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    // ── Sending ──────────────────────────────────────────────────────────

    public static function notTheSendersOwner(): self
    {
        return new self('You can only send from your own numbers.', 403);
    }

    public static function numberInactive(): self
    {
        return new self('Both the sending and receiving numbers must be active.', 422);
    }

    public static function undeliverable(): self
    {
        return new self('This number cannot receive your message (blocked or DND).', 422);
    }

    // ── Replying ─────────────────────────────────────────────────────────

    public static function notOnTheReceivingSide(): self
    {
        return new self('You can only reply to messages sent to your numbers.', 403);
    }

    public static function cannotReplyToAReply(): self
    {
        return new self('You cannot reply to a reply.', 422);
    }

    public static function alreadyReplied(): self
    {
        return new self('A reply has already been sent for this message.', 422);
    }

    public static function templateNotValidAsReply(): self
    {
        return new self('This template cannot be used as a reply to this message.', 422);
    }
}
