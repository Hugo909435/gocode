<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'           => 'sometimes|required|string|max:255',
            'path'           => 'sometimes|nullable|string|max:1024',
            'git_init'       => 'sometimes|nullable|boolean',
            'default_branch' => 'sometimes|nullable|string|max:255',
            'stack'          => 'sometimes|nullable|string|max:255',
            'description'    => 'sometimes|nullable|string',
            'git_remote'     => 'sometimes|nullable|string|max:2048',
            'metadata'       => 'sometimes|nullable|array',
        ];
    }
}
