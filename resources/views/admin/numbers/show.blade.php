@extends('layouts.admin')

@section('title', 'Number: ' . $number->number)

@section('content')

<div class="row">
    {{-- Details --}}
    <div class="col-md-4">
        <div class="card">
            <div class="card-header d-flex align-items-center">
                <a href="{{ route('admin.numbers.index') }}" class="btn btn-sm btn-outline-secondary mr-3">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h3 class="card-title mb-0">Number Details</h3>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5">Number</dt>
                    <dd class="col-sm-7 font-weight-bold">{{ $number->number }}</dd>

                    <dt class="col-sm-5">Owner</dt>
                    <dd class="col-sm-7">
                        <a href="{{ route('admin.users.show', $number->user) }}">
                            {{ $number->user->name }}
                        </a>
                    </dd>

                    <dt class="col-sm-5">Country</dt>
                    <dd class="col-sm-7">{{ $number->country ?: '—' }}</dd>

                    <dt class="col-sm-5">City</dt>
                    <dd class="col-sm-7">{{ $number->city ?: '—' }}</dd>

                    <dt class="col-sm-5">Status</dt>
                    <dd class="col-sm-7">
                        <span class="badge {{ $number->status === 'active' ? 'badge-success' : 'badge-secondary' }}">
                            {{ ucfirst($number->status) }}
                        </span>
                    </dd>

                    <dt class="col-sm-5">Share Token</dt>
                    <dd class="col-sm-7">
                        <code class="small">{{ $number->share_token }}</code>
                    </dd>

                    <dt class="col-sm-5">Created</dt>
                    <dd class="col-sm-7">{{ $number->created_at->format('d M Y') }}</dd>
                </dl>
            </div>
            <div class="card-footer">
                <a href="{{ route('admin.numbers.edit', $number) }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-edit mr-1"></i> Edit
                </a>
            </div>
        </div>
    </div>

    {{-- Message history --}}
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title mb-0">Recent Messages</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>Direction</th>
                            <th>Other Party</th>
                            <th>Message</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $allMessages = $number->sentMessages->merge($number->receivedMessages)
                                ->sortByDesc('created_at')->take(20);
                        @endphp
                        @forelse($allMessages as $msg)
                        <tr>
                            <td>
                                @if($msg->sender_number_id === $number->id)
                                    <span class="badge badge-info">Sent</span>
                                @else
                                    <span class="badge badge-secondary">Received</span>
                                @endif
                            </td>
                            <td class="small">
                                @if($msg->sender_number_id === $number->id)
                                    {{ $msg->receiver->number }}
                                @else
                                    {{ $msg->sender->number }}
                                @endif
                            </td>
                            <td class="small">{{ $msg->template->body }}</td>
                            <td>
                                <span class="badge
                                    {{ $msg->status === 'sent'    ? 'badge-primary'   : '' }}
                                    {{ $msg->status === 'read'    ? 'badge-secondary' : '' }}
                                    {{ $msg->status === 'queued'  ? 'badge-warning'   : '' }}
                                    {{ $msg->status === 'blocked' ? 'badge-danger'    : '' }}">
                                    {{ ucfirst($msg->status) }}
                                </span>
                            </td>
                            <td class="small">{{ $msg->created_at->format('d M H:i') }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-3">No messages yet.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection
