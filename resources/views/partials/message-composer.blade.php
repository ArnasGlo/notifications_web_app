{{--
    Free-text message composer with slash-command template insertion.

    Requires $categories — active categories eager-loaded with their active,
    non-reply templates (the same payload MessageController@compose already builds
    and GET /api/messages/compose-data returns).

    Emits `body` (what actually gets sent) and a hidden `template_id` recording
    which canned response seeded it, if any. Editing the text after inserting a
    template is expected: body wins, template_id is only provenance.
--}}
@php
    // Built here rather than inline in @json(...): Blade's directive argument
    // parser mis-balances the brackets in a multi-line arrow-function expression.
    $slashGroups = $categories->map(fn ($c) => [
        'name' => $c->name,
        'icon' => $c->icon,
        'templates' => $c->templates->map(fn ($t) => ['id' => $t->id, 'body' => $t->body])->values(),
    ])->values();
@endphp

<div class="position-relative" id="composerWrap">
    <textarea name="body"
              id="composerBody"
              rows="3"
              maxlength="255"
              class="form-control @error('body') is-invalid @enderror"
              placeholder="Type a message, or press / to insert a saved reply…"
              autocomplete="off">{{ old('body') }}</textarea>

    <input type="hidden" name="template_id" id="composerTemplateId" value="{{ old('template_id') }}">

    @error('body')
        <div class="text-danger small mt-1">{{ $message }}</div>
    @enderror

    <div class="d-flex justify-content-between align-items-center mt-1">
        <small class="text-muted">Press <kbd>/</kbd> for saved replies</small>
        <small class="text-muted"><span id="composerCount">0</span>/255</small>
    </div>

    {{-- Slash menu, positioned over the textarea --}}
    <div id="slashMenu"
         class="card shadow position-absolute w-100 d-none"
         style="z-index:1050; max-height:280px; overflow-y:auto; top:100%;">
        <div class="list-group list-group-flush" id="slashList"></div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const groups = @json($slashGroups);

    const body    = document.getElementById('composerBody');
    const hidden  = document.getElementById('composerTemplateId');
    const menu    = document.getElementById('slashMenu');
    const list    = document.getElementById('slashList');
    const counter = document.getElementById('composerCount');
    if (!body) return;

    const slug = s => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 28);
    let items = [];      // flattened, currently visible
    let active = -1;

    // The "/query" token immediately before the caret, or null when not in one.
    function slashQuery() {
        const upto = body.value.slice(0, body.selectionStart);
        const m = upto.match(/(?:^|\s)\/([\w-]*)$/);
        return m ? m[1].toLowerCase() : null;
    }

    function render(q) {
        list.innerHTML = '';
        items = [];
        groups.forEach(g => {
            const matches = g.templates.filter(t =>
                q === '' || slug(t.body).includes(q) || t.body.toLowerCase().includes(q) || g.name.toLowerCase().includes(q)
            );
            if (!matches.length) return;

            const head = document.createElement('div');
            head.className = 'list-group-item bg-light py-1 small fw-semibold text-muted';
            head.innerHTML = `<i class="${g.icon || 'fas fa-tag'} me-1"></i>${g.name}`;
            list.appendChild(head);

            matches.forEach(t => {
                const el = document.createElement('button');
                el.type = 'button';
                el.className = 'list-group-item list-group-item-action py-2';
                el.innerHTML = `<code class="text-primary">/${slug(t.body)}</code>
                                <div class="small text-muted">${t.body.replace(/</g, '&lt;')}</div>`;
                el.addEventListener('mousedown', e => { e.preventDefault(); choose(t); });
                list.appendChild(el);
                items.push({ el, template: t });
            });
        });

        if (!items.length) {
            list.innerHTML = '<div class="list-group-item small text-muted">No saved replies match.</div>';
        }
        active = items.length ? 0 : -1;
        highlight();
        menu.classList.remove('d-none');
    }

    function highlight() {
        items.forEach((it, i) => it.el.classList.toggle('active', i === active));
        if (active >= 0) items[active].el.scrollIntoView({ block: 'nearest' });
    }

    function close() {
        menu.classList.add('d-none');
        items = [];
        active = -1;
    }

    // Replace the "/query" token under the caret with the template text.
    function choose(t) {
        const caret = body.selectionStart;
        const before = body.value.slice(0, caret).replace(/(^|\s)\/[\w-]*$/, '$1');
        const after = body.value.slice(caret);
        body.value = (before + t.body + after).slice(0, 255);
        hidden.value = t.id;
        const pos = (before + t.body).length;
        body.focus();
        body.setSelectionRange(pos, pos);
        close();
        sync();
    }

    function sync() {
        counter.textContent = body.value.length;
        body.dispatchEvent(new CustomEvent('composer:changed', { bubbles: true }));
    }

    body.addEventListener('input', () => {
        // Typing after inserting means the text no longer matches the template
        // verbatim, but template_id stays as provenance of where it came from.
        const q = slashQuery();
        q === null ? close() : render(q);
        sync();
    });

    body.addEventListener('keydown', e => {
        if (menu.classList.contains('d-none')) return;
        if (e.key === 'ArrowDown')      { e.preventDefault(); active = Math.min(active + 1, items.length - 1); highlight(); }
        else if (e.key === 'ArrowUp')   { e.preventDefault(); active = Math.max(active - 1, 0); highlight(); }
        else if (e.key === 'Enter' || e.key === 'Tab') {
            if (active >= 0) { e.preventDefault(); choose(items[active].template); }
        } else if (e.key === 'Escape')  { e.preventDefault(); close(); }
    });

    body.addEventListener('blur', () => setTimeout(close, 120));
    sync();
})();
</script>
@endpush
