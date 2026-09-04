<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class MessageStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sender_number_id' => ['required', 'exists:numbers,id'],
            'receiver_number_id' => ['required', 'exists:numbers,id', 'different:sender_number_id'],
            'template_id' => ['required', 'exists:message_templates,id'],
        ];
    }
}
