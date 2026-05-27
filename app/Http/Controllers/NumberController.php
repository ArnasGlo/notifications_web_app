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
        $this->authorize('update', $number); // add policy or check manually
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
        $number->delete();
        return redirect()->route('numbers.index')->with('success', 'Number deleted.');
    }
}
