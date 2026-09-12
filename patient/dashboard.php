<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/priority_engine.php';
require_once __DIR__ . '/../includes/emergency_functions.php';
require_once __DIR__ . '/../includes/consultation_functions.php';

require_role('patient');

$pdo = get_db_connection();
$patient_id = $_SESSION['patient_id'];

$patientStmt = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ?");
$patientStmt->execute([$patient_id]);
$patient = $patientStmt->fetch();

$todayStmt = $pdo->prepare("
    SELECT a.*, s.name AS specialization_name, d.full_name AS doctor_name
    FROM appointments a
    JOIN specializations s ON s.specialization_id = a.specialization_id
    LEFT JOIN doctors d ON d.doctor_id = a.doctor_id
    WHERE a.patient_id = ? AND a.appointment_date = CURDATE()
      AND a.status NOT IN ('cancelled')
    ORDER BY a.created_at DESC LIMIT 1
");
$todayStmt->execute([$patient_id]);
$todayAppointment = $todayStmt->fetch();

$queueInfo = null;
$queueSummary = null;
if ($todayAppointment && in_array($todayAppointment['status'], ['arrived','waiting','in_consultation'])) {
    $queue = recalculate_queue($pdo);
    foreach ($queue as $row) {
        if ((int)$row['appointment_id'] === (int)$todayAppointment['appointment_id']) {
            $queueInfo = $row;
            break;
        }
    }
    $queueSummary = get_patient_queue_summary($pdo, $todayAppointment['appointment_id']);
}

// Live token board (Current Token / Next Token / patient's own position),
// covers both "already in the active queue" and "booked for today, not
// yet arrived" cases. Read-only - does not touch priority/queue logic.
$tokenBoard = $todayAppointment ? get_patient_token_board($pdo, $todayAppointment['appointment_id']) : null;

$activeEmergencyStmt = $pdo->prepare("SELECT * FROM emergency_requests WHERE patient_id = ? AND status != 'handled' ORDER BY triggered_at DESC LIMIT 1");
$activeEmergencyStmt->execute([$patient_id]);
$activeEmergency = $activeEmergencyStmt->fetch();

$notifications = get_notifications($pdo, $_SESSION['user_id'], 5);
$queueAhead = $queueInfo ? max(0, (int)$queueInfo['queue_position'] - 1) : 0;
$patientType = get_patient_type($pdo, $patient_id, $todayAppointment ? $todayAppointment['appointment_id'] : null);

$pageTitle = 'Patient Dashboard'; $bodyAccent = 'patient';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Welcome, <?php echo e($patient['full_name']); ?> <span class="mp-badge"><?php echo $patientType === 'returning' ? 'Returning Patient' : 'New Patient'; ?></span></h1>

<div class="mp-emergency-box">
    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
    <button id="emergencyBtn" class="mp-emergency-btn" <?php echo $activeEmergency ? 'disabled' : ''; ?>>
        ðŸš¨ EMERGENCY
    </button>
    <p id="emergencyStatusText" class="mp-emergency-status">
        <?php echo $activeEmergency ? 'Emergency active - status: ' . e(str_replace('_',' ', $activeEmergency['status'])) : 'Press only in a real medical emergency. This immediately alerts hospital staff.'; ?>
    </p>
    <p id="locationStatusText" class="mp-location-status"></p>
</div>

<div class="mp-grid">
    <div class="mp-card">
        <h2>Today's Appointment</h2>
        <?php if ($todayAppointment): ?>
            <p><strong>Token:</strong> <span id="yourTokenText" class="mp-token-inline"><?php echo e($todayAppointment['token']); ?></span></p>
            <p><strong>Specialization:</strong> <?php echo e($todayAppointment['specialization_name']); ?></p>
            <p><strong>Doctor:</strong> <?php echo $todayAppointment['doctor_name'] ? e($todayAppointment['doctor_name']) : 'Waiting for Specialist'; ?></p>
            <p><strong>Time:</strong> <?php echo e($todayAppointment['appointment_time']); ?></p>
            <p><strong>Status:</strong> <span class="mp-badge" id="appointmentStatusText"><?php echo e(str_replace('_',' ', $todayAppointment['status'])); ?></span></p>

            <?php if ($todayAppointment['status'] === 'completed'): ?>
                <div id="completionStatus" class="mp-flash mp-flash-success">CONSULTATION COMPLETED</div>
                <a id="completionReportLink" href="/medi/patient/view_report.php?appointment_id=<?php echo (int)$todayAppointment['appointment_id']; ?>" class="mp-btn mp-btn-primary">View Report / Prescription / Lab Status</a>
            <?php else: ?>
                <div id="completionStatus" class="mp-flash mp-flash-success" style="display:none;">CONSULTATION COMPLETED</div>
                <a id="completionReportLink" href="/medi/patient/view_report.php?appointment_id=<?php echo (int)$todayAppointment['appointment_id']; ?>" class="mp-btn mp-btn-primary" style="display:none;">View Report / Prescription / Lab Status</a>
            <?php endif; ?>

            <?php if ($todayAppointment['arrival_status'] === 'not_arrived' && $todayAppointment['status'] === 'scheduled'): ?>
                <form method="post" action="/medi/patient/mark_arrival.php">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="appointment_id" value="<?php echo (int)$todayAppointment['appointment_id']; ?>">
                    <button type="submit" class="mp-btn mp-btn-secondary">Mark Arrival</button>
                </form>
            <?php endif; ?>

            <?php if ($tokenBoard && !in_array($todayAppointment['status'], ['completed','cancelled'], true)): ?>
                <hr>
                <p class="mp-form-intro">Live queue â€” updates automatically.</p>
                <div class="mp-token-board mp-token-board-compact">
                    <div class="mp-token-cell">
                        <span class="mp-token-cell-label">Current Token</span>
                        <span class="mp-token-cell-value" id="currentTokenText"><?php echo $tokenBoard['current_token'] ? e($tokenBoard['current_token']) : 'â€”'; ?></span>
                    </div>
                    <div class="mp-token-cell">
                        <span class="mp-token-cell-label">Next Token</span>
                        <span class="mp-token-cell-value" id="nextTokenText"><?php echo $tokenBoard['next_token'] ? e($tokenBoard['next_token']) : 'â€”'; ?></span>
                    </div>
                    <div class="mp-token-cell is-you">
                        <span class="mp-token-cell-label">Your Token</span>
                        <span class="mp-token-cell-value"><?php echo e($todayAppointment['token']); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($queueInfo): ?>
                <hr>
                <p><strong>Queue Position:</strong> <span id="queuePositionText"><?php echo (int)$queueInfo['queue_position']; ?></span></p>
                <p><strong>Priority Score:</strong> <?php echo e($queueInfo['priority_score']); ?></p>
                <p><strong>Patients Ahead:</strong> <span id="patientsAheadText"><?php echo (int)$queueSummary['patients_ahead']; ?></span></p>
                <p><strong>Estimated Waiting Time:</strong> <span id="etaText"><?php echo (int)$queueSummary['estimated_minutes']; ?></span> min</p>
                <p><strong>Estimated Consultation Time:</strong> <span id="estimatedTimeText"><?php echo e($tokenBoard['estimated_time']); ?></span></p>

                <?php if ($queueInfo['status'] === 'in_consultation'): ?>
                    <div id="proximityBanner" class="mp-flash mp-flash-success">You're with the doctor now.</div>
                <?php elseif ((int)$queueSummary['patients_ahead'] <= 2): ?>
                    <div id="proximityBanner" class="mp-flash mp-flash-error">
                        <?php
                        $ahead = (int)$queueSummary['patients_ahead'];
                        echo $ahead === 0 ? "It's almost your turn â€” please be ready." : ($ahead . ' token(s) ahead of you â€” get ready!');
                        ?>
                    </div>
                <?php else: ?>
                    <div id="proximityBanner" class="mp-flash" style="display:none;"></div>
                <?php endif; ?>
            <?php elseif ($tokenBoard && $todayAppointment['status'] === 'scheduled'): ?>
                <hr>
                <p><strong>Queue Position (preview):</strong> <span id="queuePositionText"><?php echo $tokenBoard['queue_position'] !== null ? (int)$tokenBoard['queue_position'] : 'â€”'; ?></span></p>
                <p><strong>Patients Ahead (preview):</strong> <span id="patientsAheadText"><?php echo (int)$tokenBoard['patients_ahead']; ?></span></p>
                <p><strong>Estimated Time:</strong> <span id="estimatedTimeText"><?php echo e($tokenBoard['estimated_time']); ?></span></p>
                <p class="mp-location-status">This becomes exact once you mark arrival at the hospital.</p>
            <?php endif; ?>
        <?php else: ?>
            <p>No appointment for today yet.</p>
            <a href="/medi/patient/book_appointment.php" class="mp-btn mp-btn-primary">Book Appointment</a>
        <?php endif; ?>
    </div>

    <div class="mp-card mp-queue-visual">
        <h2>Queue room view</h2>
        <p class="mp-form-intro">Your place in today&apos;s live flow.</p>
        <div class="mp-seats" aria-label="Visual queue position">
            <?php for ($seat = 1; $seat <= min(12, max(6, $queueAhead + 3)); $seat++): ?>
                <span class="mp-seat <?php echo $seat === ($queueInfo['queue_position'] ?? 0) ? 'is-you' : ($seat < ($queueInfo['queue_position'] ?? 0) ? 'is-waiting' : 'is-open'); ?>"><?php echo $seat === ($queueInfo['queue_position'] ?? 0) ? 'You' : $seat; ?></span>
            <?php endfor; ?>
        </div>
        <p><span class="mp-seat-key is-waiting"></span> Waiting <span class="mp-seat-key is-you"></span> You <span class="mp-seat-key is-open"></span> Open</p>
    </div>

    <div class="mp-card" id="travelCard">
        <h2>Travel estimate</h2>
        <p class="mp-form-intro">From your saved address to <?php echo e(get_setting('hospital_name', 'MediPlus Hospital')); ?>.</p>
        <?php if (empty($patient['address'])): ?>
            <p id="distanceText">Add an address to your patient profile to get a travel estimate.</p>
            <p id="travelText" class="mp-location-status"></p>
        <?php elseif (!$queueSummary || !$queueSummary['travel']): ?>
            <p id="distanceText">Distance is unavailable right now.</p>
            <p id="travelText" class="mp-location-status">Your saved address is still available for route planning.</p>
        <?php else: ?>
            <p id="distanceText"><?php echo e($queueSummary['travel']['distance_km']); ?> km &middot; about <?php echo (int)$queueSummary['travel']['travel_minutes']; ?> min by road</p>
            <?php if ($queueSummary['schedule']): ?>
                <p><strong>Your token is expected around:</strong> <span id="expectedCallTime"><?php echo e($queueSummary['schedule']['expected_call_time']); ?></span></p>
                <p><strong>Aim to reach the hospital by:</strong> <span id="reachByTime"><?php echo e($queueSummary['schedule']['reach_by_time']); ?></span></p>
                <p><strong>Leave home by:</strong> <span id="leaveByTime"><?php echo e($queueSummary['schedule']['leave_by_time']); ?></span></p>
            <?php endif; ?>
            <p id="travelText" class="mp-location-status"><?php echo $queueSummary['travel']['source'] === 'routed' ? 'Based on current road routing.' : 'Straight-line estimate â€” allow extra time for traffic.'; ?></p>
        <?php endif; ?>
    </div>

    <div class="mp-card">
        <h2>Quick Links</h2>
        <ul class="mp-links">
            <li><a href="/medi/patient/book_appointment.php">Book a new appointment</a></li>
            <li><a href="/medi/patient/my_appointments.php">View all my appointments</a></li>
            <li><a href="/medi/patient/my_appointments.php">View past prescriptions, lab results &amp; reports</a></li>
            <li><a href="/medi/queue/live_queue.php">View live hospital queue</a></li>
        </ul>
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
</div>

<script>
const EMERGENCY_APPOINTMENT_ID = <?php echo $todayAppointment ? (int)$todayAppointment['appointment_id'] : 'null'; ?>;
</script>
<script src="/medi/assets/js/emergency.js"></script>
<script src="/medi/assets/js/queue.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

