{{--
    The one polling loop the app has.

    window.startPolling({url, params, onData, intervalMs}) asks the server "what
    changed?" on a timer and hands the JSON to the caller. Only the trigger lives
    here — the endpoints it calls are the same ones a push notification would
    tell the client to fetch, so swapping timers for websockets later means
    replacing this file and nothing else.

    @once, because two partials on one page may include it.
--}}
@once
@push('scripts')
<script>
window.startPolling = function (options) {
    const base = options.intervalMs || 4000;

    let timer = null;
    let failures = 0;
    let inFlight = false;
    let stopped = false;

    function schedule(delay) {
        clearTimeout(timer);
        // A hidden tab costs the server nothing; visibilitychange restarts it.
        if (stopped || document.hidden) return;
        timer = setTimeout(tick, delay);
    }

    async function tick() {
        if (stopped || document.hidden || inFlight) return;
        inFlight = true;

        try {
            const query = new URLSearchParams(options.params ? options.params() : {}).toString();

            const response = await fetch(options.url + (query ? '?' + query : ''), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            // Signed out, access revoked or thread gone: retrying can't help.
            if ([401, 403, 404].includes(response.status)) {
                stopped = true;
                return;
            }

            if (!response.ok) throw new Error('HTTP ' + response.status);

            options.onData(await response.json());
            failures = 0;
            schedule(base);
        } catch (e) {
            // Back off on errors (including 429) so a struggling server isn't
            // hammered: 8s, 16s, 32s… up to a minute.
            failures++;
            schedule(Math.min(base * Math.pow(2, failures), 60000));
        } finally {
            inFlight = false;
        }
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            clearTimeout(timer);
        } else {
            failures = 0;
            schedule(0);    // catch up on whatever was missed, immediately
        }
    });

    schedule(base);

    return { stop() { stopped = true; clearTimeout(timer); } };
};
</script>
@endpush
@endonce
