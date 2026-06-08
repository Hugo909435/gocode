<?php

namespace App\Http\Requests\Session;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode'   => ['sometimes', 'string', Rule::in(['read', 'plan', 'execute'])],
            'status' => ['sometimes', 'string', Rule::in([
                'idle', 'reading', 'planning', 'awaiting_confirmation',
                'building', 'running', 'done', 'error',
            ])],
        ];
    }
}
