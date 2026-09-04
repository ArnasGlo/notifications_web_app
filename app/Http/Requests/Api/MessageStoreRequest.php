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
            // Send free text, or name a template and let its body stand as-is.
            // At least one of the two must be present.
            'body' => ['required_without:template_id', 'nullable', 'string', 'max:255'],
            'template_id' => ['nullable', 'exists:message_templates,id'],
        ];
    }
}
