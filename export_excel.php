<?php
/**
 * export_excel.php
 * Chọn ngày và xuất lịch sử điểm danh theo từng ngày.
 */
date_default_timezone_set('Asia/Ho_Chi_Minh');
header_remove('X-Powered-By');

$attendance_file = __DIR__ . DIRECTORY_SEPARATOR . "database" . DIRECTORY_SEPARATOR . "attendance.json";

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

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function isValidDate(string $date): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date;
}

function formatDateVi(string $date): string
{
    if (!isValidDate($date)) {
        return $date;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt->format('d/m/Y');
}

function filterRecordsByDate(array $records, string $selectedDate): array
{
    return array_values(array_filter($records, function ($record) use ($selectedDate) {
        return ($record['ngay_di_hoc'] ?? '') === $selectedDate;
    }));
}

function sortRecordsByTime(array $records): array
{
    usort($records, function ($a, $b) {
        $timeA = $a['gio_den_lop'] ?? '';
        $timeB = $b['gio_den_lop'] ?? '';

        if ($timeA === $timeB) {
            return strnatcasecmp($a['ma_hoc_sinh'] ?? '', $b['ma_hoc_sinh'] ?? '');
        }

        return strcmp($timeA, $timeB);
    });

    return $records;
}

$allRecords = readJsonArray($attendance_file);

// Danh sách ngày đã có dữ liệu
$availableDates = [];
foreach ($allRecords as $record) {
    $date = $record['ngay_di_hoc'] ?? '';
    if (isValidDate($date)) {
        $availableDates[$date] = true;
    }
}
$availableDates = array_keys($availableDates);
rsort($availableDates);

// Ngày được chọn
$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!isValidDate($selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$records = filterRecordsByDate($allRecords, $selectedDate);
$records = sortRecordsByTime($records);

$isDownload = ($_GET['download'] ?? '') === '1';

if ($isDownload) {
    $fileName = "bang_diem_danh_ngay_" . str_replace('-', '', $selectedDate) . "_" . date('His') . ".xls";

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$fileName\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "\xEF\xBB\xBF";
    ?>
    <!DOCTYPE html>
    <html lang="vi">

    <head>
        <meta charset="UTF-8">
        <style>
            table {
                border-collapse: collapse;
                width: 100%;
                font-family: Arial, sans-serif;
            }

            th,
            td {
                border: 1px solid #999999;
                padding: 8px;
                text-align: left;
            }

            .title {
                font-size: 18px;
                font-weight: bold;
                text-align: center;
                background: #FFDAC1;
                color: #333333;
            }

            .subtitle {
                font-size: 14px;
                font-weight: bold;
                text-align: center;
                background: #FFF7F5;
                color: #333333;
            }

            th {
                background: #B5EAD7;
                font-weight: bold;
            }

            tr:nth-child(even) td {
                background: #FFF7F5;
            }
        </style>
    </head>

    <body>
        <table>
            <tr>
                <td class="title" colspan="7">
                    🐱 BẢNG CHĂM NGOAN - ĐIỂM DANH LỚP CHÚNG MÌNH 🌸
                </td>
            </tr>
            <tr>
                <td class="subtitle" colspan="7">
                    Ngày đi học: <?= e(formatDateVi($selectedDate)) ?> - Tổng số lượt: <?= count($records) ?>
                </td>
            </tr>
            <tr>
                <th>STT</th>
                <th>Mã Học Sinh</th>
                <th>Họ Và Tên</th>
                <th>Tổ/Chức Vụ</th>
                <th>Ngày Đi Học</th>
                <th>Giờ Đến Lớp</th>
                <th>Giờ Ra Về</th>
            </tr>

            <?php if (count($records) === 0): ?>
                <tr>
                    <td colspan="7">Chưa có dữ liệu điểm danh trong ngày này.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($records as $index => $record): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= e($record['ma_hoc_sinh'] ?? '') ?></td>
                        <td><?= e($record['ho_ten'] ?? '') ?></td>
                        <td><?= e($record['to_chuc_vu'] ?? '') ?></td>
                        <td><?= e(formatDateVi($record['ngay_di_hoc'] ?? '')) ?></td>
                        <td><?= e($record['gio_den_lop'] ?? '') ?></td>
                        <td><?= e(($record['gio_ra_ve'] ?? '') !== '' ? $record['gio_ra_ve'] : 'Chưa ra về') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </body>

    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📥 Xuất bảng điểm danh theo ngày</title>
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
            width: min(1100px, 94%);
            margin: 0 auto;
            padding: 34px 0 60px;
        }

        .panel {
            background: rgba(255, 255, 255, 0.84);
            border: 2px solid rgba(255, 255, 255, 0.95);
            border-radius: 28px;
            box-shadow: var(--shadow-cloud);
            padding: 24px;
            backdrop-filter: blur(8px);
        }

        h1 {
            margin: 0 0 10px;
            color: #2f5f54;
            font-size: clamp(28px, 5vw, 46px);
            text-align: center;
        }

        .desc {
            text-align: center;
            line-height: 1.6;
            margin-bottom: 24px;
        }

        .filter-box {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 12px;
            align-items: end;
            padding: 16px;
            border-radius: 22px;
            background: rgba(255, 253, 249, 0.8);
            border: 2px dashed rgba(181, 234, 215, 0.95);
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: 900;
            color: #7d5755;
        }

        input[type="date"] {
            width: 100%;
            border: 2px solid rgba(255, 183, 178, 0.42);
            border-radius: 18px;
            padding: 13px 15px;
            font-size: 16px;
            outline: none;
            background: rgba(255, 253, 249, 0.94);
        }

        button,
        .download-btn {
            border: none;
            border-radius: 18px;
            padding: 13px 16px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 900;
            box-shadow: var(--shadow-cloud);
            transition: transform 0.2s ease, filter 0.2s ease;
            white-space: nowrap;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        button:hover,
        .download-btn:hover {
            transform: translateY(-2px);
            filter: brightness(1.02);
        }

        .view-btn {
            background: linear-gradient(135deg, var(--sun), #FFF1DC);
            color: #704b23;
        }

        .download-btn {
            background: linear-gradient(135deg, var(--mint), #D9FFF0);
            color: #27564c;
        }

        .summary {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 14px;
            font-weight: 900;
            color: #2f5f54;
        }

        .table-wrap {
            overflow-x: auto;
            border-radius: 22px;
            border: 2px solid rgba(255, 255, 255, 0.95);
        }

        table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
            background: rgba(255, 255, 255, 0.82);
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

        .empty {
            padding: 20px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.76);
            border: 2px dashed rgba(255, 183, 178, 0.9);
            line-height: 1.7;
        }

        .available-dates {
            margin-top: 18px;
            padding: 14px;
            border-radius: 20px;
            background: rgba(255, 253, 249, 0.76);
            border: 2px dashed rgba(255, 218, 193, 0.9);
        }

        .date-chip {
            display: inline-flex;
            padding: 8px 11px;
            border-radius: 999px;
            background: rgba(181, 234, 215, 0.65);
            color: #2f5f54;
            font-weight: 900;
            text-decoration: none;
            margin: 5px;
        }

        @media (max-width: 760px) {
            .filter-box {
                grid-template-columns: 1fr;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <nav class="topbar">
        <div class="brand">📥🐾 Xuất bảng điểm danh</div>
        <div class="nav-actions">
            <a href="index.php">🐱 Cổng điểm danh</a>
            <a href="admin_dashboard.php">🌸 Quản trị lớp</a>
        </div>
    </nav>

    <main class="container">
        <section class="panel">
            <h1>🌸 Bảng điểm danh theo ngày 😸</h1>
            <p class="desc">
                Chọn ngày cần xem hoặc xuất Excel. Hệ thống chỉ lấy dữ liệu điểm danh của đúng ngày đã chọn.
            </p>

            <form class="filter-box" method="get" action="export_excel.php">
                <div>
                    <label for="date">📅 Chọn ngày điểm danh</label>
                    <input id="date" name="date" type="date" value="<?= e($selectedDate) ?>" required>
                </div>

                <button class="view-btn" type="submit">👀 Xem bảng ngày này</button>

                <a class="download-btn" href="export_excel.php?date=<?= urlencode($selectedDate) ?>&download=1">
                    📥 Xuất Excel ngày đã chọn
                </a>
            </form>

            <div class="summary">
                <span>📅 Ngày đang xem: <?= e(formatDateVi($selectedDate)) ?></span>
                <span>😸 Tổng lượt điểm danh: <?= count($records) ?></span>
            </div>

            <?php if (count($records) === 0): ?>
                <div class="empty">
                    😿 Chưa có dữ liệu điểm danh cho ngày <?= e(formatDateVi($selectedDate)) ?>.
                    Hãy chọn ngày khác hoặc điểm danh trước.
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>STT</th>
                                <th>Mã Học Sinh</th>
                                <th>Họ Và Tên</th>
                                <th>Tổ/Chức Vụ</th>
                                <th>Ngày Đi Học</th>
                                <th>Giờ Đến Lớp</th>
                                <th>Giờ Ra Về</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $index => $record): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= e($record['ma_hoc_sinh'] ?? '') ?></td>
                                    <td><?= e($record['ho_ten'] ?? '') ?></td>
                                    <td><?= e($record['to_chuc_vu'] ?? '') ?></td>
                                    <td><?= e(formatDateVi($record['ngay_di_hoc'] ?? '')) ?></td>
                                    <td><?= e($record['gio_den_lop'] ?? '') ?></td>
                                    <td><?= e(($record['gio_ra_ve'] ?? '') !== '' ? $record['gio_ra_ve'] : 'Chưa ra về') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (count($availableDates) > 0): ?>
                <div class="available-dates">
                    <strong>🌿 Các ngày đã có dữ liệu:</strong><br>
                    <?php foreach ($availableDates as $date): ?>
                        <a class="date-chip" href="export_excel.php?date=<?= urlencode($date) ?>">
                            <?= e(formatDateVi($date)) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>

</html>