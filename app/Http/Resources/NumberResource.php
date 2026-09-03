<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NumberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'country' => $this->country,
            'city' => $this->city,
            'status' => $this->status,
            'share_token' => $this->share_token,
            'created_at' => $this->created_at,
        ];
    }
}
