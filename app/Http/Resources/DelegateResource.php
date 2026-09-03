<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DelegateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assistant' => [
                'id' => $this->assistant->id,
                'name' => $this->assistant->name,
                'email' => $this->assistant->email,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
