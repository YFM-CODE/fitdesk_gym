<?php
session_start();
require_once __DIR__ . '/config/database.php';

$error = '';
$success = '';

if (isset($_SESSION['user_id']) || isset($_SESSION['username'])) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_lengkap = trim($_POST['nama_lengkap'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $konfirmasi_password = $_POST['konfirmasi_password'] ?? '';

    if (
        $nama_lengkap === '' ||
        $email === '' ||
        $username === '' ||
        $password === '' ||
        $konfirmasi_password === ''
    ) {
        $error = 'Semua kolom wajib diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } elseif ($password !== $konfirmasi_password) {
        $error = 'Konfirmasi kata sandi tidak sama.';
    } elseif (strlen($password) < 8) {
        $error = 'Kata sandi minimal 8 karakter.';
    } else {
        try {
            // Periksa username dan email yang sudah terdaftar.
            $cek = $pdo->prepare(
                'SELECT id FROM users
                 WHERE username = ? OR email = ?
                 LIMIT 1'
            );
            $cek->execute([$username, $email]);

            if ($cek->fetch()) {
                $error = 'Nama pengguna atau email sudah digunakan.';
            } else {
                // Amankan kata sandi.
                $password_hash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                // Simpan akun baru ke database.
                $stmt = $pdo->prepare(
                    'INSERT INTO users
                    (nama_lengkap, email, username, password, role)
                    VALUES (?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $nama_lengkap,
                    $email,
                    $username,
                    $password_hash,
                    'resepsionis'
                ]);

                $success = 'Registrasi berhasil! Silakan login menggunakan akun baru.';
            }
        } catch (PDOException $e) {
            error_log('Registrasi FitDesk Gym: ' . $e->getMessage());
            $error = 'Registrasi gagal. Silakan periksa data dan coba kembali.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Registrasi | FitDesk Gym</title>

    <style>
        * {
            box-sizing: border-box;
        }

        :root {
            --dark: #14251d;
            --dark-light: #1d3529;
            --lime: #c5f36a;
            --white: #ffffff;
            --muted: #a8b9ad;
            --border: #34483b;
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 35px 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            background:
                radial-gradient(
                    circle at top left,
                    #2b4936 0,
                    transparent 40%
                ),
                linear-gradient(135deg, #101d17, #1b3024);
            font-family: Arial, Helvetica, sans-serif;
            color: var(--white);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .container {
            width: 100%;
            max-width: 960px;
            min-height: 590px;
            display: grid;
            grid-template-columns: 0.9fr 1.1fr;
            overflow: hidden;
            border: 1px solid #ffffff15;
            border-radius: 24px;
            background: var(--dark);
            box-shadow: 0 25px 70px #00000040;
        }

        /* PANEL KIRI */
        .intro {
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 42px 35px;
            overflow: hidden;
            background:
                linear-gradient(
                    145deg,
                    #213c2c 0%,
                    #17291f 65%,
                    #14251d 100%
                );
        }

        .intro::after {
            content: "";
            position: absolute;
            width: 250px;
            height: 250px;
            right: -100px;
            bottom: -95px;
            border: 35px solid #c5f36a12;
            border-radius: 50%;
            pointer-events: none;
        }

        .brand {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo {
            width: 46px;
            height: 46px;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            border-radius: 13px;
            background: var(--lime);
            color: #15241b;
            font-size: 27px;
            font-weight: 900;
        }

        .brand-name {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .brand-name span {
            color: var(--lime);
        }

        .brand-caption {
            margin-top: 5px;
            color: var(--muted);
            font-size: 9px;
            letter-spacing: 2px;
        }

        .intro-content {
            position: relative;
            z-index: 1;
            margin: 45px 0;
        }

        .eyebrow {
            display: inline-block;
            padding: 8px 12px;
            border: 1px solid #c5f36a35;
            border-radius: 30px;
            background: #c5f36a10;
            color: var(--lime);
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 1.3px;
        }

        .intro h1 {
            margin: 22px 0 15px;
            font-size: 35px;
            line-height: 1.2;
            letter-spacing: -1px;
        }

        .intro h1 span {
            color: var(--lime);
        }

        .intro-description {
            color: #b4c3b8;
            font-size: 13px;
            line-height: 1.9;
        }

        .features {
            display: grid;
            gap: 15px;
            margin-top: 28px;
        }

        .feature {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #e0e9e2;
            font-size: 12px;
        }

        .feature-icon {
            width: 32px;
            height: 32px;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            border-radius: 9px;
            background: #c5f36a15;
            color: var(--lime);
            font-size: 15px;
        }

        .intro-footer {
            position: relative;
            z-index: 1;
            color: #91a397;
            font-size: 10px;
            line-height: 1.7;
        }

        /* PANEL KANAN */
        .form-area {
            padding: 42px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #172820;
        }

        .form-heading {
            margin-bottom: 24px;
        }

        .form-heading .small-title {
            margin: 0 0 10px;
            color: var(--lime);
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 1.8px;
            text-transform: uppercase;
        }

        .form-heading h2 {
            margin: 0 0 10px;
            font-size: 27px;
            letter-spacing: -0.6px;
        }

        .form-heading p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.7;
        }

        .message {
            margin-bottom: 17px;
            padding: 12px 14px;
            border-radius: 9px;
            font-size: 12px;
            line-height: 1.6;
        }

        .error {
            color: #ffd4ce;
            background: #6c2c29;
            border: 1px solid #a64a42;
        }

        .success {
            color: #dfffb0;
            background: #263e24;
            border: 1px solid #567b3a;
        }

        .field {
            margin-bottom: 15px;
        }

        .field label {
            display: block;
            margin-bottom: 8px;
            color: #e4ece6;
            font-size: 11px;
            font-weight: bold;
        }

        .input-wrap {
            position: relative;
        }

        .field input {
            width: 100%;
            height: 44px;
            padding: 0 13px;
            border: 1px solid var(--border);
            border-radius: 9px;
            outline: none;
            background: #112019;
            color: #fff;
            font-family: inherit;
            font-size: 12px;
            transition: border-color .2s, box-shadow .2s;
        }

        .field input::placeholder {
            color: #728579;
        }

        .field input:focus {
            border-color: var(--lime);
            box-shadow: 0 0 0 3px #c5f36a16;
        }

        .password-input {
            padding-right: 70px !important;
        }

        .toggle-password {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            padding: 5px 0;
            border: none;
            background: transparent;
            color: var(--lime);
            cursor: pointer;
            font-size: 10px;
            font-weight: bold;
        }

        .submit-btn {
            width: 100%;
            min-height: 46px;
            margin-top: 7px;
            padding: 12px;
            border: none;
            border-radius: 9px;
            background: var(--lime);
            color: #17251c;
            cursor: pointer;
            font-size: 13px;
            font-weight: 800;
            transition: .2s;
        }

        .submit-btn:hover {
            background: #d4ff86;
            transform: translateY(-1px);
        }

        .login-link {
            margin-top: 22px;
            color: var(--muted);
            text-align: center;
            font-size: 12px;
        }

        .login-link a {
            color: var(--lime);
            font-weight: bold;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        .security-note {
            margin-top: 22px;
            padding-top: 16px;
            border-top: 1px solid #34483b;
            color: #91a397;
            text-align: center;
            font-size: 10px;
            line-height: 1.7;
        }

        /* RESPONSIVE */
        @media (max-width: 760px) {
            body {
                padding: 20px 12px;
                align-items: flex-start;
            }

            .container {
                max-width: 480px;
                grid-template-columns: 1fr;
                border-radius: 18px;
            }

            .intro {
                padding: 25px 25px 22px;
            }

            .intro-content {
                margin: 28px 0 18px;
            }

            .intro h1 {
                font-size: 27px;
            }

            .intro-description {
                font-size: 12px;
            }

            .features {
                gap: 10px;
                margin-top: 18px;
            }

            .intro-footer {
                margin-top: 15px;
            }

            .form-area {
                padding: 30px 25px;
            }

            .form-heading h2 {
                font-size: 24px;
            }
        }

        @media (max-width: 380px) {
            .intro,
            .form-area {
                padding-left: 19px;
                padding-right: 19px;
            }

            .intro h1 {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <!-- PANEL INFORMASI -->
    <section class="intro">

        <div class="brand">
            <div class="logo">F</div>

            <div>
                <div class="brand-name">
                    FitDesk <span>Gym.</span>
                </div>
                <div class="brand-caption">
                    GYM MANAGEMENT SYSTEM
                </div>
            </div>
        </div>

        <div class="intro-content">

            <div class="eyebrow">
                MEMBER REGISTRATION
            </div>

            <h1>
                Bergabung dengan
                <span>FitDesk Gym.</span>
            </h1>

            <p class="intro-description">
                Buat akun baru untuk mengakses sistem FitDesk Gym
                dan mendukung pengelolaan aktivitas gym dengan lebih mudah.
            </p>

            <div class="features">
                <div class="feature">
                    <div class="feature-icon">✓</div>
                    <span>Registrasi akun dengan mudah</span>
                </div>

                <div class="feature">
                    <div class="feature-icon">▣</div>
                    <span>Data akun tersimpan di sistem</span>
                </div>

                <div class="feature">
                    <div class="feature-icon">⌑</div>
                    <span>Kata sandi disimpan secara aman</span>
                </div>
            </div>

        </div>

        <div class="intro-footer">
            FitDesk Gym © <?= date('Y') ?><br>
            Gym Management System
        </div>

    </section>

    <!-- PANEL FORM REGISTRASI -->
    <section class="form-area">

        <div class="form-heading">
            <p class="small-title">Buat akun baru</p>
            <h2>Registrasi Akun</h2>
            <p>
                Lengkapi data berikut untuk membuat akun FitDesk Gym.
            </p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="message error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="message success">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">

            <div class="field">
                <label for="nama_lengkap">Nama Lengkap</label>
                <input
                    type="text"
                    id="nama_lengkap"
                    name="nama_lengkap"
                    placeholder="Masukkan nama lengkap"
                    value="<?= htmlspecialchars($_POST['nama_lengkap'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    maxlength="100"
                    autocomplete="name"
                    required
                >
            </div>

            <div class="field">
                <label for="email">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Masukkan alamat email"
                    value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    maxlength="100"
                    autocomplete="email"
                    required
                >
            </div>

            <div class="field">
                <label for="username">Nama Pengguna</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    placeholder="Buat nama pengguna"
                    value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    maxlength="50"
                    autocomplete="username"
                    required
                >
            </div>

            <div class="field">
                <label for="password">Kata Sandi</label>
                <div class="input-wrap">
                    <input
                        class="password-input"
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Minimal 8 karakter"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >

                    <button
                        class="toggle-password"
                        type="button"
                        onclick="togglePassword('password', this)"
                    >
                        Lihat
                    </button>
                </div>
            </div>

            <div class="field">
                <label for="konfirmasi_password">
                    Konfirmasi Kata Sandi
                </label>

                <div class="input-wrap">
                    <input
                        class="password-input"
                        type="password"
                        id="konfirmasi_password"
                        name="konfirmasi_password"
                        placeholder="Ulangi kata sandi"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >

                    <button
                        class="toggle-password"
                        type="button"
                        onclick="togglePassword('konfirmasi_password', this)"
                    >
                        Lihat
                    </button>
                </div>
            </div>

            <button class="submit-btn" type="submit">
                Daftar Sekarang →
            </button>

        </form>

        <div class="login-link">
            Sudah punya akun?
            <a href="index.php">Login di sini</a>
        </div>

        <div class="security-note">
            Pastikan informasi yang dimasukkan sudah benar.
            Kata sandi akan disimpan menggunakan enkripsi hash.
        </div>

    </section>

</div>

<script>
function togglePassword(id, button) {
    const input = document.getElementById(id);

    if (input.type === 'password') {
        input.type = 'text';
        button.textContent = 'Sembunyi';
    } else {
        input.type = 'password';
        button.textContent = 'Lihat';
    }
}
</script>

</body>
</html>
