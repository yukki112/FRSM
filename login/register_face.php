<?php
session_start();
require_once '../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Check if already registered
$stmt = $pdo->prepare("SELECT face_registered FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user && $user['face_registered']) {
    // Show remove option
    $already_registered = true;
} else {
    $already_registered = false;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register Face - One Face Per User</title>
    <style>
        .warning { color: #dc3545; font-weight: bold; }
        .success { color: #28a745; }
    </style>
</head>
<body>
    <h1>🔒 Register Your Face</h1>
    
    <?php if ($already_registered): ?>
        <div class="warning">
            <p>⚠️ You already have a face registered.</p>
            <p>Only ONE face can be registered per account.</p>
            <button onclick="removeFace()">Remove Current Face</button>
            <button onclick="retrainFace()">Retrain/Update Face</button>
        </div>
    <?php else: ?>
        <div class="info">
            <p>📸 You can register ONE face for your account.</p>
            <p>This face will be uniquely linked to your account.</p>
            <p>Other people's faces will NOT work for your account.</p>
        </div>
        
        <div id="cameraContainer">
            <video id="video" autoplay playsinline></video>
            <button id="captureBtn">Capture & Register</button>
            <div id="status"></div>
        </div>
        
        <script>
            const userId = <?php echo $user_id; ?>;
            
            async function captureAndRegister() {
                // Capture face
                const video = document.getElementById('video');
                const canvas = document.createElement('canvas');
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0);
                
                const imageData = canvas.toDataURL('image/jpeg', 0.8);
                
                // Send to API
                const response = await fetch('http://127.0.0.1:5001/api/face/register', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        user_id: userId,
                        image: imageData.split(',')[1]
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('status').innerHTML = 
                        `<div class="success">
                            ✅ Face registered successfully!<br>
                            This face is now uniquely linked to your account.
                        </div>`;
                } else {
                    document.getElementById('status').innerHTML = 
                        `<div class="warning">❌ ${data.error}</div>`;
                }
            }
            
            // Initialize camera
            navigator.mediaDevices.getUserMedia({ video: true })
                .then(stream => {
                    document.getElementById('video').srcObject = stream;
                });
                
            document.getElementById('captureBtn').onclick = captureAndRegister;
        </script>
    <?php endif; ?>
</body>
</html>