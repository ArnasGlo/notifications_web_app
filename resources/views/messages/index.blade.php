@extends('layouts.app')

@section('content')
<div class="container py-4">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0 fw-bold">Messages</h2>
            <small class="text-muted">
                @if(request('q'))
                    Conversation with <strong>{{ request('q') }}</strong>
                @else
                    All sent and received messages
                @endif
            </small>
        </div>
        <a href="{{ route('messages.compose') }}" class="btn btn-primary">
            <i class="fas fa-paper-plane me-1"></i> Compose
        </a>
    </div>

    <form method="GET" action="{{ route('messages.index') }}" class="mb-4">
        <div class="input-group">
            <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
            <input type="text"
                   name="q"
                   value="{{ request('q') }}"
                   class="form-control"
                   placeholder="Filter by number, e.g. +37060011111">
            <button class="btn btn-outline-secondary" type="submit">Search</button>
            @if(request('q'))
                <a href="{{ route('messages.index') }}" class="btn btn-outline-secondary" title="Clear filter">
                    <i class="fas fa-times"></i>
                </a>
            @endif
        </div>
    </form>

    @if($messages->isEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                @if(request('q'))
                    <i class="fas fa-search fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No messages with that number</h5>
                    <p class="text-muted mb-4">Nothing matches <strong>{{ request('q') }}</strong> in your inbox.</p>
                    <a href="{{ route('messages.index') }}" class="btn btn-outline-secondary">Clear filter</a>
                @else
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No messages yet</h5>
                    <p class="text-muted mb-4">Send your first message or share your invite link to get started.</p>
                    <a href="{{ route('messages.compose') }}" class="btn btn-primary">Send First Message</a>
                @endif
            </div>
        </div>
    @else
        <div class="card border-0 shadow-sm">
            <div class="list-group list-group-flush">
                @foreach($messages as $message)
                @php
                    $isSender   = $myNumberIds->contains($message->sender_number_id);
                    $isReceiver = $myNumberIds->contains($message->receiver_number_id);
                    $isUnread   = $myNumberIds->contains($message->receiver_number_id) && $message->status === 'sent';
                @endphp
                <a href="{{ route('messages.show', $message) }}"
                   class="list-group-item list-group-item-action px-4 py-3 {{ $isUnread ? 'bg-light' : '' }}">
                    <div class="d-flex align-items-start gap-3">

                        <div class="mt-1 text-center" style="width: 28px; flex-shrink: 0">
                            @if($isSender && !$isReceiver)
                                <i class="fas fa-paper-plane text-primary small" title="Sent"></i>
                            @elseif($isUnread)
                                <span class="badge bg-primary rounded-circle p-1" title="Unread">&nbsp;</span>
                            @elseif($message->status === 'queued')
                                <span class="badge bg-warning rounded-circle p-1" title="Queued">&nbsp;</span>
                            @else
                                <span class="badge bg-light border rounded-circle p-1">&nbsp;</span>
                            @endif
                        </div>

                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    @if($isSender && !$isReceiver)
                                        <span class="text-muted small me-1">To:</span>
                                        <span class="fw-semibold">{{ $message->receiver->number }}</span>
                                        <span class="badge bg-primary bg-opacity-10 text-primary ms-1" style="font-size:.7rem">Sent</span>
                                    @else
                                        <span class="text-muted small me-1">From:</span>
                                        <span class="fw-semibold {{ $isUnread ? 'text-dark' : 'text-muted' }}">
                                            {{ $message->sender->number }}
                                        </span>
                                        @if(!$isReceiver)
                                            <span class="badge bg-info text-dark ms-1" style="font-size:.7rem">
                                                <i class="fas fa-user-check me-1"></i>Assistant
                                            </span>
                                        @endif
                                    @endif
                                </div>
                                <small class="text-muted text-nowrap ms-2">
                                    {{ $message->created_at->diffForHumans() }}
                                </small>
                            </div>

                            <div class="mt-1">
                                <span class="badge bg-secondary bg-opacity-10 text-secondary me-1">
                                    <i class="{{ $message->template->category->icon ?? 'fas fa-tag' }} me-1"></i>
                                    {{ $message->template->category->name }}
                                </span>
                                <span class="{{ $isUnread ? 'fw-semibold text-dark' : 'text-muted' }}">
                                    {{ $message->template->body }}
                                </span>
                            </div>

                            @if($message->status === 'queued')
                            <div class="mt-1">
                                <small class="text-warning">
                                    <i class="fas fa-clock me-1"></i>Queued (recipient busy)
                                </small>
                            </div>
                            @endif
                        </div>

                        <div class="text-muted align-self-center">
                            <i class="fas fa-chevron-right"></i>
                        </div>
                    </div>
                </a>
                @endforeach
            </div>
        </div>
        <div class="mt-4 d-flex justify-content-center">{{ $messages->links() }}</div>
    @endif
</div>
@endsection
