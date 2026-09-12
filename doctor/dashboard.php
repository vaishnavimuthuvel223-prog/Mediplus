<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/priority_engine.php';
require_once __DIR__ . '/../includes/appointment_functions.php';
require_once __DIR__ . '/../includes/consultation_functions.php';

require_doctor_face_verification();

$pdo = get_db_connection();
$doctor_id = $_SESSION['doctor_id'];

$doctorStmt = $pdo->prepare("
    SELECT d.*, s.name AS specialization_name FROM doctors d
    JOIN specializations s ON s.specialization_id = d.specialization_id
    WHERE d.doctor_id = ?
");
$doctorStmt->execute([$doctor_id]);
$doctor = $doctorStmt->fetch();

recalculate_queue($pdo);
attempt_doctor_assignment($pdo);

$currentPatientStmt = $pdo->prepare("
    SELECT a.*, p.full_name AS patient_name FROM appointments a
    JOIN patients p ON p.patient_id = a.patient_id
    WHERE a.doctor_id = ? AND a.status = 'in_consultation'
    LIMIT 1
");
$currentPatientStmt->execute([$doctor_id]);
$currentPatient = $currentPatientStmt->fetch();
$currentPatientType = $currentPatient ? get_patient_type($pdo, $currentPatient['patient_id'], $currentPatient['appointment_id']) : null;
$currentPatientHistoryCount = $currentPatient ? count(get_patient_history($pdo, $currentPatient['patient_id'], $currentPatient['appointment_id'])) : 0;

$queueStmt = $pdo->prepare("
    SELECT a.*, p.full_name AS patient_name, p.dob, p.gender, p.phone, p.address,
           qe.priority_score, qe.queue_position, qe.queue_status
    FROM appointments a
    JOIN patients p ON p.patient_id = a.patient_id
    LEFT JOIN queue_entries qe ON qe.appointment_id = a.appointment_id
    WHERE a.doctor_id = ? AND a.status IN ('waiting','arrived') AND a.appointment_date = CURDATE()
    ORDER BY qe.queue_position ASC
");
$queueStmt->execute([$doctor_id]);
$myQueue = $queueStmt->fetchAll();

$emergencyCasesStmt = $pdo->prepare("
    SELECT er.*, p.full_name AS patient_name, a.token FROM emergency_requests er
    JOIN patients p ON p.patient_id = er.patient_id
    LEFT JOIN appointments a ON a.appointment_id = er.appointment_id
    WHERE er.status != 'handled' AND (a.doctor_id = ? OR a.doctor_id IS NULL)
    ORDER BY er.triggered_at ASC
");
$emergencyCasesStmt->execute([$doctor_id]);
$emergencyCases = $emergencyCasesStmt->fetchAll();

$todayCountStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM appointments WHERE doctor_id = ? AND appointment_date = CURDATE()");
$todayCountStmt->execute([$doctor_id]);
$todayCount = $todayCountStmt->fetch()['cnt'];

$completedStmt = $pdo->prepare("SELECT a.*, p.full_name AS patient_name FROM appointments a JOIN patients p ON p.patient_id = a.patient_id WHERE a.doctor_id = ? AND a.appointment_date = CURDATE() AND a.status = 'completed' ORDER BY a.completed_at DESC");
$completedStmt->execute([$doctor_id]);
$completedAppointments = $completedStmt->fetchAll();

$cancelledStmt = $pdo->prepare("SELECT a.*, p.full_name AS patient_name FROM appointments a JOIN patients p ON p.patient_id = a.patient_id WHERE a.doctor_id = ? AND a.appointment_date = CURDATE() AND a.status = 'cancelled' ORDER BY a.cancelled_at DESC");
$cancelledStmt->execute([$doctor_id]);
$cancelledAppointments = $cancelledStmt->fetchAll();

$specialistWaitStmt = $pdo->prepare("SELECT a.*, p.full_name AS patient_name, s.name AS specialization_name, qe.priority_score, qe.queue_position FROM appointments a JOIN patients p ON p.patient_id = a.patient_id JOIN specializations s ON s.specialization_id = a.specialization_id LEFT JOIN queue_entries qe ON qe.appointment_id = a.appointment_id WHERE a.doctor_id IS NULL AND a.specialization_id = ? AND a.appointment_date = CURDATE() AND a.status IN ('waiting','arrived') ORDER BY qe.queue_position ASC");
$specialistWaitStmt->execute([$doctor['specialization_id']]);
$specialistWaiting = $specialistWaitStmt->fetchAll();

$notifications = get_notifications($pdo, $_SESSION['user_id'], 5);

$pageTitle = 'Doctor Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Dr. <?php echo e($doctor['full_name']); ?> â€” <?php echo e($doctor['specialization_name']); ?></h1>
<p class="mp-security-note">Face verified for this session.</p>

<div class="mp-card">
    <h2>Availability Status</h2>
    <p>Current status: <span class="mp-badge"><?php echo e($doctor['status']); ?></span></p>
    <form method="post" action="/medi/doctor/set_availability.php">
        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
        <select name="status">
            <option value="available" <?php echo $doctor['status']==='available'?'selected':''; ?>>Available</option>
            <option value="busy" <?php echo $doctor['status']==='busy'?'selected':''; ?>>Busy</option>
            <option value="unavailable" <?php echo $doctor['status']==='unavailable'?'selected':''; ?>>Unavailable</option>
        </select>
        <button type="submit" class="mp-btn mp-btn-secondary">Update Status</button>
    </form>
</div>

<div class="mp-card">
    <h2>Today's Appointments</h2>
    <p><?php echo (int)$todayCount; ?> total appointment(s) today.</p>
    <div class="mp-doctor-stats">
        <span><strong><?php echo count($myQueue); ?></strong> waiting</span>
        <span><strong><?php echo count($completedAppointments); ?></strong> completed</span>
        <span><strong><?php echo count($cancelledAppointments); ?></strong> cancelled</span>
        <span><strong><?php echo count($specialistWaiting); ?></strong> unassigned</span>
    </div>
</div>

<?php if (!empty($emergencyCases)): ?>
<div class="mp-card mp-emergency-card">
    <h2>ðŸš¨ Emergency Cases</h2>
    <?php foreach ($emergencyCases as $em): ?>
        <p><strong><?php echo e($em['patient_name']); ?></strong> â€” token <?php echo e($em['token'] ?? 'â€”'); ?> â€” status: <?php echo e(str_replace('_',' ',$em['status'])); ?></p>
    <?php endforeach; ?>
    <a href="/medi/emergency/emergency_alerts.php" class="mp-btn mp-btn-danger">View Emergency Alerts</a>
</div>
<?php endif; ?>

<div class="mp-card">
    <h2>Current Patient</h2>
    <?php if ($currentPatient): ?>
        <p><strong><?php echo e($currentPatient['patient_name']); ?></strong> â€” token <?php echo e($currentPatient['token']); ?>
            <span class="mp-badge"><?php echo $currentPatientType === 'returning' ? 'Returning Patient' : 'New Patient'; ?></span>
        </p>
        <?php if (!empty($currentPatient['consultation_started_at'])): ?>
            <p>Started at <?php echo e(date('h:i A', strtotime($currentPatient['consultation_started_at']))); ?> â€” elapsed <span id="consultElapsed" data-started="<?php echo e($currentPatient['consultation_started_at']); ?>">0</span> min</p>
        <?php endif; ?>
        <p><a href="/medi/doctor/patient_history.php?patient_id=<?php echo (int)$currentPatient['patient_id']; ?>&appointment_id=<?php echo (int)$currentPatient['appointment_id']; ?>">View previous records (<?php echo (int)$currentPatientHistoryCount; ?>)</a></p>

        <form method="post" action="/medi/doctor/complete_consultation.php" id="consultationForm" class="mp-consultation-form">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="appointment_id" value="<?php echo (int)$currentPatient['appointment_id']; ?>">

            <label>Diagnosis
                <input type="text" name="diagnosis" maxlength="255" placeholder="Short diagnosis summary">
            </label>
            <label>Consultation notes
                <textarea name="notes" rows="3" placeholder="Notes from this consultation"></textarea>
            </label>
            <label>Instructions to patient
                <textarea name="instructions" rows="2" placeholder="e.g. Rest for 3 days, drink plenty of fluids"></textarea>
            </label>
            <label>Follow-up
                <input type="text" name="follow_up" maxlength="255" placeholder="e.g. Review after 1 week, if fever persists">
            </label>

            <fieldset class="mp-consultation-fieldset">
                <legend>Prescription (optional)</legend>
                <div id="prescriptionRows">
                    <div class="mp-prescription-row">
                        <input type="text" name="medicine[]" placeholder="Medicine">
                        <input type="text" name="dosage[]" placeholder="Dosage (e.g. 500mg)">
                        <input type="text" name="frequency[]" placeholder="Frequency (e.g. 2x/day)">
                        <input type="text" name="duration[]" placeholder="Duration (e.g. 5 days)">
                        <input type="text" name="med_instructions[]" placeholder="Instructions (e.g. after food)">
                    </div>
                </div>
                <button type="button" class="mp-btn mp-btn-small mp-btn-secondary" id="addMedicineRow">+ Add medicine</button>
            </fieldset>

            <fieldset class="mp-consultation-fieldset">
                <legend>Lab tests (optional)</legend>
                <div id="labRows">
                    <div class="mp-lab-row">
                        <input type="text" name="lab_test[]" placeholder="Test name (e.g. Complete Blood Count)">
                    </div>
                </div>
                <button type="button" class="mp-btn mp-btn-small mp-btn-secondary" id="addLabRow">+ Add lab test</button>
            </fieldset>

            <button type="submit" class="mp-btn mp-btn-primary">Complete Consultation</button>
        </form>
    <?php else: ?>
        <p>No patient currently in consultation.</p>
    <?php endif; ?>
</div>

<div class="mp-card">
    <h2>Notifications</h2>
    <?php if (empty($notifications)): ?>
        <p>No notifications yet.</p>
    <?php else: ?>
        <ul class="mp-links">
            <?php foreach ($notifications as $note): ?>
                <li><?php echo e($note['message']); ?> <small><?php echo e($note['created_at']); ?></small></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="mp-card">
    <h2>My Waiting Queue</h2>
    <table class="mp-table">
        <thead><tr><th>Pos</th><th>Token</th><th>Booking</th><th>Patient details</th><th>Priority factors</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($myQueue as $q): ?>
            <tr>
                <td><?php echo (int)$q['queue_position']; ?></td>
                <td><?php echo e($q['token']); ?></td>
                <td><?php echo $q['booking_type'] === 'walk_in' ? 'Walk-in' : 'Online'; ?></td>
                <td><strong><?php echo e($q['patient_name']); ?></strong><br><small><?php echo e($q['gender']); ?> Â· <?php echo e($q['phone']); ?><br><?php echo e($q['address'] ?: 'No address saved'); ?></small></td>
                <td><strong><?php echo e($q['priority_score']); ?></strong><br><small><?php echo e(ucfirst($q['booking_emergency_level'])); ?> Â· <?php echo e($q['arrival_status']); ?><br><?php echo e($q['previous_status_flag']); ?> Â· <?php echo (int)$q['queue_position']; ?> position</small></td>
                <td><?php echo e(str_replace('_',' ',$q['status'])); ?></td>
                <td>
                    <?php if (!$currentPatient): ?>
                    <form method="post" action="/medi/doctor/start_consultation.php">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="appointment_id" value="<?php echo (int)$q['appointment_id']; ?>">
                        <button type="submit" class="mp-btn mp-btn-small mp-btn-primary">Start</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($myQueue)): ?>
            <tr><td colspan="7">No patients currently waiting for you today.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="mp-card">
    <h2>Specialist waiting room</h2>
    <p>Arrived patients needing <?php echo e($doctor['specialization_name']); ?> who are waiting for an available specialist.</p>
    <?php if (empty($specialistWaiting)): ?>
        <p>No unassigned patients currently require your specialization.</p>
    <?php else: ?>
        <table class="mp-table">
            <thead><tr><th>Position</th><th>Token</th><th>Patient</th><th>Priority</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($specialistWaiting as $patient): ?>
                <tr>
                    <td><?php echo (int)$patient['queue_position']; ?></td>
                    <td><?php echo e($patient['token']); ?></td>
                    <td><?php echo e($patient['patient_name']); ?></td>
                    <td><?php echo e($patient['priority_score']); ?></td>
                    <td>Waiting for specialist</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="mp-grid">
    <div class="mp-card">
        <h2>Completed today</h2>
        <?php if (empty($completedAppointments)): ?>
            <p>No completed appointments yet.</p>
        <?php else: ?>
            <ul class="mp-links">
                <?php foreach ($completedAppointments as $completed): ?>
                    <li><?php echo e($completed['token']); ?> Â· <?php echo e($completed['patient_name']); ?> <small><?php echo e($completed['completed_at']); ?></small></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <div class="mp-card">
        <h2>Cancelled today</h2>
        <?php if (empty($cancelledAppointments)): ?>
            <p>No cancellations today.</p>
        <?php else: ?>
            <ul class="mp-links">
                <?php foreach ($cancelledAppointments as $cancelled): ?>
                    <li><?php echo e($cancelled['token']); ?> Â· <?php echo e($cancelled['patient_name']); ?> <small><?php echo e($cancelled['cancelled_at']); ?></small></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const el = document.getElementById('consultElapsed');
    if (!el) return;
    const started = new Date(el.dataset.started.replace(' ', 'T'));
    function tick() {
        const minutes = Math.max(0, Math.round((Date.now() - started.getTime()) / 60000));
        el.textContent = minutes;
    }
    tick();
    setInterval(tick, 30000);
})();

(function () {
    const addMedBtn = document.getElementById('addMedicineRow');
    const prescriptionRows = document.getElementById('prescriptionRows');
    if (addMedBtn && prescriptionRows) {
        addMedBtn.addEventListener('click', function () {
            const row = document.createElement('div');
            row.className = 'mp-prescription-row';
            row.innerHTML = '<input type="text" name="medicine[]" placeholder="Medicine">' +
                '<input type="text" name="dosage[]" placeholder="Dosage (e.g. 500mg)">' +
                '<input type="text" name="frequency[]" placeholder="Frequency (e.g. 2x/day)">' +
                '<input type="text" name="duration[]" placeholder="Duration (e.g. 5 days)">' +
                '<input type="text" name="med_instructions[]" placeholder="Instructions (e.g. after food)">';
            prescriptionRows.appendChild(row);
        });
    }

    const addLabBtn = document.getElementById('addLabRow');
    const labRows = document.getElementById('labRows');
    if (addLabBtn && labRows) {
        addLabBtn.addEventListener('click', function () {
            const row = document.createElement('div');
            row.className = 'mp-lab-row';
            row.innerHTML = '<input type="text" name="lab_test[]" placeholder="Test name">';
            labRows.appendChild(row);
        });
    }

    const consultationForm = document.getElementById('consultationForm');
    if (consultationForm) {
        consultationForm.addEventListener('submit', function (e) {
            if (!confirm('Complete this consultation? The token will close and cannot be reopened.')) {
                e.preventDefault();
            }
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

