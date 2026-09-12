<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('doctor');
$pdo = get_db_connection();
$doctorStmt = $pdo->prepare('SELECT d.full_name, d.face_template FROM doctors d WHERE d.doctor_id = ?');
$doctorStmt->execute([$_SESSION['doctor_id']]);
$doctor = $doctorStmt->fetch();
$isEnrolled = !empty($doctor['face_template']);
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid session token.';
    } elseif (!is_string($_POST['face_descriptor'] ?? '') || strlen($_POST['face_descriptor']) < 100) {
        $errors[] = 'No usable face template was captured. Center your face and try again.';
    } else {
        $descriptor = json_decode($_POST['face_descriptor'], true);
        if (!is_array($descriptor) || count($descriptor) !== 128 || count(array_filter($descriptor, 'is_numeric')) !== 128) {
            $errors[] = 'The face template is invalid. Please try again.';
        } elseif (!$isEnrolled) {
            $updated = $pdo->prepare('UPDATE doctors SET face_template = ?, face_enrolled_at = NOW() WHERE doctor_id = ? AND (face_template IS NULL OR face_template = \'\')')
                ->execute([json_encode(array_values($descriptor)), $_SESSION['doctor_id']]);
            if (!$updated) {
                $errors[] = 'This doctor ID already has a saved face profile. Please use the existing face login instead.';
            } else {
                $_SESSION['doctor_face_verified_at'] = time();
                redirect('/medi/doctor/dashboard.php');
            }
        } else {
            $saved = json_decode($doctor['face_template'], true);
            if (!is_array($saved) || count($saved) !== 128 || count(array_filter($saved, 'is_numeric')) !== 128) {
                $errors[] = 'The enrolled face profile is invalid. Contact an administrator.';
                $saved = null;
            }
            if ($saved) {
                $distance = 0.0;
                foreach ($descriptor as $index => $value) {
                    $distance += ((float)$value - (float)$saved[$index]) ** 2;
                }
                $distance = sqrt($distance);
            }
            if (isset($distance) && $distance > 0.52) {
                $errors[] = 'Face does not match the enrolled doctor profile.';
            } elseif (isset($distance)) {
                $_SESSION['doctor_face_verified_at'] = time();
                redirect('/medi/doctor/dashboard.php');
            }
        }
    }
}
$pageTitle = 'Doctor Face Verification'; require_once __DIR__ . '/../includes/header.php';
?>
<div class="mp-card mp-form-card mp-face-card"><div class="mp-form-eyebrow"><?php echo $isEnrolled ? 'Identity check' : 'One-time enrollment'; ?></div><h1><?php echo $isEnrolled ? 'Verify your face' : 'Enroll your face'; ?></h1><p class="mp-form-intro"><?php echo $isEnrolled ? 'Your enrolled face must match the doctor ID used to sign in.' : 'This creates one face profile for Dr. ' . e($doctor['full_name']) . '. Enrollment is available once only.'; ?></p>
<?php foreach ($errors as $error): ?><div class="mp-flash mp-flash-error"><?php echo e($error); ?></div><?php endforeach; ?>
<div class="mp-camera-frame"><video id="faceVideo" autoplay muted playsinline></video><div id="faceGuide" class="mp-face-guide">Camera is off</div></div><p id="faceMessage" class="mp-location-status">Center one face in the frame, then start capture.</p>
<form method="post" id="faceForm"><input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="face_descriptor" id="faceDescriptor" value=""><button type="button" id="startCamera" class="mp-btn mp-btn-secondary">Start camera</button><button type="submit" id="verifyFace" class="mp-btn mp-btn-primary" disabled><?php echo $isEnrolled ? 'Verify and enter' : 'Save face profile'; ?></button></form><p class="mp-security-note">One doctor ID can have one enrolled face profile. Contact an administrator to reset it.</p></div>
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js" onerror="document.getElementById('faceMessage').textContent = 'Face verification library could not load. Check the internet connection and refresh.'"></script>
<script>
const video = document.getElementById('faceVideo'); const message = document.getElementById('faceMessage'); const guide = document.getElementById('faceGuide'); const start = document.getElementById('startCamera'); const verify = document.getElementById('verifyFace'); const descriptorField = document.getElementById('faceDescriptor'); const modelPath = 'https://justadudewhohacks.github.io/face-api.js/models';
const wait = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
start.addEventListener('click', async () => { try { if (!window.faceapi) throw new Error('Face library unavailable'); if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) throw new Error('Camera unavailable'); start.disabled = true; message.textContent = 'Loading face model...'; await faceapi.nets.tinyFaceDetector.loadFromUri(modelPath); await faceapi.nets.faceLandmark68Net.loadFromUri(modelPath); await faceapi.nets.faceRecognitionNet.loadFromUri(modelPath); message.textContent = 'Starting camera...'; const stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'user', width: {ideal: 640}, height: {ideal: 480}}, audio: false}); video.srcObject = stream; await new Promise(resolve => video.onloadedmetadata = resolve); await video.play(); await wait(900); const options = new faceapi.TinyFaceDetectorOptions({inputSize: 416, scoreThreshold: 0.25}); let result = null; for (let attempt = 1; attempt <= 12 && !result; attempt++) { message.textContent = 'Looking for one face... ' + attempt + '/12'; result = await faceapi.detectSingleFace(video, options).withFaceLandmarks().withFaceDescriptor(); if (!result) await wait(250); } if (!result) throw new Error('No face detected'); descriptorField.value = JSON.stringify(Array.from(result.descriptor)); guide.textContent = 'Face detected'; guide.style.background = '#7c5cff'; verify.disabled = false; message.textContent = 'Face captured successfully. Save the profile now.'; start.textContent = 'Face captured'; } catch (error) { start.disabled = false; message.textContent = error.message === 'No face detected' ? 'No face detected after several scans. Move closer, improve lighting, and try again.' : error.message === 'Camera unavailable' ? 'Use localhost or HTTPS and allow camera access.' : error.message === 'Face library unavailable' ? 'Face verification library did not load. Check the connection and refresh.' : 'Face model or camera could not start. Check the connection and refresh.'; } });
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
