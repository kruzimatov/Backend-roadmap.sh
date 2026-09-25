<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UrlResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'long_url' => $this->long_url,
            'expires_at' => $this->expires_at,
            'is_expired' => $this->expires_at?->isPast() ?? false,
            'click_count' => $this->click_count,
        ];
    }
}
