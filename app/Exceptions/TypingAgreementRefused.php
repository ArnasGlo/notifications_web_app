<?php

namespace App\Exceptions;

use Exception;

/**
 * A typing-agreement change was refused by a domain rule.
 *
 * Same shape as CannotSendMessage: the wording lives here, the web app flashes
 * it and the API aborts with it.
 */
class TypingAgreementRefused extends Exception
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function notTheOwner(): self
    {
        return new self('Only the owner of this number can manage its typing agreements.', 403);
    }

    public static function alreadyActive(): self
    {
        return new self('Typing is already allowed in this conversation.', 422);
    }

    public static function alreadyRequested(): self
    {
        return new self('A typing request is already waiting in this conversation.', 422);
    }

    public static function nothingToAccept(): self
    {
        return new self('There is no typing request for you to accept.', 422);
    }

    public static function nothingToRemove(): self
    {
        return new self('There is no typing agreement to remove.', 422);
    }

    public static function automatic(): self
    {
        return new self('Both numbers allow typing, so this agreement cannot be removed. Turn off "Allow typing in chat" on your number instead.', 422);
    }
}
