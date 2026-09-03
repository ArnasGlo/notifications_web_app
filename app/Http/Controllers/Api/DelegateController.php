<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DelegateResource;
use App\Models\Delegate;
use App\Models\Number;
use Illuminate\Http\Request;

class DelegateController extends Controller
{
    public function index(Request $request, Number $number)
    {
        abort_unless($number->user_id === $request->user()->id, 403);

        $delegates = $number->delegates()->with('assistant')->latest()->get();

        return DelegateResource::collection($delegates);
    }

    public function destroy(Request $request, Number $number, Delegate $delegate)
    {
        abort_unless($number->user_id === $request->user()->id, 403);
        abort_unless($delegate->number_id === $number->id, 404);

        $delegate->delete();

        return response()->json(null, 204);
    }
}
