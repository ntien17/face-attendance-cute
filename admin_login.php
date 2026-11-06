<?php
/**
 * admin_login.php
 * Trang đăng nhập giáo viên / ban cán sự.
 * Tài khoản demo: admin / cnttk23
 */
session_start();
date_default_timezone_set('Asia/Ho_Chi_Minh');
header_remove('X-Powered-By');

if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: admin_dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === 'admin' && $password === 'cnttk23') {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_name'] = 'Admin';
        header('Location: admin_dashboard.php');
        exit;
    }

    $error = 'Sai tài khoản hoặc mật khẩu rồi bé mèo ơi 😿';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;700&family=Nunito+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🐱 Đăng nhập quản trị lớp</title>
    <style>
        :root {
            --cream: #FFFDF9;
            --pink: #FFB7B2;
            --mint: #B5EAD7;
            --sun: #FFDAC1;
            --text: #4A4A4A;
            --shadow-cloud: 0 8px 24px rgba(0,0,0,0.05);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: "Be Vietnam Pro", "Nunito Sans", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(255,183,178,0.50), transparent 30%),
                radial-gradient(circle at bottom right, rgba(181,234,215,0.62), transparent 30%),
                var(--cream);
        }

        .login-card {
            position: relative;
            width: min(420px, 92vw);
            padding: 42px 30px 32px;
            border-radius: 32px;
            background: rgba(255,255,255,0.86);
            border: 3px solid rgba(255,255,255,0.95);
            box-shadow: var(--shadow-cloud);
            text-align: center;
            backdrop-filter: blur(8px);
        }

        .login-card::before,
        .login-card::after {
            content: "";
            position: absolute;
            top: -34px;
            width: 72px;
            height: 72px;
            background: var(--pink);
            border-radius: 16px;
            border: 4px solid rgba(255,255,255,0.9);
            transform: rotate(45deg);
            z-index: -1;
        }

        .login-card::before {
            left: 52px;
        }

        .login-card::after {
            right: 52px;
        }

        .face {
            width: 120px;
            height: 88px;
            margin: 0 auto 10px;
            border-radius: 42px 42px 36px 36px;
            background: linear-gradient(135deg, var(--sun), #fff2df);
            display: grid;
            place-items: center;
            font-size: 42px;
            box-shadow: var(--shadow-cloud);
        }

        h1 {
            margin: 12px 0 8px;
            color: #2f5f54;
            font-size: 30px;
        }

        p {
            line-height: 1.6;
        }

        .form-group {
            text-align: left;
            margin: 16px 0;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 800;
            color: #7d5755;
        }

        input {
            width: 100%;
            border: 2px solid rgba(255,183,178,0.45);
            border-radius: 18px;
            padding: 14px 16px;
            font-size: 16px;
            outline: none;
            background: rgba(255,253,249,0.96);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        input:focus {
            border-color: var(--mint);
            box-shadow: 0 0 0 5px rgba(181,234,215,0.35);
        }

        button {
            width: 100%;
            margin-top: 12px;
            border: none;
            border-radius: 20px;
            padding: 15px 18px;
            cursor: pointer;
            background: linear-gradient(135deg, var(--mint), #D9FFF0);
            color: #27564c;
            font-size: 17px;
            font-weight: 900;
            box-shadow: var(--shadow-cloud);
            transition: transform 0.2s ease, filter 0.2s ease;
        }

        button:hover {
            transform: scale(1.04);
            filter: brightness(1.03);
        }

        .error {
            margin: 14px 0 0;
            border-radius: 18px;
            padding: 12px;
            background: rgba(255,183,178,0.32);
            border: 2px dashed rgba(255,183,178,0.75);
            color: #8a4240;
            font-weight: 800;
        }

        .back-link {
            display: inline-block;
            margin-top: 18px;
            color: #7d5755;
            text-decoration: none;
            font-weight: 800;
        }

        .demo {
            margin-top: 10px;
            font-size: 14px;
            color: #726762;
        }
    </style>
</head>
<body>
    <main class="login-card">
        <div class="face">🐱</div>
        <h1>Đăng nhập lớp mình 🌸</h1>
        <p>Chỉ giáo viên hoặc ban cán sự mới được thêm/xóa thành viên lớp.</p>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <div class="form-group">
                <label for="username">🌿 Tài khoản</label>
                <input id="username" name="username" type="text" placeholder="admin" required>
            </div>

            <div class="form-group">
                <label for="password">🐾 Mật khẩu</label>
                <input id="password" name="password" type="password" placeholder="cnttk23" required>
            </div>

            <button type="submit">😸 ĐĂNG NHẬP VÀO LỚP</button>
        </form>

        <div class="demo">Demo: <strong>admin</strong> / <strong>123456</strong></div>
        <a class="back-link" href="index.php">← Về cổng điểm danh</a>
    </main>
</body>
</html>
