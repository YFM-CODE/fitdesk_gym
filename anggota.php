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

function tanggalID($value)
{
    if (empty($value)) {
        return '-';
    }

    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April',
        'Mei', 'Juni', 'Juli', 'Agustus',
        'September', 'Oktober', 'November', 'Desember'
    ];

    $time = strtotime($value);

    return date('j', $time) . ' ' .
        $bulan[(int) date('n', $time)] . ' ' .
        date('Y', $time);
}

$today = date('Y-m-d');
$pesan = '';
$jenisPesan = '';
$keyword = trim($_GET['cari'] ?? '');

try {
    // TAMBAH ANGGOTA
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $aksi = $_POST['aksi'] ?? '';

        if ($aksi === 'tambah') {
            $kode = trim($_POST['member_code'] ?? '');
            $nama = trim($_POST['full_name'] ?? '');
            $telepon = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if ($kode === '' || $nama === '') {
                $pesan = 'Kode anggota dan nama lengkap wajib diisi.';
                $jenisPesan = 'error';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $pesan = 'Format email tidak valid.';
                $jenisPesan = 'error';
            } else {
                $cek = $pdo->prepare(
                    'SELECT COUNT(*) FROM members WHERE member_code = ?'
                );
                $cek->execute([$kode]);

                if ((int) $cek->fetchColumn() > 0) {
                    $pesan = 'Kode anggota sudah digunakan.';
                    $jenisPesan = 'error';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO members
                            (member_code, full_name, phone, email)
                        VALUES (?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $kode,
                        $nama,
                        $telepon !== '' ? $telepon : null,
                        $email !== '' ? $email : null
                    ]);

                    header('Location: anggota.php?status=berhasil');
                    exit;
                }
            }
        }

        // HAPUS ANGGOTA
        if ($aksi === 'hapus') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id > 0) {
                $cek = $pdo->prepare(
                    'SELECT COUNT(*) FROM memberships WHERE member_id = ?'
                );
                $cek->execute([$id]);

                if ((int) $cek->fetchColumn() > 0) {
                    $pesan = 'Anggota tidak dapat dihapus karena memiliki riwayat membership.';
                    $jenisPesan = 'error';
                } else {
                    $stmt = $pdo->prepare(
                        'DELETE FROM members WHERE id = ?'
                    );
                    $stmt->execute([$id]);

                    header('Location: anggota.php?status=dihapus');
                    exit;
                }
            }
        }
    }

    if (($_GET['status'] ?? '') === 'berhasil') {
        $pesan = 'Data anggota berhasil ditambahkan.';
        $jenisPesan = 'success';
    } elseif (($_GET['status'] ?? '') === 'dihapus') {
        $pesan = 'Data anggota berhasil dihapus.';
        $jenisPesan = 'success';
    }

    // TOTAL ANGGOTA
    $totalAnggota = (int) $pdo
        ->query('SELECT COUNT(*) FROM members')
        ->fetchColumn();

    // PENCARIAN DAN DAFTAR ANGGOTA
    if ($keyword !== '') {
        $stmt = $pdo->prepare("
            SELECT id, member_code, full_name, phone, email, created_at
            FROM members
            WHERE member_code LIKE ?
               OR full_name LIKE ?
               OR phone LIKE ?
               OR email LIKE ?
            ORDER BY id DESC
        ");

        $cari = '%' . $keyword . '%';
        $stmt->execute([$cari, $cari, $cari, $cari]);
    } else {
        $stmt = $pdo->query("
            SELECT id, member_code, full_name, phone, email, created_at
            FROM members
            ORDER BY id DESC
        ");
    }

    $anggota = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $ex) {
    error_log('Kesalahan data anggota FitDesk: ' . $ex->getMessage());

    die(
        '<h2>Terjadi kesalahan database.</h2>
        <p>Periksa koneksi database serta tabel members dan memberships.</p>'
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

    <title>Data Anggota | FitDesk Gym</title>

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

        button,
        input {
            font: inherit;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        /* SIDEBAR */
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

        /* KONTEN */
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

        /* TOMBOL */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border: 0;
            border-radius: 8px;
            padding: 11px 15px;
            cursor: pointer;
            font-weight: bold;
            font-size: 12px;
            transition: .2s;
        }

        .btn-primary {
            background: #c5f578;
            color: #182720;
        }

        .btn-primary:hover {
            background: #b3e760;
        }

        .btn-secondary {
            background: #edf1ed;
            color: #36453a;
        }

        .btn-secondary:hover {
            background: #e1e8e1;
        }

        .btn-danger {
            background: #fff0ed;
            color: #a44a3f;
        }

        .btn-danger:hover {
            background: #ffe0da;
        }

        /* STATISTIK */
        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 18px;
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
            transform: translateY(-2px);
            box-shadow: 0 8px 22px #1827200b;
        }

        .stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            color: #758078;
            font-size: 11px;
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

        /* NOTIFIKASI */
        .alert {
            padding: 13px 15px;
            border-radius: 9px;
            margin-bottom: 17px;
            line-height: 1.5;
        }

        .alert.success {
            color: #267448;
            background: #eaf7ee;
            border: 1px solid #d2ead8;
        }

        .alert.error {
            color: #a44a3f;
            background: #fff0ed;
            border: 1px solid #f5d6d0;
        }

        /* PANEL */
        .panel {
            padding: 21px;
            border: 1px solid #e5eae5;
            border-radius: 13px;
            background: #fff;
            min-width: 0;
            margin-bottom: 18px;
        }

        .panel-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 18px;
        }

        .panel h3 {
            margin: 0 0 6px;
            font-size: 15px;
        }

        .panel-desc {
            margin: 0;
            color: #858e87;
            font-size: 11px;
            line-height: 1.5;
        }

        /* FORM */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .form-group {
            min-width: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #39453c;
            font-size: 11px;
            font-weight: bold;
        }

        .form-group input {
            width: 100%;
            min-height: 42px;
            padding: 11px 12px;
            border: 1px solid #dfe6df;
            border-radius: 8px;
            outline: none;
            background: #fbfcfa;
            color: #202923;
        }

        .form-group input:focus,
        .search-form input:focus {
            border-color: #8dbd59;
            box-shadow: 0 0 0 3px #c5f57833;
            background: #fff;
        }

        .form-actions {
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
            margin-top: 17px;
        }

        /* PENCARIAN */
        .search-form {
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
            width: 100%;
            max-width: 480px;
        }

        .search-form input {
            flex: 1;
            min-width: 150px;
            padding: 10px 12px;
            border: 1px solid #dfe6df;
            border-radius: 8px;
            outline: none;
            background: #fbfcfa;
        }

        /* TABEL */
        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            white-space: nowrap;
        }

        th {
            text-align: left;
            padding: 13px 11px;
            background: #f7f8f6;
            color: #7e887f;
            font-size: 10px;
            font-weight: bold;
        }

        td {
            padding: 14px 11px;
            border-bottom: 1px solid #edf0ed;
            font-size: 11px;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: #fbfcfa;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        .member-name {
            color: #26342b;
            font-weight: bold;
        }

        .member-code {
            display: inline-block;
            padding: 6px 8px;
            border-radius: 6px;
            background: #eff6e7;
            color: #315a37;
            font-size: 10px;
            font-weight: bold;
        }

        .empty {
            text-align: center;
            padding: 30px 15px;
            color: #858e87;
        }

        .actions {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .actions .btn {
            padding: 8px 10px;
            font-size: 10px;
        }

        .foot {
            margin-top: 18px;
            color: #8a938c;
            font-size: 10px;
        }

        /* RESPONSIVE */
        @media (max-width: 1000px) {
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

            .panel {
                padding: 15px;
            }

            .search-form {
                max-width: 100%;
            }
        }

        @media (max-width: 420px) {
            .stats {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 22px;
            }
        }
    </style>
</head>

<body>
<div class="layout">

    <aside class="sidebar">
        <div class="brand">FitDesk<span> Gym.</span></div>

        <div class="menu-label">Menu utama</div>

        <nav class="menu">
            <a href="dashboard.php">▦ Dashboard</a>
            <a class="active" href="anggota.php">♙ Data Anggota</a>
            <a href="memberships.php">♧ Paket Membership</a>
            <a href="laporan.php">▤ Laporan</a>
        </nav>

        <div class="menu-label">Akun</div>

        <nav class="menu">
            <a href="logout.php">↪ Keluar</a>
        </nav>
    </aside>

    <main class="main">

        <header class="topbar">
            <div>Operasional &nbsp; › &nbsp; <strong>Data Anggota</strong></div>
            <div><?= e(tanggalID($today)) ?></div>
        </header>

        <div class="content">

            <section class="heading">
                <div>
                    <h1>Data Anggota</h1>
                    <p class="subtitle">
                        Kelola informasi dan data anggota FitDesk Gym.
                    </p>
                </div>

                <a class="btn btn-primary" href="#formTambah">
                    ＋ Tambah Anggota
                </a>
            </section>

            <?php if ($pesan !== ''): ?>
                <div class="alert <?= e($jenisPesan) ?>">
                    <?= e($pesan) ?>
                </div>
            <?php endif; ?>

            <section class="stats">
                <article class="stat">
                    <div class="stat-top">
                        <span>Total anggota</span>
                        <span class="stat-icon">♙</span>
                    </div>
                    <strong><?= number_format($totalAnggota) ?></strong>
                    <small>Seluruh anggota yang terdaftar</small>
                </article>

                <article class="stat">
                    <div class="stat-top">
                        <span>Data ditampilkan</span>
                        <span class="stat-icon">▤</span>
                    </div>
                    <strong><?= number_format(count($anggota)) ?></strong>
                    <small>Jumlah hasil pencarian atau daftar anggota</small>
                </article>

                <article class="stat">
                    <div class="stat-top">
                        <span>Pengelolaan data</span>
                        <span class="stat-icon">✓</span>
                    </div>
                    <strong style="font-size:20px">Anggota</strong>
                    <small>Data anggota FitDesk Gym</small>
                </article>
            </section>

            <!-- FORM TAMBAH ANGGOTA -->
            <section class="panel" id="formTambah">
                <div class="panel-head">
                    <div>
                        <h3>Tambah Anggota</h3>
                        <p class="panel-desc">
                            Masukkan informasi anggota baru pada formulir berikut.
                        </p>
                    </div>
                </div>

                <form method="POST" action="anggota.php">
                    <input type="hidden" name="aksi" value="tambah">

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="member_code">Kode Anggota *</label>
                            <input
                                type="text"
                                id="member_code"
                                name="member_code"
                                placeholder="Contoh: FDG001"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="full_name">Nama Lengkap *</label>
                            <input
                                type="text"
                                id="full_name"
                                name="full_name"
                                placeholder="Masukkan nama lengkap"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="phone">Nomor Telepon</label>
                            <input
                                type="tel"
                                id="phone"
                                name="phone"
                                placeholder="Masukkan nomor telepon"
                            >
                        </div>

                        <div class="form-group">
                            <label for="email">Email</label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                placeholder="Masukkan alamat email"
                            >
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            Simpan Anggota
                        </button>

                        <button type="reset" class="btn btn-secondary">
                            Reset Form
                        </button>
                    </div>
                </form>
            </section>

            <!-- DAFTAR ANGGOTA -->
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h3>Daftar Anggota</h3>
                        <p class="panel-desc">
                            Daftar anggota yang tersimpan di sistem FitDesk Gym.
                        </p>
                    </div>

                    <form class="search-form" method="GET" action="anggota.php">
                        <input
                            type="search"
                            name="cari"
                            value="<?= e($keyword) ?>"
                            placeholder="Cari kode, nama, telepon, atau email..."
                            aria-label="Cari data anggota"
                        >

                        <button type="submit" class="btn btn-primary">
                            Cari
                        </button>

                        <?php if ($keyword !== ''): ?>
                            <a href="anggota.php" class="btn btn-secondary">
                                Reset
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>NO.</th>
                                <th>KODE ANGGOTA</th>
                                <th>NAMA LENGKAP</th>
                                <th>TELEPON</th>
                                <th>EMAIL</th>
                                <th>TANGGAL DAFTAR</th>
                                <th>AKSI</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php if (!$anggota): ?>
                            <tr>
                                <td colspan="7" class="empty">
                                    <?php if ($keyword !== ''): ?>
                                        Data anggota tidak ditemukan.
                                    <?php else: ?>
                                        Belum ada data anggota yang terdaftar.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($anggota as $index => $m): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>

                                    <td>
                                        <span class="member-code">
                                            <?= e($m['member_code']) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="member-name">
                                            <?= e($m['full_name']) ?>
                                        </span>
                                    </td>

                                    <td><?= e($m['phone'] ?: '-') ?></td>

                                    <td><?= e($m['email'] ?: '-') ?></td>

                                    <td>
                                        <?= e(tanggalID($m['created_at'] ?? null)) ?>
                                    </td>

                                    <td>
                                        <div class="actions">
                                            <form
                                                method="POST"
                                                action="anggota.php"
                                                onsubmit="return confirm('Yakin ingin menghapus data anggota ini?');"
                                            >
                                                <input
                                                    type="hidden"
                                                    name="aksi"
                                                    value="hapus"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int) $m['id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-danger"
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
                FitDesk Gym © <?= date('Y') ?> — Data anggota.
            </div>

        </div>
    </main>
</div>
</body>
</html>