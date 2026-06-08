<?php

namespace App\Http\Requests\Session;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendInstructionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'instruction' => ['required', 'string', 'max:50000'],
            'mode'        => ['nullable', 'string', Rule::in(['read', 'plan', 'execute'])],
        ];
    }
}
