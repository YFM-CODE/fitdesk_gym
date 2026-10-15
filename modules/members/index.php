<?php
session_start();

if (!isset($_SESSION['user_id']) && !isset($_SESSION['username'])) {
    header('Location: ../auth/login.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';

date_default_timezone_set('Asia/Jakarta');

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
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

$userRole = strtolower($_SESSION['role'] ?? $_SESSION['user']['role'] ?? 'resepsionis');
$isAdmin = in_array($userRole, ['admin', 'administrator', 'superadmin'], true);
$tab = ($isAdmin && ($_GET['tab'] ?? '') === 'riwayat') ? 'riwayat' : 'aktif';

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
                    $duration = (int)($_POST['package_duration'] ?? 0);
                    $plan = in_array($_POST['payment_plan'] ?? '', ['full', 'installment_3'], true) ? $_POST['payment_plan'] : 'full';

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

                    $newMemberId = (int)$pdo->lastInsertId();

                    if ($duration > 0) {
                        $start = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
                        $startDateObj = new DateTime($start);
                        $endDateObj = clone $startDateObj;
                        $endDateObj->modify('+' . $duration . ' months');
                        $endDateObj->modify('-1 day');
                        $todayObj = new DateTime('today');
                        $isActive = ($endDateObj >= $todayObj) ? 1 : 0;

                        $stmtMs = $pdo->prepare("
                            INSERT INTO memberships
                                (member_id, package_duration_months, start_date, end_date, is_active, payment_plan, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmtMs->execute([
                            $newMemberId,
                            $duration,
                            $startDateObj->format('Y-m-d'),
                            $endDateObj->format('Y-m-d'),
                            $isActive,
                            $plan
                        ]);
                        $newMembershipId = (int)$pdo->lastInsertId();

                        header('Location: ../payments/index.php?membership_id=' . $newMembershipId . '&sukses=' . urlencode('Anggota baru dan paket membership berhasil didaftarkan! Silakan lanjutkan pembayaran.'));
                        exit;
                    }

                    header('Location: index.php?status=berhasil');
                    exit;
                }
            }
        }

                // TETAPKAN PAKET ANGGOTA
        if ($aksi === 'tambah_paket') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            $duration = (int)($_POST['package_duration'] ?? 0);
            $plan = in_array($_POST['payment_plan'] ?? '', ['full', 'installment_3'], true) ? $_POST['payment_plan'] : 'full';
            $start = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');

            if ($memberId > 0 && in_array($duration, [1, 3, 6, 12], true)) {
                $startDateObj = new DateTime($start);
                $endDateObj = clone $startDateObj;
                $endDateObj->modify('+' . $duration . ' months');
                $endDateObj->modify('-1 day');
                $todayObj = new DateTime('today');
                $isActive = ($endDateObj >= $todayObj) ? 1 : 0;

                $stmtMs = $pdo->prepare("
                    INSERT INTO memberships
                        (member_id, package_duration_months, start_date, end_date, is_active, payment_plan, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmtMs->execute([
                    $memberId,
                    $duration,
                    $startDateObj->format('Y-m-d'),
                    $endDateObj->format('Y-m-d'),
                    $isActive,
                    $plan
                ]);
                $newMembershipId = (int)$pdo->lastInsertId();

                header('Location: ../payments/index.php?membership_id=' . $newMembershipId . '&sukses=' . urlencode('Paket membership berhasil ditetapkan! Silakan lanjutkan pembayaran.'));
                exit;
            }
        }

        // EDIT ANGGOTA
        if ($aksi === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $kode = trim($_POST['member_code'] ?? '');
            $nama = trim($_POST['full_name'] ?? '');
            $telepon = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if ($id <= 0 || $kode === '' || $nama === '') {
                $pesan = 'Kode anggota dan nama lengkap wajib diisi.';
                $jenisPesan = 'error';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $pesan = 'Format email tidak valid.';
                $jenisPesan = 'error';
            } else {
                $cek = $pdo->prepare('SELECT COUNT(*) FROM members WHERE member_code = ? AND id != ?');
                $cek->execute([$kode, $id]);
                if ((int)$cek->fetchColumn() > 0) {
                    $pesan = 'Kode anggota sudah digunakan oleh anggota lain.';
                    $jenisPesan = 'error';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE members
                        SET member_code = ?, full_name = ?, phone = ?, email = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $kode,
                        $nama,
                        $telepon !== '' ? $telepon : null,
                        $email !== '' ? $email : null,
                        $id
                    ]);

                    header('Location: index.php?status=diedit');
                    exit;
                }
            }
        }

        // HAPUS ANGGOTA (SOFT DELETE)
        // Menghapus dari daftar anggota aktif, riwayat membership & pembayaran tetap tersimpan aman untuk Admin
        if ($aksi === 'hapus') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE members SET deleted_at = NOW() WHERE id = ?');
                $stmt->execute([$id]);

                header('Location: index.php?status=dihapus');
                exit;
            }
        }

        // PULIHKAN ANGGOTA (KHUSUS ADMIN)
        if ($aksi === 'pulihkan' && $isAdmin) {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE members SET deleted_at = NULL WHERE id = ?');
                $stmt->execute([$id]);

                header('Location: index.php?tab=riwayat&status=dipulihkan');
                exit;
            }
        }
    }

    if (($_GET['status'] ?? '') === 'berhasil') {
        $pesan = 'Data anggota berhasil ditambahkan.';
        $jenisPesan = 'success';
    } elseif (($_GET['status'] ?? '') === 'diedit') {
        $pesan = 'Data anggota berhasil diperbarui.';
        $jenisPesan = 'success';
    } elseif (($_GET['status'] ?? '') === 'dihapus') {
        $pesan = 'Data anggota berhasil dihapus dari daftar aktif (riwayat data tetap tersimpan untuk Admin).';
        $jenisPesan = 'success';
    } elseif (($_GET['status'] ?? '') === 'dipulihkan') {
        $pesan = 'Data anggota berhasil dipulihkan kembali ke daftar anggota aktif.';
        $jenisPesan = 'success';
    }

    // TOTAL ANGGOTA AKTIF & TERHAPUS
    $totalAnggotaAktif = (int) $pdo
        ->query('SELECT COUNT(*) FROM members WHERE deleted_at IS NULL')
        ->fetchColumn();

    $totalAnggotaTerhapus = (int) $pdo
        ->query('SELECT COUNT(*) FROM members WHERE deleted_at IS NOT NULL')
        ->fetchColumn();

    $totalAnggota = $totalAnggotaAktif;

    // AMBIL PAKET TERSEDIA
    $pkgStmt = $pdo->query('
        SELECT duration_months, package_name, price 
        FROM membership_packages 
        WHERE is_active = 1 
          AND deleted_at IS NULL
        ORDER BY duration_months ASC, price ASC
    ');
    $rawPkgs = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);
    $packagesByDuration = [];
    foreach ($rawPkgs as $p) {
        $d = (int)$p['duration_months'];
        if (!isset($packagesByDuration[$d])) {
            $packagesByDuration[$d] = $p;
        }
    }

    // PENCARIAN DAN DAFTAR ANGGOTA BESERTA STATUS MEMBERSHIP
    $baseSql = "
        SELECT 
            m.id, 
            m.member_code, 
            m.full_name, 
            m.phone, 
            m.email, 
            m.created_at,
            m.deleted_at,
            ms.id AS latest_membership_id,
            ms.package_duration_months,
            ms.start_date,
            ms.end_date,
            ms.is_active,
            mp.package_name,
            mp.price
        FROM members m
        LEFT JOIN memberships ms ON ms.id = (
            SELECT MAX(ms2.id) FROM memberships ms2 WHERE ms2.member_id = m.id
        )
        LEFT JOIN membership_packages mp ON mp.duration_months = ms.package_duration_months
    ";

    $statusCondition = ($tab === 'riwayat') ? "m.deleted_at IS NOT NULL" : "m.deleted_at IS NULL";
    $orderBy = ($tab === 'riwayat') ? "m.deleted_at DESC, m.id DESC" : "m.id DESC";

    if ($keyword !== '') {
        $stmt = $pdo->prepare($baseSql . "
            WHERE $statusCondition
              AND (m.member_code LIKE ?
               OR m.full_name LIKE ?
               OR m.phone LIKE ?
               OR m.email LIKE ?)
            ORDER BY $orderBy
        ");

        $cari = '%' . $keyword . '%';
        $stmt->execute([$cari, $cari, $cari, $cari]);
    } else {
        $stmt = $pdo->query($baseSql . " WHERE $statusCondition ORDER BY $orderBy");
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

                .status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 99px;
            font-size: 10px;
            font-weight: bold;
            background: #eef6ec;
            color: #2e693b;
            white-space: nowrap;
        }
        .status.off {
            background: #faece9;
            color: #9c3b28;
        }
        .status.wait {
            background: #fdf5e6;
            color: #a46d1c;
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
        .btn-pkg {
            background: #3b82f6 !important;
            color: #ffffff !important;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-pkg:hover {
            background: #2563eb !important;
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
        .admin-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 18px;
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
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .modal-box {
            background: #fff;
            padding: 24px 28px;
            border-radius: 12px;
            width: 100%;
            max-width: 460px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #edf0ed;
        }
        .modal-header h4 {
            margin: 0;
            font-size: 16px;
            color: #17251f;
        }
        .close-btn {
            background: none;
            border: none;
            font-size: 20px;
            color: #888;
            cursor: pointer;
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

    <!-- SIDEBAR TERPUSAT SESUAI ROLE (AUTHENTICATED LAYOUT) -->
    <?php render_sidebar('members'); ?>

    <main class="main">

        <header class="topbar">
            <div>Operasional &nbsp; › &nbsp; <strong>Data Anggota</strong></div>
            <div><?= e(tanggalID($today)) ?></div>
        </header>

        <div class="content">

            <section class="heading">
                <div>
                    <h1><?= $tab === 'riwayat' ? 'Riwayat Anggota Terhapus' : 'Data Anggota' ?></h1>
                    <p class="subtitle">
                        <?= $tab === 'riwayat' 
                            ? 'Arsip riwayat data anggota yang pernah terdaftar. Hanya dapat diakses oleh Admin.' 
                            : 'Kelola informasi dan data anggota FitDesk Gym.' ?>
                    </p>
                </div>

                <?php if ($tab === 'riwayat'): ?>
                    <a class="btn btn-secondary" href="index.php?tab=aktif">
                        ← Kembali ke Anggota Aktif
                    </a>
                <?php else: ?>
                    <a class="btn btn-primary" href="#formTambah">
                        ＋ Tambah Anggota
                    </a>
                <?php endif; ?>
            </section>

            <?php if ($pesan !== ''): ?>
                <div class="alert <?= e($jenisPesan) ?>">
                    <?= e($pesan) ?>
                </div>
            <?php endif; ?>

            <section class="stats">
                <article class="stat">
                    <div class="stat-top">
                        <span>Total Anggota Aktif</span>
                        <span class="stat-icon">♙</span>
                    </div>
                    <strong><?= number_format($totalAnggotaAktif) ?></strong>
                    <small>Anggota aktif dalam sistem</small>
                </article>

                <?php if ($isAdmin): ?>
                    <a href="index.php?tab=riwayat" class="stat" style="text-decoration:none; color:inherit;">
                        <div class="stat-top">
                            <span>Riwayat Terhapus (Admin)</span>
                            <span class="stat-icon" style="background:#fee2e2; color:#991b1b;">📁</span>
                        </div>
                        <strong style="color: <?= $totalAnggotaTerhapus > 0 ? '#b91c1c' : 'inherit' ?>;"><?= number_format($totalAnggotaTerhapus) ?></strong>
                        <small style="color:#64748b;">Klik untuk kelola arsip terhapus</small>
                    </a>
                <?php endif; ?>

                <article class="stat">
                    <div class="stat-top">
                        <span>Data Ditampilkan</span>
                        <span class="stat-icon">▤</span>
                    </div>
                    <strong><?= number_format(count($anggota)) ?></strong>
                    <small><?= $tab === 'riwayat' ? 'Arsip anggota terhapus' : 'Hasil pencarian atau daftar aktif' ?></small>
                </article>

                <?php if (!$isAdmin): ?>
                    <article class="stat">
                        <div class="stat-top">
                            <span>Pengelolaan data</span>
                            <span class="stat-icon">✓</span>
                        </div>
                        <strong style="font-size:20px">Anggota</strong>
                        <small>Data anggota FitDesk Gym</small>
                    </article>
                <?php endif; ?>
            </section>

            <?php if ($tab === 'aktif'): ?>
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

                <form method="POST" action="index.php">
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

                        <div class="form-group">
                            <label for="package_duration">Paket Membership Awal (Opsional)</label>
                            <select id="package_duration" name="package_duration" style="width:100%;height:45px;border-radius:9px;padding:0 12px;border:1px solid #e4e8e4;background:#fff;">
                                <option value="">-- Tanpa Paket Terlebih Dahulu --</option>
                                <?php foreach ($packagesByDuration as $pkg): ?>
                                    <option value="<?= (int)$pkg['duration_months'] ?>">
                                        <?= e($pkg['package_name']) ?> (<?= (int)$pkg['duration_months'] ?> Bulan - Rp <?= number_format($pkg['price'], 0, ',', '.') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="payment_plan">Rencana Pembayaran</label>
                            <select id="payment_plan" name="payment_plan" style="width:100%;height:45px;border-radius:9px;padding:0 12px;border:1px solid #e4e8e4;background:#fff;">
                                <option value="full">Lunas / Sekali Bayar</option>
                                <option value="installment_3">Cicilan 3 Kali</option>
                            </select>
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
            <?php endif; ?>

            <?php if ($isAdmin): ?>
                <!-- TAB SWITCH UNTUK ADMIN -->
                <div class="admin-tabs">
                    <a href="index.php?tab=aktif" class="admin-tab <?= $tab === 'aktif' ? 'active' : '' ?>">
                        <span>👥 Anggota Aktif</span>
                        <span class="badge-count"><?= number_format($totalAnggotaAktif) ?></span>
                    </a>
                    <a href="index.php?tab=riwayat" class="admin-tab <?= $tab === 'riwayat' ? 'active' : '' ?>">
                        <span>📁 Riwayat Anggota Terhapus (Admin)</span>
                        <span class="badge-count"><?= number_format($totalAnggotaTerhapus) ?></span>
                    </a>
                </div>
            <?php endif; ?>

            <!-- DAFTAR ANGGOTA -->
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h3><?= $tab === 'riwayat' ? 'Daftar Riwayat Anggota Terhapus' : 'Daftar Anggota' ?></h3>
                        <p class="panel-desc">
                            <?= $tab === 'riwayat' 
                                ? 'Arsip data anggota yang telah dihapus dari operasional aktif. Riwayat paket dan transaksi pembayaran tetap tersimpan secara aman.' 
                                : 'Daftar anggota aktif yang tersimpan di sistem FitDesk Gym.' ?>
                        </p>
                    </div>

                    <form class="search-form" method="GET" action="index.php">
                        <?php if ($isAdmin && $tab === 'riwayat'): ?>
                            <input type="hidden" name="tab" value="riwayat">
                        <?php endif; ?>
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
                            <a href="index.php<?= ($isAdmin && $tab === 'riwayat') ? '?tab=riwayat' : '' ?>" class="btn btn-secondary">
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
                                <th><?= $tab === 'riwayat' ? 'RIWAYAT MEMBERSHIP' : 'STATUS MEMBERSHIP' ?></th>
                                <th><?= $tab === 'riwayat' ? 'TANGGAL DIHAPUS' : 'TANGGAL DAFTAR' ?></th>
                                <th>AKSI</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php if (!$anggota): ?>
                            <tr>
                                <td colspan="8" class="empty">
                                    <?php if ($keyword !== ''): ?>
                                        Data anggota tidak ditemukan.
                                    <?php elseif ($tab === 'riwayat'): ?>
                                        Belum ada riwayat anggota yang terhapus.
                                    <?php else: ?>
                                        Belum ada data anggota aktif yang terdaftar.
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
                                        <?php if ($tab === 'riwayat'): ?>
                                            <?php if (!empty($m['latest_membership_id'])): ?>
                                                <span class="status off">
                                                    Pernah Terdaftar (<?= (int)$m['package_duration_months'] ?> bln)
                                                </span>
                                            <?php else: ?>
                                                <span class="status wait">Tanpa Riwayat Paket</span>
                                            <?php endif; ?>
                                            <span class="status off" style="margin-left:4px;">Terhapus</span>
                                        <?php else: ?>
                                            <?php if (!empty($m['latest_membership_id'])): ?>
                                                <?php 
                                                $isAktif = !empty($m['is_active']) && !empty($m['end_date']) && $m['end_date'] >= $today; 
                                                ?>
                                                <span class="status <?= $isAktif ? '' : 'off' ?>">
                                                    <?= $isAktif ? 'Aktif' : 'Kedaluwarsa' ?> 
                                                    (<?= (int)$m['package_duration_months'] ?> bln)
                                                </span>
                                            <?php else: ?>
                                                <span class="status wait">Belum Ada Paket</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ($tab === 'riwayat'): ?>
                                            <div style="color:#b91c1c; font-weight:bold;">
                                                <?= e(tanggalID($m['deleted_at'] ?? null)) ?>
                                            </div>
                                            <small style="color:#94a3b8;"><?= !empty($m['deleted_at']) ? date('H:i', strtotime($m['deleted_at'])) . ' WIB' : '' ?></small>
                                        <?php else: ?>
                                            <?= e(tanggalID($m['created_at'] ?? null)) ?>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div class="actions">
                                            <?php if ($tab === 'riwayat'): ?>
                                                <?php if ($isAdmin): ?>
                                                    <form
                                                        method="POST"
                                                        action="index.php"
                                                        onsubmit="return confirm('Pulihkan anggota ini ke daftar anggota aktif?');"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name="aksi"
                                                            value="pulihkan"
                                                        >
                                                        <input
                                                            type="hidden"
                                                            name="id"
                                                            value="<?= (int) $m['id'] ?>"
                                                        >
                                                        <button
                                                            type="submit"
                                                            class="btn btn-restore"
                                                            title="Pulihkan ke daftar anggota aktif"
                                                        >
                                                            ↺ Pulihkan
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span style="color:#94a3b8; font-size:11px;">Hanya Admin</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if (!empty($m['latest_membership_id'])): ?>
                                                    <a href="../payments/index.php?membership_id=<?= (int)$m['latest_membership_id'] ?>" class="btn btn-pay" title="Lanjut ke Pembayaran">
                                                        💳 Bayar
                                                    </a>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-pkg" onclick="openAssignModal(<?= (int)$m['id'] ?>, '<?= e(addslashes($m['full_name'])) ?>', '<?= e(addslashes($m['member_code'])) ?>')">
                                                        ＋ Beri Paket
                                                    </button>
                                                <?php endif; ?>

                                                <button type="button" class="btn btn-edit" onclick="openEditMemberModal(<?= (int)$m['id'] ?>, '<?= e(addslashes($m['member_code'])) ?>', '<?= e(addslashes($m['full_name'])) ?>', '<?= e(addslashes($m['phone'] ?? '')) ?>', '<?= e(addslashes($m['email'] ?? '')) ?>')" title="Edit Data Anggota">
                                                    ✏ Edit
                                                </button>

                                                <form
                                                    method="POST"
                                                    action="index.php"
                                                    onsubmit="return confirm('Yakin ingin menghapus data anggota ini dari daftar aktif? Riwayat membership dan transaksi pembayaran tetap tersimpan aman di arsip Admin.');"
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
                                            <?php endif; ?>
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
<!-- MODAL BERI PAKET ANGGOTA -->
<div id="assignModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-header">
            <h4>Tetapkan Paket Membership</h4>
            <button type="button" class="close-btn" onclick="closeAssignModal()">&times;</button>
        </div>
        <form method="POST" action="index.php">
            <input type="hidden" name="aksi" value="tambah_paket">
            <input type="hidden" name="member_id" id="assign_member_id">

            <p style="font-size:12px;color:#475569;margin-bottom:15px;">
                Tetapkan paket untuk: <strong id="assign_member_name" style="color:#0f172a;"></strong> (<span id="assign_member_code" style="font-family:monospace;"></span>)
            </p>

            <div class="form-group" style="margin-bottom:14px;">
                <label for="modal_package_duration" style="font-size:11px;font-weight:bold;display:block;margin-bottom:6px;">Pilih Paket Membership *</label>
                <select id="modal_package_duration" name="package_duration" required style="width:100%;height:42px;border-radius:8px;padding:0 10px;border:1px solid #cbd5e1;background:#fff;font-size:12px;">
                    <?php foreach ($packagesByDuration as $pkg): ?>
                        <option value="<?= (int)$pkg['duration_months'] ?>">
                            <?= e($pkg['package_name']) ?> (<?= (int)$pkg['duration_months'] ?> Bulan - Rp <?= number_format($pkg['price'], 0, ',', '.') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label for="modal_payment_plan" style="font-size:11px;font-weight:bold;display:block;margin-bottom:6px;">Rencana Pembayaran *</label>
                <select id="modal_payment_plan" name="payment_plan" required style="width:100%;height:42px;border-radius:8px;padding:0 10px;border:1px solid #cbd5e1;background:#fff;font-size:12px;">
                    <option value="full">Lunas / Sekali Bayar</option>
                    <option value="installment_3">Cicilan 3 Kali</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:18px;">
                <label for="modal_start_date" style="font-size:11px;font-weight:bold;display:block;margin-bottom:6px;">Tanggal Mulai</label>
                <input type="date" id="modal_start_date" name="start_date" value="<?= date('Y-m-d') ?>" style="width:100%;height:42px;border-radius:8px;padding:0 10px;border:1px solid #cbd5e1;background:#fff;font-size:12px;">
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeAssignModal()" style="padding:10px 14px;border-radius:8px;font-size:11px;cursor:pointer;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding:10px 16px;border-radius:8px;font-size:11px;font-weight:bold;cursor:pointer;">Simpan & Lanjut Bayar →</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDIT DATA ANGGOTA -->
<div id="editMemberModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-header">
            <h4>✏ Edit Data Anggota</h4>
            <button type="button" class="close-btn" onclick="closeEditMemberModal()">&times;</button>
        </div>
        <form method="POST" action="index.php">
            <input type="hidden" name="aksi" value="edit">
            <input type="hidden" name="id" id="edit_member_id">

            <div class="form-group" style="margin-bottom:14px;">
                <label for="edit_member_code" style="font-size:11px;font-weight:bold;display:block;margin-bottom:6px;">Kode Anggota *</label>
                <input type="text" id="edit_member_code" name="member_code" required maxlength="50" style="width:100%;height:40px;border-radius:8px;padding:0 12px;border:1px solid #cbd5e1;background:#fff;font-size:12px;">
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label for="edit_full_name" style="font-size:11px;font-weight:bold;display:block;margin-bottom:6px;">Nama Lengkap *</label>
                <input type="text" id="edit_full_name" name="full_name" required maxlength="100" style="width:100%;height:40px;border-radius:8px;padding:0 12px;border:1px solid #cbd5e1;background:#fff;font-size:12px;">
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label for="edit_phone" style="font-size:11px;font-weight:bold;display:block;margin-bottom:6px;">Nomor Telepon / WhatsApp</label>
                <input type="text" id="edit_phone" name="phone" maxlength="20" style="width:100%;height:40px;border-radius:8px;padding:0 12px;border:1px solid #cbd5e1;background:#fff;font-size:12px;">
            </div>

            <div class="form-group" style="margin-bottom:18px;">
                <label for="edit_email" style="font-size:11px;font-weight:bold;display:block;margin-bottom:6px;">Email</label>
                <input type="email" id="edit_email" name="email" maxlength="100" style="width:100%;height:40px;border-radius:8px;padding:0 12px;border:1px solid #cbd5e1;background:#fff;font-size:12px;">
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditMemberModal()" style="padding:10px 14px;border-radius:8px;font-size:11px;cursor:pointer;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding:10px 16px;border-radius:8px;font-size:11px;font-weight:bold;cursor:pointer;">✓ Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAssignModal(id, name, code) {
    document.getElementById('assign_member_id').value = id;
    document.getElementById('assign_member_name').textContent = name;
    document.getElementById('assign_member_code').textContent = code;
    document.getElementById('assignModal').style.display = 'flex';
}
function closeAssignModal() {
    document.getElementById('assignModal').style.display = 'none';
}

function openEditMemberModal(id, code, name, phone, email) {
    document.getElementById('edit_member_id').value = id;
    document.getElementById('edit_member_code').value = code;
    document.getElementById('edit_full_name').value = name;
    document.getElementById('edit_phone').value = phone || '';
    document.getElementById('edit_email').value = email || '';
    document.getElementById('editMemberModal').style.display = 'flex';
}
function closeEditMemberModal() {
    document.getElementById('editMemberModal').style.display = 'none';
}
</script>
</body>
</html>