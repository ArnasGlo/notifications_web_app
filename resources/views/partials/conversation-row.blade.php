{{--
    One row in a conversation list.
    Requires $conversation (with numberOne, numberTwo, latestMessage and the
    unread_count withCount alias) and $accessibleIds — the viewer's numbers,
    which decide which side of the pair is "theirs".

    Also re-rendered on its own by ConversationController@listUpdates when
    polling finds the thread has moved; data-conversation-id is how the page
    finds the row to replace.
--}}
@php
    $counterpart = $conversation->counterpartFor($accessibleIds);
    $latest      = $conversation->latestMessage;
    $unread      = $conversation->unread_count ?? 0;
    $outbound    = $latest && $accessibleIds->contains($latest->sender_number_id);
@endphp

<a href="{{ route('conversations.show', $conversation) }}"
   class="list-group-item list-group-item-action px-4 py-3 {{ $unread ? 'bg-light' : '' }}"
   data-conversation-id="{{ $conversation->id }}">
    <div class="d-flex align-items-center gap-3">

        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0
                    {{ $unread ? 'bg-primary text-white' : 'bg-secondary bg-opacity-10 text-secondary' }}"
             style="width:44px;height:44px">
            <i class="fas fa-comment-dots"></i>
        </div>

        <div class="flex-grow-1 min-width-0">
            <div class="d-flex justify-content-between align-items-baseline">
                <span class="fw-semibold text-truncate">
                    {{ $counterpart?->number ?? 'Unknown number' }}
                </span>
                <small class="text-muted text-nowrap ms-2">
                    {{ $conversation->last_message_at?->diffForHumans() }}
                </small>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-1">
                <span class="text-truncate {{ $unread ? 'fw-semibold text-dark' : 'text-muted' }}"
                      style="max-width: 100%">
                    @if($latest)
                        @if($outbound)<i class="fas fa-reply fa-flip-horizontal text-muted me-1 small"></i>@endif
                        {{ $latest->body }}
                    @else
                        <span class="fst-italic">No messages yet</span>
                    @endif
                </span>

                <span class="ms-2 text-nowrap">
                    @if($latest && $latest->status === 'queued')
                        <i class="fas fa-clock text-warning" title="Queued (recipient busy)"></i>
                    @endif
                    @if($counterpart && $counterpart->status !== 'active')
                        <span class="badge bg-secondary" title="This number is inactive">Inactive</span>
                    @endif
                    @if($unread)
                        <span class="badge bg-primary rounded-pill">{{ $unread }}</span>
                    @endif
                </span>
            </div>
        </div>
    </div>
</a>
