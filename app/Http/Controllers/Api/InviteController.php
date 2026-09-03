<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DelegateResource;
use App\Http\Resources\InviteResource;
use App\Models\Delegate;
use App\Models\Number;
use Illuminate\Http\Request;

class InviteController extends Controller
{
    public function show(string $token)
    {
        $number = Number::where('share_token', $token)->firstOrFail();

        return new InviteResource($number);
    }

    public function accept(Request $request, string $token)
    {
        $number = Number::where('share_token', $token)->firstOrFail();
        $user = $request->user();

        abort_unless($number->user_id !== $user->id, 422, 'You already own this number.');

        $delegate = Delegate::firstOrCreate([
            'number_id' => $number->id,
            'assistant_user_id' => $user->id,
        ]);

        return new DelegateResource($delegate);
    }
}
