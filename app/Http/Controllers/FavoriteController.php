<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use App\Models\Number;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index()
    {
        $favorites = auth()->user()->favorites()->with('number')->latest()->get();

        return view('favorites.index', compact('favorites'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'number' => 'required|string|exists:numbers,number',
        ]);

        $number = Number::where('number', $data['number'])->firstOrFail();

        // Idempotent, matching Delegate::firstOrCreate in InviteController@accept.
        Favorite::firstOrCreate([
            'user_id' => auth()->id(),
            'number_id' => $number->id,
        ]);

        return back()->with('success', $number->number.' added to favorites.');
    }

    public function destroy(Favorite $favorite)
    {
        abort_unless($favorite->user_id === auth()->id(), 403);

        $favorite->delete();

        return back()->with('success', 'Removed from favorites.');
    }
}
