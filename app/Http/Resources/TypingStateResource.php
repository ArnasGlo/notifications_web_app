<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A conversation's typing state as the viewer sees it — the same array
 * ConversationResource embeds as `typing`. Expects the viewer's side attached
 * as the `myNumber` relation, as Api\ConversationController does.
 */
class TypingStateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->typingStateFor($this->myNumber, $request->user());
    }
}
