<?php
session_start();

require_once dirname(__DIR__, 2) . '/config/database.php';

if (isset($_SESSION['user_id']) || isset($_SESSION['username'])) {
    header('Location: ../dashboard/index.php');
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Nama pengguna dan kata sandi wajib diisi.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id, nama_lengkap, email, username, password, role
                FROM users
                WHERE username = ?
                LIMIT 1
            ");

            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['user'] = $user;

                header('Location: ../dashboard/index.php');
                exit;
            }

            $error = 'Nama pengguna atau kata sandi salah.';

        } catch (PDOException $ex) {
            error_log('Login FitDesk: ' . $ex->getMessage());
            $error = 'Terjadi masalah saat menghubungkan ke database.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Masuk | Fitdesk Fitness Club</title>

<style>
*{box-sizing:border-box}
:root{--dark:#14231b;--lime:#c5f578;--text:#17251d}
body{margin:0;min-height:100vh;font-family:Arial,Helvetica,sans-serif;color:var(--text);background:#fff}
a{color:inherit;text-decoration:none}
.page{min-height:100vh;display:grid;grid-template-columns:45.2% 54.8%}

/* PANEL KIRI */
.left-panel{min-height:100vh;display:flex;flex-direction:column;justify-content:space-between;padding:32px 7.5%;background:var(--dark);color:white}
.logo{display:flex;align-items:center;gap:10px;width:fit-content}
.logo-icon{width:37px;height:37px;display:grid;place-items:center;background:var(--lime);color:var(--dark);border-radius:9px;font-size:22px;font-weight:bold}
.logo-name{font-size:20px;font-weight:800;letter-spacing:-.7px}
.logo-caption{display:block;margin-top:3px;color:#c2d0c5;font-size:7px;letter-spacing:2px}
.hero{width:100%;max-width:560px;margin:40px auto}
.eyebrow{display:flex;align-items:center;gap:8px;margin-bottom:20px;color:var(--lime);font-size:10px;font-weight:bold;letter-spacing:1px;text-transform:uppercase}
.eyebrow:before{content:"";width:13px;height:2px;background:var(--lime)}
.hero h1{margin:0;font-size:clamp(30px,3vw,43px);line-height:1.13;letter-spacing:-1.5px;font-weight:800}
.hero-description{max-width:400px;margin:20px 0 24px;color:#a8b5ac;font-size:13px;line-height:1.8}
.gym-image{position:relative;width:100%;height:265px;overflow:hidden;border-radius:12px;background:#304638}
.gym-image img{width:100%;height:100%;display:block;object-fit:cover}
.location{position:absolute;left:14px;bottom:14px;padding:10px 13px;border-radius:8px;background:#15231de8;color:white;font-size:10px}
.features{display:flex;flex-wrap:wrap;gap:18px;margin-top:18px;color:#d0d9d2;font-size:10px}
.features span:before{content:"✓";margin-right:7px;color:var(--lime);font-weight:bold}
.left-footer{padding-top:18px;border-top:1px solid #344339;display:flex;justify-content:space-between;gap:12px;color:#a4b1a8;font-size:9px}

/* PANEL KANAN */
.right-panel{min-height:100vh;display:flex;flex-direction:column;padding:30px 7%;background:#fff}
.top-help{display:flex;justify-content:flex-end;gap:6px;color:#929992;font-size:10px}
.top-help a{color:var(--text);font-weight:bold}
.login-area{width:100%;max-width:410px;margin:auto;padding:35px 0}
.welcome-label{margin-bottom:13px;color:#386c48;font-size:10px;font-weight:bold;letter-spacing:.5px;text-transform:uppercase}
.login-area h2{margin:0 0 10px;font-size:31px;line-height:1.2;letter-spacing:-1px}
.login-description{margin:0 0 28px;color:#929992;font-size:12px;line-height:1.7}
.alert{margin-bottom:18px;padding:12px 14px;border-radius:8px;font-size:11px;line-height:1.6}
.alert-error{background:#fff0ed;color:#a13e33;border:1px solid #f2d8d3}
.form-group{margin-bottom:18px}
.form-group label{display:block;margin-bottom:9px;color:#344239;font-size:11px;font-weight:bold}
.input-wrap{position:relative}
.input-wrap input{width:100%;height:45px;padding:0 43px 0 14px;border:1px solid #e4e8e4;border-radius:9px;background:white;outline:none;color:#243329;font-size:12px;transition:.2s}
.input-wrap input::placeholder{color:#a0a7a1}
.input-wrap input:focus{border-color:#9fc66b;box-shadow:0 0 0 3px #c5f57830}
.input-icon{position:absolute;top:50%;right:13px;transform:translateY(-50%);color:#78847a;font-size:16px;pointer-events:none}
.password-toggle{position:absolute;top:50%;right:10px;transform:translateY(-50%);width:28px;height:28px;border:0;background:transparent;color:#78847a;cursor:pointer;font-size:16px}
.form-options{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:2px 0 22px;font-size:11px}
.remember{display:flex;align-items:center;gap:7px;color:#59645b;cursor:pointer}
.remember input{width:15px;height:15px;margin:0;accent-color:#b9ed68}
.forgot{color:#356844;font-weight:bold}
.login-button{width:100%;height:46px;display:flex;align-items:center;justify-content:center;gap:9px;border:0;border-radius:9px;background:var(--lime);color:#17251d;font-size:12px;font-weight:bold;cursor:pointer;transition:.2s}
.login-button:hover{background:#b6ec62;transform:translateY(-1px)}
.register-text{margin:23px 0;text-align:center;color:#929992;font-size:11px}
.register-text a{color:#356844;font-weight:bold}
.security-note{display:flex;align-items:flex-start;gap:11px;padding:15px;border-radius:10px;background:#f6f8f5;color:#828c83;font-size:10px;line-height:1.7}
.security-icon{color:#548060;font-size:17px}
.security-note strong{display:block;color:#59665b;font-size:10px}
.right-footer{display:flex;justify-content:center;gap:25px;flex-wrap:wrap;padding-top:15px;color:#939b94;font-size:10px}
.right-footer a:hover,.top-help a:hover,.forgot:hover,.register-text a:hover{text-decoration:underline}

@media(min-width:1500px){.left-panel{padding-left:9%;padding-right:9%}.right-panel{padding-left:10%;padding-right:10%}}
@media(max-width:900px){.page{grid-template-columns:40% 60%}.left-panel{padding:25px 7%}.right-panel{padding:25px 6%}.hero h1{font-size:31px}.gym-image{height:220px}.features{gap:10px}}
@media(max-width:650px){.page{display:block}.left-panel{min-height:auto;padding:22px}.hero{margin:35px auto 25px}.hero h1{font-size:32px}.gym-image{height:210px}.left-footer{margin-top:10px;font-size:8px}.right-panel{min-height:650px;padding:22px}.login-area{max-width:450px;padding:45px 0}.login-area h2{font-size:28px}}
</style>
</head>

<body>
<div class="page">

    <section class="left-panel">
        <a class="logo" href="login.php">
            <span class="logo-icon">♧</span>
            <span>
                <span class="logo-name">Fitdesk</span>
                <span class="logo-caption">FITNESS CLUB</span>
            </span>
        </a>

        <div class="hero">
            <div class="eyebrow">Ruang kerja resepsionis</div>

            <h1>Setiap kunjungan,<br>awal yang lebih baik.</h1>

            <p class="hero-description">
                Kelola anggota, sambut kedatangan, dan pantau
                perkembangan gym dalam satu ruang kerja.
            </p>

            <div class="gym-image">
                <img
                    src="https://images.unsplash.com/photo-1534438327276-14e5300c3a48?auto=format&fit=crop&w=1200&q=85"
                    alt="Interior gym dan peralatan kebugaran"
                >
                <div class="location">♧ &nbsp; Fitdesk Fitness Club</div>
            </div>

            <div class="features">
                <span>Data anggota terpusat</span>
                <span>Akses sesuai peran</span>
            </div>
        </div>

        <footer class="left-footer">
            <span>© <?= date('Y') ?> Fitdesk. Semua hak dilindungi.</span>
            <span>Sistem operasional gym</span>
        </footer>
    </section>

    <section class="right-panel">
        <div class="top-help">
            Butuh bantuan masuk?
            <a href="mailto:admin@fitdesk.id">Hubungi admin ↗</a>
        </div>

        <div class="login-area">
            <div class="welcome-label">Selamat datang kembali</div>
            <h2>Masuk ke Fitdesk</h2>

            <p class="login-description">
                Gunakan akun petugas untuk memulai operasional gym hari ini.
            </p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" autocomplete="on">

                <div class="form-group">
                    <label for="username">Nama pengguna</label>
                    <div class="input-wrap">
                        <input
                            type="text"
                            id="username"
                            name="username"
                            placeholder="Masukkan nama pengguna"
                            value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
                            autocomplete="username"
                            maxlength="100"
                            required
                        >
                        <span class="input-icon">♙</span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Kata sandi</label>
                    <div class="input-wrap">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Masukkan kata sandi"
                            autocomplete="current-password"
                            required
                        >
                        <button
                            class="password-toggle"
                            type="button"
                            id="togglePassword"
                            aria-label="Tampilkan kata sandi"
                            aria-pressed="false"
                        >◎</button>
                    </div>
                </div>

                <div class="form-options">
                    <label class="remember">
                        <input type="checkbox" name="remember" value="1" checked>
                        Ingat saya di perangkat ini
                    </label>
                    <a class="forgot" href="lupa_password.php">Lupa kata sandi?</a>
                </div>

                <button class="login-button" type="submit">
                    <span>➜</span>
                    Masuk ke ruang kerja
                </button>
            </form>

            <p class="register-text">
                Belum memiliki akun?
                <a href="register.php">Daftar akun petugas</a>
            </p>

            <div class="security-note">
                <span class="security-icon">♧</span>
                <div>
                    <strong>Akses khusus petugas</strong>
                    Khusus pengelola dan resepsionis. Pendaftaran anggota gym
                    dilakukan melalui ruang kerja.
                </div>
            </div>
        </div>

        <footer class="right-footer">
            <a href="#" onclick="return false;">Kebijakan privasi</a>
            <a href="#" onclick="return false;">Syarat penggunaan</a>
            <a href="mailto:admin@fitdesk.id">Bantuan</a>
        </footer>
    </section>

</div>

<script>
const passwordInput = document.getElementById('password');
const togglePassword = document.getElementById('togglePassword');

togglePassword.addEventListener('click', function () {
    const tampil = passwordInput.type === 'password';

    passwordInput.type = tampil ? 'text' : 'password';
    togglePassword.textContent = tampil ? '◉' : '◎';

    togglePassword.setAttribute(
        'aria-label',
        tampil ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'
    );

    togglePassword.setAttribute(
        'aria-pressed',
        tampil ? 'true' : 'false'
    );
});
</script>
</body>
</html>
