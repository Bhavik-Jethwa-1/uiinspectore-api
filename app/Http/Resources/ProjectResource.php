<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Optimized Project Resource
 * Only returns fields actually needed by the frontend
 */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'url' => $this->url,
            'status' => $this->status,
            'template' => $this->template,
            'tags' => $this->tags ?? [],
            'device' => $this->when(isset($this->settings['device']), $this->settings['device']),
            'screens' => $this->when(!$this->relationLoaded('screenshots') && !empty($this->settings['screens']), 
                fn() => $this->settings['screens'] ?? []),
            'thumbnail' => $this->when($this->thumbnail, $this->thumbnail),
            'screenshots_count' => (int) ($this->screenshots_count ?? 0),
            'issues_count' => (int) ($this->issues_count ?? 0),
            'analyses_count' => $this->when(isset($this->analyses_count), fn() => (int) $this->analyses_count),
            'tasks_count' => $this->when(isset($this->tasks_count), fn() => (int) $this->tasks_count),
            'team_count' => $this->when(isset($this->team_count), fn() => (int) $this->team_count),
            'reports_count' => $this->when(isset($this->reports_count), fn() => (int) $this->reports_count),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
