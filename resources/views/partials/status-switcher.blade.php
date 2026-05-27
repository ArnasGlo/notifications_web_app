{{--
    Status Switcher Partial
    Include in your navbar with: @include('partials.status-switcher')
    Only renders when the user is authenticated.
--}}

@auth
@php
    $status = auth()->user()->status;
    $config = [
        'active' => ['label' => 'Active',       'color' => 'success', 'icon' => 'fas fa-circle'],
        'busy'   => ['label' => 'Busy',          'color' => 'warning', 'icon' => 'fas fa-clock'],
        'dnd'    => ['label' => 'Do Not Disturb','color' => 'danger',  'icon' => 'fas fa-moon'],
    ];
    $current = $config[$status] ?? $config['active'];
@endphp

<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2"
       href="#" id="statusDropdown" role="button"
       data-bs-toggle="dropdown" aria-expanded="false">
        <i class="{{ $current['icon'] }} text-{{ $current['color'] }}" style="font-size:.65rem"></i>
        <span class="d-none d-md-inline">{{ $current['label'] }}</span>
    </a>

    <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="statusDropdown"
        style="min-width: 180px;">
        <li>
            <h6 class="dropdown-header text-uppercase small">My Status</h6>
        </li>

        @foreach($config as $value => $opt)
        <li>
            <form action="{{ route('status.update') }}" method="POST" class="d-inline">
                @csrf
                <input type="hidden" name="status" value="{{ $value }}">
                <button type="submit"
                        class="dropdown-item d-flex align-items-center gap-2 {{ $status === $value ? 'fw-bold' : '' }}">
                    <i class="{{ $opt['icon'] }} text-{{ $opt['color'] }}" style="font-size:.65rem; width:14px"></i>
                    {{ $opt['label'] }}
                    @if($status === $value)
                        <i class="fas fa-check ms-auto text-muted small"></i>
                    @endif
                </button>
            </form>
        </li>
        @endforeach

        <li><hr class="dropdown-divider"></li>
        <li>
            <small class="dropdown-item-text text-muted px-3" style="font-size:.75rem; line-height:1.4">
                @if($status === 'dnd')
                    Messages will be <strong>blocked</strong> while DND is on.
                @elseif($status === 'busy')
                    Messages will be <strong>queued</strong> for later delivery.
                @else
                    You will receive messages normally.
                @endif
            </small>
        </li>
    </ul>
</li>
@endauth
