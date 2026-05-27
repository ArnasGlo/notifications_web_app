<?php

namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Http\Request;

class StatusController extends Controller
{
    public function update(Request $request)
    {
        $request->validate(['status' => 'required|in:active,busy,dnd']);

        $user = auth()->user();
        $user->update(['status' => $request->status]);

        if ($request->status === 'active') {
            $myNumberIds = $user->numbers()->pluck('id');
            Message::whereIn('receiver_number_id', $myNumberIds)
                ->where('status', 'queued')
                ->update(['status' => 'sent']);
        }

        return back()->with('success', 'Status updated.');
    }
}
