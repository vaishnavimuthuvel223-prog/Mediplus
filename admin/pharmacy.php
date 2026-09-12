<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/consultation_functions.php';

require_role('admin');

$pdo = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please try again.');
        redirect('/medi/admin/pharmacy.php');
    }
    $prescription_id = (int) ($_POST['prescription_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    $result = update_prescription_status($pdo, $prescription_id, $newStatus);
    if ($result['success']) {
        set_flash('success', 'Prescription status updated.');
    } else {
        set_flash('error', $result['message']);
    }
    redirect('/medi/admin/pharmacy.php');
}

$queue = list_pharmacy_queue($pdo);

$pageTitle = 'Pharmacy'; require_once __DIR__ . '/../includes/header.php';
?>
<div class="mp-form-eyebrow">Admin Â· Pharmacy</div>
<h1>Pharmacy Queue</h1>
<p class="mp-form-intro">Prescriptions from completed consultations. Move each one forward through the flow: Sent to Pharmacy â†’ Processing â†’ Ready â†’ Dispensed.</p>

<div class="mp-card">
    <table class="mp-table">
        <thead><tr><th>Token</th><th>Patient</th><th>Status</th><th>Prescribed</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($queue as $q): $next = pharmacy_next_status($q['status']); ?>
            <tr>
                <td><span class="mp-token-inline"><?php echo e($q['token']); ?></span></td>
                <td><?php echo e($q['patient_name']); ?></td>
                <td><span class="mp-badge mp-badge-<?php echo e($q['status']); ?>"><?php echo e(str_replace('_',' ',$q['status'])); ?></span></td>
                <td><?php echo e($q['created_at']); ?></td>
                <td>
                    <?php if ($next): ?>
                    <form method="post" style="display:inline-block;">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="prescription_id" value="<?php echo (int)$q['prescription_id']; ?>">
                        <input type="hidden" name="new_status" value="<?php echo e($next); ?>">
                        <button type="submit" class="mp-btn mp-btn-small mp-btn-primary">Mark <?php echo e(str_replace('_',' ',$next)); ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($queue)): ?>
            <tr><td colspan="5">No prescriptions pending right now.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

