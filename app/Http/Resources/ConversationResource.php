<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A thread as the viewer sees it.
 *
 * `counterpart` and `my_number` are viewer-relative, so the controller resolves
 * them once per row (via Conversation::counterpartFor/myNumberFor) and attaches
 * them with setRelation — the same trick MessageController@show uses for
 * replyTemplates — rather than making this resource re-query per item.
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latest = $this->latestMessage;

        return [
            'id' => $this->id,
            'counterpart' => $this->counterpart ? [
                'id' => $this->counterpart->id,
                'number' => $this->counterpart->number,
                'country' => $this->counterpart->country,
                'city' => $this->counterpart->city,
                'status' => $this->counterpart->status,
            ] : null,
            'my_number' => $this->myNumber ? [
                'id' => $this->myNumber->id,
                'number' => $this->myNumber->number,
            ] : null,
            'last_message' => $latest ? [
                'id' => $latest->id,
                'body' => $latest->body,
                'status' => $latest->status,
                'created_at' => $latest->created_at,
                'is_outbound' => $latest->sender_number_id === $this->myNumber?->id,
            ] : null,
            'unread_count' => (int) ($this->unread_count ?? 0),
            'last_message_at' => $this->last_message_at,
        ];
    }
}
