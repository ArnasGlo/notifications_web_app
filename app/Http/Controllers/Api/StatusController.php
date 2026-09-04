<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StatusUpdateRequest;
use App\Http\Resources\UserResource;
use App\Models\Message;

class StatusController extends Controller
{
    public function update(StatusUpdateRequest $request)
    {
        $user = $request->user();
        $user->update(['status' => $request->validated('status')]);

        if ($user->status === 'active') {
            $myNumberIds = $user->numbers()->pluck('id');

            Message::whereIn('receiver_number_id', $myNumberIds)
                ->where('status', 'queued')
                ->update(['status' => 'sent']);
        }

        return new UserResource($user);
    }
}
