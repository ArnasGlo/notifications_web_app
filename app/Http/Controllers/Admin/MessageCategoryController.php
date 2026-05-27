<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MessageCategory;
use Illuminate\Http\Request;

class MessageCategoryController extends Controller
{
    public function index()
    {
        $categories = MessageCategory::withCount('templates')->latest()->paginate(20);
        return view('admin.categories.index', compact('categories'));
    }

    public function create()
    {
        return view('admin.categories.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'      => 'required|string|max:100',
            'icon'      => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        MessageCategory::create($data);

        return redirect()->route('admin.categories.index')->with('success', 'Category created.');
    }

    public function show(MessageCategory $category)
    {
        $category->load('templates');
        return view('admin.categories.show', compact('category'));
    }

    public function edit(MessageCategory $category)
    {
        return view('admin.categories.edit', compact('category'));
    }

    public function update(Request $request, MessageCategory $category)
    {
        $data = $request->validate([
            'name'      => 'required|string|max:100',
            'icon'      => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        $category->update($data);

        return redirect()->route('admin.categories.index')->with('success', 'Category updated.');
    }

    public function destroy(MessageCategory $category)
    {
        // Prevents orphaned templates
        if ($category->templates()->exists()) {
            return back()->with('error', 'Cannot delete a category that has templates. Remove templates first.');
        }

        $category->delete();

        return redirect()->route('admin.categories.index')->with('success', 'Category deleted.');
    }
}
