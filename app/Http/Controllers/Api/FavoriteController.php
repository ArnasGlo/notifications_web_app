<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\FavoriteStoreRequest;
use App\Http\Resources\FavoriteResource;
use App\Models\Favorite;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request)
    {
        $favorites = $request->user()->favorites()->with('number')->latest()->get();

        return FavoriteResource::collection($favorites);
    }

    public function store(FavoriteStoreRequest $request)
    {
        // Idempotent, as with Delegate::firstOrCreate in InviteController@accept —
        // JsonResource then answers 201 on a first star and 200 on a repeat.
        $favorite = Favorite::firstOrCreate([
            'user_id' => $request->user()->id,
            'number_id' => $request->validated('number_id'),
        ]);

        return new FavoriteResource($favorite->load('number'));
    }

    public function destroy(Request $request, Favorite $favorite)
    {
        abort_unless($favorite->user_id === $request->user()->id, 403);

        $favorite->delete();

        return response()->json(null, 204);
    }
}
