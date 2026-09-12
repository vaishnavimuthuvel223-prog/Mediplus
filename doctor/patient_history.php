<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/appointment_functions.php';
require_once __DIR__ . '/../includes/consultation_functions.php';

require_doctor_face_verification();

$pdo = get_db_connection();
$doctor_id = $_SESSION['doctor_id'];
$patient_id = (int) ($_GET['patient_id'] ?? 0);
$currentAppointmentId = (int) ($_GET['appointment_id'] ?? 0);

// A doctor may only look up history for a patient currently (or
// previously) assigned to them - never an arbitrary patient_id.
$ownershipStmt = $pdo->prepare("
    SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND doctor_id = ?
");
$ownershipStmt->execute([$patient_id, $doctor_id]);
if (!$patient_id || (int) $ownershipStmt->fetchColumn() === 0) {
    set_flash('error', 'You can only view records for your own patients.');
    redirect('/medi/doctor/dashboard.php');
}

$patientStmt = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ?");
$patientStmt->execute([$patient_id]);
$patient = $patientStmt->fetch();
if (!$patient) {
    set_flash('error', 'Patient not found.');
    redirect('/medi/doctor/dashboard.php');
}

$history = get_patient_history($pdo, $patient_id, $currentAppointmentId ?: null);
$patientType = get_patient_type($pdo, $patient_id, $currentAppointmentId ?: null);

$pageTitle = 'Patient History';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="mp-form-eyebrow">Doctor Â· Patient records</div>
<h1><?php echo e($patient['full_name']); ?> <span class="mp-badge"><?php echo $patientType === 'returning' ? 'Returning Patient' : 'New Patient'; ?></span></h1>
<p class="mp-form-intro"><?php echo e(ucfirst($patient['gender'])); ?> Â· <?php echo e($patient['phone']); ?> Â· <?php echo e($patient['age_category']); ?></p>

<div class="mp-card">
    <h2>Previous consultations</h2>
    <?php if (empty($history)): ?>
        <p>No previous completed consultations on file.</p>
    <?php else: ?>
        <?php foreach ($history as $visit): ?>
            <?php $record = get_consultation_record($pdo, $visit['appointment_id']); ?>
            <div class="mp-card" style="background: rgba(255,255,255,0.5);">
                <p><strong>Token <?php echo e($visit['token']); ?></strong> â€” <?php echo e($visit['appointment_date']); ?>
                    Â· <?php echo e($visit['specialization_name']); ?>
                    <?php echo $visit['doctor_name'] ? ' with Dr. ' . e($visit['doctor_name']) : ''; ?>
                </p>
                <?php if ($record && $record['consultation']): ?>
                    <?php if (!empty($record['consultation']['diagnosis'])): ?><p><strong>Diagnosis:</strong> <?php echo e($record['consultation']['diagnosis']); ?></p><?php endif; ?>
                    <?php if (!empty($record['consultation']['notes'])): ?><p><strong>Notes:</strong> <?php echo nl2br(e($record['consultation']['notes'])); ?></p><?php endif; ?>
                    <?php if (!empty($record['prescription_items'])): ?>
                        <p><strong>Prescribed:</strong>
                        <?php echo e(implode(', ', array_map(function ($i) { return $i['medicine']; }, $record['prescription_items']))); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($record['lab_tests'])): ?>
                        <p><strong>Lab tests:</strong>
                        <?php echo e(implode(', ', array_map(function ($t) { return $t['test_name'] . ' (' . str_replace('_',' ',$t['status']) . ')'; }, $record['lab_tests']))); ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p>No consultation notes recorded for this visit.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<a href="/medi/doctor/dashboard.php" class="mp-btn mp-btn-secondary">Back to Dashboard</a>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

