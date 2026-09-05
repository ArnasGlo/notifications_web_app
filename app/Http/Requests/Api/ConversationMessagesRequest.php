<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ConversationMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Absent means "I have nothing yet" — the first page of the thread.
        return [
            'after_id' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
