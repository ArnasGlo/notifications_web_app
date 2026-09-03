<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a Delegate row (loaded with the `number.user` relation) to expose
 * the delegated Number plus its owner, mirroring the "Numbers I Assist"
 * section of resources/views/numbers/index.blade.php.
 */
class AssistingNumberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->number->id,
            'number' => $this->number->number,
            'country' => $this->number->country,
            'city' => $this->number->city,
            'status' => $this->number->status,
            'owner' => [
                'id' => $this->number->user->id,
                'name' => $this->number->user->name,
            ],
        ];
    }
}
