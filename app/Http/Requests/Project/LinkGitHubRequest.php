<?php

namespace App\Http\Requests\Project;

use App\Services\GitHubService;
use Illuminate\Foundation\Http\FormRequest;

class LinkGitHubRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'repo_url' => 'required|string|max:2048',
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            $url = $this->input('repo_url');

            if ($url && app(GitHubService::class)->extractGitHubPath($url) === null) {
                $v->errors()->add('repo_url', 'Format invalide — attendu : https://github.com/owner/repo');
            }
        });
    }
}
