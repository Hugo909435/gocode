<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'           => 'required|string|max:255',
            'path'           => 'nullable|string|max:1024',
            'git_init'       => 'nullable|boolean',
            'default_branch' => 'nullable|string|max:255',
            'stack'          => 'nullable|string|max:255',
            'description'    => 'nullable|string',
            'git_remote'     => 'nullable|string|max:2048',
            'metadata'       => 'nullable|array',
        ];
    }
}
