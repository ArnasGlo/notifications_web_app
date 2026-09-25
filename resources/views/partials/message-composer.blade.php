{{--
    Message composer: slash-command template insertion, and free text where
    typing is allowed with the other number.

    Requires $categories — active categories eager-loaded with their active,
    non-reply templates (the same payload MessageController@compose already builds
    and GET /api/messages/compose-data returns).

    Emits `body` (what actually gets sent) and a hidden `template_id` naming the
    saved reply last inserted, if any. Editing the text after inserting one is
    expected: Message::contentFrom() keeps template_id only while the body is still
    exactly that template, so this field doesn't have to track edits.

    $typingAllowed — true when free text may be sent to the other number
    (Conversation::typingAllowedBetween). When false the box is read-only and
    only a whole template can be chosen, so the body is always exactly one. The
    compose page, which learns the recipient later, switches it with
    window.composerSetTyping(allowed). Defaults to false: templates only.

    Reply mode (chat page only) — optional:
      $replies     true to enable it. A bubble's Reply action ([data-reply-to])
                   switches the composer to answering that message: parent_id is
                   set, a "Replying to" banner shows, and "/" lists only that
                   message's reply options. Absent on the compose page.
      $replyOnly   true for assistants, who may reply but not start a message:
                   the box stays locked until a message is picked.
      $replyingTo  the message to reopen reply mode on after a bounced send,
                   already re-checked by the controller; null otherwise.
--}}
@php
    // Built here rather than inline in @json(...): Blade's directive argument
    // parser mis-balances the brackets in a multi-line arrow-function expression.
    $slashGroups = $categories->map(fn ($c) => [
        'name' => $c->name,
        'icon' => $c->icon,
        'templates' => $c->templates->map(fn ($t) => ['id' => $t->id, 'body' => $t->body])->values(),
    ])->values();

    $replies = $replies ?? false;
    $replyOnly = $replies && ($replyOnly ?? false);
    $initialReply = $replies && ! empty($replyingTo) ? [
        'id' => $replyingTo->id,
        'snippet' => \Illuminate\Support\Str::limit($replyingTo->body, 60),
        'groups' => $replyingTo->replyOptionGroups(),
    ] : null;
    $locked = $replyOnly && ! $initialReply;
    $typingAllowed = $typingAllowed ?? false;
@endphp

<div class="position-relative" id="composerWrap" data-reply-only="{{ $replyOnly ? '1' : '0' }}" data-typing="{{ $typingAllowed ? '1' : '0' }}">
    @if($replies)
        <input type="hidden" name="parent_id" id="composerParentId" value="{{ $initialReply['id'] ?? '' }}">

        <div id="composerReplyBanner"
             class="alert alert-secondary py-1 px-2 mb-2 small d-flex align-items-center {{ $initialReply ? '' : 'd-none' }}">
            <i class="fas fa-reply me-2"></i>
            <span class="text-truncate">Replying to: <span id="composerReplySnippet">{{ $initialReply['snippet'] ?? '' }}</span></span>
            <button type="button" class="btn-close ms-auto" id="composerReplyCancel" aria-label="Cancel reply"></button>
        </div>
    @endif

    <textarea name="body"
              id="composerBody"
              rows="3"
              maxlength="255"
              class="form-control @error('body') is-invalid @enderror"
              autocomplete="off"
              @readonly(! $typingAllowed)
              @disabled($locked)>{{ old('body') }}</textarea>

    <input type="hidden" name="template_id" id="composerTemplateId" value="{{ old('template_id') }}">

    @error('body')
        <div class="text-danger small mt-1">{{ $message }}</div>
    @enderror

    <div class="d-flex justify-content-between align-items-center mt-1">
        <small class="text-muted" id="composerHint"></small>
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

    const wrap    = document.getElementById('composerWrap');
    const body    = document.getElementById('composerBody');
    const hidden  = document.getElementById('composerTemplateId');
    const menu    = document.getElementById('slashMenu');
    const list    = document.getElementById('slashList');
    const counter = document.getElementById('composerCount');
    if (!body) return;

    // Reply mode: present only where the page enabled it.
    const parentInput = document.getElementById('composerParentId');
    const banner      = document.getElementById('composerReplyBanner');
    const snippet     = document.getElementById('composerReplySnippet');
    const hint        = document.getElementById('composerHint');
    const replyOnly   = wrap.dataset.replyOnly === '1';
    const placeholders = {
        message:       'Type a message, or press / to insert a saved reply…',
        reply:         'Type your reply, or press / for suggested answers…',
        messageChoose: 'Click here to choose a template…',
        replyChoose:   'Click here to choose a suggested answer…',
        locked:        'As an assistant you can reply to messages — use the reply arrow on one.',
    };

    let activeGroups = groups;   // what "/" lists: compose templates, or one message's reply options
    let replying = false;
    let typingAllowed = wrap.dataset.typing === '1';

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
        activeGroups.forEach(g => {
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
            list.innerHTML = replying && !activeGroups.length
                ? (typingAllowed
                    ? '<div class="list-group-item small text-muted">No suggested answers for this message — just type your reply.</div>'
                    : '<div class="list-group-item small text-muted">No suggested answers for this message, and typing is not allowed with this number.</div>')
                : '<div class="list-group-item small text-muted">No saved replies match.</div>';
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

    // Replace the "/query" token under the caret with the template text — or,
    // with typing not allowed, the whole body, so it stays exactly one template.
    function choose(t) {
        hidden.value = t.id;
        if (!typingAllowed) {
            body.value = t.body;
            close();
            sync();
            return;
        }

        const caret = body.selectionStart;
        const before = body.value.slice(0, caret).replace(/(^|\s)\/[\w-]*$/, '$1');
        const after = body.value.slice(caret);
        body.value = (before + t.body + after).slice(0, 255);
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

    // The box's state follows from three things: an assistant's lock, reply
    // mode, and whether typing is allowed.
    function refresh() {
        body.disabled = replyOnly && !replying;
        body.readOnly = !typingAllowed;

        if (body.disabled) {
            body.placeholder = placeholders.locked;
        } else if (typingAllowed) {
            body.placeholder = replying ? placeholders.reply : placeholders.message;
        } else {
            body.placeholder = replying ? placeholders.replyChoose : placeholders.messageChoose;
        }

        hint.innerHTML = typingAllowed
            ? 'Press <kbd>/</kbd> for saved replies'
            : 'Templates only — typing needs an agreement with this number';
    }

    // Switch between free text and templates only. Typed text can't be sent
    // without typing, so it's cleared; a whole chosen template is kept.
    window.composerSetTyping = function (allowed) {
        typingAllowed = allowed;
        if (!allowed && hidden.value === '') body.value = '';
        close();
        refresh();
        sync();
    };

    // Enter or leave reply mode. reply = {id, snippet, groups}, or null to leave.
    // A template picked in the other mode isn't valid in this one, so it's
    // forgotten — except when restoring after a bounced send, where it's kept.
    function setReply(reply, restoring) {
        if (!parentInput) return;

        replying = !!reply;
        parentInput.value = reply ? reply.id : '';
        activeGroups = reply ? reply.groups : groups;
        if (!restoring) hidden.value = '';

        snippet.textContent = reply ? reply.snippet : '';
        banner.classList.toggle('d-none', !reply);

        // Without typing the body is a template from the other mode, not valid
        // in this one, so it goes along with its id.
        if (!typingAllowed && !restoring) body.value = '';

        refresh();
        close();
        if (reply && !restoring) body.focus();
        sync();
    }

    body.addEventListener('input', () => {
        // Typing after inserting may mean the text is no longer the template
        // verbatim; the server drops template_id in that case.
        const q = slashQuery();
        q === null ? close() : render(q);
        sync();
    });

    // Templates only: the read-only box opens the full list instead of taking text.
    body.addEventListener('click', () => {
        if (!typingAllowed && !body.disabled) render('');
    });

    body.addEventListener('keydown', e => {
        if (menu.classList.contains('d-none')) {
            if (!typingAllowed && ['Enter', ' ', '/', 'ArrowDown'].includes(e.key)) {
                e.preventDefault();
                render('');
            }
            return;
        }
        if (e.key === 'ArrowDown')      { e.preventDefault(); active = Math.min(active + 1, items.length - 1); highlight(); }
        else if (e.key === 'ArrowUp')   { e.preventDefault(); active = Math.max(active - 1, 0); highlight(); }
        else if (e.key === 'Enter' || e.key === 'Tab') {
            if (active >= 0) { e.preventDefault(); choose(items[active].template); }
        } else if (e.key === 'Escape')  { e.preventDefault(); close(); }
    });

    body.addEventListener('blur', () => setTimeout(close, 120));

    if (parentInput) {
        // Delegated, so bubbles appended later by polling work too.
        document.addEventListener('click', e => {
            const button = e.target.closest('[data-reply-to]');
            if (!button) return;
            setReply({
                id: button.dataset.replyTo,
                snippet: button.dataset.replySnippet,
                groups: JSON.parse(button.dataset.replyOptions || '[]'),
            }, false);
        });

        document.getElementById('composerReplyCancel').addEventListener('click', () => setReply(null, false));

        setReply(@json($initialReply), true);
    } else {
        refresh();
        sync();
    }
})();
</script>
@endpush
