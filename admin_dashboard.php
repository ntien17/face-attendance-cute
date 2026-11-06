<?php
/**
 * admin_dashboard.php
 * Bảng quản lý học sinh và danh sách lớp.
 */
session_start();
date_default_timezone_set('Asia/Ho_Chi_Minh');
header_remove('X-Powered-By');

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin_login.php');
    exit;
}

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit;
}

$db_dir = __DIR__ . DIRECTORY_SEPARATOR . "database";
$students_file = $db_dir . DIRECTORY_SEPARATOR . "students.json";
$dataset_dir = __DIR__ . DIRECTORY_SEPARATOR . "dataset";

ensureAppStorage([$db_dir, $dataset_dir], [$students_file]);

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

function readCapturedImagesFromPost(): array
{
    $images = [];

    // Phiên bản mới: JavaScript gửi một mảng ảnh dạng JSON trong input captured_images.
    $capturedImagesJson = $_POST['captured_images'] ?? '';
    if (is_string($capturedImagesJson) && trim($capturedImagesJson) !== '') {
        $decoded = json_decode($capturedImagesJson, true);
        if (is_array($decoded)) {
            foreach ($decoded as $imageData) {
                if (is_string($imageData) && trim($imageData) !== '') {
                    $images[] = $imageData;
                }
            }
        }
    }

    // Tương thích ngược với bản cũ chỉ có 1 ảnh captured_image.
    if (!$images && !empty($_POST['captured_image']) && is_string($_POST['captured_image'])) {
        $images[] = $_POST['captured_image'];
    }

    return $images;
}

function cleanStudentId(string $value): string
{
    $value = strtoupper(trim($value));
    return preg_replace('/[^A-Z0-9_-]/', '', $value);
}


function getStudentImageIndex(string $fileName, string $studentId): ?int
{
    $pattern = '/^' . preg_quote($studentId, '/') . '(?:_(\d+))?\.jpg$/i';
    if (!preg_match($pattern, $fileName, $matches)) {
        return null;
    }
    return isset($matches[1]) ? (int) $matches[1] : 0;
}

function getStudentDatasetImages(string $datasetDir, string $studentId): array
{
    $images = [];
    if ($studentId === '' || !is_dir($datasetDir)) {
        return [];
    }

    foreach (scandir($datasetDir) ?: [] as $fileName) {
        if ($fileName === '.' || $fileName === '..') {
            continue;
        }

        $index = getStudentImageIndex($fileName, $studentId);
        if ($index === null) {
            continue;
        }

        $images[] = [
            'index' => $index,
            'file_name' => $fileName,
            'absolute' => $datasetDir . DIRECTORY_SEPARATOR . $fileName,
            'relative' => 'dataset/' . $fileName
        ];
    }

    usort($images, function ($a, $b) {
        return $a['index'] <=> $b['index'];
    });

    return $images;
}

function getNextStudentImageTarget(string $datasetDir, string $studentId): array
{
    $images = getStudentDatasetImages($datasetDir, $studentId);
    $usedIndexes = array_map(function ($item) {
        return (int) $item['index'];
    }, $images);

    $nextIndex = 0;
    while (in_array($nextIndex, $usedIndexes, true)) {
        $nextIndex++;
    }

    $fileName = $nextIndex === 0
        ? $studentId . '.jpg'
        : $studentId . '_' . $nextIndex . '.jpg';

    return [
        'index' => $nextIndex,
        'file_name' => $fileName,
        'absolute' => $datasetDir . DIRECTORY_SEPARATOR . $fileName,
        'relative' => 'dataset/' . $fileName
    ];
}

function getLatestStudentImageRelative(string $datasetDir, string $studentId): ?string
{
    $images = getStudentDatasetImages($datasetDir, $studentId);
    if (!$images) {
        return null;
    }
    $latest = end($images);
    return $latest['relative'] ?? null;
}

function deleteStudentDatasetImages(string $datasetDir, string $studentId): int
{
    $deleted = 0;
    foreach (getStudentDatasetImages($datasetDir, $studentId) as $image) {
        if (is_file($image['absolute']) && unlink($image['absolute'])) {
            $deleted++;
        }
    }
    return $deleted;
}


function normalizeSortText(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value));
    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }

    // Tạo key phụ không dấu để sắp xếp tiếng Việt ổn định hơn khi máy có iconv.
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false && trim($ascii) !== '') {
        $value = $ascii;
    }

    return preg_replace('/[^a-z0-9]+/i', ' ', $value);
}

function getLastNameKey(string $fullName): string
{
    $fullName = trim(preg_replace('/\s+/u', ' ', $fullName));
    if ($fullName === '') {
        return '';
    }

    $parts = preg_split('/\s+/u', $fullName);
    $lastName = $parts ? end($parts) : $fullName;

    // Sắp xếp theo tên cuối trước, nếu trùng tên thì xét cả họ tên đầy đủ.
    return normalizeSortText((string) $lastName) . ' ' . normalizeSortText($fullName);
}

function compareStudentRows(array $a, array $b, string $sortBy): int
{
    $idA = (string) ($a['ma_hoc_sinh'] ?? '');
    $idB = (string) ($b['ma_hoc_sinh'] ?? '');

    switch ($sortBy) {
        case 'name':
            $keyA = getLastNameKey((string) ($a['ho_ten'] ?? ''));
            $keyB = getLastNameKey((string) ($b['ho_ten'] ?? ''));
            break;

        case 'group':
            $keyA = normalizeSortText((string) ($a['to_chuc_vu'] ?? ''));
            $keyB = normalizeSortText((string) ($b['to_chuc_vu'] ?? ''));
            break;

        case 'id':
        default:
            $keyA = normalizeSortText($idA);
            $keyB = normalizeSortText($idB);
            break;
    }

    $result = strnatcasecmp($keyA, $keyB);

    // Nếu key chính trùng nhau thì sắp xếp phụ theo mã học sinh để danh sách ổn định.
    if ($result === 0) {
        $result = strnatcasecmp($idA, $idB);
    }

    return $result;
}

function buildSortUrl(string $sortBy, string $currentSort, string $currentDir): string
{
    $nextDir = ($currentSort === $sortBy && $currentDir === 'asc') ? 'desc' : 'asc';
    return 'admin_dashboard.php?sort=' . urlencode($sortBy) . '&dir=' . urlencode($nextDir);
}

function sortButtonClass(string $sortBy, string $currentSort): string
{
    return 'sort-btn' . ($sortBy === $currentSort ? ' active' : '');
}

function sortIndicator(string $sortBy, string $currentSort, string $currentDir): string
{
    if ($sortBy !== $currentSort) {
        return '↕';
    }
    return $currentDir === 'desc' ? '↓' : '↑';
}

$flash = [
    'type' => '',
    'message' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_student') {
        $studentId = cleanStudentId($_POST['ma_hoc_sinh'] ?? '');
        $fullName = trim($_POST['ho_ten'] ?? '');
        $role = trim($_POST['to_chuc_vu'] ?? '');
        $capturedImages = readCapturedImagesFromPost();

        if ($studentId === '' || $fullName === '' || $role === '') {
            $flash = ['type' => 'error', 'message' => 'Vui lòng nhập đủ Mã HS, Họ tên và Tổ/Chức vụ 😿'];
        } elseif (count($capturedImages) === 0) {
            $flash = ['type' => 'error', 'message' => 'Bạn cần chụp ít nhất 1 ảnh chân dung trước khi lưu nhé 🐾'];
        } else {
            $imageBinaries = [];
            $invalidImageIndex = null;

            foreach ($capturedImages as $index => $imageData) {
                $binary = normalizeImageDataUrl($imageData);
                if ($binary === null) {
                    $invalidImageIndex = $index + 1;
                    break;
                }
                $imageBinaries[] = $binary;
            }

            if ($invalidImageIndex !== null) {
                $flash = ['type' => 'error', 'message' => 'Ảnh số ' . $invalidImageIndex . ' không hợp lệ. Hãy chụp lại nhé 😿'];
            } else {
                $students = readJsonArray($students_file);
                $updated = false;
                $now = date('Y-m-d H:i:s');
                $savedTargets = [];
                $saveError = false;

                // Mỗi ảnh được lưu theo thứ tự còn trống: HS001.jpg, HS001_1.jpg, HS001_2.jpg...
                foreach ($imageBinaries as $binary) {
                    $imageTarget = getNextStudentImageTarget($dataset_dir, $studentId);
                    if (file_put_contents($imageTarget['absolute'], $binary, LOCK_EX) === false) {
                        $saveError = true;
                        break;
                    }
                    $savedTargets[] = $imageTarget;
                }

                if ($saveError || !$savedTargets) {
                    $flash = ['type' => 'error', 'message' => 'Không thể lưu ảnh vào dataset/. Kiểm tra quyền ghi thư mục dataset/ 😿'];
                } else {
                    $latestTarget = end($savedTargets);
                    $sampleCount = count(getStudentDatasetImages($dataset_dir, $studentId));

                    foreach ($students as &$student) {
                        if (($student['ma_hoc_sinh'] ?? '') === $studentId) {
                            $student['ho_ten'] = $fullName;
                            $student['to_chuc_vu'] = $role;
                            // Avatar hiển thị ảnh mới nhất; AI vẫn dùng toàn bộ ảnh cùng mã học sinh.
                            $student['anh_mau'] = $latestTarget['relative'];
                            $student['so_anh_mau'] = $sampleCount;
                            $student['cap_nhat_luc'] = $now;
                            $updated = true;
                            break;
                        }
                    }
                    unset($student);

                    if (!$updated) {
                        $students[] = [
                            'ma_hoc_sinh' => $studentId,
                            'ho_ten' => $fullName,
                            'to_chuc_vu' => $role,
                            'anh_mau' => $latestTarget['relative'],
                            'so_anh_mau' => $sampleCount,
                            'tao_luc' => $now,
                            'cap_nhat_luc' => $now
                        ];
                    }

                    $savedJson = writeJsonArray($students_file, $students);
                    if ($savedJson) {
                        $savedFileNames = array_map(function ($target) {
                            return $target['file_name'];
                        }, $savedTargets);

                        $flash = [
                            'type' => 'success',
                            'message' => ($updated ? 'Đã thêm ' : 'Đã kết nạp và lưu ')
                                . count($savedTargets) . ' ảnh mẫu cho ' . $studentId
                                . ': ' . implode(', ', $savedFileNames) . ' 😸🌸'
                        ];
                    } else {
                        $flash = ['type' => 'error', 'message' => 'Đã lưu ảnh nhưng không thể cập nhật students.json. Kiểm tra quyền ghi database/ 😿'];
                    }
                }
            }
        }
    }

    if ($action === 'delete_student') {
        $studentId = cleanStudentId($_POST['ma_hoc_sinh'] ?? '');
        $students = readJsonArray($students_file);
        $before = count($students);

        $students = array_values(array_filter($students, function ($student) use ($studentId) {
            return ($student['ma_hoc_sinh'] ?? '') !== $studentId;
        }));

        $deletedImages = deleteStudentDatasetImages($dataset_dir, $studentId);

        writeJsonArray($students_file, $students);

        $flash = [
            'type' => $before === count($students) ? 'error' : 'success',
            'message' => $before === count($students)
                ? 'Không tìm thấy học sinh cần xóa 😿'
                : 'Đã xóa thành viên khỏi lớp và xóa ' . $deletedImages . ' ảnh mẫu 🗑️🐾'
        ];
    }
}

$students = readJsonArray($students_file);

$allowedSorts = ['id', 'name', 'group'];
$sortBy = $_GET['sort'] ?? 'id';
$sortBy = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'id';

$sortDir = $_GET['dir'] ?? 'asc';
$sortDir = $sortDir === 'desc' ? 'desc' : 'asc';

usort($students, function ($a, $b) use ($sortBy, $sortDir) {
    $result = compareStudentRows($a, $b, $sortBy);
    return $sortDir === 'desc' ? -$result : $result;
});

$totalStudents = count($students);
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;700&family=Nunito+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🌸 Bảng quản lý lớp học</title>
    <style>
        :root {
            --cream: #FFFDF9;
            --pink: #FFB7B2;
            --mint: #B5EAD7;
            --sun: #FFDAC1;
            --text: #4A4A4A;
            --danger: #FF8F8F;
            --shadow-cloud: 0 8px 24px rgba(0, 0, 0, 0.05);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Nunito", Arial, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 8% 8%, rgba(255, 183, 178, 0.45), transparent 26%),
                radial-gradient(circle at 93% 18%, rgba(181, 234, 215, 0.60), transparent 25%),
                radial-gradient(circle at 50% 100%, rgba(255, 218, 193, 0.60), transparent 34%),
                var(--cream);
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding: 16px min(4vw, 42px);
            background: rgba(255, 253, 249, 0.86);
            border-bottom: 2px solid rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            box-shadow: var(--shadow-cloud);
        }

        .brand {
            font-size: clamp(20px, 3vw, 30px);
            font-weight: 900;
            color: #2f5f54;
        }

        .nav-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .nav-actions a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 10px 14px;
            border-radius: 16px;
            text-decoration: none;
            font-weight: 900;
            color: #6a4a46;
            background: rgba(255, 255, 255, 0.85);
            box-shadow: var(--shadow-cloud);
            transition: transform 0.2s ease;
        }

        .nav-actions a:hover {
            transform: translateY(-2px);
        }

        .container {
            width: min(1200px, 94%);
            margin: 0 auto;
            padding: 30px 0 56px;
        }

        .title {
            text-align: center;
            margin-bottom: 24px;
        }

        .title h1 {
            margin: 0;
            font-size: clamp(30px, 5vw, 52px);
            color: #2f5f54;
            text-shadow: 0 3px 0 rgba(255, 255, 255, 0.85);
        }

        .title p {
            margin: 10px auto 0;
            max-width: 800px;
            line-height: 1.6;
        }

        .layout {
            display: grid;
            grid-template-columns: 0.92fr 1.08fr;
            gap: 24px;
            align-items: start;
        }

        .panel {
            background: rgba(255, 255, 255, 0.83);
            border: 2px solid rgba(255, 255, 255, 0.95);
            border-radius: 28px;
            box-shadow: var(--shadow-cloud);
            padding: 22px;
            backdrop-filter: blur(8px);
        }

        .panel h2 {
            margin: 0 0 16px;
            color: #2f5f54;
        }

        .flash {
            margin-bottom: 18px;
            border-radius: 20px;
            padding: 14px 16px;
            font-weight: 900;
            border: 2px dashed;
        }

        .flash.success {
            background: rgba(181, 234, 215, 0.36);
            border-color: var(--mint);
            color: #2f5f54;
        }

        .flash.error {
            background: rgba(255, 183, 178, 0.34);
            border-color: var(--pink);
            color: #8a4240;
        }

        .form-group {
            margin-bottom: 14px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: 900;
            color: #7d5755;
        }

        input {
            width: 100%;
            border: 2px solid rgba(255, 183, 178, 0.42);
            border-radius: 18px;
            padding: 13px 15px;
            font-size: 16px;
            outline: none;
            background: rgba(255, 253, 249, 0.94);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        input:focus {
            border-color: var(--mint);
            box-shadow: 0 0 0 5px rgba(181, 234, 215, 0.35);
        }

        .camera-box {
            position: relative;
            padding: 12px;
            border-radius: 24px;
            border: 3px solid var(--pink);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.90), rgba(255, 218, 193, 0.35));
            margin-top: 8px;
        }

        video {
            width: 100%;
            aspect-ratio: 4 / 3;
            object-fit: cover;
            display: block;
            border-radius: 18px;
            background: #f8f0eb;
            transform: scaleX(-1);
        }

        canvas {
            display: none;
        }

        .preview {
            display: none;
            width: 118px;
            height: 118px;
            object-fit: cover;
            border-radius: 50%;
            margin: 14px auto 0;
            border: 5px solid var(--mint);
            box-shadow: var(--shadow-cloud);
        }

        .capture-summary {
            display: none;
            margin: 14px 0 0;
            padding: 12px 14px;
            border-radius: 18px;
            border: 2px dashed rgba(181, 234, 215, 0.95);
            background: rgba(237, 255, 248, 0.76);
            color: #2f5f54;
            font-weight: 900;
            line-height: 1.5;
        }

        .preview-grid {
            display: none;
            grid-template-columns: repeat(auto-fill, minmax(82px, 1fr));
            gap: 10px;
            margin-top: 12px;
        }

        .sample-thumb {
            position: relative;
            overflow: hidden;
            border-radius: 18px;
            border: 3px solid rgba(181, 234, 215, 0.95);
            background: rgba(255, 253, 249, 0.92);
            box-shadow: var(--shadow-cloud);
        }

        .sample-thumb img {
            width: 100%;
            aspect-ratio: 1 / 1;
            object-fit: cover;
            display: block;
        }

        .sample-thumb span {
            display: block;
            padding: 5px;
            font-size: 12px;
            text-align: center;
            font-weight: 900;
            color: #6a4a46;
            background: rgba(255, 218, 193, 0.72);
        }

        .remove-shot-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            padding: 0;
            background: rgba(255, 143, 143, 0.92);
            color: #fff;
            font-size: 13px;
            line-height: 1;
            box-shadow: none;
        }

        .button-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 14px;
        }

        button,
        .small-link {
            border: none;
            border-radius: 18px;
            padding: 13px 15px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 900;
            box-shadow: var(--shadow-cloud);
            transition: transform 0.2s ease, filter 0.2s ease;
        }

        button:hover,
        .small-link:hover {
            transform: translateY(-2px);
            filter: brightness(1.02);
        }

        .capture-btn {
            background: linear-gradient(135deg, var(--sun), #FFF1DC);
            color: #704b23;
        }

        .save-btn {
            background: linear-gradient(135deg, var(--mint), #D9FFF0);
            color: #27564c;
        }

        .toggle-camera-btn {
            background: linear-gradient(135deg, #E5F7FF, #D9FFF0);
            color: #27564c;
        }

        .clear-btn {
            background: linear-gradient(135deg, #FFE1E1, #FFF1DC);
            color: #7c3030;
        }

        button:disabled {
            cursor: not-allowed;
            opacity: 0.58;
            transform: none !important;
        }

        .delete-btn {
            background: linear-gradient(135deg, var(--danger), #FFD0D0);
            color: #7c3030;
            padding: 10px 12px;
            border-radius: 14px;
            white-space: nowrap;
        }

        .list-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .list-header h2 {
            margin-bottom: 0;
        }

        .student-count {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: linear-gradient(135deg, rgba(181, 234, 215, 0.78), rgba(255, 253, 249, 0.92));
            color: #2f5f54;
            font-weight: 900;
            box-shadow: var(--shadow-cloud);
            white-space: nowrap;
        }

        .sort-panel {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin: 0 0 16px;
            padding: 12px;
            border-radius: 20px;
            background: rgba(255, 253, 249, 0.72);
            border: 2px dashed rgba(181, 234, 215, 0.9);
        }

        .sort-label {
            font-weight: 900;
            color: #6a4a46;
        }

        .sort-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .sort-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 9px 12px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 900;
            color: #6a4a46;
            background: rgba(255, 255, 255, 0.88);
            border: 2px solid rgba(255, 183, 178, 0.34);
            box-shadow: var(--shadow-cloud);
            transition: transform 0.2s ease, background 0.2s ease;
        }

        .sort-btn:hover {
            transform: translateY(-2px);
            background: rgba(255, 218, 193, 0.55);
        }

        .sort-btn.active {
            color: #27564c;
            background: linear-gradient(135deg, rgba(181, 234, 215, 0.95), rgba(255, 253, 249, 0.95));
            border-color: rgba(181, 234, 215, 0.95);
        }

        .table-wrap {
            overflow-x: auto;
            border-radius: 22px;
            border: 2px solid rgba(255, 255, 255, 0.95);
        }

        table {
            width: 100%;
            min-width: 720px;
            border-collapse: collapse;
            background: rgba(255, 255, 255, 0.78);
            overflow: hidden;
        }

        th,
        td {
            padding: 14px 13px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 183, 178, 0.22);
            vertical-align: middle;
        }

        th {
            background: linear-gradient(135deg, rgba(255, 183, 178, 0.55), rgba(255, 218, 193, 0.55));
            color: #5c3e3d;
            font-weight: 900;
        }

        tbody tr:nth-child(even) {
            background: rgba(181, 234, 215, 0.18);
        }

        tbody tr:nth-child(odd) {
            background: rgba(255, 218, 193, 0.16);
        }

        tbody tr:hover {
            background: rgba(255, 183, 178, 0.18);
        }

        .avatar {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid rgba(181, 234, 215, 0.9);
            background: #fff;
        }

        .empty {
            padding: 22px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.76);
            border: 2px dashed rgba(181, 234, 215, 0.9);
            line-height: 1.7;
        }

        .note {
            margin-top: 16px;
            color: #766b66;
            line-height: 1.55;
            font-size: 14px;
        }

        @media (max-width: 950px) {
            .layout {
                grid-template-columns: 1fr;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .button-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <nav class="topbar">
        <div class="brand">🌿🐾 Bảng quản lý lớp</div>
        <div class="nav-actions">
            <a href="index.php">🐱 Cổng điểm danh</a>
            <a href="export_excel.php">📥 Xuất Excel</a>
            <a href="admin_dashboard.php?logout=1">🌸 Đăng xuất</a>
        </div>
    </nav>

    <main class="container">
        <section class="title">
            <h1>🌸 Lớp học chăm ngoan 😸</h1>
            <p>Thêm thành viên mới bằng webcam, lưu ảnh mẫu vào dataset/ và quản lý danh sách học sinh bằng file JSON.
            </p>
        </section>

        <?php if ($flash['message']): ?>
                <div class="flash <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
                </div>
        <?php endif; ?>

        <section class="layout">
            <article class="panel">
                <h2>🌸 KẾT NẠP THÀNH VIÊN MỚI</h2>

                <form method="post" id="studentForm" autocomplete="off">
                    <input type="hidden" name="action" value="add_student">
                    <input type="hidden" name="captured_images" id="capturedImages">

                    <div class="form-group">
                        <label for="ma_hoc_sinh">🐾 Mã Học Sinh</label>
                        <input id="ma_hoc_sinh" name="ma_hoc_sinh" type="text" placeholder="VD: HS001" required>
                    </div>

                    <div class="form-group">
                        <label for="ho_ten">😸 Họ Và Tên</label>
                        <input id="ho_ten" name="ho_ten" type="text" placeholder="VD: Nguyễn Văn A" required>
                    </div>

                    <div class="form-group">
                        <label for="to_chuc_vu">🌿 Tổ/Chức vụ</label>
                        <input id="to_chuc_vu" name="to_chuc_vu" type="text" placeholder="VD:Tổ 1/lớp trưởng..."
                            required>
                    </div>

                    <label>📸 Webcam chụp ảnh chân dung</label>
                    <div class="camera-box">
                        <video id="webcam" autoplay playsinline muted></video>
                        <canvas id="snapshot"></canvas>
                    </div>
                    <img id="preview" class="preview" alt="Ảnh mẫu mới nhất vừa chụp">
                    <div id="captureSummary" class="capture-summary">🐾 Chưa có ảnh nào được chụp.</div>
                    <div id="previewGrid" class="preview-grid" aria-live="polite"></div>

                    <div class="button-row">
                        <button class="capture-btn" type="button" id="captureBtn">🐱 Chụp thêm ảnh mẫu</button>
                        <button class="toggle-camera-btn" type="button" id="toggleCameraBtn">📷 Tắt camera</button>
                        <button class="clear-btn" type="button" id="clearShotsBtn" disabled>🧹 Xóa ảnh vừa chụp</button>
                        <button class="save-btn" type="submit">🌸 Lưu tất cả ảnh vào lớp</button>
                    </div>
                </form>

                <p class="note">Mẹo: Có thể nhấn "Chụp thêm ảnh mẫu" nhiều lần rồi bấm lưu một lần. Hệ thống tự lưu ảnh lần lượt: HS001.jpg, HS001_1.jpg, HS001_2.jpg... Nếu muốn bổ sung dataset cho học sinh cũ, nhập lại đúng Mã HS rồi chụp thêm ảnh.</p>
            </article>

            <article class="panel">
                <div class="list-header">
                    <h2>🐾 DANH SÁCH THÀNH VIÊN TRONG LỚP</h2>
                    <div class="student-count">😸 Tổng số: <?= $totalStudents ?> Sinh Viên</div>
                </div>

                <div class="sort-panel">
                    <div class="sort-label">🌿 Sắp xếp danh sách:</div>
                    <div class="sort-actions">
                        <a class="<?= sortButtonClass('id', $sortBy) ?>"
                            href="<?= htmlspecialchars(buildSortUrl('id', $sortBy, $sortDir), ENT_QUOTES, 'UTF-8') ?>">
                            Mã HS <?= sortIndicator('id', $sortBy, $sortDir) ?>
                        </a>
                        <a class="<?= sortButtonClass('name', $sortBy) ?>"
                            href="<?= htmlspecialchars(buildSortUrl('name', $sortBy, $sortDir), ENT_QUOTES, 'UTF-8') ?>">
                            Tên cuối <?= sortIndicator('name', $sortBy, $sortDir) ?>
                        </a>
                        <a class="<?= sortButtonClass('group', $sortBy) ?>"
                            href="<?= htmlspecialchars(buildSortUrl('group', $sortBy, $sortDir), ENT_QUOTES, 'UTF-8') ?>">
                            Tổ/Chức vụ <?= sortIndicator('group', $sortBy, $sortDir) ?>
                        </a>
                    </div>
                </div>

                <?php if ($totalStudents === 0): ?>
                        <div class="empty">🌿 Lớp mình chưa có thành viên nào. Hãy thêm học sinh đầu tiên ở form bên trái nhé.
                        </div>
                <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Ảnh 😺</th>
                                        <th>
                                            <a class="<?= sortButtonClass('id', $sortBy) ?>"
                                                href="<?= htmlspecialchars(buildSortUrl('id', $sortBy, $sortDir), ENT_QUOTES, 'UTF-8') ?>">
                                                Mã HS <?= sortIndicator('id', $sortBy, $sortDir) ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a class="<?= sortButtonClass('name', $sortBy) ?>"
                                                href="<?= htmlspecialchars(buildSortUrl('name', $sortBy, $sortDir), ENT_QUOTES, 'UTF-8') ?>">
                                                Họ tên <?= sortIndicator('name', $sortBy, $sortDir) ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a class="<?= sortButtonClass('group', $sortBy) ?>"
                                                href="<?= htmlspecialchars(buildSortUrl('group', $sortBy, $sortDir), ENT_QUOTES, 'UTF-8') ?>">
                                                Tổ/Chức vụ <?= sortIndicator('group', $sortBy, $sortDir) ?>
                                            </a>
                                        </th>
                                        <th>Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $student): ?>
                                            <?php
                                            $sid = htmlspecialchars($student['ma_hoc_sinh'] ?? '', ENT_QUOTES, 'UTF-8');
                                            $name = htmlspecialchars($student['ho_ten'] ?? '', ENT_QUOTES, 'UTF-8');
                                            $role = htmlspecialchars($student['to_chuc_vu'] ?? '', ENT_QUOTES, 'UTF-8');
                                            $studentIdRaw = $student['ma_hoc_sinh'] ?? '';
                                            $imgRaw = $student['anh_mau'] ?? getLatestStudentImageRelative($dataset_dir, $studentIdRaw) ?? ('dataset/' . $studentIdRaw . '.jpg');
                                            $img = htmlspecialchars($imgRaw, ENT_QUOTES, 'UTF-8');
                                            ?>
                                            <tr>
                                                <td>
                                                    <img class="avatar" src="<?= $img ?>?v=<?= time() ?>" alt="Avatar <?= $name ?>"
                                                        onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2258%22 height=%2258%22><rect width=%2258%22 height=%2258%22 rx=%2229%22 fill=%22%23FFDAC1%22/><text x=%2250%25%22 y=%2255%25%22 text-anchor=%22middle%22 font-size=%2226%22>🐱</text></svg>'">
                                                </td>
                                                <td><strong><?= $sid ?></strong></td>
                                                <td><?= $name ?></td>
                                                <td><?= $role ?></td>
                                                <td>
                                                    <form method="post"
                                                        onsubmit="return confirm('Xóa học sinh này khỏi lớp và dataset?');">
                                                        <input type="hidden" name="action" value="delete_student">
                                                        <input type="hidden" name="ma_hoc_sinh" value="<?= $sid ?>">
                                                        <button class="delete-btn" type="submit">🗑️ Xóa Khỏi Lớp</button>
                                                    </form>
                                                </td>
                                            </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                <?php endif; ?>
            </article>
        </section>
    </main>

    <script>
        const webcam = document.getElementById('webcam');
        const canvas = document.getElementById('snapshot');
        const captureBtn = document.getElementById('captureBtn');
        const toggleCameraBtn = document.getElementById('toggleCameraBtn');
        const clearShotsBtn = document.getElementById('clearShotsBtn');
        const capturedImagesInput = document.getElementById('capturedImages');
        const preview = document.getElementById('preview');
        const previewGrid = document.getElementById('previewGrid');
        const captureSummary = document.getElementById('captureSummary');
        const form = document.getElementById('studentForm');

        let cameraStream = null;
        let capturedImages = [];

        function setCameraUi(isOn) {
            captureBtn.disabled = !isOn;
            toggleCameraBtn.textContent = isOn ? '📷 Tắt camera' : '📷 Bật camera';
            toggleCameraBtn.classList.toggle('camera-off', !isOn);
        }

        async function startCamera() {
            try {
                if (cameraStream) {
                    return;
                }

                const stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        width: { ideal: 960 },
                        height: { ideal: 720 },
                        facingMode: 'user'
                    },
                    audio: false
                });

                cameraStream = stream;
                webcam.srcObject = stream;
                setCameraUi(true);
            } catch (error) {
                setCameraUi(false);
                alert('Không mở được webcam. Hãy cấp quyền camera hoặc chạy qua localhost nhé 😿');
            }
        }

        function stopCamera() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                cameraStream = null;
            }
            webcam.srcObject = null;
            setCameraUi(false);
        }

        function takeSnapshot() {
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

            return canvas.toDataURL('image/jpeg', 0.94);
        }

        function escapeHtml(value) {
            return String(value)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function getStudentIdPreview() {
            const input = document.getElementById('ma_hoc_sinh');
            const cleanValue = (input.value || 'HS00X')
                .trim()
                .toUpperCase()
                .replace(/[^A-Z0-9_-]/g, '');
            return cleanValue || 'HS00X';
        }

        function getTargetFileName(index) {
            const studentId = getStudentIdPreview();
            return `${studentId} - ảnh mới ${index + 1}`;
        }

        function syncCapturedImages() {
            capturedImagesInput.value = JSON.stringify(capturedImages);
            clearShotsBtn.disabled = capturedImages.length === 0;

            if (capturedImages.length === 0) {
                preview.style.display = 'none';
                previewGrid.style.display = 'none';
                captureSummary.style.display = 'none';
                previewGrid.innerHTML = '';
                captureBtn.textContent = '🐱 Chụp thêm ảnh mẫu';
                return;
            }

            preview.src = capturedImages[capturedImages.length - 1];
            preview.style.display = 'block';
            previewGrid.style.display = 'grid';
            captureSummary.style.display = 'block';
            captureSummary.innerHTML = `😸 Đã chụp <strong>${capturedImages.length}</strong> ảnh. Khi lưu, hệ thống sẽ tự đặt tên tiếp theo trong dataset.`;
            captureBtn.textContent = `🐱 Chụp thêm ảnh mẫu (${capturedImages.length})`;

            previewGrid.innerHTML = capturedImages.map((image, index) => `
                <div class="sample-thumb">
                    <button class="remove-shot-btn" type="button" data-index="${index}" title="Xóa ảnh này">×</button>
                    <img src="${image}" alt="Ảnh mẫu ${index + 1}">
                    <span>${escapeHtml(getTargetFileName(index))}</span>
                </div>
            `).join('');
        }

        captureBtn.addEventListener('click', () => {
            if (!cameraStream || !webcam.srcObject) {
                alert('Webcam chưa sẵn sàng 🐱');
                return;
            }

            const dataUrl = takeSnapshot();
            capturedImages.push(dataUrl);
            syncCapturedImages();
        });

        toggleCameraBtn.addEventListener('click', () => {
            if (cameraStream) {
                stopCamera();
            } else {
                startCamera();
            }
        });

        clearShotsBtn.addEventListener('click', () => {
            if (!capturedImages.length) {
                return;
            }
            if (confirm('Xóa toàn bộ ảnh vừa chụp trên form này?')) {
                capturedImages = [];
                syncCapturedImages();
            }
        });

        previewGrid.addEventListener('click', (event) => {
            const button = event.target.closest('.remove-shot-btn');
            if (!button) {
                return;
            }

            const index = Number(button.dataset.index);
            if (!Number.isNaN(index)) {
                capturedImages.splice(index, 1);
                syncCapturedImages();
            }
        });

        document.getElementById('ma_hoc_sinh').addEventListener('input', syncCapturedImages);

        form.addEventListener('submit', (event) => {
            if (capturedImages.length === 0) {
                event.preventDefault();
                alert('Bạn cần chụp ít nhất 1 ảnh chân dung trước khi lưu nhé 🐾');
                return;
            }

            capturedImagesInput.value = JSON.stringify(capturedImages);
        });

        window.addEventListener('beforeunload', stopCamera);

        startCamera();
    </script>
</body>

</html>