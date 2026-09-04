<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InviteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'number' => [
                'id' => $this->id,
                'number' => $this->number,
                'country' => $this->country,
                'city' => $this->city,
            ],
            'owner' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
            'is_owner' => $user ? $this->user_id === $user->id : false,
            'already_assistant' => $user ? $this->hasAssistant($user) : false,
        ];
    }
}
