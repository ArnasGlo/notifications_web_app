{{--
    The chat page's typing-agreement bar: whether free text is allowed in this
    conversation, and the owner's request / accept / decline / remove buttons.

    Requires $conversation, $typing (Conversation::typingStateFor()) and
    $counterpart. Rendered by the chat page and by its polling endpoint, which
    swaps it in place when the state changes.
--}}
@php
    $them = $counterpart?->number ?? 'the other number';
    $removeLabel = match (true) {
        $typing['status'] === 'active' => 'Remove',
        (bool) $typing['requested_by_me'] => 'Cancel request',
        default => 'Decline',
    };
@endphp
<div id="typingAgreement" data-state="{{ json_encode($typing) }}"
     class="alert py-2 px-3 mb-3 small d-flex flex-wrap align-items-center gap-2 {{ $typing['status'] === 'active' ? 'alert-success' : ($typing['status'] === 'pending' ? 'alert-warning' : 'alert-secondary') }}">
    <span class="me-auto">
        @if($typing['status'] === 'active')
            <i class="fas fa-keyboard me-1"></i>
            @if($typing['automatic'])
                Typing allowed — both numbers allow typing in chat.
            @else
                Typing agreement active — you can type freely.
            @endif
        @elseif($typing['status'] === 'pending')
            <i class="fas fa-hourglass-half me-1"></i>
            @if($typing['requested_by_me'])
                Typing request sent — waiting for {{ $them }} to accept.
            @else
                {{ $them }} asks to allow typing in this conversation.
            @endif
        @else
            <i class="fas fa-list me-1"></i> Templates only — typing needs an agreement with {{ $them }}.
        @endif
    </span>

    @if($typing['can_request'])
        <form action="{{ route('conversations.typing.store', $conversation) }}" method="POST">
            @csrf
            <button class="btn btn-sm btn-primary">Request typing</button>
        </form>
    @endif

    @if($typing['can_accept'])
        <form action="{{ route('conversations.typing.accept', $conversation) }}" method="POST">
            @csrf
            <button class="btn btn-sm btn-success">Accept</button>
        </form>
    @endif

    @if($typing['can_remove'])
        <form action="{{ route('conversations.typing.destroy', $conversation) }}" method="POST">
            @csrf
            @method('DELETE')
            <button class="btn btn-sm btn-outline-danger">{{ $removeLabel }}</button>
        </form>
    @endif
</div>
