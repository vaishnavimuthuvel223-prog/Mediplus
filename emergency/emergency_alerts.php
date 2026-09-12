<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/emergency_functions.php';

require_login();
if (!in_array(current_role(), ['doctor','nurse','admin','management'], true)) {
    http_response_code(403);
    die('Access denied.');
}

$pdo = get_db_connection();
$emergencies = get_active_emergencies($pdo);

$pageTitle = 'Emergency Alerts';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>ðŸš¨ Active Emergency Alerts</h1>
<p>Authorized staff only. This list refreshes automatically.</p>

<div id="emergencyAlertsContainer" class="mp-grid">
    <?php if (empty($emergencies)): ?>
        <p id="noEmergenciesMsg">No active emergencies right now.</p>
    <?php endif; ?>
    <?php foreach ($emergencies as $em): ?>
        <div class="mp-card mp-emergency-card">
            <h3><?php echo e($em['patient_name']); ?></h3>
            <p><strong>Status:</strong> <span class="mp-badge mp-badge-danger"><?php echo e(str_replace('_',' ', $em['status'])); ?></span></p>
            <p><strong>Token:</strong> <?php echo $em['token'] ? e($em['token']) : 'â€”'; ?></p>
            <p><strong>Specialization:</strong> <?php echo e($em['specialization_name'] ?? 'â€”'); ?></p>
            <p><strong>Phone:</strong> <?php echo e($em['phone']); ?></p>
            <p><strong>Triggered At:</strong> <?php echo e($em['triggered_at']); ?></p>
            <?php if ($em['location']): ?>
                <p><strong>Last Known Location:</strong>
                    <?php echo e($em['location']['latitude']); ?>, <?php echo e($em['location']['longitude']); ?>
                    (<?php echo e($em['location']['captured_at']); ?>)
                </p>
                <a target="_blank" href="https://www.google.com/maps?q=<?php echo e($em['location']['latitude']); ?>,<?php echo e($em['location']['longitude']); ?>">View on map</a>
            <?php else: ?>
                <p><em>Location not yet shared by patient.</em></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<script>
async function refreshEmergencies() {
    try {
        const res = await fetch('/medi/emergency/emergency_alerts_data.php');
        const data = await res.json();
        const container = document.getElementById('emergencyAlertsContainer');
        if (!data.emergencies || data.emergencies.length === 0) {
            container.innerHTML = '<p>No active emergencies right now.</p>';
            return;
        }
        container.innerHTML = data.emergencies.map(em => `
            <div class="mp-card mp-emergency-card">
                <h3>${em.patient_name}</h3>
                <p><strong>Status:</strong> <span class="mp-badge mp-badge-danger">${em.status.replace('_',' ')}</span></p>
                <p><strong>Token:</strong> ${em.token ?? 'â€”'}</p>
                <p><strong>Specialization:</strong> ${em.specialization_name ?? 'â€”'}</p>
                <p><strong>Phone:</strong> ${em.phone}</p>
                <p><strong>Triggered At:</strong> ${em.triggered_at}</p>
                ${em.location ? `<p><strong>Last Known Location:</strong> ${em.location.latitude}, ${em.location.longitude} (${em.location.captured_at})</p>
                <a target="_blank" href="https://www.google.com/maps?q=${em.location.latitude},${em.location.longitude}">View on map</a>` : '<p><em>Location not yet shared by patient.</em></p>'}
            </div>
        `).join('');
    } catch (e) {
        console.error('Failed to refresh emergencies', e);
    }
}
setInterval(refreshEmergencies, 5000);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

