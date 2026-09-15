<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class MessageReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A mapped template, typed text, or both (text identical to the
            // template keeps it; anything else is typed text). At least one.
            'template_id' => ['required_without:body', 'nullable', 'exists:message_templates,id'],
            'body' => ['required_without:template_id', 'nullable', 'string', 'max:255'],
        ];
    }
}
