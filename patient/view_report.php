<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/consultation_functions.php';

require_role('patient');

$pdo = get_db_connection();
$patient_id = $_SESSION['patient_id'];
$appointment_id = (int) ($_GET['appointment_id'] ?? 0);

// Ownership check - a patient may only ever view their own report.
$stmt = $pdo->prepare("
    SELECT a.*, s.name AS specialization_name, d.full_name AS doctor_name, p.full_name AS patient_name,
           p.dob, p.gender, p.phone
    FROM appointments a
    JOIN specializations s ON s.specialization_id = a.specialization_id
    JOIN patients p ON p.patient_id = a.patient_id
    LEFT JOIN doctors d ON d.doctor_id = a.doctor_id
    WHERE a.appointment_id = ? AND a.patient_id = ?
");
$stmt->execute([$appointment_id, $patient_id]);
$appointment = $stmt->fetch();

if (!$appointment) {
    set_flash('error', 'Appointment not found.');
    redirect('/medi/patient/my_appointments.php');
}

if ($appointment['status'] !== 'completed') {
    set_flash('error', 'This consultation is not completed yet.');
    redirect('/medi/patient/my_appointments.php');
}

$record = get_consultation_record($pdo, $appointment_id);
$patientType = get_patient_type($pdo, $patient_id, $appointment_id);

$pageTitle = 'Consultation Report'; $bodyAccent = 'patient';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="mp-card mp-form-card mp-report" id="reportCard">
    <div class="mp-report-header">
        <div>
            <div class="mp-form-eyebrow">MediPlus Â· Consultation Report</div>
            <h1><?php echo e(get_setting('hospital_name', 'MediPlus Hospital')); ?></h1>
        </div>
        <button class="mp-btn mp-btn-secondary mp-no-print" onclick="window.print()">Download / Print Report</button>
    </div>

    <div class="mp-flash mp-flash-success">CONSULTATION COMPLETED</div>

    <div class="mp-grid">
        <div>
            <h2>Patient</h2>
            <p><strong><?php echo e($appointment['patient_name']); ?></strong> <span class="mp-badge"><?php echo $patientType === 'returning' ? 'Returning Patient' : 'New Patient'; ?></span></p>
            <p><?php echo e(ucfirst($appointment['gender'])); ?> Â· DOB <?php echo e($appointment['dob']); ?> Â· <?php echo e($appointment['phone']); ?></p>
        </div>
        <div>
            <h2>Appointment</h2>
            <p><strong>Token:</strong> <?php echo e($appointment['token']); ?></p>
            <p><strong>Date:</strong> <?php echo e($appointment['appointment_date']); ?> at <?php echo e($appointment['appointment_time']); ?></p>
            <p><strong>Specialization:</strong> <?php echo e($appointment['specialization_name']); ?></p>
            <p><strong>Doctor:</strong> <?php echo $appointment['doctor_name'] ? 'Dr. ' . e($appointment['doctor_name']) : 'â€”'; ?></p>
            <p><strong>Consultation completed:</strong> <?php echo e($appointment['completed_at']); ?></p>
        </div>
    </div>

    <hr>

    <?php if ($record && $record['consultation']): ?>
        <h2>Consultation Summary</h2>
        <?php if (!empty($record['consultation']['diagnosis'])): ?>
            <p><strong>Diagnosis:</strong> <?php echo e($record['consultation']['diagnosis']); ?></p>
        <?php endif; ?>
        <?php if (!empty($record['consultation']['notes'])): ?>
            <p><strong>Doctor's notes:</strong><br><?php echo nl2br(e($record['consultation']['notes'])); ?></p>
        <?php endif; ?>
        <?php if (!empty($record['consultation']['instructions'])): ?>
            <p><strong>Instructions:</strong><br><?php echo nl2br(e($record['consultation']['instructions'])); ?></p>
        <?php endif; ?>
        <?php if (!empty($record['consultation']['follow_up'])): ?>
            <p><strong>Follow-up:</strong> <?php echo e($record['consultation']['follow_up']); ?></p>
        <?php endif; ?>

        <?php if ($record['prescription']): ?>
            <h2>Prescription</h2>
            <p><strong>Pharmacy status:</strong>
                <span class="mp-badge mp-badge-<?php echo e($record['prescription']['status']); ?>">
                    <?php echo e(str_replace('_', ' ', $record['prescription']['status'])); ?>
                </span>
            </p>
            <?php if (!empty($record['prescription_items'])): ?>
                <table class="mp-table">
                    <thead><tr><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Duration</th><th>Instructions</th></tr></thead>
                    <tbody>
                    <?php foreach ($record['prescription_items'] as $item): ?>
                        <tr>
                            <td><?php echo e($item['medicine']); ?></td>
                            <td><?php echo e($item['dosage'] ?: 'â€”'); ?></td>
                            <td><?php echo e($item['frequency'] ?: 'â€”'); ?></td>
                            <td><?php echo e($item['duration'] ?: 'â€”'); ?></td>
                            <td><?php echo e($item['instructions'] ?: 'â€”'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($record['lab_tests'])): ?>
            <h2>Lab Tests</h2>
            <table class="mp-table">
                <thead><tr><th>Test</th><th>Status</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($record['lab_tests'] as $lab): ?>
                    <tr>
                        <td><?php echo e($lab['test_name']); ?></td>
                        <td><span class="mp-badge mp-badge-<?php echo e($lab['status']); ?>"><?php echo e(str_replace('_', ' ', $lab['status'])); ?></span></td>
                        <td><?php echo $lab['result_text'] ? nl2br(e($lab['result_text'])) : 'â€”'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php else: ?>
        <p>No detailed consultation notes were recorded for this visit.</p>
    <?php endif; ?>

    <p class="mp-no-print"><a href="/medi/patient/my_appointments.php" class="mp-btn mp-btn-secondary">Back to My Appointments</a></p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

