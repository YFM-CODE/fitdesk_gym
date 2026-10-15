
<?php
session_start();

if (!isset($_SESSION['user_id']) && !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

date_default_timezone_set('Asia/Jakarta');

function e($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rupiah($angka)
{
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}

function tanggalID($value)
{
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April',
        'Mei', 'Juni', 'Juli', 'Agustus',
        'September', 'Oktober', 'November', 'Desember'
    ];

    if (!$value) {
        return '-';
    }

    $time = strtotime($value);

    return date('j', $time) . ' ' .
        $bulan[(int)date('n', $time)] . ' ' .
        date('Y', $time);
}

$today = date('Y-m-d');
$pesan = '';
$jenisPesan = 'success';
$editPaket = null;

/*
|--------------------------------------------------------------------------
| TAMBAH PAKET
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    try {
        if ($aksi === 'tambah' || $aksi === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $nama = trim($_POST['package_name'] ?? '');
            $durasi = (int)($_POST['duration_months'] ?? 0);
            $harga = (float)($_POST['price'] ?? 0);
            $deskripsi = trim($_POST['description'] ?? '');
            $aktif = isset($_POST['is_active']) ? 1 : 0;

            if (
                $nama === '' ||
                $durasi < 1 ||
                $durasi > 120 ||
                $harga <= 0
            ) {
                throw new RuntimeException(
                    'Nama paket, durasi, dan harga wajib diisi dengan benar.'
                );
            }

            if ($aksi === 'tambah') {
                $stmt = $pdo->prepare("
                    INSERT INTO membership_packages
                        (package_name, duration_months, price, description, is_active)
                    VALUES (?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $nama,
                    $durasi,
                    $harga,
                    $deskripsi !== '' ? $deskripsi : null,
                    $aktif
                ]);

                header('Location: memberships.php?pesan=tambah');
                exit;
            }

            if ($id < 1) {
                throw new RuntimeException('ID paket tidak valid.');
            }

            /*
             * Durasi paket tidak diubah apabila sudah dipakai
             * oleh membership agar riwayat tetap konsisten.
             */
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM memberships
                WHERE package_duration_months = ?
            ");
            $stmt->execute([$durasi]);
            $jumlahDurasi = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT duration_months
                FROM membership_packages
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            $durasiLama = $stmt->fetchColumn();

            if ($durasiLama === false) {
                throw new RuntimeException('Paket tidak ditemukan.');
            }

            if ((int)$durasiLama !== $durasi && $jumlahDurasi > 0) {
                throw new RuntimeException(
                    'Durasi tidak dapat diubah karena sudah digunakan membership.'
                );
            }

            $stmt = $pdo->prepare("
                UPDATE membership_packages
                SET package_name = ?,
                    duration_months = ?,
                    price = ?,
                    description = ?,
                    is_active = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $nama,
                $durasi,
                $harga,
                $deskripsi !== '' ? $deskripsi : null,
                $aktif,
                $id
            ]);

            header('Location: memberships.php?pesan=edit');
            exit;
        }

        /*
         * HAPUS PAKET
         */
        if ($aksi === 'hapus') {
            $id = (int)($_POST['id'] ?? 0);

            $stmt = $pdo->prepare("
                SELECT duration_months
                FROM membership_packages
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            $durasi = $stmt->fetchColumn();

            if ($durasi === false) {
                throw new RuntimeException('Paket tidak ditemukan.');
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM memberships
                WHERE package_duration_months = ?
            ");
            $stmt->execute([(int)$durasi]);

            if ((int)$stmt->fetchColumn() > 0) {
                throw new RuntimeException(
                    'Paket tidak dapat dihapus karena sudah digunakan membership. Nonaktifkan paket jika tidak ingin digunakan lagi.'
                );
            }

            $stmt = $pdo->prepare("
                DELETE FROM membership_packages
                WHERE id = ?
            ");
            $stmt->execute([$id]);

            header('Location: memberships.php?pesan=hapus');
            exit;
        }

    } catch (Throwable $ex) {
        error_log('Membership FitDesk: ' . $ex->getMessage());

        $pesan = $ex instanceof RuntimeException
            ? $ex->getMessage()
            : 'Proses gagal. Periksa kembali data dan struktur database.';

        $jenisPesan = 'error';
    }
}

/*
|--------------------------------------------------------------------------
| PESAN SETELAH PROSES
|--------------------------------------------------------------------------
*/
if (isset($_GET['pesan'])) {
    $daftarPesan = [
        'tambah' => 'Paket membership berhasil ditambahkan.',
        'edit' => 'Paket membership berhasil diperbarui.',
        'hapus' => 'Paket membership berhasil dihapus.'
    ];

    if (isset($daftarPesan[$_GET['pesan']])) {
        $pesan = $daftarPesan[$_GET['pesan']];
        $jenisPesan = 'success';
    }
}

/*
|--------------------------------------------------------------------------
| AMBIL DATA UNTUK EDIT
|--------------------------------------------------------------------------
*/
if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM membership_packages
        WHERE id = ?
    ");
    $stmt->execute([(int)$_GET['edit']]);
    $editPaket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$editPaket) {
        $pesan = 'Paket yang akan diedit tidak ditemukan.';
        $jenisPesan = 'error';
    }
}

/*
|--------------------------------------------------------------------------
| AMBIL DATA PAKET
|--------------------------------------------------------------------------
*/
try {
    $stmt = $pdo->query("
        SELECT
            mp.*,
            (
                SELECT COUNT(*)
                FROM memberships ms
                WHERE ms.package_duration_months = mp.duration_months
            ) AS jumlah_membership
        FROM membership_packages mp
        ORDER BY mp.duration_months ASC, mp.id ASC
    ");

    $paketList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalPaket = count($paketList);
    $totalAktif = 0;
    $totalNonaktif = 0;

    foreach ($paketList as $paket) {
        if ((int)$paket['is_active'] === 1) {
            $totalAktif++;
        } else {
            $totalNonaktif++;
        }
    }
} catch (PDOException $ex) {
    error_log('Data paket FitDesk: ' . $ex->getMessage());

    die(
        '<h2>Terjadi kesalahan database.</h2>
        <p>Pastikan tabel membership_packages dan memberships sudah tersedia.</p>'
    );
}

$namaPengguna = $_SESSION['nama_lengkap']
    ?? $_SESSION['username']
    ?? 'Pengguna';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paket Membership | FitDesk Gym</title>

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
            max-width: 1500px;
            margin: auto;
            padding: 27px 28px 35px;
        }

        .heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
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

        .welcome {
            padding: 23px 25px;
            border-radius: 14px;
            background: #1c3026;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            overflow: hidden;
            position: relative;
            margin-bottom: 18px;
        }

        .welcome:after {
            content: 'FIT';
            font-size: 100px;
            font-weight: 900;
            color: #ffffff0b;
            position: absolute;
            right: 20px;
            top: -18px;
            pointer-events: none;
        }

        .welcome h2 {
            margin: 0 0 8px;
            font-size: 21px;
        }

        .welcome p {
            margin: 0;
            color: #d0ddd4;
            line-height: 1.6;
        }

        .date {
            z-index: 1;
            background: #c5f578;
            color: #1d2a20;
            padding: 10px 13px;
            border-radius: 9px;
            font-size: 11px;
            font-weight: bold;
            white-space: nowrap;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin: 18px 0;
        }

        .stat {
            padding: 19px;
            border: 1px solid #e5eae5;
            border-radius: 12px;
            background: #fff;
            min-width: 0;
            transition: .2s;
        }

        .stat:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 22px #1827200b;
        }

        .stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #758078;
            font-size: 11px;
            gap: 8px;
        }

        .stat-icon {
            display: grid;
            place-items: center;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #eff6e7;
            color: #315a37;
            font-size: 18px;
        }

        .stat strong {
            display: block;
            margin-top: 13px;
            font-size: 27px;
        }

        .stat small {
            display: block;
            margin-top: 7px;
            color: #8b938d;
            font-size: 10px;
        }

        .panel {
            padding: 21px;
            border: 1px solid #e5eae5;
            border-radius: 13px;
            background: #fff;
            min-width: 0;
            margin-bottom: 17px;
        }

        .panel-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .panel h3 {
            margin: 0 0 6px;
            font-size: 15px;
        }

        .panel p {
            margin: 0;
            color: #858e87;
            font-size: 11px;
            line-height: 1.5;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 11px;
            font-weight: bold;
            color: #435047;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 12px 13px;
            border: 1px solid #dfe6df;
            border-radius: 9px;
            outline: none;
            background: #fff;
            color: #26342b;
            font-family: inherit;
            font-size: 12px;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #8dbd55;
            box-shadow: 0 0 0 3px #c5f57833;
        }

        textarea {
            resize: vertical;
            min-height: 85px;
        }

        .checkbox {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 10px 0;
        }

        .checkbox input {
            width: auto;
            accent-color: #5c985e;
        }

        .checkbox label {
            cursor: pointer;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border: 0;
            border-radius: 9px;
            padding: 12px 17px;
            background: #c5f578;
            color: #1d2a20;
            font-weight: bold;
            font-family: inherit;
            font-size: 12px;
            cursor: pointer;
            transition: .2s;
        }

        .btn:hover {
            background: #b3e962;
            transform: translateY(-1px);
        }

        .btn-dark {
            background: #1c3026;
            color: #fff;
        }

        .btn-dark:hover {
            background: #294535;
        }

        .btn-light {
            background: #f0f3ef;
            color: #344139;
        }

        .btn-danger {
            background: #fff0ed;
            color: #a44a3f;
        }

        .btn-small {
            padding: 8px 10px;
            font-size: 10px;
        }

        .alert {
            padding: 13px 15px;
            margin-bottom: 17px;
            border-radius: 9px;
            font-size: 12px;
            line-height: 1.5;
        }

        .alert.success {
            color: #267448;
            background: #eaf7ee;
            border: 1px solid #cce9d4;
        }

        .alert.error {
            color: #a44a3f;
            background: #fff0ed;
            border: 1px solid #f2d2cc;
        }

        .table-wrap {
            overflow-x: auto;
            margin-top: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            white-space: nowrap;
        }

        th {
            text-align: left;
            padding: 12px 10px;
            background: #f7f8f6;
            color: #7e887f;
            font-size: 10px;
            font-weight: bold;
        }

        td {
            padding: 13px 10px;
            border-bottom: 1px solid #edf0ed;
            font-size: 11px;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        .package-name {
            font-weight: bold;
            color: #26342b;
        }

        .subtext {
            display: block;
            margin-top: 4px;
            color: #89928b;
            font-size: 10px;
            white-space: normal;
            min-width: 130px;
            max-width: 250px;
            line-height: 1.5;
        }

        .status {
            display: inline-block;
            padding: 6px 9px;
            border-radius: 20px;
            background: #eaf7ee;
            color: #267448;
            font-size: 10px;
        }

        .status.off {
            background: #fff0ed;
            color: #a44a3f;
        }

        .empty {
            text-align: center;
            padding: 25px;
            color: #858e87;
        }

        .actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .actions form {
            margin: 0;
        }

        .foot {
            margin-top: 18px;
            color: #8a938c;
            font-size: 10px;
        }

        @media (max-width: 1050px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 700px) {
            .layout {
                display: block;
            }

            .sidebar {
                width: 100%;
                padding: 15px;
            }

            .brand {
                padding: 0 10px 12px;
            }

            .menu {
                display: flex;
                gap: 5px;
                overflow-x: auto;
            }

            .menu-label {
                display: none;
            }

            .menu a {
                white-space: nowrap;
                padding: 10px;
            }

            .topbar {
                padding: 14px 15px;
            }

            .content {
                padding: 20px 14px;
            }

            .welcome {
                align-items: flex-start;
                flex-direction: column;
            }

            .stats {
                gap: 10px;
            }

            .stat {
                padding: 14px;
            }

            .stat strong {
                font-size: 23px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: auto;
            }

            .panel {
                padding: 15px;
            }
        }
    </style>
</head>

<body>
<div class="layout">

    <!-- SIDEBAR YANG SAMA DI SETIAP HALAMAN -->
    <aside class="sidebar">
        <div class="brand">FitDesk<span> Gym.</span></div>

        <div class="menu-label">Menu utama</div>

        <nav class="menu">
            <a href="dashboard.php">▦ Dashboard</a>
            <a href="anggota.php">♙ Data Anggota</a>
            <a class="active" href="memberships.php">♧ Paket Membership</a>
            <a href="pembayaran.php">💳 Pembayaran</a>
            <a href="laporan.php">▤ Laporan</a>
        </nav>

        <div class="menu-label">Akun</div>

        <nav class="menu">
            <a href="logout.php">↪ Keluar</a>
        </nav>
    </aside>

    <main class="main">

        <header class="topbar">
            <div>Operasional &nbsp; › &nbsp; <strong>Paket Membership</strong></div>
            <div><?= e(tanggalID($today)) ?></div>
        </header>

        <div class="content">

            <section class="welcome">
                <div>
                    <h2>Pengelolaan Paket Membership 👋</h2>
                    <p>
                        Kelola paket gym, durasi keanggotaan, harga,
                        dan status ketersediaan paket.
                    </p>
                </div>

                <div class="date">
                    ▦ &nbsp; <?= e(tanggalID($today)) ?>
                </div>
            </section>

            <?php if ($pesan !== ''): ?>
                <div class="alert <?= e($jenisPesan) ?>">
                    <?= e($pesan) ?>
                </div>
            <?php endif; ?>

            <section class="stats">
                <article class="stat">
                    <div class="stat-top">
                        <span>Total paket</span>
                        <span class="stat-icon">♧</span>
                    </div>
                    <strong><?= number_format($totalPaket) ?></strong>
                    <small>Seluruh paket yang tersimpan</small>
                </article>

                <article class="stat">
                    <div class="stat-top">
                        <span>Paket aktif</span>
                        <span class="stat-icon">✓</span>
                    </div>
                    <strong><?= number_format($totalAktif) ?></strong>
                    <small>Paket tersedia untuk digunakan</small>
                </article>

                <article class="stat">
                    <div class="stat-top">
                        <span>Paket nonaktif</span>
                        <span class="stat-icon">◷</span>
                    </div>
                    <strong><?= number_format($totalNonaktif) ?></strong>
                    <small>Paket yang tidak ditawarkan</small>
                </article>
            </section>

            <!-- FORM TAMBAH / EDIT -->
            <section class="panel" id="formPaket">
                <div class="panel-head">
                    <div>
                        <h3>
                            <?= $editPaket ? '✎ Edit paket membership' : '＋ Tambah paket membership' ?>
                        </h3>
                        <p>
                            Isi nama paket, durasi dalam bulan, harga, dan deskripsi.
                        </p>
                    </div>

                    <?php if ($editPaket): ?>
                        <a href="memberships.php" class="btn btn-light">
                            Batal edit
                        </a>
                    <?php endif; ?>
                </div>

                <form method="POST" action="memberships.php">
                    <input
                        type="hidden"
                        name="aksi"
                        value="<?= $editPaket ? 'edit' : 'tambah' ?>"
                    >

                    <?php if ($editPaket): ?>
                        <input
                            type="hidden"
                            name="id"
                            value="<?= (int)$editPaket['id'] ?>"
                        >
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="field">
                            <label for="package_name">Nama paket</label>
                            <input
                                type="text"
                                id="package_name"
                                name="package_name"
                                maxlength="100"
                                placeholder="Contoh: Paket 1 Bulan"
                                value="<?= e($editPaket['package_name'] ?? '') ?>"
                                required
                            >
                        </div>

                        <div class="field">
                            <label for="duration_months">Durasi membership</label>
                            <select
                                id="duration_months"
                                name="duration_months"
                                required
                            >
                                <?php
                                $durasiPilihan = [
                                    1 => '1 Bulan',
                                    3 => '3 Bulan',
                                    6 => '6 Bulan',
                                    12 => '12 Bulan'
                                ];

                                $durasiTerpilih = (int)($editPaket['duration_months'] ?? 1);
                                ?>

                                <?php foreach ($durasiPilihan as $angka => $label): ?>
                                    <option
                                        value="<?= $angka ?>"
                                        <?= $durasiTerpilih === $angka ? 'selected' : '' ?>
                                    >
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="subtext">
                                Durasi yang sudah dipakai membership tidak bisa diubah.
                            </span>
                        </div>

                        <div class="field">
                            <label for="price">Harga paket (Rp)</label>
                            <input
                                type="number"
                                id="price"
                                name="price"
                                min="1"
                                step="1000"
                                placeholder="250000"
                                value="<?= e($editPaket['price'] ?? '') ?>"
                                required
                            >
                        </div>

                        <div class="field">
                            <label>Status paket</label>
                            <div class="checkbox">
                                <input
                                    type="checkbox"
                                    id="is_active"
                                    name="is_active"
                                    value="1"
                                    <?= !isset($editPaket) || (int)$editPaket['is_active'] === 1 ? 'checked' : '' ?>
                                >
                                <label for="is_active">Paket aktif dan tersedia</label>
                            </div>
                        </div>

                        <div class="field full">
                            <label for="description">Deskripsi paket</label>
                            <textarea
                                id="description"
                                name="description"
                                maxlength="1000"
                                placeholder="Tuliskan fasilitas atau keterangan paket..."
                            ><?= e($editPaket['description'] ?? '') ?></textarea>
                        </div>

                        <div class="field full">
                            <button type="submit" class="btn">
                                <?= $editPaket ? '✓ Simpan perubahan' : '＋ Simpan paket' ?>
                            </button>
                        </div>
                    </div>
                </form>
            </section>

            <!-- DAFTAR PAKET -->
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h3>Daftar paket membership</h3>
                        <p>Semua paket yang tersimpan di database FitDesk Gym.</p>
                    </div>

                    <a href="pembayaran.php" class="btn btn-dark">
                        💳 Ke pembayaran
                    </a>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>NO</th>
                            <th>NAMA PAKET</th>
                            <th>DURASI</th>
                            <th>HARGA</th>
                            <th>DESKRIPSI</th>
                            <th>MEMBERSHIP</th>
                            <th>STATUS</th>
                            <th>AKSI</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php if (!$paketList): ?>
                            <tr>
                                <td colspan="8" class="empty">
                                    Belum ada paket membership. Silakan tambahkan paket baru.
                                </td>
                            </tr>
                        <?php else: ?>

                            <?php foreach ($paketList as $index => $paket): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>

                                    <td>
                                        <span class="package-name">
                                            <?= e($paket['package_name']) ?>
                                        </span>
                                        <span class="subtext">
                                            ID Paket: <?= (int)$paket['id'] ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= (int)$paket['duration_months'] ?> bulan
                                    </td>

                                    <td>
                                        <strong><?= e(rupiah($paket['price'])) ?></strong>
                                    </td>

                                    <td>
                                        <?= e($paket['description'] ?: '-') ?>
                                    </td>

                                    <td>
                                        <?= number_format((int)$paket['jumlah_membership']) ?>
                                        membership
                                    </td>

                                    <td>
                                        <?php if ((int)$paket['is_active'] === 1): ?>
                                            <span class="status">Aktif</span>
                                        <?php else: ?>
                                            <span class="status off">Nonaktif</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div class="actions">
                                            <a
                                                class="btn btn-dark btn-small"
                                                href="memberships.php?edit=<?= (int)$paket['id'] ?>#formPaket"
                                            >
                                                Edit
                                            </a>

                                            <form
                                                method="POST"
                                                action="memberships.php"
                                                onsubmit="return confirm('Yakin ingin menghapus paket ini? Paket yang sudah digunakan membership tidak bisa dihapus.');"
                                            >
                                                <input type="hidden" name="aksi" value="hapus">
                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int)$paket['id'] ?>"
                                                >
                                                <button
                                                    type="submit"
                                                    class="btn btn-danger btn-small"
                                                >
                                                    Hapus
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="foot">
                FitDesk Gym © <?= date('Y') ?> — Pengelolaan paket membership.
            </div>

        </div>
    </main>
</div>
</body>
</html>
