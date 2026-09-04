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
        // This resource is served from a public route (no auth:sanctum), so nothing has
        // called Auth::shouldUse('sanctum') — a bare $request->user() would resolve the
        // default 'web' session guard and always return null for a bearer token.
        $user = $request->user('sanctum');

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
