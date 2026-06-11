<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'path' => $this->path,
            'default_branch' => $this->default_branch,
            'stack' => $this->stack,
            'description' => $this->description,
            'git_remote' => $this->git_remote,
            'clone_status' => $this->clone_status,
            'clone_error' => $this->clone_error,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
