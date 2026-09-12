<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/appointment_functions.php';

require_role('patient');

$pdo = get_db_connection();
$patient_id = $_SESSION['patient_id'];

$specializations = $pdo->query("SELECT specialization_id, name FROM specializations ORDER BY name")->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid session token. Please try again.';
    }

    $specialization_id = $_POST['specialization_id'] ?? '';
    $doctor_id = $_POST['doctor_id'] ?? '';
    $date = $_POST['appointment_date'] ?? '';
    $time = $_POST['appointment_time'] ?? '';
    $emergency_level = $_POST['emergency_level'] ?? 'normal';

    if ($specialization_id === '' || $date === '' || $time === '') {
        $errors[] = 'Specialization, date and time are required.';
    }
    if (!in_array($emergency_level, ['normal','urgent','critical'])) {
        $emergency_level = 'normal';
    }
    if ($date !== '' && $date < date('Y-m-d')) {
        $errors[] = 'Appointment date cannot be in the past.';
    }

    if (empty($errors)) {
        $appointment_id = book_appointment($pdo, $patient_id, $specialization_id, $doctor_id ?: null, $date, $time, $emergency_level);
        set_flash('success', 'Appointment booked successfully. Here is your token.');
        redirect('/medi/patient/booking_confirmation.php?appointment_id=' . $appointment_id);
    }
}

$pageTitle = 'Book Appointment'; $bodyAccent = 'patient';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="mp-card mp-form-card">
    <div class="mp-form-eyebrow">Patient Â· New booking</div>
    <h1>Book an Appointment</h1>
    <p class="mp-form-intro">Pick a specialization and time â€” you'll get a live token the moment you confirm.</p>

    <?php foreach ($errors as $error): ?>
        <div class="mp-flash mp-flash-error"><?php echo e($error); ?></div>
    <?php endforeach; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

        <label>Specialization</label>
        <select name="specialization_id" id="specializationSelect" onchange="loadDoctors()" required>
            <option value="">-- Select Specialization --</option>
            <?php foreach ($specializations as $s): ?>
                <option value="<?php echo (int)$s['specialization_id']; ?>"><?php echo e($s['name']); ?></option>
            <?php endforeach; ?>
        </select>

        <label>Preferred Doctor (optional)</label>
        <select name="doctor_id" id="doctorSelect">
            <option value="">-- Any Available Doctor --</option>
        </select>

        <label>Appointment Date</label>
        <input type="date" name="appointment_date" min="<?php echo date('Y-m-d'); ?>" required>

        <label>Appointment Time</label>
        <input type="time" name="appointment_time" required>

        <label>Urgency Level</label>
        <select name="emergency_level">
            <option value="normal">Normal</option>
            <option value="urgent">Urgent</option>
            <option value="critical">Critical</option>
        </select>

        <button type="submit" class="mp-btn mp-btn-primary">Book Appointment</button>
    </form>
</div>

<script>
async function loadDoctors() {
    const specId = document.getElementById('specializationSelect').value;
    const doctorSelect = document.getElementById('doctorSelect');
    doctorSelect.innerHTML = '<option value="">-- Any Available Doctor --</option>';
    if (!specId) return;
    try {
        const res = await fetch('/medi/patient/get_doctors.php?specialization_id=' + encodeURIComponent(specId));
        const doctors = await res.json();
        doctors.forEach(doc => {
            const opt = document.createElement('option');
            opt.value = doc.doctor_id;
            opt.textContent = doc.full_name + ' (' + doc.status + ')';
            doctorSelect.appendChild(opt);
        });
    } catch (e) {
        console.error('Failed to load doctors', e);
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

