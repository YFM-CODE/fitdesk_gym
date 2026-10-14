
<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (isset($_SESSION['user_id']) || isset($_SESSION['username'])) {
    header('Location: dashboard.php');
    exit;
}

date_default_timezone_set('Asia/Jakarta');

$error = '';
$nama = '';
$email = '';
$role = 'resepsionis';
$kodeUndangan = '';

/*
 * Ganti kode undangan ini sesuai kebutuhan.
 * Untuk penggunaan nyata, jangan membagikan kode admin
 * kepada orang yang tidak berwenang.
 */
$kodeUndanganValid = 'FTD-DAGO-2026';

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama = trim($_POST['nama_lengkap'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'resepsionis';
    $kodeUndangan = trim($_POST['kode_undangan'] ?? '');
    $password = $_POST['password'] ?? '';
    $konfirmasi = $_POST['konfirmasi_password'] ?? '';
    $setuju = isset($_POST['setuju']);

    if (
        $nama === '' ||
        $email === '' ||
        $kodeUndangan === '' ||
        $password === '' ||
        $konfirmasi === ''
    ) {
        $error = 'Semua kolom wajib diisi.';
    } elseif (mb_strlen($nama) > 100) {
        $error = 'Nama lengkap maksimal 100 karakter.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $error = 'Format email tidak valid.';
    } elseif (!in_array($role, ['admin', 'resepsionis'], true)) {
        $error = 'Peran yang dipilih tidak valid.';
    } elseif (!hash_equals($kodeUndanganValid, $kodeUndangan)) {
        $error = 'Kode undangan tidak valid.';
    } elseif (strlen($password) < 8) {
        $error = 'Kata sandi minimal 8 karakter.';
    } elseif ($password !== $konfirmasi) {
        $error = 'Konfirmasi kata sandi tidak cocok.';
    } elseif (!$setuju) {
        $error = 'Kamu harus menyetujui syarat penggunaan.';
    } else {
        try {
            $cek = $pdo->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                LIMIT 1
            ");
            $cek->execute([$email]);

            if ($cek->fetch()) {
                $error = 'Email sudah terdaftar. Silakan gunakan email lain.';
            } else {
                /*
                 * Username dibuat otomatis dari bagian email
                 * sebelum tanda @, karena login menggunakan username.
                 */
                $bagianEmail = strtolower(explode('@', $email)[0]);
                $usernameDasar = preg_replace('/[^a-z0-9_.-]/', '', $bagianEmail);

                if ($usernameDasar === '') {
                    $usernameDasar = 'petugas';
                }

                $username = substr($usernameDasar, 0, 80);
                $usernameAkhir = $username;
                $nomor = 1;

                while (true) {
                    $cekUsername = $pdo->prepare("
                        SELECT id FROM users WHERE username = ? LIMIT 1
                    ");
                    $cekUsername->execute([$usernameAkhir]);

                    if (!$cekUsername->fetch()) {
                        break;
                    }

                    $akhiran = (string)$nomor;
                    $usernameAkhir = substr(
                        $username,
                        0,
                        100 - strlen($akhiran)
                    ) . $akhiran;

                    $nomor++;

                    if ($nomor > 10000) {
                        throw new RuntimeException(
                            'Tidak dapat membuat nama pengguna. Silakan coba lagi.'
                        );
                    }
                }

                $hashPassword = password_hash($password, PASSWORD_DEFAULT);

                $simpan = $pdo->prepare("
                    INSERT INTO users
                        (nama_lengkap, email, username, password, role)
                    VALUES
                        (?, ?, ?, ?, ?)
                ");

                $simpan->execute([
                    $nama,
                    $email,
                    $usernameAkhir,
                    $hashPassword,
                    $role
                ]);

                /*
                 * Setelah berhasil, kembali ke login.
                 * Nama pengguna hasil registrasi ditampilkan
                 * agar bisa dipakai untuk login.
                 */
                $_SESSION['registrasi_sukses'] =
                    'Akun berhasil dibuat. Nama pengguna kamu: ' .
                    $usernameAkhir;

                header('Location: index.php');
                exit;
            }
        } catch (PDOException $ex) {
            error_log('Registrasi FitDesk: ' . $ex->getMessage());
            $error = 'Registrasi gagal karena masalah database. Periksa struktur tabel users.';
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Daftar Petugas | Fitdesk Fitness Club</title>

<style>
*{box-sizing:border-box}
:root{--dark:#14231b;--lime:#c5f578;--text:#17251d}
body{margin:0;min-height:100vh;font-family:Arial,Helvetica,sans-serif;color:var(--text);background:#fff}
a{color:inherit;text-decoration:none}
.page{min-height:100vh;display:grid;grid-template-columns:44.5% 55.5%}

/* PANEL KIRI */
.left-panel{min-height:100vh;display:flex;flex-direction:column;justify-content:space-between;padding:32px 6%;background:var(--dark);color:#fff}
.logo{display:flex;align-items:center;gap:10px;width:fit-content}
.logo-icon{width:37px;height:37px;display:grid;place-items:center;background:var(--lime);color:var(--dark);border-radius:9px;font-size:22px;font-weight:bold}
.logo-name{font-size:20px;font-weight:800;letter-spacing:-.7px}
.logo-caption{display:block;margin-top:3px;color:#c2d0c5;font-size:7px;letter-spacing:2px}
.hero{width:100%;max-width:560px;margin:30px auto}
.eyebrow{display:flex;align-items:center;gap:8px;margin-bottom:20px;color:var(--lime);font-size:10px;font-weight:bold;letter-spacing:1px;text-transform:uppercase}
.eyebrow:before{content:"";width:13px;height:2px;background:var(--lime)}
.hero h1{margin:0;font-size:clamp(29px,3vw,41px);line-height:1.15;letter-spacing:-1.3px;font-weight:800}
.hero-description{max-width:400px;margin:20px 0 24px;color:#a8b5ac;font-size:13px;line-height:1.8}
.gym-image{position:relative;width:100%;height:250px;overflow:hidden;border-radius:12px;background:#304638}
.gym-image img{width:100%;height:100%;display:block;object-fit:cover}
.location{position:absolute;left:14px;bottom:14px;padding:10px 13px;border-radius:8px;background:#15231de8;color:#fff;font-size:10px}
.features{display:flex;flex-wrap:wrap;gap:18px;margin-top:18px;color:#d0d9d2;font-size:10px}
.features span:before{content:"✓";margin-right:7px;color:var(--lime);font-weight:bold}
.left-footer{padding-top:18px;border-top:1px solid #344339;display:flex;justify-content:space-between;gap:12px;color:#a4b1a8;font-size:9px}

/* PANEL KANAN */
.right-panel{min-height:100vh;display:flex;flex-direction:column;padding:25px 9%;background:#fff}
.back-link{display:flex;align-items:center;gap:8px;color:#909991;font-size:11px}
.back-link span{font-size:17px;color:#4d9972}
.register-area{width:100%;max-width:430px;margin:auto;padding:22px 0}
.welcome-label{margin-bottom:12px;color:#386c48;font-size:10px;font-weight:bold;letter-spacing:.5px;text-transform:uppercase}
.register-area h2{margin:0 0 10px;font-size:29px;line-height:1.2;letter-spacing:-.8px}
.description{margin:0 0 20px;color:#929992;font-size:11px;line-height:1.7}
.alert{margin-bottom:15px;padding:11px 13px;border-radius:8px;font-size:11px;line-height:1.6}
.alert-error{background:#fff0ed;color:#a13e33;border:1px solid #f2d8d3}
.form-group{margin-bottom:13px}
.form-group label{display:block;margin-bottom:7px;color:#344239;font-size:11px;font-weight:bold}
.input-wrap{position:relative}
.input-wrap input,.input-wrap select{width:100%;height:41px;padding:0 13px;border:1px solid #e4e8e4;border-radius:8px;background:#fff;outline:none;color:#243329;font-size:11px}
.input-wrap input::placeholder{color:#a0a7a1}
.input-wrap input:focus,.input-wrap select:focus{border-color:#9fc66b;box-shadow:0 0 0 3px #c5f57830}
.input-wrap.has-icon input{padding-right:42px}
.input-icon{position:absolute;top:50%;right:13px;transform:translateY(-50%);color:#78847a;font-size:15px;pointer-events:none}
.password-toggle{position:absolute;top:50%;right:8px;transform:translateY(-50%);width:29px;height:29px;border:0;background:transparent;color:#78847a;cursor:pointer;font-size:15px}
.two-columns{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.branch-note{display:flex;align-items:center;gap:8px;margin-top:-3px;margin-bottom:13px;padding:10px;border-radius:8px;background:#f5f7f4;color:#79867b;font-size:10px}
.branch-note span{color:#42835b}
.hint{margin-top:6px;color:#969e97;font-size:10px;line-height:1.5}
.terms{display:flex;align-items:flex-start;gap:8px;margin:15px 0;color:#838c84;font-size:10px;line-height:1.6}
.terms input{width:14px;height:14px;margin:1px 0 0;accent-color:#b9ed68;flex-shrink:0}
.terms a{color:#386c48;font-weight:bold}
.register-button{width:100%;height:43px;display:flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:8px;background:var(--lime);color:#17251d;font-size:11px;font-weight:bold;cursor:pointer;transition:.2s}
.register-button:hover{background:#b6ec62;transform:translateY(-1px)}
.login-text{margin:17px 0 0;text-align:center;color:#929992;font-size:11px}
.login-text a{color:#356844;font-weight:bold}
.bottom-note{display:flex;justify-content:center;gap:7px;padding:12px 0 4px;color:#909991;font-size:10px;text-align:center}
.bottom-note span{color:#697c6c}
@media(min-width:1500px){.left-panel{padding-left:8%;padding-right:8%}.right-panel{padding-left:11%;padding-right:11%}}
@media(max-width:900px){.page{grid-template-columns:40% 60%}.left-panel{padding:25px 7%}.right-panel{padding:24px 6%}.hero h1{font-size:30px}.gym-image{height:210px}}
@media(max-width:650px){.page{display:block}.left-panel{min-height:auto;padding:22px}.hero{margin:30px auto 24px}.hero h1{font-size:30px}.gym-image{height:200px}.left-footer{margin-top:8px;font-size:8px}.right-panel{min-height:650px;padding:22px}.register-area{max-width:450px;padding:30px 0}.register-area h2{font-size:27px}}
@media(max-width:380px){.two-columns{grid-template-columns:1fr;gap:0}.left-footer{flex-direction:column}}
</style>
</head>

<body>
<div class="page">

    <section class="left-panel">
        <a class="logo" href="index.php">
            <span class="logo-icon">♧</span>
            <span>
                <span class="logo-name">Fitdesk</span>
                <span class="logo-caption">FITNESS CLUB</span>
            </span>
        </a>

        <div class="hero">
            <div class="eyebrow">Ruang kerja resepsionis</div>

            <h1>Tim yang terhubung.<br>Layanan lebih baik.</h1>

            <p class="hero-description">
                Kelola anggota, sambut kedatangan, dan pantau perkembangan gym
                dalam satu ruang kerja.
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
        <a class="back-link" href="index.php">
            <span>☆</span> ← Kembali ke halaman masuk
        </a>

        <div class="register-area">
            <div class="welcome-label">Bergabung dengan tim</div>
            <h2>Buat akun petugas</h2>

            <p class="description">
                Daftarkan akun pengelola atau resepsionis menggunakan
                kode undangan dari admin cabang.
            </p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error" role="alert">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="register.php">

                <div class="form-group">
                    <label for="nama_lengkap">Nama lengkap *</label>
                    <div class="input-wrap">
                        <input
                            type="text"
                            id="nama_lengkap"
                            name="nama_lengkap"
                            placeholder="Masukkan nama lengkap"
                            value="<?= h($nama) ?>"
                            maxlength="100"
                            autocomplete="name"
                            required
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email kerja *</label>
                    <div class="input-wrap has-icon">
                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="nama@fitdesk.id"
                            value="<?= h($email) ?>"
                            maxlength="254"
                            autocomplete="email"
                            required
                        >
                        <span class="input-icon">✉</span>
                    </div>
                </div>

                <div class="two-columns">
                    <div class="form-group">
                        <label for="role">Peran *</label>
                        <div class="input-wrap">
                            <select id="role" name="role" required>
                                <option value="resepsionis" <?= $role === 'resepsionis' ? 'selected' : '' ?>>
                                    Resepsionis
                                </option>
                                <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>
                                    Admin
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="kode_undangan">Kode undangan *</label>
                        <div class="input-wrap">
                            <input
                                type="text"
                                id="kode_undangan"
                                name="kode_undangan"
                                placeholder="Kode undangan"
                                value="<?= h($kodeUndangan) ?>"
                                maxlength="100"
                                required
                            >
                        </div>
                    </div>
                </div>

                <div class="branch-note">
                    <span>♧</span>
                    Cabang undangan: Fitdesk Fitness Club
                </div>

                <div class="form-group">
                    <label for="password">Kata sandi *</label>
                    <div class="input-wrap has-icon">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Buat kata sandi"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >
                        <button
                            class="password-toggle"
                            type="button"
                            data-target="password"
                            aria-label="Tampilkan kata sandi"
                        >◎</button>
                    </div>
                    <div class="hint">Minimal 8 karakter. Gunakan kombinasi huruf dan angka.</div>
                </div>

                <div class="form-group">
                    <label for="konfirmasi_password">Konfirmasi kata sandi *</label>
                    <div class="input-wrap has-icon">
                        <input
                            type="password"
                            id="konfirmasi_password"
                            name="konfirmasi_password"
                            placeholder="Ulangi kata sandi"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >
                        <button
                            class="password-toggle"
                            type="button"
                            data-target="konfirmasi_password"
                            aria-label="Tampilkan konfirmasi kata sandi"
                        >◎</button>
                    </div>
                </div>

                <label class="terms">
                    <input type="checkbox" name="setuju" value="1" required>
                    <span>
                        Saya menyetujui
                        <a href="#" onclick="return false;">Syarat Penggunaan</a>
                        dan
                        <a href="#" onclick="return false;">Kebijakan Privasi</a>
                        Fitdesk.
                    </span>
                </label>

                <button class="register-button" type="submit">
                    ♙ &nbsp; Buat akun petugas
                </button>
            </form>

            <p class="login-text">
                Sudah punya akun?
                <a href="index.php">Masuk di sini</a>
            </p>
        </div>

        <div class="bottom-note">
            <span>ⓘ</span>
            Form ini bukan untuk pendaftaran anggota gym.
        </div>
    </section>
</div>

<script>
document.querySelectorAll('.password-toggle').forEach(function(button) {
    button.addEventListener('click', function() {
        const input = document.getElementById(button.dataset.target);
        const tampil = input.type === 'password';

        input.type = tampil ? 'text' : 'password';
        button.textContent = tampil ? '◉' : '◎';
        button.setAttribute(
            'aria-label',
            tampil ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'
        );
    });
});

const form = document.querySelector('form');
form.addEventListener('submit', function(event) {
    const password = document.getElementById('password').value;
    const konfirmasi = document.getElementById('konfirmasi_password').value;

    if (password !== konfirmasi) {
        event.preventDefault();
        alert('Konfirmasi kata sandi tidak cocok.');
    }
});
</script>

</body>
</html>
