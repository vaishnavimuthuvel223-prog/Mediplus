<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/travel_functions.php';

require_role('admin');

$pdo = get_db_connection();

$editableKeys = [
    'hospital_name' => 'Hospital name',
    'hospital_address' => 'Hospital address (used for reference / re-geocoding)',
    'hospital_latitude' => 'Hospital latitude',
    'hospital_longitude' => 'Hospital longitude',
    'avg_consultation_minutes' => 'Average consultation length (minutes)',
    'reminder_thresholds' => 'Send "get ready" reminders when this many tokens are ahead (comma-separated, e.g. 3,2,1,0)',
    'checkin_buffer_minutes' => 'Check-in buffer before a token is called (minutes)',
    'avg_travel_speed_kmph' => 'Fallback travel speed when live routing is unavailable (km/h)',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid session token. Please try again.';
    }

    $action = $_POST['action'] ?? 'save_settings';

    if ($action === 'save_settings' && empty($errors)) {
        $update = $pdo->prepare("UPDATE settings SET setting_value = :value WHERE setting_key = :key");
        foreach ($editableKeys as $key => $label) {
            if (!isset($_POST[$key])) continue;
            $value = trim((string) $_POST[$key]);
            if ($value === '') continue;
            $update->execute([':value' => $value, ':key' => $key]);
        }
        set_flash('success', 'Hospital settings updated.');
        redirect('/medi/admin/settings.php');
    }

    if ($action === 'lookup_hospital_address' && empty($errors)) {
        $addressToLookup = trim((string) ($_POST['hospital_address'] ?? ''));
        $geo = geocode_address($addressToLookup);
        if ($geo) {
            $update = $pdo->prepare("UPDATE settings SET setting_value = :value WHERE setting_key = :key");
            $update->execute([':value' => $addressToLookup, ':key' => 'hospital_address']);
            $update->execute([':value' => $geo['latitude'], ':key' => 'hospital_latitude']);
            $update->execute([':value' => $geo['longitude'], ':key' => 'hospital_longitude']);
            set_flash('success', 'Hospital coordinates updated from address lookup: ' . $geo['latitude'] . ', ' . $geo['longitude']);
        } else {
            set_flash('error', 'Could not resolve that address to coordinates. You can enter latitude/longitude manually below.');
        }
        redirect('/medi/admin/settings.php');
    }
}

$currentSettings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
foreach ($stmt->fetchAll() as $row) {
    $currentSettings[$row['setting_key']] = $row['setting_value'];
}

$pageTitle = 'Hospital Settings';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Hospital Settings</h1>
<p class="mp-form-intro">This hospital's location is fixed and used to calculate every patient's travel time and "reach by" schedule. Update it here if the hospital ever relocates.</p>

<?php foreach ($errors as $error): ?>
    <div class="mp-flash mp-flash-error"><?php echo e($error); ?></div>
<?php endforeach; ?>

<div class="mp-card mp-form-card">
    <h2>Look up coordinates from an address</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="lookup_hospital_address">
        <label>Hospital address</label>
        <input type="text" name="hospital_address" value="<?php echo e($currentSettings['hospital_address'] ?? ''); ?>" placeholder="e.g. Government District Head Quarters Hospital, Kangayam, Tamil Nadu">
        <button type="submit" class="mp-btn mp-btn-secondary">Look up latitude / longitude</button>
    </form>
</div>

<div class="mp-card mp-form-card">
    <h2>All settings</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="save_settings">

        <?php foreach ($editableKeys as $key => $label): ?>
            <label><?php echo e($label); ?></label>
            <input type="text" name="<?php echo e($key); ?>" value="<?php echo e($currentSettings[$key] ?? ''); ?>">
        <?php endforeach; ?>

        <button type="submit" class="mp-btn mp-btn-primary">Save settings</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

