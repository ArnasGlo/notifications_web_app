@extends('layouts.app')

@section('content')
<div class="container py-4">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="d-flex align-items-center mb-4">
        <a href="{{ route('numbers.index') }}" class="btn btn-sm btn-outline-secondary me-3">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <h2 class="mb-0 fw-bold">{{ $number->number }}</h2>
            <small class="text-muted">
                @if($isOwner)
                    <span class="badge bg-success me-1">Owner</span>
                @else
                    <span class="badge bg-info text-dark me-1">Assistant</span>
                @endif
                @if($number->city || $number->country)
                    <i class="fas fa-map-marker-alt me-1"></i>
                    {{ collect([$number->city, $number->country])->filter()->implode(', ') }}
                @endif
            </small>
        </div>
    </div>

    @if($messages->isEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No messages for this number</h5>
            </div>
        </div>
    @else
        <div class="card border-0 shadow-sm">
            <div class="list-group list-group-flush">
                @foreach($messages as $message)
                @php
                    $isSender = $message->sender_number_id === $number->id;
                    $isUnread = !$isSender && $message->status === 'sent';
                @endphp
                <a href="{{ route('messages.show', $message) }}"
                   class="list-group-item list-group-item-action px-4 py-3 {{ $isUnread ? 'bg-light' : '' }}">
                    <div class="d-flex align-items-start gap-3">

                        <div class="mt-1 text-center" style="width: 28px; flex-shrink: 0">
                            @if($isSender)
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
                                    @if($isSender)
                                        <span class="text-muted small me-1">To:</span>
                                        <span class="fw-semibold">{{ $message->receiver->number }}</span>
                                        <span class="badge bg-primary bg-opacity-10 text-primary ms-1" style="font-size:.7rem">Sent</span>
                                    @else
                                        <span class="text-muted small me-1">From:</span>
                                        <span class="fw-semibold {{ $isUnread ? 'text-dark' : 'text-muted' }}">
                                            {{ $message->sender->number }}
                                        </span>
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

        <div class="mt-4 d-flex justify-content-center">
            {{ $messages->links() }}
        </div>
    @endif
</div>
@endsection