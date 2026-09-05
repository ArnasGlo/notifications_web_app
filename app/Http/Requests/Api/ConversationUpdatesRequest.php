<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ConversationUpdatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // `since` is a server timestamp from a previous response's
        // meta.server_time, not a moment the client made up.
        return [
            'since' => ['required', 'date'],
        ];
    }
}
