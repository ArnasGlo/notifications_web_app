<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NumberLookupRequest;
use App\Http\Requests\Api\NumberStoreRequest;
use App\Http\Requests\Api\NumberUpdateRequest;
use App\Http\Resources\AssistingNumberResource;
use App\Http\Resources\NumberLookupResource;
use App\Http\Resources\NumberResource;
use App\Models\Number;
use Illuminate\Http\Request;

class NumberController extends Controller
{
    public function index(Request $request)
    {
        $owned = $request->user()->numbers()->latest()->get();
        $delegations = $request->user()->delegations()->with('number.user')->latest()->get();

        return response()->json([
            'data' => [
                'owned' => NumberResource::collection($owned)->resolve(),
                'assisting' => AssistingNumberResource::collection($delegations)->resolve(),
            ],
        ]);
    }

    public function store(NumberStoreRequest $request)
    {
        $number = $request->user()->numbers()->create($request->validated())->refresh();

        return (new NumberResource($number))->response()->setStatusCode(201);
    }

    public function update(NumberUpdateRequest $request, Number $number)
    {
        abort_unless($number->user_id === $request->user()->id, 403);

        $number->update($request->validated());

        return new NumberResource($number);
    }

    public function destroy(Request $request, Number $number)
    {
        abort_unless($number->user_id === $request->user()->id, 403);

        // messages.sender_number_id / receiver_number_id are RESTRICT foreign keys,
        // so deleting a number with history would raise a constraint violation (500).
        abort_if(
            $number->sentMessages()->exists() || $number->receivedMessages()->exists(),
            409,
            'This number has message history and cannot be deleted. Set it to inactive instead.'
        );

        $number->delete();

        return response()->json(null, 204);
    }

    public function lookup(NumberLookupRequest $request)
    {
        $number = Number::where('number', $request->validated('number'))
            ->where('status', 'active')
            ->first(['id', 'number']);

        abort_unless($number, 404);

        return new NumberLookupResource($number);
    }
}
