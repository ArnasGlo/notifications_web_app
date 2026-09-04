<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BlockStoreRequest;
use App\Http\Resources\BlockResource;
use App\Models\Block;
use App\Models\Number;
use Illuminate\Http\Request;

class BlockController extends Controller
{
    public function index(Request $request, Number $number)
    {
        abort_unless($number->user_id === $request->user()->id, 403);

        $blocks = $number->blocks()->latest()->get();

        return BlockResource::collection($blocks);
    }

    public function store(BlockStoreRequest $request, Number $number)
    {
        abort_unless($number->user_id === $request->user()->id, 403);

        $block = $number->blocks()->create($request->validated());

        return (new BlockResource($block))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Number $number, Block $block)
    {
        abort_unless($number->user_id === $request->user()->id, 403);
        abort_unless($block->number_id === $number->id, 404);

        $block->delete();

        return response()->json(null, 204);
    }
}
