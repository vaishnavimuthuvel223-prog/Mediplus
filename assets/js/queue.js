(function () {
    function escapeHtml(str) {
        return String(str == null ? '' : str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ---- Live queue board auto-refresh (queue/live_queue.php) ----
    // Drives three pieces from one poll: the "Now serving" hero,
    // the horizontal ticket strip, and the full details table.
    const queueBody = document.getElementById('queueTableBody');
    const ticketStrip = document.getElementById('ticketStrip');
    if (queueBody || ticketStrip) {
        async function refreshQueueTable() {
            try {
                const res = await fetch('/medi/queue/queue_data.php');
                const data = await res.json();
                const queue = data.queue || [];

                // Now serving hero + waiting count
                const nowServing = queue.find(function (r) { return r.status === 'in_consultation'; });
                const tokenEl = document.getElementById('nowServingToken');
                const metaEl = document.getElementById('nowServingMeta');
                const countEl = document.getElementById('waitingCount');
                if (tokenEl) tokenEl.textContent = nowServing ? nowServing.token : 'â€”';
                if (metaEl) {
                    metaEl.textContent = nowServing
                        ? nowServing.patient_name + ' Â· ' + nowServing.specialization_name + (nowServing.doctor_name ? ' Â· Dr. ' + nowServing.doctor_name : '')
                        : 'No patient in consultation right now';
                }
                if (countEl) {
                    const waiting = queue.filter(function (r) { return r.status !== 'in_consultation'; }).length;
                    countEl.textContent = waiting;
                }

                // Ticket strip
                if (ticketStrip) {
                    if (queue.length === 0) {
                        ticketStrip.innerHTML = '<div class="mp-ticket"><span class="mp-ticket-sub">No patients currently in the queue.</span></div>';
                    } else {
                        ticketStrip.innerHTML = queue.map(function (row) {
                            let cls = 'mp-ticket';
                            let statusLabel = row.status.replace(/_/g, ' ');
                            statusLabel = statusLabel.charAt(0).toUpperCase() + statusLabel.slice(1);
                            if (row.status === 'in_consultation') { cls += ' is-serving'; statusLabel = 'In room'; }
                            else if (row.has_emergency) { cls += ' is-emergency'; statusLabel = 'Emergency'; }
                            return '<div class="' + cls + '">' +
                                '<span class="mp-ticket-pos">#' + row.queue_position + '</span>' +
                                '<span class="mp-ticket-token">' + escapeHtml(row.token) + '</span>' +
                                '<span class="mp-ticket-name">' + escapeHtml(row.patient_name) + '</span>' +
                                '<span class="mp-ticket-sub">' + escapeHtml(row.specialization_name) + ' Â· ' + row.waiting_minutes + 'm wait</span>' +
                                '<span class="mp-ticket-status">' + escapeHtml(statusLabel) + '</span>' +
                                '</div>';
                        }).join('');
                    }
                }

                // Full details table
                if (queueBody) {
                    if (queue.length === 0) {
                        queueBody.innerHTML = '<tr><td colspan="11">No patients currently in the queue.</td></tr>';
                    } else {
                        queueBody.innerHTML = queue.map(function (row) {
                            return '<tr class="' + (row.has_emergency ? 'mp-row-emergency' : '') + '">' +
                                '<td>' + row.queue_position + '</td>' +
                                '<td>' + escapeHtml(row.token) + '</td>' +
                                '<td>' + escapeHtml(row.patient_name) + '</td>' +
                                '<td>' + (row.booking_type === 'walk_in' ? 'Walk-in' : 'Online') + '</td>' +
                                '<td>' + (row.has_emergency ? 'ðŸš¨ Emergency' : escapeHtml(row.booking_emergency_level)) + '</td>' +
                                '<td>' + row.priority_score + '</td>' +
                                '<td>' + escapeHtml(row.appointment_time) + '</td>' +
                                '<td>' + row.waiting_minutes + '</td>' +
                                '<td>' + escapeHtml(row.specialization_name) + '</td>' +
                                '<td>' + (row.doctor_name ? 'Dr. ' + escapeHtml(row.doctor_name) : 'Waiting for Specialist') + '</td>' +
                                '<td>' + row.status.replace(/_/g, ' ') + '</td>' +
                                '</tr>';
                        }).join('');
                    }
                }
            } catch (e) { /* ignore transient errors */ }
        }
        setInterval(refreshQueueTable, 5000);
    }

    // ---- Collapsible full queue table toggle ----
    const detailsToggle = document.getElementById('queueDetailsToggle');
    const detailsPanel = document.getElementById('queueDetails');
    if (detailsToggle && detailsPanel) {
        detailsToggle.addEventListener('click', function () {
            const isOpen = detailsPanel.classList.toggle('is-open');
            detailsToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            detailsToggle.textContent = isOpen ? 'Hide full queue table' : 'Show full queue table';
        });
    }

    // ---- Patient's own live token board auto-refresh -----------------
    // Used on both patient/dashboard.php (EMERGENCY_APPOINTMENT_ID, set
    // whenever there's a today appointment) and
    // patient/booking_confirmation.php (TOKEN_BOARD_APPOINTMENT_ID, set
    // right after booking, before arrival). Same polling endpoint
    // (patient/queue_status.php) drives both - it is ownership-checked
    // server side so a patient only ever sees their own token.
    const myAppointmentId =
        (typeof TOKEN_BOARD_APPOINTMENT_ID !== 'undefined' && TOKEN_BOARD_APPOINTMENT_ID) ? TOKEN_BOARD_APPOINTMENT_ID :
        (typeof EMERGENCY_APPOINTMENT_ID !== 'undefined' && EMERGENCY_APPOINTMENT_ID) ? EMERGENCY_APPOINTMENT_ID :
        null;

    const hasTokenBoardUi = document.getElementById('etaText') || document.getElementById('currentTokenText') ||
        document.getElementById('patientsAheadText') || document.getElementById('queuePositionText');

    if (myAppointmentId && hasTokenBoardUi) {
        async function refreshMyStatus() {
            try {
                const res = await fetch('/medi/patient/queue_status.php?appointment_id=' + myAppointmentId);
                const data = await res.json();
                if (!data.success) return;

                const etaEl = document.getElementById('etaText');
                if (etaEl && data.estimated_minutes !== null) etaEl.textContent = data.estimated_minutes;

                const aheadEl = document.getElementById('patientsAheadText');
                if (aheadEl && data.patients_ahead !== null) aheadEl.textContent = data.patients_ahead;

                const positionEl = document.getElementById('queuePositionText');
                if (positionEl) positionEl.textContent = (data.queue_position !== null && data.queue_position !== undefined) ? data.queue_position : 'â€”';

                const currentTokenEl = document.getElementById('currentTokenText');
                if (currentTokenEl) currentTokenEl.textContent = data.current_token || 'â€”';

                const nextTokenEl = document.getElementById('nextTokenText');
                if (nextTokenEl) nextTokenEl.textContent = data.next_token || 'â€”';

                const estTimeEl = document.getElementById('estimatedTimeText');
                if (estTimeEl && data.estimated_time) estTimeEl.textContent = data.estimated_time;

                const statusEl = document.getElementById('appointmentStatusText');
                if (statusEl && data.appointment_status) statusEl.textContent = data.appointment_status.replace(/_/g, ' ');

                const completionEl = document.getElementById('completionStatus');
                const reportLinkEl = document.getElementById('completionReportLink');
                if (data.appointment_status === 'completed') {
                    if (completionEl) completionEl.style.display = '';
                    if (reportLinkEl) reportLinkEl.style.display = '';
                }

                const banner = document.getElementById('proximityBanner');
                if (banner) {
                    if (data.proximity_message) {
                        banner.textContent = data.proximity_message;
                        banner.style.display = '';
                        banner.className = 'mp-flash ' + (data.status === 'in_consultation' ? 'mp-flash-success' : 'mp-flash-error');
                    } else {
                        banner.style.display = 'none';
                    }
                }

                if (data.travel) {
                    const distanceEl = document.getElementById('distanceText');
                    if (distanceEl) distanceEl.textContent = data.travel.distance_km + ' km Â· about ' + data.travel.travel_minutes + ' min by road';
                }
                if (data.schedule) {
                    const callEl = document.getElementById('expectedCallTime');
                    const reachEl = document.getElementById('reachByTime');
                    const leaveEl = document.getElementById('leaveByTime');
                    if (callEl) callEl.textContent = data.schedule.expected_call_time;
                    if (reachEl) reachEl.textContent = data.schedule.reach_by_time;
                    if (leaveEl) leaveEl.textContent = data.schedule.leave_by_time;
                }
            } catch (e) { /* ignore */ }
        }
        refreshMyStatus();
        setInterval(refreshMyStatus, 8000);
    }
})();

