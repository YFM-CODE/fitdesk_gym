<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_admin();

date_default_timezone_set('Asia/Jakarta');

function tanggalID($value)
{
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April',
        'Mei', 'Juni', 'Juli', 'Agustus',
        'September', 'Oktober', 'November', 'Desember'
    ];

    $time = strtotime($value ?: 'now');

    return date('j', $time) . ' ' .
        $bulan[(int) date('n', $time)] . ' ' .
        date('Y', $time);
}

$today = date('Y-m-d');
$errors = [];
$usernameInput = '';
$role = 'resepsionis';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $usernameInput = trim($_POST['username'] ?? '');
    $passwordInput = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';

    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $usernameInput)) {
        $errors[] = 'Username harus 3–50 karakter, hanya huruf, angka, titik, garis bawah, atau tanda hubung.';
    }

    if (strlen($passwordInput) < 8) {
        $errors[] = 'Password minimal 8 karakter.';
    }

    if (!in_array($role, ['admin', 'resepsionis'], true)) {
        $errors[] = 'Role tidak valid.';
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password, role) VALUES (?, ?, ?)'
            );
            $stmt->execute([
                $usernameInput,
                password_hash($passwordInput, PASSWORD_DEFAULT),
                $role
            ]);

            flash('success', 'Pengguna baru berhasil ditambahkan.');
            redirect('index.php');
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                $errors[] = 'Username sudah digunakan. Silakan gunakan username lain.';
            } else {
                error_log($ex->getMessage());
                $errors[] = 'Pengguna gagal disimpan.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Pengguna | FitDesk Gym</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f6f4;
            color: #202923;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 13px;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        /* SIDEBAR IDENTIK DENGAN DASHBOARD */
        .sidebar {
            width: 235px;
            flex-shrink: 0;
            padding: 26px 15px;
            background: #182720;
            color: #fff;
        }

        .brand {
            font-size: 23px;
            font-weight: 800;
            padding: 0 12px 30px;
        }

        .brand span {
            color: #c5f578;
        }

        .menu-label {
            margin: 18px 12px 10px;
            color: #a4b1a8;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .menu a {
            display: block;
            margin: 5px 0;
            padding: 13px 12px;
            border-radius: 9px;
            color: #e0e7e2;
            font-size: 13px;
            transition: .2s;
        }

        .menu a:hover,
        .menu a.active {
            background: #c5f578;
            color: #17251f;
            font-weight: bold;
        }

        /* AREA KONTEN */
        .main {
            flex: 1;
            min-width: 0;
        }

        .topbar {
            min-height: 58px;
            padding: 14px 28px;
            background: #fff;
            border-bottom: 1px solid #e4e9e5;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            color: #737d75;
            font-size: 11px;
        }

        .content {
            max-width: 900px;
            margin: auto;
            padding: 27px 28px 35px;
        }

        .heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 25px;
        }

        .subtitle {
            margin: 0;
            color: #818b83;
            font-size: 12px;
            line-height: 1.6;
        }

        /* PANEL & FORM */
        .panel {
            padding: 26px;
            border: 1px solid #e5eae5;
            border-radius: 13px;
            background: #fff;
            margin-bottom: 17px;
        }

        .panel-head {
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 1px solid #edf0ed;
        }

        .panel h3 {
            margin: 0 0 6px;
            font-size: 16px;
        }

        .panel p {
            margin: 0;
            color: #858e87;
            font-size: 12px;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
            color: #2b3930;
            font-size: 12px;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid #dce4dc;
            border-radius: 8px;
            font-family: inherit;
            font-size: 13px;
            color: #202923;
            background: #fff;
            transition: .2s;
            outline: none;
        }

        .form-control:focus, .form-select:focus {
            border-color: #8fb65a;
            box-shadow: 0 0 0 3px rgba(197, 245, 120, 0.35);
        }

        .form-help {
            display: block;
            margin-top: 6px;
            color: #858e87;
            font-size: 11px;
        }

        .form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 25px;
            padding-top: 18px;
            border-top: 1px solid #edf0ed;
        }

        /* TOMBOL */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border: 0;
            border-radius: 9px;
            padding: 12px 20px;
            background: #c5f578;
            color: #1d2a20;
            font-weight: bold;
            font-family: inherit;
            font-size: 12px;
            cursor: pointer;
            transition: .2s;
            text-decoration: none;
        }

        .btn:hover {
            background: #b3e962;
            transform: translateY(-1px);
        }

        .btn-light {
            background: #eef2ed;
            color: #344239;
        }

        .btn-light:hover {
            background: #e2e8e1;
        }

        /* ALERT */
        .alert {
            padding: 13px 16px;
            margin-bottom: 20px;
            border-radius: 9px;
            font-size: 12px;
            line-height: 1.5;
        }

        .alert.error {
            color: #a44a3f;
            background: #fff0ed;
            border: 1px solid #f2d2cc;
        }

        .alert ul {
            margin: 0;
            padding-left: 20px;
        }

        .foot {
            margin-top: 18px;
            color: #8a938c;
            font-size: 10px;
        }

        @media (max-width: 900px) {
            .layout {
                display: block;
            }

            .sidebar {
                width: 100%;
                padding: 15px;
            }

            .topbar {
                padding: 14px 15px;
            }

            .content {
                padding: 20px 14px;
            }
        }
    </style>
</head>

<body>
<div class="layout">

    <!-- SIDEBAR TERPUSAT SESUAI ROLE (AUTHENTICATED LAYOUT) -->
    <?php render_sidebar('users'); ?>

    <!-- KONTEN UTAMA -->
    <main class="main">

        <header class="topbar">
            <div>Administrasi &nbsp; › &nbsp; <a href="index.php" style="color:#737d75;">Pengguna</a> &nbsp; › &nbsp; <strong>Tambah Pengguna</strong></div>
            <div><?= e(tanggalID($today)) ?></div>
        </header>

        <div class="content">

            <section class="heading">
                <div>
                    <h1>Tambah Pengguna Baru</h1>
                    <p class="subtitle">
                        Buat akun Admin atau Resepsionis baru untuk operasional FitDesk Gym.
                    </p>
                </div>

                <a href="index.php" class="btn btn-light">
                    ← Kembali ke Daftar
                </a>
            </section>

            <?php if ($errors): ?>
                <div class="alert error">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= e($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <section class="panel">
                <div class="panel-head">
                    <h3>Informasi Akun</h3>
                    <p>Lengkapi username, kata sandi, dan tentukan hak akses peran pengguna.</p>
                </div>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                    <div class="form-group">
                        <label for="username">Username *</label>
                        <input type="text" id="username" class="form-control" name="username" value="<?= e($usernameInput) ?>" minlength="3" maxlength="50" required placeholder="Contoh: staff_gym01" autocomplete="username">
                        <span class="form-help">Gunakan 3–50 karakter (huruf, angka, titik, atau garis bawah).</span>
                    </div>

                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" class="form-control" name="password" minlength="8" required autocomplete="new-password" placeholder="Minimal 8 karakter">
                        <span class="form-help">Minimal 8 karakter demi keamanan sistem.</span>
                    </div>

                    <div class="form-group">
                        <label for="role">Hak Akses (Role) *</label>
                        <select id="role" class="form-select" name="role" required>
                            <option value="resepsionis" <?= $role === 'resepsionis' ? 'selected' : '' ?>>Resepsionis (Akses operasional: Anggota, Membership, Pembayaran)</option>
                            <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admin (Akses penuh: CRUD Paket, Pengguna, Laporan, Dashboard)</option>
                        </select>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn">
                            ＋ Simpan Pengguna
                        </button>
                        <a href="index.php" class="btn btn-light">
                            Batal
                        </a>
                    </div>
                </form>
            </section>

            <div class="foot">
                FitDesk Gym © <?= date('Y') ?> — Manajemen pengguna sistem.
            </div>

        </div>
    </main>

</div>
</body>
</html>
