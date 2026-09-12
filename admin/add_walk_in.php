<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/appointment_functions.php';
require_once __DIR__ . '/../includes/priority_engine.php';

require_role('admin');

$pdo = get_db_connection();
$admin_id = $_SESSION['admin_id'];

$specializations = $pdo->query("SELECT specialization_id, name FROM specializations ORDER BY name")->fetchAll();
$errors = [];
$createdToken = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid session token. Please try again.';
    }

    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $specialization_id = $_POST['specialization_id'] ?? '';
    $doctor_id = $_POST['doctor_id'] ?? '';
    $emergency_level = $_POST['emergency_level'] ?? 'normal';
    $dob = $_POST['dob'] ?? '';
    $gender = $_POST['gender'] ?? 'other';
    $address = trim($_POST['address'] ?? '');

    if ($full_name === '' || $phone === '' || $specialization_id === '') {
        $errors[] = 'Name, phone, and specialization are required.';
    }

    if (empty($errors)) {
        $result = add_walk_in_token(
            $pdo, $admin_id, $full_name, $phone, $specialization_id,
            $doctor_id ?: null, $emergency_level, $dob ?: null, $gender, $address ?: null
        );
        if ($result['success']) {
            set_flash('success', 'Walk-in token ' . $result['token'] . ' created and added to the live queue.');
            redirect('/medi/admin/add_walk_in.php');
        } else {
            $errors[] = $result['message'];
        }
    }
}

$pageTitle = 'Add Walk-in Token';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="mp-card mp-form-card">
    <h1>Add Walk-in Token</h1>
    <p class="mp-form-intro">For patients who arrive directly without booking online. This creates a token and drops them straight into today's live queue, alongside every online booking, in the same priority order.</p>

    <?php foreach ($errors as $error): ?>
        <div class="mp-flash mp-flash-error"><?php echo e($error); ?></div>
    <?php endforeach; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

        <label>Patient Full Name</label>
        <input type="text" name="full_name" required value="<?php echo e($_POST['full_name'] ?? ''); ?>">

        <label>Phone</label>
        <input type="text" name="phone" required value="<?php echo e($_POST['phone'] ?? ''); ?>">
        <p class="mp-form-intro">If this phone number already exists in the system, the existing patient record is reused instead of creating a duplicate.</p>

        <label>Date of Birth (optional if patient is new)</label>
        <input type="date" name="dob" value="<?php echo e($_POST['dob'] ?? ''); ?>">

        <label>Gender</label>
        <select name="gender">
            <option value="other">Other</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
        </select>

        <label>Address (optional â€” enables their travel/reach-by estimate)</label>
        <input type="text" name="address" value="<?php echo e($_POST['address'] ?? ''); ?>">

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

        <label>Urgency Level</label>
        <select name="emergency_level">
            <option value="normal">Normal</option>
            <option value="urgent">Urgent</option>
            <option value="critical">Critical</option>
        </select>

        <button type="submit" class="mp-btn mp-btn-primary">Add to Live Queue</button>
    </form>
</div>

<script>
async function loadDoctors() {
    const specId = document.getElementById('specializationSelect').value;
    const doctorSelect = document.getElementById('doctorSelect');
    doctorSelect.innerHTML = '<option value="">-- Any Available Doctor --</option>';
    if (!specId) return;
    try {
        const res = await fetch('/medi/admin/get_doctors.php?specialization_id=' + encodeURIComponent(specId));
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

