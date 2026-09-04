<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'status' => $this->status,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
            'sender' => [
                'id' => $this->sender->id,
                'number' => $this->sender->number,
                'user_id' => $this->sender->user_id,
            ],
            'receiver' => [
                'id' => $this->receiver->id,
                'number' => $this->receiver->number,
                'user_id' => $this->receiver->user_id,
            ],
            'template' => [
                'id' => $this->template->id,
                'body' => $this->template->body,
                'category' => [
                    'id' => $this->template->category->id,
                    'name' => $this->template->category->name,
                    'icon' => $this->template->category->icon,
                ],
            ],
            'replies' => $this->whenLoaded('replies', fn () => $this->replies->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'created_at' => $r->created_at,
                'sender' => [
                    'id' => $r->sender->id,
                    'number' => $r->sender->number,
                    'user_id' => $r->sender->user_id,
                ],
                'receiver' => [
                    'id' => $r->receiver->id,
                    'number' => $r->receiver->number,
                    'user_id' => $r->receiver->user_id,
                ],
                'template' => [
                    'id' => $r->template->id,
                    'body' => $r->template->body,
                ],
            ])->values()),
            'reply_templates' => $this->whenLoaded('replyTemplates', fn () => $this->replyTemplates->map(fn ($t) => [
                'id' => $t->id,
                'body' => $t->body,
            ])->values()),
        ];
    }
}
