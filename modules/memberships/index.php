<?php
session_start();

if (!isset($_SESSION['user_id']) && !isset($_SESSION['username'])) {
    header('Location: ../auth/login.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';

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
$userRole = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? 'resepsionis';
$isAdmin = in_array(strtolower($userRole), ['admin', 'administrator', 'superadmin'], true);
$tabPaket = ($isAdmin && ($_GET['tab_paket'] ?? '') === 'riwayat') ? 'riwayat' : 'aktif';

/*
|--------------------------------------------------------------------------
| TAMBAH / EDIT / HAPUS PAKET (KHUSUS ADMIN)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    try {
        if (!$isAdmin) {
            throw new RuntimeException(
                'Akses ditolak. Hanya Admin yang memiliki wewenang untuk menambah, mengubah, atau menghapus paket membership.'
            );
        }

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

                header('Location: index.php?pesan=tambah');
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

            header('Location: index.php?pesan=edit');
            exit;
        }

        /*
         * HAPUS PAKET (SOFT DELETE)
         * Menghapus paket dari daftar aktif; riwayat keanggotaan dan pembayaran tetap tersimpan aman untuk Admin
         */
        if ($aksi === 'hapus') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE membership_packages SET deleted_at = NOW() WHERE id = ?");
                $stmt->execute([$id]);

                header('Location: index.php?pesan=hapus');
                exit;
            }
        }

        /*
         * PULIHKAN PAKET (KHUSUS ADMIN)
         */
        if ($aksi === 'pulihkan' && $isAdmin) {
            $id = (int)($_POST['id'] ?? 0);

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE membership_packages SET deleted_at = NULL WHERE id = ?");
                $stmt->execute([$id]);

                header('Location: index.php?tab_paket=riwayat&pesan=dipulihkan');
                exit;
            }
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
        'hapus' => 'Paket membership berhasil dihapus dari daftar aktif (riwayat data tetap tersimpan untuk Admin).',
        'dipulihkan' => 'Paket membership berhasil dipulihkan kembali ke daftar paket aktif.'
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
    if (!$isAdmin) {
        $pesan = 'Hanya Admin yang diizinkan untuk mengedit paket membership.';
        $jenisPesan = 'error';
    } else {
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
}

/*
|--------------------------------------------------------------------------
| AMBIL SELURUH MEMBER BESERTA STATUS PEMBAYARAN (UNTUK ADMIN & RESEPSIONIS)
|--------------------------------------------------------------------------
*/
try {
    $sqlMemberStatus = "
        SELECT 
            m.id AS member_id,
            m.member_code,
            m.full_name,
            m.phone,
            m.email,
            ms.id AS membership_id,
            ms.package_duration_months,
            ms.payment_plan,
            ms.start_date,
            ms.end_date,
            ms.is_active,
            mp.package_name,
            COALESCE(mp.price, 0) AS price,
            COALESCE(pay.total_bayar, 0) AS total_bayar,
            COALESCE(pay.cicilan_terakhir, 0) AS cicilan_terakhir,
            COALESCE(pay.jumlah_bayar, 0) AS jumlah_bayar
        FROM members m
        LEFT JOIN (
            SELECT ms1.*
            FROM memberships ms1
            INNER JOIN (
                SELECT member_id, MAX(id) AS max_id
                FROM memberships
                GROUP BY member_id
            ) ms2 ON ms1.id = ms2.max_id
        ) ms ON ms.member_id = m.id
        LEFT JOIN (
            SELECT duration_months, MIN(package_name) AS package_name, MIN(price) AS price
            FROM membership_packages
            WHERE is_active = 1
            GROUP BY duration_months
        ) mp ON mp.duration_months = ms.package_duration_months
        LEFT JOIN (
            SELECT membership_id, SUM(amount) AS total_bayar, MAX(installment_number) AS cicilan_terakhir, COUNT(*) AS jumlah_bayar
            FROM payments
            GROUP BY membership_id
        ) pay ON pay.membership_id = ms.id
        WHERE m.deleted_at IS NULL
        ORDER BY m.id DESC
    ";
    $stmtMS = $pdo->query($sqlMemberStatus);
    $allMembersPayment = $stmtMS->fetchAll(PDO::FETCH_ASSOC);

    $totalSemuaMember = count($allMembersPayment);
    $totalMemberLunas = 0;
    $totalMemberBelumLunas = 0;
    $totalMemberTanpaPaket = 0;

    foreach ($allMembersPayment as $item) {
        if (!$item['membership_id']) {
            $totalMemberTanpaPaket++;
        } else {
            $sisa = max(0, (float)$item['price'] - (float)$item['total_bayar']);
            if ($sisa <= 0) {
                $totalMemberLunas++;
            } else {
                $totalMemberBelumLunas++;
            }
        }
    }
} catch (PDOException $ex) {
    error_log('Data member status: ' . $ex->getMessage());
    $allMembersPayment = [];
    $totalSemuaMember = 0;
    $totalMemberLunas = 0;
    $totalMemberBelumLunas = 0;
    $totalMemberTanpaPaket = 0;
}

/*
|--------------------------------------------------------------------------
| AMBIL DATA PAKET
|--------------------------------------------------------------------------
*/
try {
    // Total statistik paket
    $totalPaketAktif = (int)$pdo->query("SELECT COUNT(*) FROM membership_packages WHERE deleted_at IS NULL")->fetchColumn();
    $totalPaketTersedia = (int)$pdo->query("SELECT COUNT(*) FROM membership_packages WHERE deleted_at IS NULL AND is_active = 1")->fetchColumn();
    $totalPaketNonaktif = (int)$pdo->query("SELECT COUNT(*) FROM membership_packages WHERE deleted_at IS NULL AND is_active = 0")->fetchColumn();
    $totalPaketTerhapus = (int)$pdo->query("SELECT COUNT(*) FROM membership_packages WHERE deleted_at IS NOT NULL")->fetchColumn();

    $totalPaket = $totalPaketAktif;
    $totalAktif = $totalPaketTersedia;
    $totalNonaktif = $totalPaketNonaktif;

    if ($tabPaket === 'riwayat') {
        $stmt = $pdo->query("
            SELECT
                mp.*,
                (
                    SELECT COUNT(*)
                    FROM memberships ms
                    WHERE ms.package_duration_months = mp.duration_months
                ) AS jumlah_membership
            FROM membership_packages mp
            WHERE mp.deleted_at IS NOT NULL
            ORDER BY mp.deleted_at DESC, mp.id DESC
        ");
    } else {
        $stmt = $pdo->query("
            SELECT
                mp.*,
                (
                    SELECT COUNT(*)
                    FROM memberships ms
                    WHERE ms.package_duration_months = mp.duration_months
                ) AS jumlah_membership
            FROM membership_packages mp
            WHERE mp.deleted_at IS NULL
            ORDER BY mp.duration_months ASC, mp.id ASC
        ");
    }

    $paketList = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        .admin-tabs {
            display: flex;
            gap: 10px;
            margin: 18px 0 14px;
            border-bottom: 2px solid #e5eae5;
            padding-bottom: 12px;
        }
        .admin-tab {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: bold;
            text-decoration: none;
            transition: .2s;
            background: #fff;
            color: #556258;
            border: 1px solid #dfe6df;
        }
        .admin-tab:hover {
            background: #f7f9f7;
            color: #202923;
        }
        .admin-tab.active {
            background: #1e3a24;
            color: #ffffff;
            border-color: #1e3a24;
        }
        .admin-tab .badge-count {
            padding: 2px 7px;
            border-radius: 99px;
            font-size: 11px;
            background: rgba(0,0,0,0.08);
        }
        .admin-tab.active .badge-count {
            background: rgba(255,255,255,0.2);
            color: #ffffff;
        }
        .btn-restore {
            background: #0284c7 !important;
            color: #ffffff !important;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-restore:hover {
            background: #0369a1 !important;
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

        .filter-btn {
            border: none;
            background: transparent;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            color: #4b5563;
            transition: .2s;
        }

        .filter-btn:hover {
            color: #111827;
        }

        .filter-btn.active {
            background: #182720;
            color: #ffffff;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: bold;
            white-space: nowrap;
        }

        .btn-pay {
            background: #c5f578 !important;
            color: #17251f !important;
            font-weight: bold;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-pay:hover {
            background: #b5e865 !important;
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

    <!-- SIDEBAR TERPUSAT SESUAI ROLE (AUTHENTICATED LAYOUT) -->
    <?php render_sidebar('memberships'); ?>

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
                    <small>Paket aktif terdaftar</small>
                </article>

                <article class="stat">
                    <div class="stat-top">
                        <span>Paket aktif</span>
                        <span class="stat-icon">✓</span>
                    </div>
                    <strong><?= number_format($totalAktif) ?></strong>
                    <small>Paket tersedia untuk digunakan</small>
                </article>

                <?php if ($isAdmin): ?>
                    <a href="index.php?tab_paket=riwayat" class="stat" style="text-decoration:none; color:inherit;">
                        <div class="stat-top">
                            <span>Riwayat Terhapus (Admin)</span>
                            <span class="stat-icon" style="background:#fee2e2; color:#991b1b;">📁</span>
                        </div>
                        <strong style="color: <?= $totalPaketTerhapus > 0 ? '#b91c1c' : 'inherit' ?>;"><?= number_format($totalPaketTerhapus) ?></strong>
                        <small style="color:#64748b;">Klik untuk kelola arsip paket terhapus</small>
                    </a>
                <?php else: ?>
                    <article class="stat">
                        <div class="stat-top">
                            <span>Paket nonaktif</span>
                            <span class="stat-icon">◷</span>
                        </div>
                        <strong><?= number_format($totalNonaktif) ?></strong>
                        <small>Paket yang tidak ditawarkan</small>
                    </article>
                <?php endif; ?>
            </section>

            <?php if ($isAdmin && $tabPaket === 'aktif'): ?>
            <!-- FORM TAMBAH / EDIT (KHUSUS ADMIN) -->
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
                        <a href="index.php" class="btn btn-light">
                            Batal edit
                        </a>
                    <?php endif; ?>
                </div>

                <form method="POST" action="index.php">
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
                                min="0"
                                step="any"
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
            <?php else: ?>
            <!-- INFO ROLE RESEPSIONIS -->
            <section class="panel" style="background:#f0fdf4;border-color:#bbf7d0;">
                <div style="display:flex;align-items:center;gap:14px;color:#166534;">
                    <span style="font-size:26px;">ℹ</span>
                    <div>
                        <strong style="font-size:13px;display:block;margin-bottom:3px;">Informasi Hak Akses (Resepsionis)</strong>
                        <span style="font-size:11px;color:#15803d;line-height:1.5;display:block;">
                            Pengelolaan paket membership (tambah, edit, dan hapus) dikelola secara terpusat oleh <strong>Admin</strong>. Sebagai Resepsionis, Anda dapat melihat daftar paket yang tersedia serta memantau status pembayaran keanggotaan seluruh member pada tabel di bawah.
                        </span>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($isAdmin): ?>
                <!-- TAB SWITCH PAKET UNTUK ADMIN -->
                <div class="admin-tabs">
                    <a href="index.php?tab_paket=aktif" class="admin-tab <?= $tabPaket === 'aktif' ? 'active' : '' ?>">
                        <span>♧ Paket Aktif</span>
                        <span class="badge-count"><?= number_format($totalPaketAktif) ?></span>
                    </a>
                    <a href="index.php?tab_paket=riwayat" class="admin-tab <?= $tabPaket === 'riwayat' ? 'active' : '' ?>">
                        <span>📁 Riwayat Paket Terhapus (Admin)</span>
                        <span class="badge-count"><?= number_format($totalPaketTerhapus) ?></span>
                    </a>
                </div>
            <?php endif; ?>

            <!-- DAFTAR PAKET -->
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h3><?= $tabPaket === 'riwayat' ? 'Daftar Riwayat Paket Terhapus' : 'Daftar paket membership' ?></h3>
                        <p><?= $tabPaket === 'riwayat' ? 'Arsip paket yang telah dihapus dari penawaran aktif. Riwayat transaksi membership tetap aman terjaga.' : 'Semua paket aktif yang tersimpan di database FitDesk Gym.' ?></p>
                    </div>

                    <?php if ($tabPaket === 'riwayat'): ?>
                        <a href="index.php?tab_paket=aktif" class="btn btn-light">
                            ← Kembali ke Paket Aktif
                        </a>
                    <?php else: ?>
                        <a href="../payments/index.php" class="btn btn-dark">
                            💳 Ke pembayaran
                        </a>
                    <?php endif; ?>
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
                            <th><?= $tabPaket === 'riwayat' ? 'RIWAYAT MEMBERSHIP' : 'MEMBERSHIP' ?></th>
                            <th><?= $tabPaket === 'riwayat' ? 'TANGGAL DIHAPUS' : 'STATUS' ?></th>
                            <th>AKSI</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php if (!$paketList): ?>
                            <tr>
                                <td colspan="8" class="empty">
                                    <?= $tabPaket === 'riwayat' ? 'Belum ada riwayat paket yang terhapus.' : 'Belum ada paket membership. Silakan tambahkan paket baru.' ?>
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
                                        <?php if ($tabPaket === 'riwayat'): ?>
                                            <div style="color:#b91c1c; font-weight:bold;">
                                                <?= e(tanggalID($paket['deleted_at'] ?? null)) ?>
                                            </div>
                                            <small style="color:#94a3b8;"><?= !empty($paket['deleted_at']) ? date('H:i', strtotime($paket['deleted_at'])) . ' WIB' : '' ?></small>
                                        <?php else: ?>
                                            <?php if ((int)$paket['is_active'] === 1): ?>
                                                <span class="status">Aktif</span>
                                            <?php else: ?>
                                                <span class="status off">Nonaktif</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ($isAdmin): ?>
                                            <div class="actions">
                                                <?php if ($tabPaket === 'riwayat'): ?>
                                                    <form
                                                        method="POST"
                                                        action="index.php"
                                                        onsubmit="return confirm('Pulihkan paket ini ke daftar paket aktif?');"
                                                    >
                                                        <input type="hidden" name="aksi" value="pulihkan">
                                                        <input
                                                            type="hidden"
                                                            name="id"
                                                            value="<?= (int)$paket['id'] ?>"
                                                        >
                                                        <button
                                                            type="submit"
                                                            class="btn btn-restore btn-small"
                                                            title="Pulihkan paket ke daftar aktif"
                                                        >
                                                            ↺ Pulihkan
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <a
                                                        class="btn btn-dark btn-small"
                                                        href="index.php?edit=<?= (int)$paket['id'] ?>#formPaket"
                                                    >
                                                        Edit
                                                    </a>

                                                    <form
                                                        method="POST"
                                                        action="index.php"
                                                        onsubmit="return confirm('Yakin ingin menghapus paket ini? Paket akan dihapus dari penawaran aktif dan tersimpan di arsip Admin.');"
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
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="font-size:10px;padding:4px 8px;border-radius:6px;background:#f1f5f9;color:#64748b;font-weight:600;">Khusus Admin</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- TABEL STATUS PEMBAYARAN SELURUH MEMBER (ADMIN & RESEPSIONIS) -->
            <section class="panel" id="statusMemberSection">
                <div class="panel-head">
                    <div>
                        <h3>Status Pembayaran & Keanggotaan Member</h3>
                        <p>Memantau member yang berstatus Lunas, Cicilan Berjalan, atau Belum Bayar.</p>
                    </div>

                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <div style="display:flex;background:#edf2ed;padding:4px;border-radius:8px;gap:4px;">
                            <button type="button" class="filter-btn active" onclick="filterMemberStatus('all', this)">Semua (<?= $totalSemuaMember ?>)</button>
                            <button type="button" class="filter-btn" onclick="filterMemberStatus('lunas', this)">✓ Lunas (<?= $totalMemberLunas ?>)</button>
                            <button type="button" class="filter-btn" onclick="filterMemberStatus('belum_lunas', this)">◷ Belum Lunas (<?= $totalMemberBelumLunas ?>)</button>
                        </div>
                        <input type="text" id="cariMemberStatus" placeholder="Cari nama / kode member..." onkeyup="searchMemberTable()" style="max-width:210px;padding:9px 12px;font-size:11px;border:1px solid #d1d5db;border-radius:8px;outline:none;">
                    </div>
                </div>

                <div class="table-wrap">
                    <table id="tableMemberStatus">
                        <thead>
                            <tr>
                                <th>NO</th>
                                <th>KODE & NAMA MEMBER</th>
                                <th>TELEPON</th>
                                <th>PAKET MEMBERSHIP</th>
                                <th>HARGA</th>
                                <th>TERBAYAR</th>
                                <th>SISA TAGIHAN</th>
                                <th>STATUS PEMBAYARAN</th>
                                <th>AKSI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$allMembersPayment): ?>
                                <tr>
                                    <td colspan="9" class="empty">Belum ada data anggota terdaftar.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($allMembersPayment as $idx => $m): ?>
                                    <?php
                                    $hasPkg = !empty($m['membership_id']);
                                    $harga = (float)$m['price'];
                                    $dibayar = (float)$m['total_bayar'];
                                    $sisa = max(0, $harga - $dibayar);
                                    $isLunas = $hasPkg && ($sisa <= 0);
                                    $isCicil = $hasPkg && ($dibayar > 0 && $sisa > 0);
                                    $isBelumBayar = $hasPkg && ($dibayar <= 0);

                                    $filterClass = 'status-all ';
                                    if (!$hasPkg) {
                                        $filterClass .= 'status-belum_lunas';
                                    } elseif ($isLunas) {
                                        $filterClass .= 'status-lunas';
                                    } else {
                                        $filterClass .= 'status-belum_lunas';
                                    }
                                    ?>
                                    <tr class="member-status-row <?= $filterClass ?>">
                                        <td><?= $idx + 1 ?></td>
                                        <td>
                                            <strong style="color:#1e293b;"><?= e($m['full_name']) ?></strong>
                                            <span class="subtext" style="font-family:monospace;"><?= e($m['member_code']) ?></span>
                                        </td>
                                        <td><?= e($m['phone'] ?: '-') ?></td>
                                        <td>
                                            <?php if ($hasPkg): ?>
                                                <strong><?= e($m['package_name'] ?? ($m['package_duration_months'] . ' Bulan')) ?></strong>
                                                <span class="subtext">
                                                    <?= (int)$m['package_duration_months'] ?> bln 
                                                    (<?= $m['payment_plan'] === 'installment_3' ? 'Cicil 3x' : 'Lunas/Tunai' ?>)
                                                </span>
                                            <?php else: ?>
                                                <span style="color:#94a3b8;font-style:italic;">Belum pilih paket</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $hasPkg ? rupiah($harga) : '-' ?></td>
                                        <td><?= $hasPkg ? rupiah($dibayar) : '-' ?></td>
                                        <td>
                                            <?php if (!$hasPkg): ?>
                                                -
                                            <?php elseif ($sisa > 0): ?>
                                                <strong style="color:#b91c1c;"><?= rupiah($sisa) ?></strong>
                                            <?php else: ?>
                                                <span style="color:#15803d;font-weight:600;">Rp 0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!$hasPkg): ?>
                                                <span class="badge" style="background:#f1f5f9;color:#64748b;">Belum Ada Paket</span>
                                            <?php elseif ($isLunas): ?>
                                                <span class="badge" style="background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;">✓ Lunas</span>
                                            <?php elseif ($isCicil): ?>
                                                <span class="badge" style="background:#ffedd5;color:#c2410c;border:1px solid #fed7aa;">
                                                    ◷ Cicilan (Ke-<?= (int)$m['cicilan_terakhir'] ?>/3)
                                                </span>
                                            <?php else: ?>
                                                <span class="badge" style="background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;">✕ Belum Bayar</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($hasPkg): ?>
                                                <?php if ($sisa > 0): ?>
                                                    <a href="../payments/index.php?membership_id=<?= (int)$m['membership_id'] ?>" class="btn btn-pay btn-small">
                                                        💳 <?= $isCicil ? 'Bayar Cicil' : 'Bayar' ?>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="../payments/index.php?membership_id=<?= (int)$m['membership_id'] ?>" class="btn btn-light btn-small" title="Lihat Riwayat Pembayaran">
                                                        Riwayat
                                                    </a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <a href="../members/index.php" class="btn btn-dark btn-small">
                                                    ＋ Beri Paket
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="foot">
                FitDesk Gym © <?= date('Y') ?> — Pengelolaan paket & status keanggotaan.
            </div>

        </div>
    </main>
</div>

<script>
function filterMemberStatus(type, btn) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');

    const rows = document.querySelectorAll('.member-status-row');
    rows.forEach(r => {
        if (type === 'all') {
            r.style.display = '';
        } else if (type === 'lunas') {
            r.style.display = r.classList.contains('status-lunas') ? '' : 'none';
        } else if (type === 'belum_lunas') {
            r.style.display = r.classList.contains('status-belum_lunas') ? '' : 'none';
        }
    });
}

function searchMemberTable() {
    const q = document.getElementById('cariMemberStatus').value.toLowerCase();
    const rows = document.querySelectorAll('.member-status-row');
    rows.forEach(r => {
        r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>
</body>
</html>
