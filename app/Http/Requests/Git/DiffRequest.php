<?php

namespace App\Http\Requests\Git;

use Illuminate\Foundation\Http\FormRequest;

class DiffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === null) {
                        return;
                    }
                    // Rejette les chemins absolus et les traversées de répertoire parent
                    if (
                        str_contains($value, '..')
                        || str_starts_with($value, '/')
                        || str_starts_with($value, '\\')
                    ) {
                        $fail('The file path must be relative and cannot traverse parent directories.');
                    }
                },
            ],
        ];
    }
}
