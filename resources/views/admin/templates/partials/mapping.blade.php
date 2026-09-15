{{--
    Reply mapping (message_template_replies) for the template form, read from
    whichever side the template is on:
      - a prompt picks the reply templates it offers as answers;
      - a reply template picks the prompts it answers.

    Both lists are rendered; the "Reply only" checkbox decides which one shows,
    and the hidden list's inputs are disabled so they aren't submitted. Lists are
    grouped by category for reading only — any pair may be chosen.

    Requires $replyOptions and $promptOptions (templates grouped by category
    name) and $mappedReplyIds / $mappedPromptIds (what is saved now).
--}}
@php
    $isReply = (bool) old('is_reply', isset($template) ? $template->is_reply : false);

    // After a failed save, show what was just submitted rather than what's stored.
    $chosenReplyIds = collect($errors->any() ? old('reply_template_ids', []) : $mappedReplyIds)->map(fn ($id) => (int) $id);
    $chosenPromptIds = collect($errors->any() ? old('prompt_template_ids', []) : $mappedPromptIds)->map(fn ($id) => (int) $id);

    $sides = [
        [
            'key' => 'replies',
            'name' => 'reply_template_ids[]',
            'errors' => 'reply_template_ids.*',
            'shown' => ! $isReply,
            'options' => $replyOptions,
            'chosen' => $chosenReplyIds,
            'label' => 'Replies this template offers',
            'help' => 'Reply templates a recipient can answer with when a message was sent with this template. With none chosen, those messages offer no replies.',
            'empty' => 'There are no reply templates yet.',
        ],
        [
            'key' => 'prompts',
            'name' => 'prompt_template_ids[]',
            'errors' => 'prompt_template_ids.*',
            'shown' => $isReply,
            'options' => $promptOptions,
            'chosen' => $chosenPromptIds,
            'label' => 'Prompts this reply answers',
            'help' => 'Templates this reply can be sent in answer to. With none chosen, it is never offered as an answer.',
            'empty' => 'There are no prompt templates yet.',
        ],
    ];
@endphp

<div id="template-mapping">
    @foreach($sides as $side)
        <div class="form-group {{ $side['shown'] ? '' : 'd-none' }}" data-mapping-side="{{ $side['key'] }}">
            <label class="mb-0">{{ $side['label'] }}</label>
            <small class="form-text text-muted mt-0 mb-2">
                {{ $side['help'] }} Categories only group this list; any category can be chosen.
            </small>

            @error($side['errors'])
                <div class="text-danger small mb-2">{{ $message }}</div>
            @enderror

            <div class="border rounded p-2" style="max-height: 280px; overflow-y: auto;">
                @forelse($side['options'] as $categoryName => $options)
                    <div class="small font-weight-bold text-muted {{ $loop->first ? '' : 'mt-2' }}">{{ $categoryName }}</div>

                    @foreach($options as $option)
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input"
                                   id="mapping-{{ $side['key'] }}-{{ $option->id }}"
                                   name="{{ $side['name'] }}" value="{{ $option->id }}" @checked($side['chosen']->contains($option->id)) @disabled(! $side['shown'])>
                            <label class="custom-control-label" for="mapping-{{ $side['key'] }}-{{ $option->id }}">
                                {{ $option->body }}
                                @unless($option->is_active)
                                    <span class="badge badge-secondary ml-1">Inactive</span>
                                @endunless
                            </label>
                        </div>
                    @endforeach
                @empty
                    <div class="small text-muted">{{ $side['empty'] }}</div>
                @endforelse
            </div>
        </div>
    @endforeach
</div>

@push('scripts')
<script>
(function () {
    const isReply = document.getElementById('is_reply');
    const sides = document.querySelectorAll('[data-mapping-side]');
    if (!isReply) return;

    // A reply template maps prompts; anything else maps replies.
    function sync() {
        sides.forEach(function (side) {
            const shown = (side.dataset.mappingSide === 'prompts') === isReply.checked;
            side.classList.toggle('d-none', !shown);
            side.querySelectorAll('input').forEach(function (input) { input.disabled = !shown; });
        });
    }

    isReply.addEventListener('change', sync);
    sync();
})();
</script>
@endpush
