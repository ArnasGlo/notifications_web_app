<?php

namespace App\Http\Controllers;

use App\Models\Delegate;
use App\Models\Number;
use Illuminate\Http\Request;

class InviteController extends Controller
{
    public function show(string $token)
    {
        $number = Number::where('share_token', $token)->firstOrFail();

        $alreadyAssistant = false;
        if (auth()->check()) {
            $alreadyAssistant = $number->hasAssistant(auth()->user());
        }

        $isOwner = auth()->check() && $number->user_id === auth()->id();

        return view('invite.show', compact('number', 'alreadyAssistant', 'isOwner'));
    }

    public function accept(Request $request, string $token)
    {
        $number = Number::where('share_token', $token)->firstOrFail();
        $user   = auth()->user();

        if ($number->user_id === $user->id) {
            return back()->with('error', 'You already own this number.');
        }

        Delegate::firstOrCreate([
            'number_id'         => $number->id,
            'assistant_user_id' => $user->id,
        ]);

        return redirect()->route('messages.index')
            ->with('success', 'You are now an assistant for ' . $number->number . '.');
    }
}
