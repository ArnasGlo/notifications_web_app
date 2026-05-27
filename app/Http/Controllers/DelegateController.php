<?php

namespace App\Http\Controllers;

use App\Models\Delegate;
use App\Models\Number;

class DelegateController extends Controller
{
    public function index(Number $number)
    {
        abort_unless($number->user_id === auth()->id(), 403);

        $delegates = $number->delegates()->with('assistant')->latest()->get();

        return view('delegates.index', compact('number', 'delegates'));
    }

    public function destroy(Number $number, Delegate $delegate)
    {
        abort_unless($number->user_id === auth()->id(), 403);
        abort_unless($delegate->number_id === $number->id, 404);

        $delegate->delete();

        return back()->with('success', 'Assistant access revoked.');
    }
}
