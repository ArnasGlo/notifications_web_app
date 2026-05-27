<?php

namespace App\Http\Controllers;

use App\Models\Block;
use App\Models\Number;
use Illuminate\Http\Request;

class BlockController extends Controller
{
    public function index(Number $number)
    {
        abort_unless($number->user_id === auth()->id(), 403);
        $blocks = $number->blocks()->latest()->get();
        return view('blocks.index', compact('number', 'blocks'));
    }

    public function create(Number $number)
    {
        abort_unless($number->user_id === auth()->id(), 403);
        return view('blocks.create', compact('number'));
    }

    public function store(Request $request, Number $number)
    {
        abort_unless($number->user_id === auth()->id(), 403);
        $data = $request->validate([
            'type'  => 'required|in:number,user,city,country',
            'value' => 'required|string|max:255',
        ]);
        $number->blocks()->create($data);
        return redirect()->route('numbers.blocks.index', $number)->with('success', 'Block added.');
    }

    public function destroy(Number $number, Block $block)
    {
        abort_unless($number->user_id === auth()->id(), 403);
        abort_unless($block->number_id === $number->id, 403);
        $block->delete();
        return redirect()->route('numbers.blocks.index', $number)->with('success', 'Block removed.');
    }
}
