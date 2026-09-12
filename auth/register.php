<?php
require_once __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    redirect('/medi/index.php');
}

$pdo = get_db_connection();
$specializations = $pdo->query("SELECT specialization_id, name FROM specializations ORDER BY name")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid session token. Please try again.';
    }

    $role = $_POST['role'] ?? 'patient';
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');

    if ($username === '' || $email === '' || $password === '' || $full_name === '') {
        $errors[] = 'All required fields must be filled.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($role !== 'patient') {
        $errors[] = 'Only patient registration is available.';
    }

    if ($role === 'patient') {
        $dob = $_POST['dob'] ?? '';
        $phone = trim($_POST['phone'] ?? '');
        $gender = $_POST['gender'] ?? 'other';
        if ($dob === '' || $phone === '') {
            $errors[] = 'Date of birth and phone are required for patients.';
        }
    }

    $address = trim($_POST['address'] ?? '');

    if (empty($errors)) {
        try {
            $checkStmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
            $checkStmt->execute([$username, $email]);
            if ($checkStmt->fetch()) {
                $errors[] = 'Username or email already in use.';
            } else {
                $pdo->beginTransaction();

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $userStmt = $pdo->prepare("INSERT INTO users (role, username, email, password_hash) VALUES (?, ?, ?, ?)");
                $userStmt->execute([$role, $username, $email, $hash]);
                $user_id = (int) $pdo->lastInsertId();

                if ($role === 'patient') {
                    $ageCategory = calculate_age_category($dob);
                    $pStmt = $pdo->prepare("
                        INSERT INTO patients (user_id, full_name, dob, age_category, gender, phone, address)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $pStmt->execute([$user_id, $full_name, $dob, $ageCategory, $gender, $phone, $address ?: null]);
                }

                $pdo->commit();
                set_flash('success', 'Registration successful. Please log in.');
                redirect('/medi/auth/patient_login.php');
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Registration failed: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Register'; $bodyAccent = 'patient';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="mp-card mp-form-card">
    <h1>Create your MediPlus account</h1>

    <?php foreach ($errors as $error): ?>
        <div class="mp-flash mp-flash-error"><?php echo e($error); ?></div>
    <?php endforeach; ?>

    <form method="post" id="registerForm">
        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

        <label>Register as</label>
        <select name="role" id="roleSelect" onchange="toggleRoleFields()">
            <option value="patient">Patient</option>
        </select>

        <label>Full Name</label>
        <input type="text" name="full_name" value="<?php echo e($_POST['full_name'] ?? ''); ?>" required>

        <label>Username</label>
        <input type="text" name="username" value="<?php echo e($_POST['username'] ?? ''); ?>" required>

        <label>Email</label>
        <input type="email" name="email" value="<?php echo e($_POST['email'] ?? ''); ?>" required>

        <label>Password</label>
        <input type="password" name="password" required>

        <div id="patientFields">
            <label>Date of Birth</label>
            <input type="date" name="dob" value="<?php echo e($_POST['dob'] ?? ''); ?>">

            <label>Gender</label>
            <select name="gender">
                <option value="male">Male</option>
                <option value="female">Female</option>
                <option value="other">Other</option>
            </select>

            <label>Phone</label>
            <input type="text" name="phone" value="<?php echo e($_POST['phone'] ?? ''); ?>">

            <label>Home Address</label>
            <input type="text" name="address" value="<?php echo e($_POST['address'] ?? ''); ?>" placeholder="Used for travel estimate">
        </div>

        <button type="submit" class="mp-btn mp-btn-primary">Register</button>
    </form>
    <p>Already have an account? <a href="/medi/auth/patient_login.php">Login here</a></p>
</div>

<script>
document.getElementById('roleSelect').disabled = true;
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

