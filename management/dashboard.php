<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/priority_engine.php';

require_role('management');

$pdo = get_db_connection();
recalculate_queue($pdo);

$totalAppointments = (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()")->fetchColumn();
$completedAppointments = (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE() AND status = 'completed'")->fetchColumn();
$cancelledAppointments = (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE() AND status = 'cancelled'")->fetchColumn();
$waitingPatients = (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE() AND status IN ('waiting','arrived')")->fetchColumn();
$emergencyCases = (int) $pdo->query("SELECT COUNT(*) FROM emergency_requests WHERE status != 'handled'")->fetchColumn();
$availableDoctors = (int) $pdo->query("SELECT COUNT(*) FROM doctors WHERE status = 'available'")->fetchColumn();
$busyDoctors = (int) $pdo->query("SELECT COUNT(*) FROM doctors WHERE status = 'busy'")->fetchColumn();
$unavailableDoctors = (int) $pdo->query("SELECT COUNT(*) FROM doctors WHERE status = 'unavailable'")->fetchColumn();
$avgWait = $pdo->query("SELECT COALESCE(ROUND(AVG(q.queue_position * 15), 0), 0) AS avg_wait FROM queue_entries q JOIN appointments a ON a.appointment_id = q.appointment_id WHERE a.appointment_date = CURDATE() AND q.queue_status = 'waiting'")->fetch();
$avgWaitingTime = (int) ($avgWait['avg_wait'] ?? 0);

$departmentStats = $pdo->query("
    SELECT d.name AS department_name,
           COUNT(a.appointment_id) AS patient_count,
           SUM(CASE WHEN er.emergency_id IS NOT NULL THEN 1 ELSE 0 END) AS emergency_count
    FROM departments d
    LEFT JOIN specializations s ON s.department_id = d.department_id
    LEFT JOIN appointments a ON a.specialization_id = s.specialization_id AND a.appointment_date = CURDATE()
    LEFT JOIN emergency_requests er ON er.appointment_id = a.appointment_id AND er.status != 'handled'
    GROUP BY d.department_id, d.name
    ORDER BY patient_count DESC, emergency_count DESC
")->fetchAll();

$workloadStmt = $pdo->query("
    SELECT d.full_name, s.name AS specialization_name, COUNT(a.appointment_id) AS appointment_count,
           CASE d.status WHEN 'available' THEN 'Available' WHEN 'busy' THEN 'Busy' ELSE 'Unavailable' END AS status
    FROM doctors d
    LEFT JOIN appointments a ON a.doctor_id = d.doctor_id AND a.appointment_date = CURDATE() AND a.status != 'cancelled'
    JOIN specializations s ON s.specialization_id = d.specialization_id
    GROUP BY d.doctor_id, d.full_name, s.name, d.status
    ORDER BY appointment_count DESC
");
$doctorWorkload = $workloadStmt->fetchAll();

$specialistShortage = $pdo->query("
    SELECT s.name AS specialization_name, COUNT(d.doctor_id) AS available_doctors
    FROM specializations s
    LEFT JOIN doctors d ON d.specialization_id = s.specialization_id AND d.status = 'available'
    GROUP BY s.specialization_id, s.name
    HAVING available_doctors = 0
    ORDER BY s.name
")->fetchAll();

$queueSummary = $pdo->query("SELECT * FROM queue_entries qe JOIN appointments a ON a.appointment_id = qe.appointment_id WHERE a.appointment_date = CURDATE() AND qe.queue_status IN ('waiting','in_consultation') ORDER BY qe.queue_position ASC LIMIT 10")->fetchAll();
$mostCongested = $departmentStats[0]['department_name'] ?? 'N/A';
$longestQueue = $queueSummary ? (int) $queueSummary[count($queueSummary)-1]['queue_position'] : 0;
$queueCongestion = (int) $pdo->query("SELECT COUNT(*) FROM queue_entries qe JOIN appointments a ON a.appointment_id = qe.appointment_id WHERE a.appointment_date = CURDATE() AND qe.queue_status = 'waiting'")->fetchColumn();

$notifications = get_notifications($pdo, $_SESSION['user_id'], 5);

$pageTitle = 'Management Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Hospital Management Dashboard</h1>

<div class="mp-grid" id="overview">
    <div class="mp-card">
        <h2>Appointments</h2>
        <p>Total Today: <strong><?php echo (int)$totalAppointments; ?></strong></p>
        <p>Completed: <strong><?php echo (int)$completedAppointments; ?></strong></p>
        <p>Cancelled: <strong><?php echo (int)$cancelledAppointments; ?></strong></p>
        <p>Waiting: <strong><?php echo (int)$waitingPatients; ?></strong></p>
    </div>
    <div class="mp-card">
        <h2>Emergency</h2>
        <p>Active Cases: <strong><?php echo (int)$emergencyCases; ?></strong></p>
    </div>
    <div class="mp-card">
        <h2>Doctors</h2>
        <p>Available: <strong><?php echo (int)$availableDoctors; ?></strong></p>
        <p>Busy: <strong><?php echo (int)$busyDoctors; ?></strong></p>
        <p>Unavailable: <strong><?php echo (int)$unavailableDoctors; ?></strong></p>
    </div>
    <div class="mp-card">
        <h2>Queue Metrics</h2>
        <p>Average wait: <strong><?php echo (int)$avgWaitingTime; ?> min</strong></p>
        <p>Queue congestion: <strong><?php echo (int)$queueCongestion; ?></strong></p>
    </div>
</div>

<div class="mp-grid">
    <div class="mp-card">
        <h2>Operational Insights</h2>
        <ul class="mp-links">
            <li><strong>Most congested department:</strong> <?php echo e($mostCongested); ?></li>
            <li><strong>Longest waiting queue:</strong> <?php echo (int)$longestQueue; ?> patients</li>
            <li><strong>Specialist shortage:</strong> <?php echo empty($specialistShortage) ? 'No shortage detected' : implode(', ', array_map(function ($row) { return $row['specialization_name']; }, $specialistShortage)); ?></li>
            <li><strong>Doctor availability issues:</strong> <?php echo (int)($unavailableDoctors + $busyDoctors); ?></li>
            <li><strong>Emergency workload:</strong> <?php echo (int)$emergencyCases; ?> active cases</li>
        </ul>
    </div>

    <div class="mp-card">
        <h2>Department Overview</h2>
        <table class="mp-table">
            <thead><tr><th>Department</th><th>Patients</th><th>Emergency</th></tr></thead>
            <tbody>
                <?php foreach ($departmentStats as $stat): ?>
                    <tr>
                        <td><?php echo e($stat['department_name']); ?></td>
                        <td><?php echo (int)$stat['patient_count']; ?></td>
                        <td><?php echo (int)$stat['emergency_count']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="mp-card">
    <h2>Doctor Workload</h2>
    <table class="mp-table">
        <thead><tr><th>Doctor</th><th>Specialization</th><th>Status</th><th>Appointments</th></tr></thead>
        <tbody>
            <?php foreach ($doctorWorkload as $row): ?>
                <tr>
                    <td><?php echo e($row['full_name']); ?></td>
                    <td><?php echo e($row['specialization_name']); ?></td>
                    <td><?php echo e($row['status']); ?></td>
                    <td><?php echo (int)$row['appointment_count']; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="mp-card">
    <h2>Notifications</h2>
    <?php if (empty($notifications)): ?>
        <p>No notifications yet.</p>
    <?php else: ?>
        <ul class="mp-links">
            <?php foreach ($notifications as $note): ?>
                <li><?php echo e($note['message']); ?> <small><?php echo e($note['created_at']); ?></small></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
