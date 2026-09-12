<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/consultation_functions.php';

require_role('patient');

$pdo = get_db_connection();
$patient_id = $_SESSION['patient_id'];
$patientType = get_patient_type($pdo, $patient_id);

$stmt = $pdo->prepare("
    SELECT a.*, s.name AS specialization_name, d.full_name AS doctor_name
    FROM appointments a
    JOIN specializations s ON s.specialization_id = a.specialization_id
    LEFT JOIN doctors d ON d.doctor_id = a.doctor_id
    WHERE a.patient_id = ?
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
");
$stmt->execute([$patient_id]);
$appointments = $stmt->fetchAll();

$pageTitle = 'My Appointments'; $bodyAccent = 'patient';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="mp-form-eyebrow">Patient Â· History</div>
<h1>My Appointments <span class="mp-badge"><?php echo $patientType === 'returning' ? 'Returning Patient' : 'New Patient'; ?></span></h1>
<p class="mp-form-intro">Every visit you've booked, past and upcoming.</p>

<div class="mp-card">
<table class="mp-table">
    <thead>
        <tr>
            <th>Token</th><th>Date</th><th>Time</th><th>Specialization</th>
            <th>Doctor</th><th>Status</th><th>Arrival</th><th>Action</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($appointments as $a): ?>
        <tr class="<?php echo $a['booking_emergency_level'] !== 'normal' ? 'mp-row-emergency' : ''; ?>">
            <td><span class="mp-token-inline"><?php echo e($a['token']); ?></span></td>
            <td><?php echo e($a['appointment_date']); ?></td>
            <td><?php echo e($a['appointment_time']); ?></td>
            <td><?php echo e($a['specialization_name']); ?></td>
            <td><?php echo $a['doctor_name'] ? e($a['doctor_name']) : 'â€”'; ?></td>
            <td><span class="mp-badge<?php echo $a['status'] === 'cancelled' ? ' mp-badge-danger' : ''; ?>"><?php echo e(str_replace('_',' ', $a['status'])); ?></span></td>
            <td><?php echo e(str_replace('_',' ', $a['arrival_status'])); ?></td>
            <td>
                <?php if (in_array($a['status'], ['scheduled','waiting','arrived'])): ?>
                    <form method="post" action="/medi/patient/cancel_appointment.php" onsubmit="return confirm('Cancel this appointment?');">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="appointment_id" value="<?php echo (int)$a['appointment_id']; ?>">
                        <button type="submit" class="mp-btn mp-btn-small mp-btn-danger">Cancel</button>
                    </form>
                <?php elseif ($a['status'] === 'completed'): ?>
                    <a href="/medi/patient/view_report.php?appointment_id=<?php echo (int)$a['appointment_id']; ?>" class="mp-btn mp-btn-small mp-btn-secondary">View Report</a>
                <?php else: ?>
                    â€”
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($appointments)): ?>
        <tr><td colspan="8">No appointments yet. <a href="/medi/patient/book_appointment.php">Book your first one</a>.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

