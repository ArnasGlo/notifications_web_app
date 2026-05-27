<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use Illuminate\Http\Request;

class MessageTemplateController extends Controller
{
    public function index()
    {
        $templates = MessageTemplate::with('category')->latest()->paginate(20);
        return view('admin.templates.index', compact('templates'));
    }

    public function create()
    {
        $categories = MessageCategory::where('is_active', true)->orderBy('name')->get();
        return view('admin.templates.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category_id' => 'required|exists:message_categories,id',
            'body'        => 'required|string|max:255',
            'is_reply'    => 'boolean',
            'is_active'   => 'boolean',
        ]);

        $data['is_reply']  = $request->boolean('is_reply');
        $data['is_active'] = $request->boolean('is_active', true);

        MessageTemplate::create($data);

        return redirect()->route('admin.templates.index')->with('success', 'Template created.');
    }

    public function show(MessageTemplate $template)
    {
        $template->load('category');
        return view('admin.templates.show', compact('template'));
    }

    public function edit(MessageTemplate $template)
    {
        $categories = MessageCategory::where('is_active', true)->orderBy('name')->get();
        return view('admin.templates.edit', compact('template', 'categories'));
    }

    public function update(Request $request, MessageTemplate $template)
    {
        $data = $request->validate([
            'category_id' => 'required|exists:message_categories,id',
            'body'        => 'required|string|max:255',
            'is_reply'    => 'boolean',
            'is_active'   => 'boolean',
        ]);

        $data['is_reply']  = $request->boolean('is_reply');
        $data['is_active'] = $request->boolean('is_active');

        $template->update($data);

        return redirect()->route('admin.templates.index')->with('success', 'Template updated.');
    }

    public function destroy(MessageTemplate $template)
    {
        $template->delete();

        return redirect()->route('admin.templates.index')->with('success', 'Template deleted.');
    }
}
