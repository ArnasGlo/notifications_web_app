<?php

namespace App\Http\Controllers;

use App\Models\Number;
use Illuminate\Http\Request;

class NumberController extends Controller
{
    public function index()
    {
        $numbers = auth()->user()->numbers()->latest()->get();
        return view('numbers.index', compact('numbers'));
    }

    public function create()
    {
        return view('numbers.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'number'  => 'required|string|unique:numbers,number',
            'country' => 'nullable|string|max:100',
            'city'    => 'nullable|string|max:100',
        ]);

        auth()->user()->numbers()->create($data);
        return redirect()->route('numbers.index')->with('success', 'Number added.');
    }

    public function edit(Number $number)
    {
        abort_unless($number->user_id === auth()->id(), 403);
        return view('numbers.edit', compact('number'));
    }

    public function update(Request $request, Number $number)
    {
        abort_unless($number->user_id === auth()->id(), 403);
        $number->update($request->validate([
            'country' => 'nullable|string|max:100',
            'city'    => 'nullable|string|max:100',
            'status'  => 'in:active,inactive',
        ]));
        return redirect()->route('numbers.index')->with('success', 'Number updated.');
    }

    public function destroy(Number $number)
    {
        abort_unless($number->user_id === auth()->id(), 403);

        // messages.sender_number_id / receiver_number_id are RESTRICT foreign keys,
        // so deleting a number with history would raise a constraint violation.
        if ($number->sentMessages()->exists() || $number->receivedMessages()->exists()) {
            return back()->with('error', 'This number has message history and cannot be deleted. Set it to inactive instead.');
        }

        $number->delete();
        return redirect()->route('numbers.index')->with('success', 'Number deleted.');
    }
}
