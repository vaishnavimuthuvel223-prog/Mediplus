<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/priority_engine.php';

$pdo = get_db_connection();
$queue = recalculate_queue($pdo);

$nowServing = null;
foreach ($queue as $row) {
    if ($row['status'] === 'in_consultation') { $nowServing = $row; break; }
}
$waitingCount = 0;
foreach ($queue as $row) {
    if ($row['status'] !== 'in_consultation') $waitingCount++;
}

$pageTitle = 'Live Queue';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="mp-queue-head">
    <h1>Live Hospital Queue</h1>
    <span class="mp-queue-live-pill"><span class="mp-status-dot"></span> Live | refreshes every 5s</span>
</div>

<div class="mp-now-serving" id="nowServingBox">
    <div>
        <div class="mp-now-serving-label">Now serving</div>
        <div class="mp-now-serving-token" id="nowServingToken"><?php echo $nowServing ? e($nowServing['token']) : '-'; ?></div>
        <div class="mp-now-serving-meta" id="nowServingMeta">
            <?php echo $nowServing
                ? e($nowServing['patient_name']) . ' Â· ' . e($nowServing['specialization_name']) . ($nowServing['doctor_name'] ? ' Â· Dr. ' . e($nowServing['doctor_name']) : '')
                : 'No patient in consultation right now'; ?>
        </div>
    </div>
    <div class="mp-now-serving-side">
        <div class="mp-now-serving-count" id="waitingCount"><?php echo (int)$waitingCount; ?></div>
        <div class="mp-now-serving-count-label">waiting in line</div>
    </div>
</div>

<div class="mp-ticket-strip" id="ticketStrip">
    <?php foreach ($queue as $row): ?>
        <?php
            $cls = 'mp-ticket';
            if ($row['status'] === 'in_consultation') $cls .= ' is-serving';
            elseif ($row['has_emergency']) $cls .= ' is-emergency';
            $statusLabel = $row['status'] === 'in_consultation' ? 'In room' : ($row['has_emergency'] ? 'Emergency' : ucfirst(str_replace('_',' ',$row['status'])));
        ?>
        <div class="<?php echo $cls; ?>">
            <span class="mp-ticket-pos">#<?php echo (int)$row['queue_position']; ?></span>
            <span class="mp-ticket-token"><?php echo e($row['token']); ?></span>
            <span class="mp-ticket-name"><?php echo e($row['patient_name']); ?></span>
            <span class="mp-ticket-sub"><?php echo e($row['specialization_name']); ?> Â· <?php echo (int)$row['waiting_minutes']; ?>m wait</span>
            <span class="mp-ticket-status"><?php echo e($statusLabel); ?></span>
        </div>
    <?php endforeach; ?>
    <?php if (empty($queue)): ?>
        <div class="mp-ticket"><span class="mp-ticket-sub">No patients currently in the queue.</span></div>
    <?php endif; ?>
</div>

<button type="button" class="mp-queue-toggle" id="queueDetailsToggle" aria-expanded="false">Show full queue table</button>

<div class="mp-card mp-queue-details" id="queueDetails">
<table class="mp-table" id="queueTable">
    <thead>
        <tr>
            <th>Pos</th><th>Token</th><th>Patient</th><th>Booking</th><th>Emergency</th><th>Priority</th>
            <th>Appt Time</th><th>Waiting (min)</th><th>Specialization</th><th>Doctor</th><th>Status</th>
        </tr>
    </thead>
    <tbody id="queueTableBody">
        <?php foreach ($queue as $row): ?>
        <tr class="<?php echo $row['has_emergency'] ? 'mp-row-emergency' : ''; ?>">
            <td><?php echo (int)$row['queue_position']; ?></td>
            <td><?php echo e($row['token']); ?></td>
            <td><?php echo e($row['patient_name']); ?></td>
            <td><?php echo $row['booking_type'] === 'walk_in' ? 'Walk-in' : 'Online'; ?></td>
            <td><?php echo $row['has_emergency'] ? 'Emergency' : e(ucfirst($row['booking_emergency_level'])); ?></td>
            <td><?php echo e($row['priority_score']); ?></td>
            <td><?php echo e($row['appointment_time']); ?></td>
            <td><?php echo (int)$row['waiting_minutes']; ?></td>
            <td><?php echo e($row['specialization_name']); ?></td>
            <td><?php echo $row['doctor_name'] ? 'Dr. ' . e($row['doctor_name']) : 'Waiting for Specialist'; ?></td>
            <td><?php echo e(str_replace('_',' ',$row['status'])); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($queue)): ?>
        <tr><td colspan="11">No patients currently in the queue.</td></tr>
        <?php endif; ?>
    </tbody>
</table>
</div>

<script src="/medi/assets/js/queue.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

