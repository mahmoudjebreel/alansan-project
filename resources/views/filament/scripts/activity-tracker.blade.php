{{--
    Reports how long this tab stays visible on the page it loaded.

    Rendered once per full page load by UserActivityServiceProvider, for a
    signed-in user only. The page's visit is identified by a token minted on
    the way in; the row id never reaches the browser.

    What is counted is approximate observed active time, and nothing more:

      - a hidden tab sends nothing, and its clock restarts from zero when it
        is shown again, so hidden minutes are never counted;
      - each beat carries the seconds the tab was visible since the previous
        successful beat, and the server caps what one beat may add;
      - a failed beat (offline, server down) is simply retried next tick, and
        the seconds it would have carried are lost, not estimated;
      - a 401 or 419 means the session is gone; the script stops for good;
      - leaving the page (close, refresh, navigation) sends a beacon, which
        the browser does not promise to deliver - the server closes a silent
        visit by its last heartbeat instead.

    Every beat goes through the session, which pushes its expiry forward for
    a visible tab. The keep-alive ping already does that; hidden tabs still
    expire exactly as before.

    @param string $visitToken
    @param string $heartbeatUrl
    @param string $leaveUrl
    @param int    $heartbeatSeconds
    @param string $csrfToken
--}}
<script>
    (function () {
        const visitToken = @js($visitToken);
        const heartbeatUrl = @js($heartbeatUrl);
        const leaveUrl = @js($leaveUrl);
        const csrfToken = @js($csrfToken);
        const intervalMs = @js($heartbeatSeconds) * 1000;

        if (!visitToken || !heartbeatUrl || !leaveUrl) {
            return;
        }

        let visibleSince = document.visibilityState === "visible" ? Date.now() : null;
        let stopped = false;
        let left = false;
        let inFlight = false;

        function pendingSeconds() {
            if (visibleSince === null) {
                return 0;
            }

            return Math.max(0, Math.floor((Date.now() - visibleSince) / 1000));
        }

        function restartClock() {
            visibleSince = document.visibilityState === "visible" ? Date.now() : null;
        }

        function beat(keepalive) {
            if (stopped || left || inFlight) {
                return;
            }

            const seconds = pendingSeconds();

            inFlight = true;

            fetch(heartbeatUrl, {
                method: "POST",
                credentials: "same-origin",
                cache: "no-store",
                keepalive: keepalive === true,
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                    "Accept": "application/json",
                    "X-CSRF-TOKEN": csrfToken,
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: new URLSearchParams({ visit: visitToken, seconds: String(seconds) }).toString(),
            }).then((response) => {
                if (response.status === 401 || response.status === 419) {
                    stopped = true;
                    return;
                }

                if (response.ok) {
                    restartClock();
                }
            }).catch(() => {
                // Offline or the server is down: the next tick retries.
            }).finally(() => {
                inFlight = false;
            });
        }

        document.addEventListener("visibilitychange", () => {
            if (document.visibilityState === "visible") {
                visibleSince = Date.now();
                return;
            }

            // Hand over what was seen before the tab went quiet.
            beat(true);
            visibleSince = null;
        });

        setInterval(() => {
            if (document.visibilityState === "visible") {
                beat(false);
            }
        }, intervalMs);

        window.addEventListener("pagehide", () => {
            if (stopped || left) {
                return;
            }

            left = true;

            const body = new URLSearchParams({
                _token: csrfToken,
                visit: visitToken,
                seconds: String(pendingSeconds()),
            }).toString();

            if (navigator.sendBeacon) {
                navigator.sendBeacon(leaveUrl, new Blob([body], { type: "application/x-www-form-urlencoded" }));
            }
        });
    })();
</script>
