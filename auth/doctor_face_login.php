<?php
require_once __DIR__ . '/../includes/functions.php';
if (is_logged_in()) redirect(role_dashboard_url(current_role()));
$errors = [];
$enrolledCount = 0;
$pdo = get_db_connection();
$enrolledCount = (int)$pdo->query("SELECT COUNT(*) FROM doctors d JOIN users u ON u.user_id = d.user_id WHERE u.role = 'doctor' AND u.is_active = 1 AND d.face_template IS NOT NULL")->fetchColumn();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid session token.';
    } else {
        $descriptor = json_decode((string)($_POST['face_descriptor'] ?? ''), true);
        if (!is_array($descriptor) || count($descriptor) !== 128 || count(array_filter($descriptor, 'is_numeric')) !== 128) {
            $errors[] = 'No valid face was captured.';
        } else {
            $doctors = $pdo->query("SELECT d.doctor_id, d.user_id, d.full_name, d.face_template FROM doctors d JOIN users u ON u.user_id = d.user_id WHERE u.role = 'doctor' AND u.is_active = 1 AND d.face_template IS NOT NULL")->fetchAll();
            $match = null; $bestDistance = 0.52;
            foreach ($doctors as $doctor) {
                $saved = json_decode($doctor['face_template'], true);
                if (!is_array($saved) || count($saved) !== 128) continue;
                $distance = 0.0;
                foreach ($descriptor as $index => $value) $distance += ((float)$value - (float)$saved[$index]) ** 2;
                $distance = sqrt($distance);
                if ($distance <= $bestDistance) { $bestDistance = $distance; $match = $doctor; }
            }
            if (!$match) {
                $errors[] = $enrolledCount === 0
                    ? 'No doctor faces are enrolled yet. Complete one-time enrollment with each doctor ID first.'
                    : 'Face not recognized. Keep one face centered and try again.';
            } else {
                $userStmt = $pdo->prepare('SELECT username FROM users WHERE user_id = ?');
                $userStmt->execute([(int)$match['user_id']]); $user = $userStmt->fetch();
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$match['user_id']; $_SESSION['username'] = $user['username']; $_SESSION['role'] = 'doctor'; $_SESSION['doctor_id'] = (int)$match['doctor_id']; $_SESSION['doctor_face_verified_at'] = time();
                redirect('/medi/doctor/dashboard.php');
            }
        }
    }
}
$pageTitle = 'Doctor Face Login'; $bodyAccent = 'doctor'; require_once __DIR__ . '/../includes/header.php';
?>
<div class="mp-card mp-form-card mp-face-card"><div class="mp-form-eyebrow">Fast clinical access</div><h1>Doctor face sign-in</h1><p class="mp-form-intro">Show your enrolled face. MediPlus will match it to your own doctor account only.</p><div class="mp-doctor-roster"><strong>Face profiles: <?php echo $enrolledCount; ?> enrolled</strong><span>Each doctor ID owns one saved face profile.</span></div>
<?php foreach ($errors as $error): ?><div class="mp-flash mp-flash-error"><?php echo e($error); ?></div><?php endforeach; ?>
<div class="mp-camera-frame"><video id="faceVideo" autoplay muted playsinline></video><div id="faceGuide" class="mp-face-guide">Camera is off</div></div><p id="faceMessage" class="mp-location-status">Center one face in the frame, then start.</p>
<form method="post"><input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="face_descriptor" id="faceDescriptor"><button type="button" id="startCamera" class="mp-btn mp-btn-secondary">Start camera</button><button type="submit" id="verifyFace" class="mp-btn mp-btn-primary" disabled>Open my dashboard</button></form><p class="mp-security-note">Each doctor ID can be enrolled once. Re-enrollment is disabled after a profile is saved.</p></div>
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script>
const video = document.getElementById('faceVideo'); const message = document.getElementById('faceMessage'); const guide = document.getElementById('faceGuide'); const start = document.getElementById('startCamera'); const verify = document.getElementById('verifyFace'); const descriptorField = document.getElementById('faceDescriptor'); const modelPath = 'https://justadudewhohacks.github.io/face-api.js/models'; const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
start.addEventListener('click', async () => { try { if (!window.faceapi) throw new Error('library'); if (!navigator.mediaDevices?.getUserMedia) throw new Error('camera'); start.disabled = true; message.textContent = 'Loading face model...'; await faceapi.nets.tinyFaceDetector.loadFromUri(modelPath); await faceapi.nets.faceLandmark68Net.loadFromUri(modelPath); await faceapi.nets.faceRecognitionNet.loadFromUri(modelPath); const stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'user', width: {ideal: 640}, height: {ideal: 480}}, audio: false}); video.srcObject = stream; await new Promise(resolve => video.onloadedmetadata = resolve); await video.play(); await wait(900); const options = new faceapi.TinyFaceDetectorOptions({inputSize: 416, scoreThreshold: 0.25}); let result = null; for (let attempt = 1; attempt <= 12 && !result; attempt++) { message.textContent = 'Recognizing face... ' + attempt + '/12'; result = await faceapi.detectSingleFace(video, options).withFaceLandmarks().withFaceDescriptor(); if (!result) await wait(250); } if (!result) throw new Error('face'); descriptorField.value = JSON.stringify(Array.from(result.descriptor)); guide.textContent = 'Face detected'; guide.style.background = '#7c5cff'; verify.disabled = false; message.textContent = 'Face captured. Sign in now.'; start.textContent = 'Face captured'; } catch (error) { start.disabled = false; message.textContent = error.message === 'face' ? 'No face detected. Move closer and improve lighting.' : error.message === 'camera' ? 'Use localhost or HTTPS and allow camera access.' : 'Face model could not start. Check the connection and refresh.'; } });
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
