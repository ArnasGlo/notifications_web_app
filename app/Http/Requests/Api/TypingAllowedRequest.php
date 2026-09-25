<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class TypingAllowedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sender_number_id' => ['required', 'integer', 'exists:numbers,id'],
            'receiver_number_id' => ['required', 'integer', 'exists:numbers,id'],
        ];
    }
}
