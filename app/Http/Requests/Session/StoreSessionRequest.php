<?php

namespace App\Http\Requests\Session;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', 'string', Rule::in(['read', 'plan', 'execute'])],
            'initial_instruction' => ['nullable', 'string'],
        ];
    }
}
