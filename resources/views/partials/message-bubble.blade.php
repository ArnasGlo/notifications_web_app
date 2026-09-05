{{--
    One chat bubble.

    Requires $message and $outbound (true when one of the viewer's numbers sent
    it). Rendered both by the chat page and by the polling endpoint
    (ConversationController@updates), so a message that arrives without a reload
    looks exactly like one that was there when the page loaded.
--}}
<div class="d-flex mb-2 {{ $outbound ? 'justify-content-end' : 'justify-content-start' }}"
     data-message-id="{{ $message->id }}">
    <div class="px-3 py-2 rounded-3 shadow-sm {{ $outbound ? 'bg-primary text-white' : 'bg-white border' }}"
         style="max-width:78%">
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
</div>
