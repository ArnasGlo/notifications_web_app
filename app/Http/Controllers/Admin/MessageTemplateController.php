<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        return view('admin.templates.create', compact('categories') + $this->mappingOptions() + [
            'mappedReplyIds' => collect(),
            'mappedPromptIds' => collect(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category_id' => 'required|exists:message_categories,id',
            'body'        => 'required|string|max:255',
            'is_reply'    => 'boolean',
            'is_active'   => 'boolean',
        ] + $this->mappingRules());

        $data['is_reply']  = $request->boolean('is_reply');
        $data['is_active'] = $request->boolean('is_active', true);

        $template = MessageTemplate::create($data);
        $template->syncMapping($this->chosenMapping($request, $template));

        return $this->redirectAfterSave($template, 'Template created.');
    }

    public function edit(MessageTemplate $template)
    {
        $categories = MessageCategory::where('is_active', true)->orderBy('name')->get();

        return view('admin.templates.edit', compact('template', 'categories') + $this->mappingOptions($template) + [
            'mappedReplyIds' => $template->replyTemplates()->allRelatedIds(),
            'mappedPromptIds' => $template->promptTemplates()->allRelatedIds(),
        ]);
    }

    public function update(Request $request, MessageTemplate $template)
    {
        $data = $request->validate([
            'category_id' => 'required|exists:message_categories,id',
            'body'        => 'required|string|max:255',
            'is_reply'    => 'boolean',
            'is_active'   => 'boolean',
        ] + $this->mappingRules());

        $data['is_reply']  = $request->boolean('is_reply');
        $data['is_active'] = $request->boolean('is_active');

        $template->update($data);
        $template->syncMapping($this->chosenMapping($request, $template));

        return $this->redirectAfterSave($template, 'Template updated.');
    }

    /**
     * Retires a template; never hard-deletes it.
     *
     * messages.template_id is a RESTRICT foreign key, so deleting a template any
     * message was sent from used to fail with a 500. Unused ones are retired the
     * same way, so a template row is never removed. Deactivating takes it out of
     * the composer and every reply list, the same as unticking "active" on the
     * edit form.
     */
    public function destroy(MessageTemplate $template)
    {
        $template->update(['is_active' => false]);

        return redirect()->route('admin.templates.index')->with('success', 'Template deactivated.');
    }

    // ── Reply mapping (message_template_replies) ─────────────────────────────

    /**
     * Every other template, sorted by category and then text, split into the two
     * sides of the mapping and grouped by category for display.
     */
    private function mappingOptions(?MessageTemplate $except = null): array
    {
        $templates = MessageTemplate::with('category')
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->get()
            ->sortBy([['category.name', 'asc'], ['body', 'asc']]);

        return [
            'replyOptions' => $templates->where('is_reply', true)->groupBy('category.name'),
            'promptOptions' => $templates->where('is_reply', false)->groupBy('category.name'),
        ];
    }

    /**
     * Each side may only name templates of the right kind. There is deliberately
     * no category check: category is display grouping only, and any reply
     * template may answer any prompt.
     */
    private function mappingRules(): array
    {
        return [
            'reply_template_ids' => ['array'],
            'reply_template_ids.*' => ['integer', Rule::exists('message_templates', 'id')->where('is_reply', 1)],
            'prompt_template_ids' => ['array'],
            'prompt_template_ids.*' => ['integer', Rule::exists('message_templates', 'id')->where('is_reply', 0)],
        ];
    }

    /**
     * The ids for the side this template is on once saved. The form disables the
     * other side's inputs, and only this side is read even if they arrive.
     */
    private function chosenMapping(Request $request, MessageTemplate $template): array
    {
        return $request->input($template->is_reply ? 'prompt_template_ids' : 'reply_template_ids', []);
    }

    /**
     * Back to the list, unless the mapping needs the admin's attention; then back
     * to the template's form, at the mapping section, saying why.
     */
    private function redirectAfterSave(MessageTemplate $template, string $success)
    {
        $warning = $this->mappingWarning($template);

        if (is_null($warning)) {
            return redirect()->route('admin.templates.index')->with('success', $success);
        }

        return redirect()->to(route('admin.templates.edit', $template).'#template-mapping')
            ->with('success', $success)
            ->with('warning', $warning);
    }

    /**
     * Warn when a save leaves the mapping doing nothing: a prompt with no active
     * reply templates to offer, or an active reply template mapped to no prompt.
     * An inactive reply template isn't offered anyway, so it isn't warned about.
     * A template that was just reactivated says so.
     */
    private function mappingWarning(MessageTemplate $template): ?string
    {
        if (! $template->hasNoEffectiveMappings() || ($template->is_reply && ! $template->is_active)) {
            return null;
        }

        $lead = $template->wasChanged('is_active') && $template->is_active ? 'Reactivated, but' : 'Saved, but';

        return $lead.($template->is_reply
            ? ' this reply template is not mapped to any prompt, so it will not be offered as an answer. Choose the prompts it answers below.'
            : ' no active reply templates are mapped to this template, so messages sent with it will offer no replies. Choose the replies it offers below.');
    }
}
