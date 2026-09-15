{{--
    One chat bubble.

    Requires $message (loaded with Message::forThread()), $outbound (true when one
    of the viewer's numbers sent it) and $accessibleIds (the viewer's numbers).
    Rendered both by the chat page and by the polling endpoint
    (ConversationController@updates), so a message that arrives without a reload
    looks exactly like one that was there when the page loaded.

    A Reply action appears only where Message::canBeRepliedToFrom() allows it —
    the same check the reply action enforces — and carries that message's reply
    options for the composer's "/" menu.
--}}
@php
    $canReply = $message->canBeRepliedToFrom($accessibleIds);
@endphp
<div class="d-flex mb-2 {{ $outbound ? 'justify-content-end' : 'justify-content-start' }}"
     data-message-id="{{ $message->id }}">
    <div class="px-3 py-2 rounded-3 shadow-sm {{ $outbound ? 'bg-primary text-white' : 'bg-white border' }}"
         style="max-width:78%">
        @if($message->parent)
            <div class="small border-start border-2 ps-2 mb-1 {{ $outbound ? 'text-white-50 border-light' : 'text-muted' }}"
                 data-in-reply-to="{{ $message->parent_id }}">
                <i class="fas fa-reply me-1"></i>{{ \Illuminate\Support\Str::limit($message->parent->body, 60) }}
            </div>
        @endif
        <div>{{ $message->body }}</div>
        <div class="small mt-1 text-end {{ $outbound ? 'text-white-50' : 'text-muted' }}">
            {{ $message->created_at->format('H:i') }}
            @if($message->status === 'queued')
                <i class="fas fa-clock ms-1" title="Queued — recipient is busy"></i>
            @elseif($outbound && $message->status === 'read')
                <i class="fas fa-check-double ms-1" title="Read"></i>
            @elseif($outbound)
                <i class="fas fa-check ms-1" title="Sent"></i>
            @endif
        </div>
    </div>
    @if($canReply)
        <button type="button" class="btn btn-sm btn-link text-muted align-self-end px-2" title="Reply"
                data-reply-to="{{ $message->id }}"
                data-reply-snippet="{{ \Illuminate\Support\Str::limit($message->body, 60) }}"
                data-reply-options="{{ json_encode($message->replyOptionGroups()) }}">
            <i class="fas fa-reply"></i>
        </button>
    @endif
</div>
