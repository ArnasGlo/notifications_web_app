@extends('layouts.app')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">

            <div class="text-center mb-4">
                <div class="mb-3"><span style="font-size: 3rem;">🔑</span></div>
                <h2 class="fw-bold">Number Access Invite</h2>
                <p class="text-muted">
                    You've been invited to become an <strong>assistant</strong> for a number on {{ config('app.name') }}.
                </p>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4 text-center">
                    <p class="text-muted small fw-semibold mb-1">NUMBER</p>
                    <h3 class="fw-bold mb-1">{{ $number->number }}</h3>
                    @if($number->city || $number->country)
                        <p class="text-muted mb-0">
                            <i class="fas fa-map-marker-alt me-1"></i>
                            {{ collect([$number->city, $number->country])->filter()->implode(', ') }}
                        </p>
                    @endif
                    <hr class="my-3">
                    <p class="text-muted small mb-0">
                        <i class="fas fa-user me-1"></i>
                        Managed by <strong>{{ $number->user->name }}</strong>
                    </p>
                </div>
            </div>

            <div class="alert alert-info mb-4">
                <i class="fas fa-info-circle me-2"></i>
                <strong>What does assistant access mean?</strong>
                <ul class="mb-0 mt-2 ps-3">
                    <li>You can view and reply to messages received by this number.</li>
                    <li>No personal contact relationship is created between you and the owner.</li>
                    <li>The owner can revoke your access at any time.</li>
                </ul>
            </div>

            @guest
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4 text-center">
                        <p class="text-muted mb-3">You need to be logged in to accept this invitation.</p>
                        <a href="{{ route('login') }}?redirect={{ urlencode(request()->fullUrl()) }}"
                           class="btn btn-primary btn-lg w-100">
                            <i class="fas fa-sign-in-alt me-2"></i> Log In to Accept
                        </a>
                    </div>
                </div>
            @else
                @if($isOwner)
                    <div class="alert alert-warning text-center">
                        <i class="fas fa-crown me-2"></i>
                        You own this number. Share this link with others to grant them assistant access.
                    </div>
                    <a href="{{ route('numbers.delegates.index', $number) }}" class="btn btn-outline-primary w-100">
                        <i class="fas fa-users me-1"></i> View Assistants
                    </a>

                @elseif($alreadyAssistant)
                    <div class="alert alert-success text-center">
                        <i class="fas fa-check-circle me-2"></i>
                        You are already an assistant for this number.
                    </div>
                    <a href="{{ route('messages.index') }}" class="btn btn-outline-primary w-100">
                        <i class="fas fa-inbox me-1"></i> Go to Inbox
                    </a>

                @else
                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-4 text-center">
                            <p class="text-muted mb-4">
                                Click below to accept the invite and become an assistant for
                                <strong>{{ $number->number }}</strong>.
                            </p>
                            <form action="{{ route('invite.accept', $number->share_token) }}" method="POST">
                                @csrf
                                @if(session('error'))
                                    <div class="alert alert-danger">{{ session('error') }}</div>
                                @endif
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="fas fa-user-check me-2"></i> Accept & Become Assistant
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endif
            @endguest

        </div>
    </div>
</div>
@endsection
