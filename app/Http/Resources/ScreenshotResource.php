<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScreenshotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'url' => $this->url,
            'name' => $this->name,
            'width' => $this->width,
            'height' => $this->height,
            'file_size' => $this->file_size,
            'thumbnail_url' => $this->thumbnail_url,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
