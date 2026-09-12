<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/consultation_functions.php';

require_role('admin');

$pdo = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please try again.');
        redirect('/medi/admin/lab.php');
    }
    $lab_test_id = (int) ($_POST['lab_test_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    $resultText = $_POST['result_text'] ?? null;
    $result = update_lab_status($pdo, $lab_test_id, $newStatus, $resultText);
    if ($result['success']) {
        set_flash('success', 'Lab test status updated.');
    } else {
        set_flash('error', $result['message']);
    }
    redirect('/medi/admin/lab.php');
}

$queue = list_lab_queue($pdo);

$pageTitle = 'Lab'; require_once __DIR__ . '/../includes/header.php';
?>
<div class="mp-form-eyebrow">Admin Â· Lab</div>
<h1>Lab Test Queue</h1>
<p class="mp-form-intro">Tests requested by doctors during consultation. Move each forward: Requested â†’ Processing â†’ Result Available. Enter the result text only once it's actually available â€” nothing here is auto-generated.</p>

<div class="mp-card">
    <table class="mp-table">
        <thead><tr><th>Token</th><th>Patient</th><th>Test</th><th>Status</th><th>Requested</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($queue as $q): $next = lab_next_status($q['status']); ?>
            <tr>
                <td><span class="mp-token-inline"><?php echo e($q['token']); ?></span></td>
                <td><?php echo e($q['patient_name']); ?></td>
                <td><?php echo e($q['test_name']); ?></td>
                <td><span class="mp-badge mp-badge-<?php echo e($q['status']); ?>"><?php echo e(str_replace('_',' ',$q['status'])); ?></span></td>
                <td><?php echo e($q['requested_at']); ?></td>
                <td>
                    <?php if ($next === 'result_available'): ?>
                    <form method="post" style="display:flex; gap:6px; align-items:center;">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="lab_test_id" value="<?php echo (int)$q['lab_test_id']; ?>">
                        <input type="hidden" name="new_status" value="result_available">
                        <input type="text" name="result_text" placeholder="Result summary" style="padding:6px 8px; border-radius:8px; border:1px solid var(--mp-border); font-size:.8rem;">
                        <button type="submit" class="mp-btn mp-btn-small mp-btn-primary">Mark Result Available</button>
                    </form>
                    <?php elseif ($next): ?>
                    <form method="post" style="display:inline-block;">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="lab_test_id" value="<?php echo (int)$q['lab_test_id']; ?>">
                        <input type="hidden" name="new_status" value="<?php echo e($next); ?>">
                        <button type="submit" class="mp-btn mp-btn-small mp-btn-primary">Mark <?php echo e(str_replace('_',' ',$next)); ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($queue)): ?>
            <tr><td colspan="6">No lab tests pending right now.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

