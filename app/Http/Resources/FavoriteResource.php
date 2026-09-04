<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exposes the favorited number only — deliberately no owner name.
 *
 * Favoriting is unilateral: anyone can favorite any number without the owner's
 * consent. AssistingNumberResource and InviteResource do expose an owner name,
 * but both sit behind an actual relationship (a delegation, or holding the share
 * token). Keeping this to NumberLookupResource's shape means a favorite reveals
 * nothing that GET /api/numbers/search doesn't already.
 */
class FavoriteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => [
                'id' => $this->number->id,
                'number' => $this->number->number,
                'country' => $this->number->country,
                'city' => $this->number->city,
                'status' => $this->number->status,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
