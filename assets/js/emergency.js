(function () {
    const btn = document.getElementById('emergencyBtn');
    const statusText = document.getElementById('emergencyStatusText');
    const locationText = document.getElementById('locationStatusText');
    if (!btn) return;

    const csrfMeta = document.querySelector('input[name="csrf_token"]');
    let csrfToken = csrfMeta ? csrfMeta.value : null;
    let currentEmergencyId = null;
    let locationWatchId = null;

    async function getCsrfToken() {
        if (csrfToken) return csrfToken;
        return null;
    }

    btn.addEventListener('click', async function () {
        if (!confirm('Confirm EMERGENCY? This will immediately alert hospital staff.')) return;

        btn.disabled = true;
        statusText.textContent = 'Triggering emergency alert...';

        try {
            const res = await fetch('/medi/emergency/trigger_emergency.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: await getCsrfToken() })
            });
            const data = await res.json();

            if (!data.success) {
                statusText.textContent = 'Failed to trigger emergency: ' + (data.message || 'Unknown error');
                btn.disabled = false;
                return;
            }

            currentEmergencyId = data.emergency_id;
            statusText.textContent = 'Emergency triggered. Hospital staff have been alerted.';
            requestLocationPermission();
            pollEmergencyStatus();
        } catch (err) {
            statusText.textContent = 'Network error while triggering emergency.';
            btn.disabled = false;
        }
    });

    function requestLocationPermission() {
        if (!navigator.geolocation) {
            locationText.textContent = 'Location sharing is not supported on this browser.';
            return;
        }

        locationText.textContent = 'Requesting location permission...';

        navigator.geolocation.getCurrentPosition(
            function (position) {
                locationText.textContent = 'Location shared with hospital staff (last known position).';
                sendLocation(position.coords.latitude, position.coords.longitude);

                // Keep sending the latest position while the emergency is active
                locationWatchId = navigator.geolocation.watchPosition(
                    function (pos) {
                        sendLocation(pos.coords.latitude, pos.coords.longitude);
                    },
                    function () { /* silent - keep last known location */ },
                    { enableHighAccuracy: true, maximumAge: 15000, timeout: 10000 }
                );
            },
            function (error) {
                locationText.textContent = 'Location permission denied. Staff will respond using your registered details.';
            },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    }

    async function sendLocation(lat, lng) {
        if (!currentEmergencyId) return;
        try {
            await fetch('/medi/emergency/update_location.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: await getCsrfToken(),
                    emergency_id: currentEmergencyId,
                    latitude: lat,
                    longitude: lng
                })
            });
        } catch (e) { /* ignore transient network errors */ }
    }

    async function pollEmergencyStatus() {
        try {
            const res = await fetch('/medi/emergency/emergency_status.php');
            const data = await res.json();

            if (!data.active) {
                statusText.textContent = 'Emergency handled. Thank you.';
                btn.disabled = false;
                if (locationWatchId !== null) {
                    navigator.geolocation.clearWatch(locationWatchId);
                    locationWatchId = null;
                }
                return;
            }

            statusText.textContent = 'Emergency active - status: ' + data.status.replace(/_/g, ' ');
            setTimeout(pollEmergencyStatus, 4000);
        } catch (e) {
            setTimeout(pollEmergencyStatus, 6000);
        }
    }

    // Resume polling on page load if an emergency is already active
    (async function initExisting() {
        try {
            const res = await fetch('/medi/emergency/emergency_status.php');
            const data = await res.json();
            if (data.active) {
                currentEmergencyId = data.emergency_id;
                btn.disabled = true;
                requestLocationPermission();
                pollEmergencyStatus();
            }
        } catch (e) { /* ignore */ }
    })();
})();

