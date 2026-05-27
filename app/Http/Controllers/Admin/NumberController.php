<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Number;
use App\Models\User;
use Illuminate\Http\Request;

class NumberController extends Controller
{
    public function index()
    {
        $numbers = Number::with('user')->latest()->paginate(20);
        return view('admin.numbers.index', compact('numbers'));
    }

    public function create()
    {
        $users = User::orderBy('name')->get();
        return view('admin.numbers.create', compact('users'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'number'  => 'required|string|unique:numbers,number',
            'country' => 'nullable|string|max:100',
            'city'    => 'nullable|string|max:100',
            'status'  => 'in:active,inactive',
        ]);

        $data['status'] = $data['status'] ?? 'active';

        Number::create($data);

        return redirect()->route('admin.numbers.index')->with('success', 'Number created.');
    }

    public function show(Number $number)
    {
        $number->load('user', 'sentMessages.template', 'receivedMessages.template');
        return view('admin.numbers.show', compact('number'));
    }

    public function edit(Number $number)
    {
        $users = User::orderBy('name')->get();
        return view('admin.numbers.edit', compact('number', 'users'));
    }

    public function update(Request $request, Number $number)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'number'  => 'required|string|unique:numbers,number,' . $number->id,
            'country' => 'nullable|string|max:100',
            'city'    => 'nullable|string|max:100',
            'status'  => 'in:active,inactive',
        ]);

        $data['status'] = $data['status'] ?? 'active';
        $number->update($data);

        return redirect()->route('admin.numbers.index')->with('success', 'Number updated.');
    }

    public function destroy(Number $number)
    {
        $number->delete();

        return redirect()->route('admin.numbers.index')->with('success', 'Number deleted.');
    }
}
