<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/priority_engine.php';

require_role('patient');

$pdo = get_db_connection();
$patient_id = $_SESSION['patient_id'];
$appointment_id = (int) ($_GET['appointment_id'] ?? 0);

// Ownership check - a patient may only ever see their own token/board.
$stmt = $pdo->prepare("
    SELECT a.*, s.name AS specialization_name, d.full_name AS doctor_name
    FROM appointments a
    JOIN specializations s ON s.specialization_id = a.specialization_id
    LEFT JOIN doctors d ON d.doctor_id = a.doctor_id
    WHERE a.appointment_id = ? AND a.patient_id = ?
");
$stmt->execute([$appointment_id, $patient_id]);
$appointment = $stmt->fetch();

if (!$appointment) {
    set_flash('error', 'Appointment not found.');
    redirect('/medi/patient/my_appointments.php');
}

$board = get_patient_token_board($pdo, $appointment_id);

$pageTitle = 'Booking Confirmation'; $bodyAccent = 'patient';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="mp-card mp-form-card">
    <h1>âœ… Token Confirmed</h1>
    <p class="mp-form-intro">
        <?php echo e($appointment['specialization_name']); ?>
        <?php echo $appointment['doctor_name'] ? ' with ' . e($appointment['doctor_name']) : ' â€” doctor will be assigned automatically'; ?>
    </p>

    <div class="mp-token-hero">
        <span class="mp-token-hero-label">Your Token</span>
        <span class="mp-token-hero-value" id="yourTokenText"><?php echo e($appointment['token']); ?></span>
    </div>

    <p><strong>Appointment Date/Time:</strong>
        <?php echo e($appointment['appointment_date']); ?> at <?php echo e($appointment['appointment_time']); ?>
    </p>
    <p><strong>Appointment Status:</strong> <span class="mp-badge" id="appointmentStatusText"><?php echo e(str_replace('_', ' ', $appointment['status'])); ?></span></p>

    <div class="mp-token-board">
        <div class="mp-token-cell">
            <span class="mp-token-cell-label">Current Token</span>
            <span class="mp-token-cell-value" id="currentTokenText"><?php echo $board['current_token'] ? e($board['current_token']) : 'â€”'; ?></span>
        </div>
        <div class="mp-token-cell">
            <span class="mp-token-cell-label">Next Token</span>
            <span class="mp-token-cell-value" id="nextTokenText"><?php echo $board['next_token'] ? e($board['next_token']) : 'â€”'; ?></span>
        </div>
        <div class="mp-token-cell is-you">
            <span class="mp-token-cell-label">Your Token</span>
            <span class="mp-token-cell-value"><?php echo e($appointment['token']); ?></span>
        </div>
        <div class="mp-token-cell">
            <span class="mp-token-cell-label">Patients Ahead</span>
            <span class="mp-token-cell-value" id="patientsAheadText"><?php echo (int) $board['patients_ahead']; ?></span>
        </div>
        <div class="mp-token-cell">
            <span class="mp-token-cell-label">Queue Position</span>
            <span class="mp-token-cell-value" id="queuePositionText"><?php echo $board['queue_position'] !== null ? (int) $board['queue_position'] : 'â€”'; ?></span>
        </div>
        <div class="mp-token-cell">
            <span class="mp-token-cell-label">Estimated Time</span>
            <span class="mp-token-cell-value" id="estimatedTimeText"><?php echo e($board['estimated_time']); ?></span>
        </div>
    </div>

    <?php if (!$board['in_active_queue']): ?>
        <p class="mp-location-status">Patients ahead / queue position are a live preview based on today's current queue. They become exact the moment you mark arrival at the hospital.</p>
    <?php endif; ?>

    <div class="mp-links" style="margin-top: 18px;">
        <a href="/medi/patient/dashboard.php" class="mp-btn mp-btn-primary">Go to Dashboard</a>
        <a href="/medi/patient/my_appointments.php" class="mp-btn mp-btn-secondary">View All Appointments</a>
    </div>
</div>

<script>
const TOKEN_BOARD_APPOINTMENT_ID = <?php echo (int) $appointment_id; ?>;
</script>
<script src="/medi/assets/js/queue.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

