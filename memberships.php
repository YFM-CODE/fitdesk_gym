
<?php
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rupiah($value) {
    return 'Rp' . number_format((float)$value, 0, ',', '.');
}

function tanggalID($value) {
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April',
        'Mei', 'Juni', 'Juli', 'Agustus',
        'September', 'Oktober', 'November', 'Desember'
    ];

    $time = strtotime($value);
    return date('j', $time) . ' ' .
        $bulan[(int)date('n', $time)] . ' ' . date('Y', $time);
}

function kembali($pesan) {
    header('Location: memberships.php?pesan=' . urlencode($pesan));
    exit;
}

function tanggalBerakhir($tanggalMulai, $durasi) {
    $tanggal = new DateTime($tanggalMulai);
    $hariAwal = (int)$tanggal->format('d');

    $tanggal->modify('first day of this month');
    $tanggal->modify('+' . (int)$durasi . ' months');

    $hariTerakhir = (int)$tanggal->format('t');

    $tanggal->setDate(
        (int)$tanggal->format('Y'),
        (int)$tanggal->format('m'),
        min($hariAwal, $hariTerakhir)
    );

    $tanggal->modify('-1 day');

    return $tanggal->format('Y-m-d');
}

try {
    /*
    |--------------------------------------------------------------------------
    | MEMBUAT TABEL PAKET JIKA BELUM ADA
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS membership_packages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            package_name VARCHAR(50) NOT NULL,
            duration_months INT NOT NULL UNIQUE,
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            description VARCHAR(255) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    /*
    |--------------------------------------------------------------------------
    | MEMBUAT PAKET DEFAULT
    |--------------------------------------------------------------------------
    */

    $paketDefault = [
        ['1 bulan', 1, 250000, 'Awal yang tepat untuk rutinitas baru.'],
        ['3 bulan', 3, 675000, 'Jaga konsistensi, rasakan progresnya.'],
        ['6 bulan', 6, 1200000, 'Komitmen lebih panjang, nilai lebih baik.']
    ];

    foreach ($paketDefault as $paket) {
        $cek = $pdo->prepare(
            "SELECT id FROM membership_packages
             WHERE duration_months = ? LIMIT 1"
        );

        $cek->execute([$paket[1]]);

        if (!$cek->fetch()) {
            $insert = $pdo->prepare("
                INSERT INTO membership_packages
                    (package_name, duration_months, price, description, is_active)
                VALUES (?, ?, ?, ?, 1)
            ");

            $insert->execute($paket);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | TAMBAH PAKET
    |--------------------------------------------------------------------------
    */

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        ($_POST['aksi'] ?? '') === 'tambah_paket'
    ) {
        $nama = trim($_POST['package_name'] ?? '');
        $durasi = (int)($_POST['duration_months'] ?? 0);
        $harga = filter_var(
            $_POST['price'] ?? null,
            FILTER_VALIDATE_FLOAT
        );
        $deskripsi = trim($_POST['description'] ?? '');

        if (
            $nama === '' ||
            !in_array($durasi, [1, 3, 6], true) ||
            $harga === false ||
            $harga < 0
        ) {
            kembali('Data paket tidak valid.');
        }

        $cek = $pdo->prepare("
            SELECT COUNT(*)
            FROM membership_packages
            WHERE duration_months = ?
        ");
        $cek->execute([$durasi]);

        if ((int)$cek->fetchColumn() > 0) {
            kembali('Paket dengan durasi tersebut sudah tersedia.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO membership_packages
                (package_name, duration_months, price, description, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");

        $stmt->execute([$nama, $durasi, $harga, $deskripsi]);

        kembali('Paket berhasil ditambahkan.');
    }

    /*
    |--------------------------------------------------------------------------
    | UBAH PAKET
    |--------------------------------------------------------------------------
    */

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        ($_POST['aksi'] ?? '') === 'ubah_paket'
    ) {
        $id = (int)($_POST['id'] ?? 0);
        $nama = trim($_POST['package_name'] ?? '');
        $durasi = (int)($_POST['duration_months'] ?? 0);
        $harga = filter_var(
            $_POST['price'] ?? null,
            FILTER_VALIDATE_FLOAT
        );
        $deskripsi = trim($_POST['description'] ?? '');

        if (
            $id < 1 ||
            $nama === '' ||
            !in_array($durasi, [1, 3, 6], true) ||
            $harga === false ||
            $harga < 0
        ) {
            kembali('Data paket tidak valid.');
        }

        $cek = $pdo->prepare("
            SELECT COUNT(*)
            FROM membership_packages
            WHERE duration_months = ? AND id <> ?
        ");
        $cek->execute([$durasi, $id]);

        if ((int)$cek->fetchColumn() > 0) {
            kembali('Durasi paket tersebut sudah digunakan.');
        }

        $stmt = $pdo->prepare("
            UPDATE membership_packages
            SET package_name = ?,
                duration_months = ?,
                price = ?,
                description = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $nama,
            $durasi,
            $harga,
            $deskripsi,
            $id
        ]);

        kembali('Paket berhasil diperbarui.');
    }

    /*
    |--------------------------------------------------------------------------
    | ARSIPKAN / AKTIFKAN PAKET
    |--------------------------------------------------------------------------
    */

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        ($_POST['aksi'] ?? '') === 'arsip_paket'
    ) {
        $id = (int)($_POST['id'] ?? 0);

        $stmt = $pdo->prepare("
            UPDATE membership_packages
            SET is_active = IF(is_active = 1, 0, 1)
            WHERE id = ?
        ");

        $stmt->execute([$id]);

        kembali('Status paket berhasil diperbarui.');
    }

    /*
    |--------------------------------------------------------------------------
    | REGISTRASI ANGGOTA BARU + MEMBERSHIP
    |--------------------------------------------------------------------------
    | 1. Validasi data.
    | 2. Mulai transaksi.
    | 3. INSERT anggota ke tabel members.
    | 4. Ambil ID anggota yang baru dibuat.
    | 5. INSERT membership ke tabel memberships.
    | 6. COMMIT jika kedua INSERT berhasil.
    | 7. ROLLBACK jika terjadi kegagalan.
    |--------------------------------------------------------------------------
    */

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        ($_POST['aksi'] ?? '') === 'registrasi_anggota_membership'
    ) {
        $nama = trim($_POST['full_name'] ?? '');
        $telepon = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $packageId = (int)($_POST['package_id'] ?? 0);
        $startDate = $_POST['start_date'] ?? '';

        $date = DateTime::createFromFormat('!Y-m-d', $startDate);

        $validDate = $date &&
            $date->format('Y-m-d') === $startDate;

        if (
            $nama === '' ||
            mb_strlen($nama) > 100 ||
            $telepon === '' ||
            strlen($telepon) > 15 ||
            ($email !== '' &&
                (!filter_var($email, FILTER_VALIDATE_EMAIL) ||
                 strlen($email) > 100)) ||
            $packageId < 1 ||
            !$validDate
        ) {
            kembali('Periksa kembali data anggota, paket, dan tanggal.');
        }

        $cekPaket = $pdo->prepare("
            SELECT id, duration_months, package_name
            FROM membership_packages
            WHERE id = ? AND is_active = 1
            LIMIT 1
        ");

        $cekPaket->execute([$packageId]);
        $paketDipilih = $cekPaket->fetch(PDO::FETCH_ASSOC);

        if (!$paketDipilih) {
            kembali('Paket tidak ditemukan atau sudah diarsipkan.');
        }

        $durasi = (int)$paketDipilih['duration_months'];
        $endDate = tanggalBerakhir($startDate, $durasi);

        try {
            $pdo->beginTransaction();

            // LANGKAH 1: Buat kode anggota unik.
            do {
                $kodeAnggota = 'MB-' . date('ymd') . '-' .
                    strtoupper(bin2hex(random_bytes(3)));

                $cekKode = $pdo->prepare("
                    SELECT id FROM members
                    WHERE member_code = ?
                    LIMIT 1
                ");

                $cekKode->execute([$kodeAnggota]);

            } while ($cekKode->fetch(PDO::FETCH_ASSOC));

            // LANGKAH 2: Simpan anggota terlebih dahulu.
            $stmtAnggota = $pdo->prepare("
                INSERT INTO members
                    (member_code, full_name, phone, email)
                VALUES (?, ?, ?, ?)
            ");

            $stmtAnggota->execute([
                $kodeAnggota,
                $nama,
                $telepon,
                $email !== '' ? $email : null
            ]);

            // LANGKAH 3: Ambil ID anggota yang baru disimpan.
            $memberIdBaru = (int)$pdo->lastInsertId();

            // LANGKAH 4: Simpan membership untuk anggota tersebut.
            $stmtMembership = $pdo->prepare("
                INSERT INTO memberships
                    (member_id, package_duration_months,
                     start_date, end_date, is_active)
                VALUES (?, ?, ?, ?, 1)
            ");

            $stmtMembership->execute([
                $memberIdBaru,
                $durasi,
                $startDate,
                $endDate
            ]);

            // LANGKAH 5: Permanenkan kedua penyimpanan.
            $pdo->commit();

            kembali(
                'Registrasi berhasil! Kode anggota: ' .
                $kodeAnggota .
                '. Paket ' . $paketDipilih['package_name'] .
                '. Tanggal berakhir: ' . tanggalID($endDate) . '.'
            );

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Registrasi anggota dan membership gagal: ' .
                $ex->getMessage()
            );

            kembali(
                'Registrasi gagal. Tidak ada data yang disimpan. ' .
                'Silakan periksa struktur tabel database.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | REGISTRASI PAKET UNTUK ANGGOTA YANG SUDAH ADA
    |--------------------------------------------------------------------------
    */

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        ($_POST['aksi'] ?? '') === 'daftar_anggota'
    ) {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $packageId = (int)($_POST['package_id'] ?? 0);
        $startDate = $_POST['start_date'] ?? '';

        $date = DateTime::createFromFormat('!Y-m-d', $startDate);
        $validDate = $date &&
            $date->format('Y-m-d') === $startDate;

        if ($memberId < 1 || $packageId < 1 || !$validDate) {
            kembali('Pilih anggota, paket, dan tanggal mulai.');
        }

        $cekAnggota = $pdo->prepare("
            SELECT id FROM members WHERE id = ?
        ");
        $cekAnggota->execute([$memberId]);

        if (!$cekAnggota->fetch(PDO::FETCH_ASSOC)) {
            kembali('Anggota tidak ditemukan.');
        }

        $cekPaket = $pdo->prepare("
            SELECT duration_months
            FROM membership_packages
            WHERE id = ? AND is_active = 1
        ");
        $cekPaket->execute([$packageId]);
        $paket = $cekPaket->fetch(PDO::FETCH_ASSOC);

        if (!$paket) {
            kembali('Paket tidak tersedia.');
        }

        $durasi = (int)$paket['duration_months'];
        $endDate = tanggalBerakhir($startDate, $durasi);

        $stmt = $pdo->prepare("
            INSERT INTO memberships
                (member_id, package_duration_months,
                 start_date, end_date, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");

        $stmt->execute([
            $memberId,
            $durasi,
            $startDate,
            $endDate
        ]);

        kembali('Membership berhasil disimpan. Berakhir ' . tanggalID($endDate) . '.');
    }

    /*
    |--------------------------------------------------------------------------
    | AMBIL DATA UNTUK TAMPILAN
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->query("
        SELECT *
        FROM membership_packages
        ORDER BY duration_months ASC
    ");
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT id, member_code, full_name
        FROM members
        ORDER BY full_name ASC
    ");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalAnggota = (int)$pdo->query("
        SELECT COUNT(*) FROM members
    ")->fetchColumn();

    $totalMembership = (int)$pdo->query("
        SELECT COUNT(*) FROM memberships
    ")->fetchColumn();

    $totalAktif = (int)$pdo->query("
        SELECT COUNT(DISTINCT member_id)
        FROM memberships
        WHERE is_active = 1
          AND start_date <= CURDATE()
          AND end_date >= CURDATE()
    ")->fetchColumn();

    $totalArsip = (int)$pdo->query("
        SELECT COUNT(*)
        FROM membership_packages
        WHERE is_active = 0
    ")->fetchColumn();

    $jumlahPerDurasi = [];

    $stmt = $pdo->query("
        SELECT package_duration_months,
               COUNT(DISTINCT member_id) AS total
        FROM memberships
        WHERE is_active = 1
          AND start_date <= CURDATE()
          AND end_date >= CURDATE()
        GROUP BY package_duration_months
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $jumlahPerDurasi[(int)$row['package_duration_months']] =
            (int)$row['total'];
    }

} catch (PDOException $ex) {
    error_log('Kesalahan memberships.php: ' . $ex->getMessage());
    die(
        '<h2>Terjadi kesalahan database.</h2>' .
        '<p>Pastikan database aktif dan struktur tabel members serta memberships benar.</p>'
    );
}

$editPackage = null;

if (isset($_GET['edit'])) {
    foreach ($packages as $p) {
        if ((int)$p['id'] === (int)$_GET['edit']) {
            $editPackage = $p;
            break;
        }
    }
}

$pesan = $_GET['pesan'] ?? '';
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Paket Membership | FitDesk Gym</title>

<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f6f4;color:#202923;font-family:Arial,Helvetica,sans-serif;font-size:13px}
a{text-decoration:none;color:inherit}
button,input,select,textarea{font:inherit}
.layout{display:flex;min-height:100vh}
.sidebar{width:235px;flex-shrink:0;padding:26px 15px;background:#182720;color:white}
.brand{font-size:23px;font-weight:800;padding:0 12px 30px}
.brand span{color:#c5f578}
.menu-label{margin:18px 12px 10px;color:#a4b1a8;font-size:10px;text-transform:uppercase;letter-spacing:1px}
.menu a{display:block;margin:5px 0;padding:13px 12px;border-radius:9px;color:#e0e7e2;font-size:13px}
.menu a:hover,.menu a.active{background:#c5f578;color:#17251f;font-weight:bold}
.main{flex:1;min-width:0}
.topbar{min-height:58px;padding:14px 28px;background:white;border-bottom:1px solid #e4e9e5;display:flex;align-items:center;justify-content:space-between;gap:12px;color:#737d75;font-size:11px}
.content{max-width:1500px;margin:auto;padding:27px 28px 35px}
.heading{display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap}
h1{margin:0 0 8px;font-size:25px}
.subtitle{margin:0;color:#818b83;font-size:12px}
.btn{display:inline-flex;justify-content:center;align-items:center;gap:7px;border:0;border-radius:8px;padding:11px 15px;background:#c5f578;color:#203020;font-weight:bold;font-size:12px;cursor:pointer}
.btn:hover{filter:brightness(.96)}
.btn-outline{background:white;border:1px solid #e1e6e1;color:#26342b}
.btn:disabled{opacity:.5;cursor:not-allowed}
.summary{display:flex;align-items:center;flex-wrap:wrap;gap:9px;margin:24px 0 18px}
.pill{padding:9px 12px;background:white;border:1px solid #e6ebe6;border-radius:8px;color:#657067;font-size:11px}
.pill.active{background:#19261f;color:white;border-color:#19261f}
.muted,.footer-note{color:#858e87;font-size:10px}
.right-note{margin-left:auto}
.packages{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:17px}
.package{display:flex;flex-direction:column;min-width:0;padding:20px;border:1px solid #e4e9e4;border-radius:13px;background:white}
.package.featured{background:#f4fae9;border-color:#b9d995}
.package.archived{opacity:.7}
.package-top{display:flex;justify-content:space-between;align-items:center}
.package-icon{display:grid;place-items:center;width:39px;height:39px;border-radius:10px;background:#f0f4ef;font-size:21px}
.featured .package-icon{background:#c5f578}
.status{padding:6px 9px;border-radius:20px;background:#eaf7ee;color:#277448;font-size:10px}
.status.off{background:#fff0ee;color:#a64b41}
.package h2{margin:18px 0 7px;font-size:19px}
.description{min-height:30px;color:#818a83;font-size:11px;line-height:1.5}
.price{margin:13px 0 5px;font-size:28px;font-weight:800;letter-spacing:-.5px;overflow-wrap:anywhere}
.price-caption{color:#858e87;font-size:10px}
.saving{min-height:25px;margin-top:8px;color:#286944;font-size:10px}
.package hr{width:100%;margin:12px 0;border:0;border-top:1px solid #e8ede8}
.features{margin:0 0 17px;padding:0;list-style:none;color:#39473d;font-size:11px;line-height:2.2}
.features li:before{content:"✓";margin-right:9px;color:#32804c;font-weight:bold}
.member-count{display:flex;justify-content:space-between;gap:8px;margin-top:auto;margin-bottom:16px;color:#858e87;font-size:11px}
.member-count strong{color:#253128}
.package-actions{display:flex;gap:8px}
.package-actions .edit{flex:1}
.package-actions form{margin:0}
.panel{padding:21px;border:1px solid #e5eae5;border-radius:13px;background:white}
.panel h3{margin:0 0 12px;font-size:15px}
.panel-intro{margin-bottom:20px;color:#858e87;font-size:11px;line-height:1.6}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}
.field label{display:block;margin-bottom:8px;font-size:11px;font-weight:bold}
.field input,.field select,.field textarea{width:100%;padding:12px;border:1px solid #e0e6e0;border-radius:8px;background:white;color:#39473d;font-size:12px}
.field input:focus,.field select:focus,.field textarea:focus{outline:2px solid #d4f3ac;border-color:#94bf6a}
.result{margin-top:15px;padding:17px;border-radius:10px;background:#f5f7f4}
.result small{color:#7d887f;font-size:11px}
.result-bottom{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.result strong{display:block;margin-top:5px;font-size:21px}
.result-status{padding:7px 10px;border-radius:20px;background:#e4f6e9;color:#267044;font-size:10px}
.lower-grid{display:grid;grid-template-columns:1.25fr .9fr;gap:17px;margin-top:18px}
.rules{display:grid;gap:18px}
.rule{display:flex;gap:12px;font-size:11px;line-height:1.6}
.rule-icon{color:#26734a;font-size:17px}
.rule strong{display:block;margin-bottom:3px}
.rule p{margin:0;color:#838c85}
.alert{margin:18px 0;padding:12px 15px;border-radius:9px;background:#eaf8ed;color:#25633d;font-size:12px}
.footer-note{margin-top:18px}
.overlay{position:fixed;inset:0;z-index:10;display:flex;align-items:center;justify-content:center;padding:20px;background:#14201988}
.modal{width:100%;max-width:460px;max-height:90vh;overflow-y:auto;padding:25px;border-radius:14px;background:white;box-shadow:0 15px 60px #0002}
.modal h2{margin:0 0 18px;font-size:20px}
.modal .field{margin-bottom:14px}
.modal-actions{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:9px;margin-top:20px}
@media(max-width:1000px){.sidebar{width:190px}.packages{grid-template-columns:repeat(2,minmax(0,1fr))}.lower-grid{grid-template-columns:1fr}}
@media(max-width:700px){.layout{display:block}.sidebar{width:100%;padding:15px}.brand{padding:0 10px 12px}.menu{display:flex;gap:5px;overflow-x:auto}.menu-label{display:none}.menu a{white-space:nowrap;padding:10px}.topbar{padding:14px 15px}.content{padding:20px 14px}.packages{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}.right-note{margin-left:0}}
</style>
</head>

<body>
<div class="layout">

<aside class="sidebar">
    <div class="brand">FitDesk<span> Gym.</span></div>
    <div class="menu-label">Menu utama</div>
    <nav class="menu">
        <a href="dashboard.php">▦ Dashboard</a>
        <a href="anggota.php">♙ Data Anggota</a>
        <a class="active" href="memberships.php">♧ Paket Membership</a>
        <a href="laporan.php">▤ Laporan</a>
    </nav>
    <div class="menu-label">Akun</div>
    <nav class="menu">
        <a href="logout.php">↪ Keluar</a>
    </nav>
</aside>

<main class="main">
<header class="topbar">
    <div>Operasional &nbsp; › &nbsp; <strong>Paket membership</strong></div>
    <div><?= e(tanggalID(date('Y-m-d'))) ?></div>
</header>

<div class="content">

    <div class="heading">
        <div>
            <h1>Paket membership</h1>
            <p class="subtitle">Atur pilihan durasi dan harga membership untuk anggota FitDesk.</p>
        </div>
        <button class="btn" type="button" onclick="bukaModal()">＋ Tambah paket</button>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert"><?= e($pesan) ?></div>
    <?php endif; ?>

    <div class="summary">
        <span class="pill active">
            Paket aktif <?= count(array_filter($packages, fn($p) => (int)$p['is_active'] === 1)) ?>
        </span>
        <span class="pill">Diarsipkan <?= $totalArsip ?></span>
        <span class="muted right-note">
            Harga berlaku per <?= e(tanggalID(date('Y-m-d'))) ?> · IDR
        </span>
    </div>

    <!-- KARTU PAKET -->
    <section class="packages">
    <?php foreach ($packages as $p): ?>
        <?php
        $durasi = (int)$p['duration_months'];
        $aktif = (int)$p['is_active'] === 1;
        $hemat = $durasi === 3 ? 75000 : ($durasi === 6 ? 300000 : 0);
        $persen = $durasi === 3 ? 10 : ($durasi === 6 ? 20 : 0);
        $jumlahAnggota = $jumlahPerDurasi[$durasi] ?? 0;
        ?>
        <article class="package <?= $durasi === 3 ? 'featured' : '' ?> <?= !$aktif ? 'archived' : '' ?>">
            <div class="package-top">
                <div class="package-icon">♧</div>
                <span class="status <?= !$aktif ? 'off' : '' ?>">
                    <?= $aktif ? '◉ Aktif' : 'Diarsipkan' ?>
                </span>
            </div>

            <h2><?= e($p['package_name']) ?></h2>
            <div class="description"><?= e($p['description'] ?: 'Pilihan membership FitDesk Gym.') ?></div>
            <div class="price"><?= rupiah($p['price']) ?></div>
            <div class="price-caption">
                Setara <?= rupiah((float)$p['price'] / max(1, $durasi)) ?> / bulan
            </div>
            <div class="saving">
                <?php if ($hemat > 0): ?>
                    Hemat <?= rupiah($hemat) ?> · <?= $persen ?>%
                <?php else: ?>
                    Fleksibel setiap bulan
                <?php endif; ?>
            </div>

            <hr>
            <ul class="features">
                <li>Akses seluruh area latihan</li>
                <li>Check-in selama membership aktif</li>
            </ul>

            <div class="member-count">
                <span>Anggota aktif saat ini</span>
                <strong><?= $jumlahAnggota ?> anggota</strong>
            </div>

            <div class="package-actions">
                <a class="btn btn-outline edit" href="memberships.php?edit=<?= (int)$p['id'] ?>">✎ Ubah paket</a>
                <form method="post" onsubmit="return confirm('Ubah status paket ini?')">
                    <input type="hidden" name="aksi" value="arsip_paket">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button class="btn btn-outline" type="submit" title="Arsipkan atau aktifkan">•••</button>
                </form>
            </div>
        </article>
    <?php endforeach; ?>
    </section>

    <!-- FORM ANGGOTA BARU DAN MEMBERSHIP -->
    <section class="panel" style="margin-top:18px">
        <h3>＋ Registrasi anggota baru</h3>
        <p class="panel-intro">
            Data anggota disimpan terlebih dahulu ke tabel members,
            kemudian paket membership disimpan ke tabel memberships.
        </p>

        <form method="post">
            <input type="hidden" name="aksi" value="registrasi_anggota_membership">

            <div class="form-grid">
                <div class="field">
                    <label>Nama lengkap</label>
                    <input type="text" name="full_name" maxlength="100"
                           placeholder="Masukkan nama lengkap" required>
                </div>

                <div class="field">
                    <label>Nomor telepon</label>
                    <input type="text" name="phone" maxlength="15"
                           placeholder="Contoh: 081234567890" required>
                </div>

                <div class="field">
                    <label>Email (opsional)</label>
                    <input type="email" name="email" maxlength="100"
                           placeholder="nama@email.com">
                </div>

                <div class="field">
                    <label>Paket membership</label>
                    <select name="package_id" required>
                        <option value="">-- Pilih paket --</option>
                        <?php foreach ($packages as $p): ?>
                            <?php if ((int)$p['is_active'] === 1): ?>
                                <option value="<?= (int)$p['id'] ?>">
                                    <?= e($p['package_name']) ?> -
                                    <?= e(rupiah($p['price'])) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Tanggal mulai membership</label>
                    <input type="date" name="start_date"
                           value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;margin-top:18px">
                <button type="submit" class="btn">＋ Daftarkan anggota</button>
            </div>
        </form>
    </section>

    <!-- FORM MEMBERSHIP UNTUK ANGGOTA YANG SUDAH TERDAFTAR -->
    <section class="panel" style="margin-top:18px">
        <h3>♙ &nbsp; Tambah membership anggota terdaftar</h3>
        <p class="panel-intro">
            Gunakan form ini untuk mendaftarkan paket bagi anggota yang sudah ada.
        </p>

        <form method="post" id="formMembership">
            <input type="hidden" name="aksi" value="daftar_anggota">

            <div class="form-grid">
                <div class="field">
                    <label for="member_id">Pilih anggota</label>
                    <select name="member_id" id="member_id" required>
                        <option value="">-- Pilih anggota --</option>
                        <?php foreach ($members as $m): ?>
                            <option value="<?= (int)$m['id'] ?>">
                                <?= e($m['member_code']) ?> - <?= e($m['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="package_id">Pilih paket membership</label>
                    <select name="package_id" id="package_id" required onchange="perbaruiForm()">
                        <option value="">-- Pilih paket --</option>
                        <?php foreach ($packages as $p): ?>
                            <?php if ((int)$p['is_active'] === 1): ?>
                                <option value="<?= (int)$p['id'] ?>"
                                    data-durasi="<?= (int)$p['duration_months'] ?>"
                                    data-harga="<?= e($p['price']) ?>">
                                    <?= e($p['package_name']) ?> - <?= e(rupiah($p['price'])) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="start_date">Tanggal mulai membership</label>
                    <input type="date" name="start_date" id="start_date"
                           value="<?= date('Y-m-d') ?>" required onchange="perbaruiForm()">
                </div>

                <div class="field">
                    <label for="hargaForm">Harga paket</label>
                    <input type="text" id="hargaForm" readonly value="Pilih paket terlebih dahulu">
                </div>
            </div>

            <div class="result">
                <small>Tanggal berakhir otomatis</small>
                <div class="result-bottom">
                    <strong id="akhirForm">Pilih paket dan tanggal mulai</strong>
                    <span class="result-status">◉ Masa aktif otomatis</span>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;margin-top:18px">
                <button type="submit" class="btn" <?= !$members ? 'disabled' : '' ?>>
                    ＋ Simpan membership
                </button>
            </div>
        </form>
    </section>

    <!-- SIMULASI MASA AKTIF DAN KETENTUAN -->
    <section class="lower-grid">
        <div class="panel">
            <h3>▦ &nbsp; Simulasi masa aktif</h3>
            <p class="panel-intro">Pratinjau tanggal berakhir sebelum registrasi atau perpanjangan anggota.</p>

            <div class="form-grid">
                <div class="field">
                    <label for="simPackage">Paket membership</label>
                    <select id="simPackage" onchange="hitungSimulasi()">
                        <?php foreach ($packages as $p): ?>
                            <?php if ((int)$p['is_active'] === 1): ?>
                                <option value="<?= (int)$p['duration_months'] ?>">
                                    <?= e($p['package_name']) ?> • <?= e(rupiah($p['price'])) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="simDate">Tanggal mulai</label>
                    <input type="date" id="simDate" value="<?= date('Y-m-d') ?>" onchange="hitungSimulasi()">
                </div>
            </div>

            <div class="result">
                <small>Tanggal berakhir otomatis</small>
                <div class="result-bottom">
                    <strong id="tanggalAkhir">-</strong>
                    <span class="result-status">◉ Aktif hingga 23.59</span>
                </div>
            </div>

            <p class="footer-note">Tanggal akhir dihitung berdasarkan kalender.</p>
        </div>

        <div class="panel">
            <h3>☷ &nbsp; Ketentuan pengelolaan</h3>

            <div class="rules">
                <div class="rule">
                    <div class="rule-icon">✎</div>
                    <div>
                        <strong>Perubahan harga</strong>
                        <p>Harga baru berlaku untuk transaksi berikutnya. Membership yang sudah dibeli tidak berubah.</p>
                    </div>
                </div>

                <div class="rule">
                    <div class="rule-icon">▣</div>
                    <div>
                        <strong>Arsipkan paket</strong>
                        <p>Paket yang diarsipkan tidak tersedia untuk registrasi baru. Aktifkan kembali melalui menu yang sama.</p>
                    </div>
                </div>

                <div class="rule">
                    <div class="rule-icon">♧</div>
                    <div>
                        <strong>Membership tetap aman</strong>
                        <p>Mengarsipkan paket tidak menghapus riwayat atau masa aktif anggota.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <p class="footer-note">
        ⓘ <?= $totalAktif ?> anggota memiliki membership aktif dari
        <?= $totalAnggota ?> anggota terdaftar.
        Total riwayat membership: <?= $totalMembership ?>.
    </p>

</div>
</main>
</div>

<!-- MODAL EDIT PAKET -->
<?php if ($editPackage): ?>
<div class="overlay">
    <div class="modal">
        <h2>Ubah paket membership</h2>

        <form method="post">
            <input type="hidden" name="aksi" value="ubah_paket">
            <input type="hidden" name="id" value="<?= (int)$editPackage['id'] ?>">

            <div class="field">
                <label>Nama paket</label>
                <input name="package_name" maxlength="50" required value="<?= e($editPackage['package_name']) ?>">
            </div>

            <div class="field">
                <label>Durasi paket</label>
                <select name="duration_months" required>
                    <?php foreach ([1, 3, 6] as $d): ?>
                        <option value="<?= $d ?>" <?= (int)$editPackage['duration_months'] === $d ? 'selected' : '' ?>>
                            <?= $d ?> bulan
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Harga (Rp)</label>
                <input type="number" name="price" min="0" step="1000" required value="<?= e($editPackage['price']) ?>">
            </div>

            <div class="field">
                <label>Deskripsi</label>
                <textarea name="description" rows="3" maxlength="255"><?= e($editPackage['description']) ?></textarea>
            </div>

            <div class="modal-actions">
                <a href="memberships.php" class="btn btn-outline">Batal</a>
                <button class="btn" type="submit">Simpan perubahan</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- MODAL TAMBAH PAKET -->
<div class="overlay" id="addOverlay" style="display:none">
    <div class="modal">
        <h2>Tambah paket membership</h2>

        <form method="post">
            <input type="hidden" name="aksi" value="tambah_paket">

            <div class="field">
                <label>Nama paket</label>
                <input name="package_name" maxlength="50" required placeholder="Contoh: 1 bulan">
            </div>

            <div class="field">
                <label>Durasi paket</label>
                <select name="duration_months" required>
                    <?php
                    $durasiAda = array_map(
                        'intval',
                        array_column($packages, 'duration_months')
                    );
                    $jumlahPilihan = 0;

                    foreach ([1, 3, 6] as $d):
                        if (!in_array($d, $durasiAda, true)):
                            $jumlahPilihan++;
                    ?>
                        <option value="<?= $d ?>"><?= $d ?> bulan</option>
                    <?php
                        endif;
                    endforeach;
                    ?>
                </select>

                <?php if ($jumlahPilihan === 0): ?>
                    <p class="footer-note">Semua durasi paket sudah tersedia. Ubah paket yang ada untuk mengganti harga.</p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label>Harga (Rp)</label>
                <input type="number" name="price" min="0" step="1000" required placeholder="250000">
            </div>

            <div class="field">
                <label>Deskripsi</label>
                <textarea name="description" rows="3" maxlength="255" placeholder="Deskripsi singkat paket"></textarea>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="tutupModal()">Batal</button>
                <button type="submit" class="btn" <?= $jumlahPilihan === 0 ? 'disabled' : '' ?>>Simpan paket</button>
            </div>
        </form>
    </div>
</div>

<script>
function formatTanggal(date) {
    return new Intl.DateTimeFormat('id-ID', {
        day: 'numeric',
        month: 'long',
        year: 'numeric'
    }).format(date);
}

function hitungTanggal(mulai, durasi) {
    if (!mulai || !durasi) return null;

    const bagian = mulai.split('-').map(Number);
    const tanggal = new Date(bagian[0], bagian[1] - 1, bagian[2]);
    const hariAwal = tanggal.getDate();

    tanggal.setDate(1);
    tanggal.setMonth(tanggal.getMonth() + Number(durasi));

    const hariTerakhir = new Date(
        tanggal.getFullYear(),
        tanggal.getMonth() + 1,
        0
    ).getDate();

    tanggal.setDate(Math.min(hariAwal, hariTerakhir));
    tanggal.setDate(tanggal.getDate() - 1);

    return tanggal;
}

function perbaruiForm() {
    const paket = document.getElementById('package_id');
    const tanggal = document.getElementById('start_date');
    const harga = document.getElementById('hargaForm');
    const akhir = document.getElementById('akhirForm');

    if (!paket || !tanggal || !harga || !akhir) return;

    const option = paket.options[paket.selectedIndex];

    if (!option || !option.value || !tanggal.value) {
        harga.value = 'Pilih paket terlebih dahulu';
        akhir.textContent = 'Pilih paket dan tanggal mulai';
        return;
    }

    harga.value = new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0
    }).format(Number(option.dataset.harga));

    const hasil = hitungTanggal(
        tanggal.value,
        Number(option.dataset.durasi)
    );

    akhir.textContent = hasil ? formatTanggal(hasil) : '-';
}

function hitungSimulasi() {
    const durasi = document.getElementById('simPackage').value;
    const mulai = document.getElementById('simDate').value;
    const hasil = hitungTanggal(mulai, durasi);

    document.getElementById('tanggalAkhir').textContent =
        hasil ? formatTanggal(hasil) : '-';
}

function bukaModal() {
    document.getElementById('addOverlay').style.display = 'flex';
}

function tutupModal() {
    document.getElementById('addOverlay').style.display = 'none';
}

document.getElementById('addOverlay').addEventListener('click', function(event) {
    if (event.target === this) tutupModal();
});

perbaruiForm();
hitungSimulasi();
</script>

</body>
</html>