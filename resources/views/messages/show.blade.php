@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">

            <div class="d-flex align-items-center mb-4">
                <a href="{{ route('messages.index') }}" class="btn btn-sm btn-outline-secondary me-3">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h2 class="mb-0 fw-bold">Message</h2>
                    <small class="text-muted">
                        {{ $message->template->category->name }}
                        &middot; {{ $message->created_at->format('d M Y, H:i') }}
                    </small>
                </div>

                @if($counterpart)
                    <div class="ms-auto">
                        @if($counterpartFavorite)
                            <form action="{{ route('favorites.destroy', $counterpartFavorite) }}" method="POST">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-warning" title="Remove from favorites">
                                    <i class="fas fa-star me-1"></i> Favorited
                                </button>
                            </form>
                        @else
                            <form action="{{ route('favorites.store') }}" method="POST">
                                @csrf
                                <input type="hidden" name="number" value="{{ $counterpart->number }}">
                                <button class="btn btn-sm btn-outline-warning" title="Add to favorites">
                                    <i class="far fa-star me-1"></i> Favorite
                                </button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>

            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <span class="fw-bold">{{ $message->sender->number }}</span>
                            <span class="text-muted mx-2">→</span>
                            <span class="text-muted">{{ $message->receiver->number }}</span>
                        </div>
                        <span class="badge
                            {{ $message->status === 'sent'    ? 'bg-primary'             : '' }}
                            {{ $message->status === 'read'    ? 'bg-secondary'            : '' }}
                            {{ $message->status === 'queued'  ? 'bg-warning text-dark'    : '' }}
                            {{ $message->status === 'blocked' ? 'bg-danger'               : '' }}
                        ">
                            {{ ucfirst($message->status) }}
                        </span>
                    </div>

                    <div class="p-3 bg-light rounded mb-2">
                        <div class="text-muted small mb-1">
                            <i class="{{ $message->template->category->icon ?? 'fas fa-tag' }} me-1"></i>
                            {{ $message->template->category->name }}
                        </div>
                        <p class="fs-5 mb-0 fw-semibold">{{ $message->template->body }}</p>
                    </div>

                    <small class="text-muted">
                        Sent {{ $message->created_at->diffForHumans() }}
                        @if($message->read_at)
                            &middot; Read {{ \Carbon\Carbon::parse($message->read_at)->diffForHumans() }}
                        @endif
                    </small>

                    @if($accessibleIds->contains($message->receiver_number_id) && !$myNumberIds->contains($message->receiver_number_id))
                        <div class="mt-2">
                            <span class="badge bg-info text-dark">
                                <i class="fas fa-user-check me-1"></i> Managing as Assistant
                            </span>
                        </div>
                    @endif
                </div>
            </div>

            @if($message->replies->isNotEmpty())
            <div class="ms-4 mb-3">
                <p class="text-muted small fw-semibold mb-2">REPLIES</p>
                @foreach($message->replies as $reply)
                <div class="card border-0 shadow-sm mb-2">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="fw-semibold small">{{ $reply->sender->number }}</span>
                                <span class="text-muted small mx-1">→</span>
                                <span class="text-muted small">{{ $reply->receiver->number }}</span>
                            </div>
                            <small class="text-muted">{{ $reply->created_at->diffForHumans() }}</small>
                        </div>
                        <p class="mb-0 mt-2">{{ $reply->template->body }}</p>
                    </div>
                </div>
                @endforeach
            </div>
            @endif

            @php
                // Threads are one level deep: a reply cannot itself be replied to.
                // MessageController@reply enforces this server-side.
                $isReply        = ! is_null($message->parent_id);
                $canReply       = ! $isReply && $accessibleIds->contains($message->receiver_number_id);
                $alreadyReplied = $message->replies->whereIn('sender_number_id', $accessibleIds->toArray())->isNotEmpty();
            @endphp

            @if($canReply && !$alreadyReplied && $replyTemplates->isNotEmpty())
            <div class="card border-0 shadow-sm border-start border-primary border-3">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3">
                        <i class="fas fa-reply me-2 text-primary"></i>Reply
                    </h6>
                    <form action="{{ route('messages.reply', $message) }}" method="POST">
                        @csrf
                        <p class="text-muted small mb-2 fw-semibold">SELECT A REPLY</p>
                        <div class="list-group mb-3" id="replyList">
                            @foreach($replyTemplates as $tpl)
                            <button type="button"
                                    class="list-group-item list-group-item-action reply-btn"
                                    data-id="{{ $tpl->id }}"
                                    onclick="selectReply({{ $tpl->id }}, this)">
                                {{ $tpl->body }}
                            </button>
                            @endforeach
                        </div>
                        <input type="hidden" name="template_id" id="replyTemplateId">
                        @error('template_id')
                            <div class="text-danger small mb-2">{{ $message }}</div>
                        @enderror
                        <button type="submit" class="btn btn-primary" id="replyBtn" disabled>
                            <i class="fas fa-reply me-2"></i> Send Reply
                        </button>
                    </form>
                </div>
            </div>

            @elseif($canReply && $alreadyReplied)
                <div class="alert alert-secondary">
                    <i class="fas fa-check-circle me-2"></i>A reply has already been sent for this message.
                </div>

            @elseif(!$isReply && !$canReply)
                <div class="alert alert-light border">
                    <i class="fas fa-info-circle me-2 text-muted"></i>
                    You sent this message. Replies from the recipient will appear above.
                </div>
            @endif

        </div>
    </div>
</div>

@push('scripts')
<script>
function selectReply(id, btn) {
    document.querySelectorAll('.reply-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('replyTemplateId').value = id;
    document.getElementById('replyBtn').disabled = false;
}
</script>
@endpush
@endsection
