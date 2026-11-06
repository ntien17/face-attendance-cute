<?php
/**
 * index.php
 * Cổng điểm danh học đường bằng nhận diện khuôn mặt.
 * Lưu ý cấu hình:
 * - Đổi $python_exe theo máy của bạn nếu dùng môi trường ảo:
 *   Windows ví dụ: __DIR__ . "\\.venv\\Scripts\\python.exe"
 *   macOS/Linux ví dụ: __DIR__ . "/.venv/bin/python"
 * - Cài Python package trong requirements.txt trước khi chạy.
 */
date_default_timezone_set('Asia/Ho_Chi_Minh');
header_remove('X-Powered-By');

$python_exe = __DIR__ . "\\.venv\\Scripts\\python.exe";// TODO: sửa thành đường dẫn tuyệt đối nếu cần, ví dụ: __DIR__ . "\\.venv\\Scripts\\python.exe"
$py_script = __DIR__ . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "ai_engine.py";

$db_dir = __DIR__ . DIRECTORY_SEPARATOR . "database";
$students_file = $db_dir . DIRECTORY_SEPARATOR . "students.json";
$attendance_file = $db_dir . DIRECTORY_SEPARATOR . "attendance.json";
$uploads_dir = __DIR__ . DIRECTORY_SEPARATOR . "uploads";

ensureAppStorage([$db_dir, $uploads_dir], [$students_file, $attendance_file]);

function ensureAppStorage(array $dirs, array $files): void
{
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }
    foreach ($files as $file) {
        if (!file_exists($file)) {
            file_put_contents($file, "[]", LOCK_EX);
        }
    }
}

function readJsonArray(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }
    $content = file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return [];
    }
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function writeJsonArray(string $path, array $data): bool
{
    $json = json_encode(array_values($data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function normalizeImageDataUrl(string $dataUrl): ?string
{
    if (!preg_match('/^data:image\/(png|jpg|jpeg);base64,/', $dataUrl)) {
        return null;
    }
    $base64 = preg_replace('/^data:image\/(png|jpg|jpeg);base64,/', '', $dataUrl);
    $base64 = str_replace(' ', '+', $base64);
    $binary = base64_decode($base64, true);
    return $binary === false ? null : $binary;
}

function findStudentById(array $students, string $studentId): ?array
{
    foreach ($students as $student) {
        if (($student['ma_hoc_sinh'] ?? '') === $studentId) {
            return $student;
        }
    }
    return null;
}

function markAttendance(string $attendanceFile, array $student): array
{
    $records = readJsonArray($attendanceFile);
    $today = date('Y-m-d');
    $now = date('H:i:s');

    $studentId = $student['ma_hoc_sinh'] ?? '';
    $matchedIndex = null;

    for ($i = count($records) - 1; $i >= 0; $i--) {
        $sameStudent = ($records[$i]['ma_hoc_sinh'] ?? '') === $studentId;
        $sameDate = ($records[$i]['ngay_di_hoc'] ?? '') === $today;
        $hasCheckIn = !empty($records[$i]['gio_den_lop']);
        $hasNoCheckOut = empty($records[$i]['gio_ra_ve']);

        if ($sameStudent && $sameDate && $hasCheckIn && $hasNoCheckOut) {
            $matchedIndex = $i;
            break;
        }
    }

    if ($matchedIndex !== null) {
        $records[$matchedIndex]['gio_ra_ve'] = $now;
        $records[$matchedIndex]['trang_thai'] = 'CHECK-OUT';
        $records[$matchedIndex]['cap_nhat_luc'] = date('Y-m-d H:i:s');
        writeJsonArray($attendanceFile, $records);

        return [
            'type' => 'CHECK-OUT',
            'message' => 'CHECK-OUT (RA VỀ) 🌿',
            'record' => $records[$matchedIndex]
        ];
    }

    $newRecord = [
        'ma_hoc_sinh' => $student['ma_hoc_sinh'] ?? '',
        'ho_ten' => $student['ho_ten'] ?? '',
        'to_chuc_vu' => $student['to_chuc_vu'] ?? '',
        'ngay_di_hoc' => $today,
        'gio_den_lop' => $now,
        'gio_ra_ve' => '',
        'trang_thai' => 'CHECK-IN',
        'cap_nhat_luc' => date('Y-m-d H:i:s')
    ];

    $records[] = $newRecord;
    writeJsonArray($attendanceFile, $records);

    return [
        'type' => 'CHECK-IN',
        'message' => 'CHECK-IN (VÀO LỚP) 🌸',
        'record' => $newRecord
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'scan') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    if (!is_array($payload) || empty($payload['image'])) {
        jsonResponse(['ok' => false, 'message' => 'Không nhận được ảnh từ webcam 😿'], 400);
    }

    $binary = normalizeImageDataUrl($payload['image']);
    if ($binary === null) {
        jsonResponse(['ok' => false, 'message' => 'Dữ liệu ảnh không hợp lệ. Hãy thử lại nhé 🐾'], 400);
    }

    $fileName = 'scan_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
    $uploadPath = $uploads_dir . DIRECTORY_SEPARATOR . $fileName;

    if (file_put_contents($uploadPath, $binary, LOCK_EX) === false) {
        jsonResponse(['ok' => false, 'message' => 'Không thể lưu ảnh tạm vào uploads/ 😿'], 500);
    }

    $command = escapeshellarg($python_exe) . ' ' . escapeshellarg($py_script) . ' ' . escapeshellarg($uploadPath);
    $output = [];
    $returnCode = 0;
    exec($command . ' 2>&1', $output, $returnCode);

    $joinedOutput = trim(implode("\n", $output));
    $aiResult = json_decode($joinedOutput, true);

    if ($returnCode !== 0 || !is_array($aiResult)) {
        jsonResponse([
            'ok' => false,
            'message' => 'AI chưa phản hồi đúng định dạng. Kiểm tra đường dẫn Python và thư viện face_recognition nhé 😿',
            'debug' => $joinedOutput
        ], 500);
    }

    if (($aiResult['status'] ?? '') !== 'success') {
        $friendly = [
            'unknown' => 'AI không tìm thấy khuôn mặt rõ ràng. Hãy nhìn thẳng camera và đủ sáng nhé 😺',
            'failed' => 'Khuôn mặt chưa khớp với dữ liệu lớp. Cô/ban cán sự cần thêm ảnh mẫu trước 🐱',
            'empty_dataset' => 'Chưa có dữ liệu khuôn mặt trong dataset/. Hãy thêm học sinh ở trang quản trị 🌸',
            'no_students' => 'Chưa có danh sách học sinh trong database/students.json 🌿'
        ];
        $status = $aiResult['status'] ?? 'failed';
        jsonResponse([
            'ok' => false,
            'status' => $status,
            'message' => $friendly[$status] ?? 'AI chưa nhận diện được bạn. Hãy thử lại nhé 🐾',
            'ai' => $aiResult
        ]);
    }

    $students = readJsonArray($students_file);
    $student = findStudentById($students, $aiResult['student_id'] ?? '');

    if ($student === null) {
        jsonResponse([
            'ok' => false,
            'message' => 'AI nhận diện được mã học sinh nhưng không thấy trong students.json 😿',
            'ai' => $aiResult
        ], 404);
    }

    $attendance = markAttendance($attendance_file, $student);

    jsonResponse([
        'ok' => true,
        'message' => 'Điểm danh thành công ' . (($attendance['type'] ?? '') === 'CHECK-IN' ? '😸🌸' : '😸🌿'),
        'attendance_message' => $attendance['message'],
        'student' => $student,
        'record' => $attendance['record'],
        'ai' => $aiResult
    ]);
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🐱Điểm Danh lớp CNTTK23</title>
    <style>
        :root {
            --cream: #FFFDF9;
            --pink: #FFB7B2;
            --mint: #B5EAD7;
            --sun: #FFDAC1;
            --text: #4A4A4A;
            --soft-white: rgba(255, 255, 255, 0.82);
            --shadow-cloud: 0 8px 24px rgba(0, 0, 0, 0.05);
            --shadow-hover: 0 14px 34px rgba(255, 183, 178, 0.28);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Roboto", Arial, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 10% 10%, rgba(255, 183, 178, 0.42), transparent 28%),
                radial-gradient(circle at 90% 15%, rgba(181, 234, 215, 0.52), transparent 24%),
                radial-gradient(circle at 50% 95%, rgba(255, 218, 193, 0.70), transparent 34%),
                var(--cream);
            overflow-x: hidden;
        }

        .floating {
            position: fixed;
            pointer-events: none;
            opacity: 0.85;
            animation: floaty 7s ease-in-out infinite;
            font-size: 34px;
            z-index: 0;
        }

        .float-1 {
            top: 7%;
            left: 7%;
            animation-delay: 0s;
        }

        .float-2 {
            top: 16%;
            right: 8%;
            animation-delay: 1.2s;
        }

        .float-3 {
            bottom: 12%;
            left: 10%;
            animation-delay: 2.4s;
        }

        .float-4 {
            bottom: 20%;
            right: 12%;
            animation-delay: 3.3s;
        }

        @keyframes floaty {

            0%,
            100% {
                transform: translateY(0) rotate(0deg);
            }

            50% {
                transform: translateY(-18px) rotate(6deg);
            }
        }

        .page {
            position: relative;
            z-index: 1;
            width: min(1100px, 94%);
            margin: 0 auto;
            padding: 32px 0 56px;
        }

        .hero {
            text-align: center;
            padding: 20px 16px 26px;
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(30px, 5vw, 54px);
            color: #2f5f54;
            line-height: 1.1;
            text-shadow: 0 3px 0 rgba(255, 255, 255, 0.85);
        }

        .hero p {
            margin: 14px auto 0;
            max-width: 760px;
            font-size: 18px;
            line-height: 1.6;
        }

        .grid {
            display: grid;
            grid-template-columns: 1.08fr 0.92fr;
            gap: 24px;
            align-items: stretch;
        }

        .panel {
            background: var(--soft-white);
            border: 2px solid rgba(255, 255, 255, 0.92);
            border-radius: 28px;
            box-shadow: var(--shadow-cloud);
            backdrop-filter: blur(8px);
            padding: 22px;
        }

        .camera-card {
            position: relative;
            border: 4px solid var(--pink);
            border-radius: 28px;
            padding: 14px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.92), rgba(255, 218, 193, 0.36));
            box-shadow: var(--shadow-cloud);
        }

        .camera-card::before,
        .camera-card::after {
            content: "";
            position: absolute;
            top: -30px;
            width: 58px;
            height: 58px;
            background: var(--pink);
            border: 4px solid rgba(255, 255, 255, 0.9);
            transform: rotate(45deg);
            z-index: -1;
            border-radius: 14px;
        }

        .camera-card::before {
            left: 42px;
        }

        .camera-card::after {
            right: 42px;
        }

        video {
            display: block;
            width: 100%;
            aspect-ratio: 4 / 3;
            object-fit: cover;
            border-radius: 20px;
            background: #f9f1ea;
            transform: scaleX(-1);
        }

        .camera-caption {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
            font-weight: 800;
            color: #9a5b58;
        }

        .scan-button,
        .toggle-camera-button,
        .download-button,
        .admin-button {
            border: none;
            outline: none;
            cursor: pointer;
            border-radius: 22px;
            padding: 16px 20px;
            font-weight: 900;
            letter-spacing: 0.4px;
            box-shadow: var(--shadow-cloud);
            transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }

        .scan-button {
            width: 100%;
            margin-top: 20px;
            min-height: 64px;
            font-size: clamp(16px, 2.4vw, 22px);
            color: #5c3e3d;
            background: linear-gradient(135deg, var(--pink), var(--sun));
        }

        .toggle-camera-button {
            width: 100%;
            margin-top: 14px;
            color: #2f5f54;
            background: linear-gradient(135deg, var(--mint), #F3FFF9);
        }

        .toggle-camera-button.off {
            color: #7c3030;
            background: linear-gradient(135deg, #FFD0D0, #FFF1D6);
        }

        .download-button {
            color: #27564c;
            background: linear-gradient(135deg, var(--mint), #D8F8ED);
            margin-top: 14px;
            width: 100%;
        }

        .admin-button {
            color: #704b23;
            background: linear-gradient(135deg, #FFF1D6, #FFE1C8);
            margin-top: 14px;
            width: 100%;
        }

        .scan-button:hover,
        .toggle-camera-button:hover,
        .download-button:hover,
        .admin-button:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: var(--shadow-hover);
            filter: brightness(1.02);
        }

        .scan-button:disabled {
            cursor: wait;
            opacity: 0.72;
            transform: none;
        }

        .result-card {
            min-height: 360px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 16px;
        }

        .result-title {
            margin: 0;
            font-size: 28px;
            color: #2f5f54;
        }

        .status-box {
            border-radius: 24px;
            padding: 18px;
            background: rgba(255, 255, 255, 0.78);
            border: 2px dashed rgba(255, 183, 178, 0.75);
            line-height: 1.65;
        }

        .status-box.success {
            border-color: rgba(181, 234, 215, 0.95);
            background: rgba(237, 255, 248, 0.88);
        }

        .status-box.error {
            border-color: rgba(255, 183, 178, 0.95);
            background: rgba(255, 242, 241, 0.88);
        }

        .student-chip {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 8px 12px;
            margin-top: 8px;
            font-size: 16px;
        }

        .student-chip strong {
            color: #7d5755;
        }

        .hint-list {
            margin: 8px 0 0;
            padding-left: 20px;
            line-height: 1.7;
        }

        .footer-note {
            margin-top: 24px;
            text-align: center;
            color: #776b66;
            font-size: 14px;
        }

        canvas {
            display: none;
        }

        @media (max-width: 860px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .camera-card::before,
            .camera-card::after {
                width: 46px;
                height: 46px;
                top: -24px;
            }

            .student-chip {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <span class="floating float-1">🌸</span>
    <span class="floating float-2">🐱</span>
    <span class="floating float-3">🍀</span>
    <span class="floating float-4">🐾</span>

    <main class="page">
        <section class="hero">
            <h1>🐱 ĐIỂM DANH CNTTK23 🌻</h1>
            <p>Nhìn vào webcam, bấm nút nhận diện và để AI điểm danh thật nhanh nhé!</p>
        </section>

        <section class="grid">
            <div class="panel">
                <div class="camera-card">
                    <video id="webcam" autoplay playsinline muted></video>
                    <div class="camera-caption">🐾 Camera lớp mình đang sẵn sàng 🌿</div>
                </div>

                <button id="scanBtn" class="scan-button">🐾 BẤM ĐỂ AI NHẬN DIỆN ĐIỂM DANH 🐾</button>
                <button id="toggleCameraBtn" class="toggle-camera-button" type="button">📷 TẮT CAMERA</button>
                <a class="download-button" href="export_excel.php">📥 TẢI BẢNG ĐIỂM DANH (EXCEL)</a>
                <a class="admin-button" href="admin_login.php">🌸 VÀO TRANG QUẢN TRỊ LỚP</a>
                <canvas id="snapshot"></canvas>
            </div>

            <aside class="panel result-card">
                <h2 class="result-title">😸 Kết quả điểm danh</h2>
                <div id="resultBox" class="status-box">
                    <strong>🌿 Hướng dẫn nhỏ:</strong>
                    <ul class="hint-list">
                        <li>Đứng nơi đủ sáng, nhìn thẳng vào camera.</li>
                        <li>Mỗi lần đầu trong ngày là CHECK-IN.</li>
                        <li>Lần quét tiếp theo khi chưa có giờ ra về là CHECK-OUT.</li>
                    </ul>
                </div>
            </aside>
        </section>

        <p class="footer-note">🌸 Dữ liệu dùng cho lớp học demo. Cảm ơn các bạn đã cung cấp dữ liệu khuôn mặt.</p>
    </main>

    <script>
        const webcam = document.getElementById('webcam');
        const canvas = document.getElementById('snapshot');
        const scanBtn = document.getElementById('scanBtn');
        const toggleCameraBtn = document.getElementById('toggleCameraBtn');
        const resultBox = document.getElementById('resultBox');
        let cameraStream = null;
        let cameraEnabled = false;

        async function startCamera() {
            try {
                if (cameraStream) {
                    stopCamera(false);
                }

                cameraStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        width: { ideal: 960 },
                        height: { ideal: 720 },
                        facingMode: 'user'
                    },
                    audio: false
                });

                webcam.srcObject = cameraStream;
                cameraEnabled = true;
                scanBtn.disabled = false;
                toggleCameraBtn.classList.remove('off');
                toggleCameraBtn.textContent = '📷 TẮT CAMERA';
            } catch (error) {
                cameraEnabled = false;
                scanBtn.disabled = true;
                toggleCameraBtn.classList.add('off');
                toggleCameraBtn.textContent = '📷 BẬT CAMERA';
                resultBox.className = 'status-box error';
                resultBox.innerHTML = `<strong>😿 Không mở được webcam.</strong><br>Hãy kiểm tra quyền camera trong trình duyệt hoặc chạy website qua localhost.`;
            }
        }

        function stopCamera(showMessage = true) {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
            }

            cameraStream = null;
            cameraEnabled = false;
            webcam.srcObject = null;
            scanBtn.disabled = true;
            toggleCameraBtn.classList.add('off');
            toggleCameraBtn.textContent = '📷 BẬT CAMERA';

            if (showMessage) {
                resultBox.className = 'status-box';
                resultBox.innerHTML = `<strong>🌙 Camera đang tắt.</strong><br>Bấm “📷 BẬT CAMERA” để mở lại trước khi điểm danh nhé 🐾`;
            }
        }

        function captureImage() {
            const width = webcam.videoWidth || 960;
            const height = webcam.videoHeight || 720;
            canvas.width = width;
            canvas.height = height;

            const ctx = canvas.getContext('2d');
            ctx.save();
            ctx.translate(width, 0);
            ctx.scale(-1, 1);
            ctx.drawImage(webcam, 0, 0, width, height);
            ctx.restore();

            return canvas.toDataURL('image/jpeg', 0.92);
        }

        function renderSuccess(data) {
            const student = data.student || {};
            const record = data.record || {};
            resultBox.className = 'status-box success';
            resultBox.innerHTML = `
                <strong>🎉 ${data.message}</strong><br>
                <span>${data.attendance_message}</span>
                <div class="student-chip">
                    <strong>Mã học sinh</strong><span>${student.ma_hoc_sinh || '-'}</span>
                    <strong>Họ tên</strong><span>${student.ho_ten || '-'}</span>
                    <strong>Tổ/Chức vụ</strong><span>${student.to_chuc_vu || '-'}</span>
                    <strong>Ngày</strong><span>${record.ngay_di_hoc || '-'}</span>
                    <strong>Giờ đến</strong><span>${record.gio_den_lop || '-'}</span>
                    <strong>Giờ ra về</strong><span>${record.gio_ra_ve || 'Chưa ghi nhận'}</span>
                </div>
                <p>😸 Chúc bạn một ngày học thật chăm ngoan và nhiều năng lượng!</p>
            `;
        }

        function renderError(message, debug = '') {
            resultBox.className = 'status-box error';
            resultBox.innerHTML = `
                <strong>🐱 Chưa điểm danh được</strong><br>
                ${message}
                ${debug ? `<details style="margin-top:10px;"><summary>Debug kỹ thuật</summary><pre style="white-space:pre-wrap;">${debug}</pre></details>` : ''}
            `;
        }

        toggleCameraBtn.addEventListener('click', async () => {
            if (cameraEnabled) {
                stopCamera(true);
            } else {
                toggleCameraBtn.textContent = '⏳ ĐANG BẬT CAMERA...';
                await startCamera();
            }
        });

        scanBtn.addEventListener('click', async () => {
            if (!cameraEnabled || !webcam.srcObject) {
                renderError('Camera đang tắt hoặc chưa sẵn sàng. Hãy bấm “📷 BẬT CAMERA” trước nhé 🌸');
                return;
            }

            scanBtn.disabled = true;
            scanBtn.textContent = '😺 AI ĐANG NHẬN DIỆN...';

            try {
                const image = captureImage();
                const response = await fetch('index.php?action=scan', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ image })
                });

                const data = await response.json();
                if (!data.ok) {
                    renderError(data.message || 'Có lỗi xảy ra khi nhận diện.', data.debug || '');
                    return;
                }
                renderSuccess(data);
            } catch (error) {
                renderError('Không gửi được ảnh đến máy chủ. Hãy kiểm tra Apache/PHP đang chạy nhé 😿');
            } finally {
                scanBtn.disabled = false;
                scanBtn.textContent = '🐾 BẤM ĐỂ AI NHẬN DIỆN ĐIỂM DANH 🐾';
            }
        });

        window.addEventListener('beforeunload', () => stopCamera(false));
        startCamera();
    </script>
</body>

</html>