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

    $time = strtotime($value ?: 'now');

    return date('j', $time) . ' ' .
        $bulan[(int) date('n', $time)] . ' ' .
        date('Y', $time);
}

$today = date('Y-m-d');
$role = $_SESSION['role'] ?? $_SESSION['user']['role'] ?? 'resepsionis';

try {
    // 1. STATISTIK UTAMA ANGGOTA & MEMBERSHIP
    $totalAnggota = (int) $pdo
        ->query("SELECT COUNT(*) FROM members WHERE deleted_at IS NULL")
        ->fetchColumn();

    $totalMembership = (int) $pdo
        ->query("SELECT COUNT(*) FROM memberships")
        ->fetchColumn();

    $totalAktif = (int) $pdo->query("
        SELECT COUNT(DISTINCT member_id)
        FROM memberships
        WHERE is_active = 1
          AND start_date <= CURDATE()
          AND end_date >= CURDATE()
    ")->fetchColumn();

    $totalKedaluwarsa = (int) $pdo->query("
        SELECT COUNT(DISTINCT member_id)
        FROM memberships
        WHERE end_date < CURDATE()
    ")->fetchColumn();

    // 2. STATISTIK PEMASUKAN GYM (IDENTIK UNTUK ADMIN & RESEPSIONIS)
    $totalPemasukan = (float) $pdo
        ->query("SELECT COALESCE(SUM(amount), 0) FROM payments")
        ->fetchColumn();

    $firstDayThisMonth = date('Y-m-01 00:00:00');
    $stmtThisMonth = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_date >= ?");
    $stmtThisMonth->execute([$firstDayThisMonth]);
    $pemasukanBulanIni = (float) $stmtThisMonth->fetchColumn();

    // 3. DAFTAR MEMBER YANG BELUM LUNAS / CICILAN BERJALAN
    $sqlMemberBelumLunas = "
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
            mp.package_name,
            COALESCE(mp.price, 0) AS price,
            COALESCE(pay.total_bayar, 0) AS total_bayar,
            COALESCE(pay.cicilan_terakhir, 0) AS cicilan_terakhir,
            COALESCE(pay.jumlah_bayar, 0) AS jumlah_bayar
        FROM members m
        INNER JOIN (
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
        WHERE (COALESCE(mp.price, 0) - COALESCE(pay.total_bayar, 0)) > 0
          AND m.deleted_at IS NULL
        ORDER BY (COALESCE(mp.price, 0) - COALESCE(pay.total_bayar, 0)) DESC, m.id DESC
    ";
    $stmtBelumLunas = $pdo->query($sqlMemberBelumLunas);
    $memberBelumLunasList = $stmtBelumLunas->fetchAll(PDO::FETCH_ASSOC);

    $totalMemberBelumLunas = count($memberBelumLunasList);
    $totalSisaTunggakan = 0;
    foreach ($memberBelumLunasList as $item) {
        $totalSisaTunggakan += max(0, (float)$item['price'] - (float)$item['total_bayar']);
    }

    // 4. JUMLAH MEMBER LUNAS & BELUM MEMILIKI PAKET (UNTUK DONUT CHART)
    $countLunas = (int) $pdo->query("
        SELECT COUNT(*) FROM (
            SELECT 
                m.id AS member_id,
                COALESCE(mp.price, 0) AS price,
                COALESCE(pay.total_bayar, 0) AS total_bayar
            FROM members m
            INNER JOIN (
                SELECT ms1.*
                FROM memberships ms1
                INNER JOIN (
                    SELECT member_id, MAX(id) AS max_id
                    FROM memberships
                    GROUP BY member_id
                ) ms2 ON ms1.id = ms2.max_id
            ) ms ON ms.member_id = m.id
            LEFT JOIN (
                SELECT duration_months, MIN(price) AS price
                FROM membership_packages
                WHERE is_active = 1
                GROUP BY duration_months
            ) mp ON mp.duration_months = ms.package_duration_months
            LEFT JOIN (
                SELECT membership_id, SUM(amount) AS total_bayar
                FROM payments
                GROUP BY membership_id
            ) pay ON pay.membership_id = ms.id
            WHERE COALESCE(mp.price, 0) > 0 
              AND COALESCE(pay.total_bayar, 0) >= COALESCE(mp.price, 0)
              AND m.deleted_at IS NULL
        ) lunas_sub
    ")->fetchColumn();

    $countTanpaPaket = (int) $pdo->query("
        SELECT COUNT(*)
        FROM members m
        LEFT JOIN memberships ms ON ms.member_id = m.id
        WHERE ms.id IS NULL
          AND m.deleted_at IS NULL
    ")->fetchColumn();

    // 5. MENGAMBIL TUJUH ANGGOTA TERBARU
    $stmt = $pdo->query("
        SELECT
            m.id,
            m.member_code,
            m.full_name,
            m.phone,
            m.email,
            m.created_at,
            ms.start_date,
            ms.end_date,
            ms.is_active,
            ms.package_duration_months
        FROM members m
        LEFT JOIN memberships ms
            ON ms.id = (
                SELECT ms2.id
                FROM memberships ms2
                WHERE ms2.member_id = m.id
                ORDER BY ms2.start_date DESC, ms2.id DESC
                LIMIT 1
            )
        WHERE m.deleted_at IS NULL
        ORDER BY m.id DESC
        LIMIT 7
    ");

    $anggotaTerbaru = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. DATA GRAFIK ENAM BULAN TERAKHIR (PEMASUKAN & PENDAFTARAN)
    $labels = [];
    $dataPemasukan = [];
    $dataAnggota = [];

    for ($i = 5; $i >= 0; $i--) {
        $bulan = date(
            'Y-m',
            strtotime("first day of this month -{$i} months")
        );

        $labels[] = date('M Y', strtotime($bulan . '-01'));

        $awal = $bulan . '-01 00:00:00';
        $akhir = date('Y-m-d 00:00:00', strtotime($bulan . '-01 +1 month'));

        $qIncome = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_date >= ? AND payment_date < ?");
        $qIncome->execute([$awal, $akhir]);
        $dataPemasukan[] = (float) $qIncome->fetchColumn();

        $q = $pdo->prepare("
            SELECT COUNT(*)
            FROM members
            WHERE created_at >= ? AND created_at < ?
        ");
        $q->execute([$awal, $akhir]);
        $dataAnggota[] = (int) $q->fetchColumn();
    }

} catch (PDOException $ex) {
    error_log('Kesalahan dashboard FitDesk: ' . $ex->getMessage());

    die(
        '<h2>Terjadi kesalahan database.</h2>
        <p>Pastikan database, tabel members, and memberships sudah tersedia.</p>'
    );
}

$namaPengguna = $_SESSION['nama_lengkap']
    ?? $_SESSION['username']
    ?? 'Pengguna';

$belumAktif = max(0, $totalAnggota - $totalAktif);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard | FitDesk Gym</title>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
        }

        h1 {
            margin: 0 0 8px;
            font-size: 25px;
        }

        .subtitle {
            margin: 0;
            color: #818b83;
            font-size: 12px;
        }

        /* SAPAAN */
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

        .welcome .date {
            z-index: 1;
            background: #c5f578;
            color: #1d2a20;
            padding: 10px 13px;
            border-radius: 9px;
            font-size: 11px;
            font-weight: bold;
            white-space: nowrap;
        }

        /* KARTU STATISTIK */
        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
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
            letter-spacing: -.5px;
        }

        .stat small {
            display: block;
            margin-top: 7px;
            color: #8b938d;
            font-size: 10px;
        }

        /* GRAFIK */
        .grid {
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) minmax(280px, 1fr);
            gap: 17px;
        }

        .panel {
            padding: 21px;
            border: 1px solid #e5eae5;
            border-radius: 13px;
            background: #fff;
            min-width: 0;
        }

        .panel-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 18px;
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

        .chart-box {
            position: relative;
            height: 275px;
        }

        /* TOMBOL AKSES CEPAT */
        .quick {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-top: 17px;
        }

        .quick a {
            padding: 14px;
            border: 1px solid #e5eae5;
            border-radius: 10px;
            background: #fbfcfa;
            transition: .15s;
        }

        .quick a:hover {
            border-color: #c5f578;
            background: #f7fbef;
        }

        .quick b {
            display: block;
            margin-bottom: 6px;
            font-size: 12px;
        }

        .quick span {
            font-size: 10px;
            color: #858e87;
        }

        /* TABEL ANGGOTA */
        .table-wrap {
            overflow-x: auto;
            margin-top: 15px;
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

        .member-name {
            font-weight: bold;
            color: #26342b;
        }

        .subtext {
            display: block;
            margin-top: 4px;
            color: #89928b;
            font-size: 10px;
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

        .status.wait {
            background: #fff6df;
            color: #8b6a20;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: bold;
            white-space: nowrap;
        }

        .badge-green {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        .badge-orange {
            background: #ffedd5;
            color: #c2410c;
            border: 1px solid #fed7aa;
        }

        .badge-red {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .btn-pay {
            background: #c5f578 !important;
            color: #17251f !important;
            font-weight: bold;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11px;
            transition: .2s;
        }

        .btn-pay:hover {
            background: #b5e865 !important;
            transform: translateY(-1px);
        }

        .view-all {
            font-size: 11px;
            color: #3f6b45;
            font-weight: bold;
        }

        .empty {
            text-align: center;
            padding: 25px;
            color: #858e87;
        }

        .foot {
            margin-top: 18px;
            color: #8a938c;
            font-size: 10px;
        }

        /* RESPONSIVE */
        @media (max-width: 1050px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .grid {
                grid-template-columns: 1fr;
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

            .quick {
                grid-template-columns: 1fr 1fr;
            }

            .chart-box {
                height: 240px;
            }
        }
    </style>
</head>

<body>
<div class="layout">

    <!-- SIDEBAR TERPUSAT SESUAI ROLE (AUTHENTICATED LAYOUT) -->
    <?php render_sidebar('dashboard'); ?>

    <!-- KONTEN UTAMA -->
    <main class="main">

        <header class="topbar">
            <div>Operasional &nbsp; › &nbsp; <strong>Dashboard</strong></div>
            <div><?= e(tanggalID($today)) ?></div>
        </header>

        <div class="content">

            <!-- SAPAAN -->
            <section class="welcome">
                <div>
                    <h2>Selamat datang, <?= e($namaPengguna) ?>! 👋</h2>
                    <p>
                        Pantau anggota, membership, dan aktivitas
                        FitDesk Gym dalam satu halaman.
                    </p>
                </div>

                <div class="date">
                    ▦ &nbsp; <?= e(tanggalID($today)) ?>
                </div>
            </section>

            <!-- STATISTIK PEMASUKAN & MEMBER (ADMIN & RESEPSIONIS SAMA) -->
            <section class="stats">

                <article class="stat">
                    <div class="stat-top">
                        <span>Total pemasukan</span>
                        <span class="stat-icon" style="background:#eaf7ee;color:#267448;">↗</span>
                    </div>
                    <strong><?= rupiah($totalPemasukan) ?></strong>
                    <small>Akumulasi seluruh transaksi masuk</small>
                </article>

                <article class="stat">
                    <div class="stat-top">
                        <span>Pemasukan bulan ini</span>
                        <span class="stat-icon" style="background:#ecfdf5;color:#059669;">▦</span>
                    </div>
                    <strong><?= rupiah($pemasukanBulanIni) ?></strong>
                    <small>Periode <?= date('F Y') ?></small>
                </article>

                <article class="stat">
                    <div class="stat-top">
                        <span>Member belum lunas</span>
                        <span class="stat-icon" style="background:#fff1dc;color:#9a620e;">◷</span>
                    </div>
                    <strong><?= number_format($totalMemberBelumLunas) ?> Orang</strong>
                    <small>Sisa tagihan: <?= rupiah($totalSisaTunggakan) ?></small>
                </article>

                <article class="stat">
                    <div class="stat-top">
                        <span>Total anggota</span>
                        <span class="stat-icon" style="background:#eff6ff;color:#2563eb;">♙</span>
                    </div>
                    <strong><?= number_format($totalAnggota) ?> Orang</strong>
                    <small><?= number_format($totalAktif) ?> Membership aktif</small>
                </article>

            </section>

            <!-- GRAFIK LAPORAN (PEMASUKAN & STATUS PEMBAYARAN) -->
            <section class="grid">

                <article class="panel">
                    <div class="panel-head">
                        <div>
                            <h3>Tren pemasukan & pendaftaran</h3>
                            <p>
                                Pemasukan gym (Rp) dan jumlah pendaftaran anggota baru selama enam bulan terakhir.
                            </p>
                        </div>
                    </div>

                    <div class="chart-box">
                        <canvas id="trendChart"></canvas>
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-head">
                        <div>
                            <h3>Status pembayaran member</h3>
                            <p>Proporsi kelunasan dan cicilan seluruh anggota.</p>
                        </div>
                    </div>

                    <div class="chart-box" style="height:220px">
                        <canvas id="statusChart"></canvas>
                    </div>

                    <div class="quick">
                        <?php if (($role ?? '') === 'admin'): ?>
                            <a href="../memberships/index.php">
                                <b>♧ Paket Membership</b>
                                <span>Kelola tarif & status member</span>
                            </a>

                            <a href="../reports/index.php">
                                <b>▤ Laporan Operasional</b>
                                <span>Rekap transaksi & pendapatan</span>
                            </a>

                            <a href="../users/index.php">
                                <b>⚙ Pengguna Sistem</b>
                                <span>Kelola akun admin & staf</span>
                            </a>
                        <?php else: ?>
                            <a href="../members/index.php">
                                <b>♙ Data Anggota</b>
                                <span>Kelola & pendaftaran anggota</span>
                            </a>

                            <a href="../memberships/index.php">
                                <b>♧ Membership</b>
                                <span>Lihat tarif paket & keanggotaan</span>
                            </a>

                            <a href="../payments/index.php">
                                <b>💳 Pembayaran</b>
                                <span>Catat pembayaran cicil & tunai</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </article>

            </section>

            <!-- TABEL MEMBER BELUM LUNAS / CICILAN BERJALAN (ADMIN & RESEPSIONIS SAMA) -->
            <section class="panel" style="margin-top:17px;border-left:4px solid #f59e0b;">
                <div class="panel-head">
                    <div>
                        <h3>Daftar Member Belum Lunas / Cicilan Berjalan</h3>
                        <p>Daftar anggota yang masih memiliki sisa tagihan membership atau cicilan belum lunas.</p>
                    </div>

                    <?php if (($role ?? '') === 'admin'): ?>
                        <a class="view-all" href="../memberships/index.php#statusMemberSection">
                            Buka status kelunasan member →
                        </a>
                    <?php else: ?>
                        <a class="view-all" href="../payments/index.php">
                            Buka menu pembayaran →
                        </a>
                    <?php endif; ?>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>NO</th>
                                <th>KODE & NAMA MEMBER</th>
                                <th>TELEPON</th>
                                <th>PAKET MEMBERSHIP</th>
                                <th>HARGA PAKET</th>
                                <th>SUDAH DIBAYAR</th>
                                <th>SISA TAGIHAN</th>
                                <th>STATUS</th>
                                <th>AKSI</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php if (!$memberBelumLunasList): ?>
                            <tr>
                                <td colspan="9" class="empty" style="color:#15803d;background:#f0fdf4;padding:25px;">
                                    ✓ Semua pembayaran member lunas! Tidak ada tunggakan saat ini.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($memberBelumLunasList as $idx => $m): ?>
                                <?php
                                $harga = (float)$m['price'];
                                $dibayar = (float)$m['total_bayar'];
                                $sisa = max(0, $harga - $dibayar);
                                $isCicil = ($m['payment_plan'] === 'installment_3');
                                $cicilanTerakhir = (int)$m['cicilan_terakhir'];
                                ?>
                                <tr>
                                    <td><?= $idx + 1 ?></td>
                                    <td>
                                        <span class="member-name"><?= e($m['full_name']) ?></span>
                                        <span class="subtext" style="font-family:monospace;"><?= e($m['member_code']) ?></span>
                                    </td>
                                    <td><?= e($m['phone'] ?: '-') ?></td>
                                    <td>
                                        <strong><?= e($m['package_name'] ?? ($m['package_duration_months'] . ' Bulan')) ?></strong>
                                        <span class="subtext"><?= (int)$m['package_duration_months'] ?> bulan</span>
                                    </td>
                                    <td><?= rupiah($harga) ?></td>
                                    <td><?= rupiah($dibayar) ?></td>
                                    <td><strong style="color:#b91c1c;"><?= rupiah($sisa) ?></strong></td>
                                    <td>
                                        <?php if ($isCicil): ?>
                                            <?php if ($cicilanTerakhir > 0): ?>
                                                <span class="badge badge-orange">
                                                    Cicilan Ke-<?= $cicilanTerakhir ?> / 3 (Sisa <?= 3 - $cicilanTerakhir ?>x)
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-orange">Cicilan Belum Dibayar</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge badge-red">Belum Bayar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (($role ?? '') === 'admin'): ?>
                                            <a href="../memberships/index.php#statusMemberSection" class="btn-pay" style="background:#eaf7ee;color:#267448;border:1px solid #cce9d4;" title="Lihat Status Kelunasan">
                                                👁️ Cek Status
                                            </a>
                                        <?php else: ?>
                                            <a href="../payments/index.php?membership_id=<?= (int)$m['membership_id'] ?>" class="btn-pay" title="Lanjut Bayar">
                                                💳 <?= $isCicil ? 'Bayar Cicilan ' . ($cicilanTerakhir + 1) : 'Bayar' ?>
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

            <!-- TABEL ANGGOTA TERBARU -->
            <section class="panel" style="margin-top:17px">

                <div class="panel-head">
                    <div>
                        <h3>Anggota terbaru</h3>
                        <p>Data anggota terakhir yang tercatat di sistem.</p>
                    </div>

                    <?php if (($role ?? '') === 'admin'): ?>
                        <a class="view-all" href="../memberships/index.php#statusMemberSection">
                            Lihat status semua member →
                        </a>
                    <?php else: ?>
                        <a class="view-all" href="../members/index.php">
                            Lihat semua anggota →
                        </a>
                    <?php endif; ?>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>KODE ANGGOTA</th>
                                <th>NAMA ANGGOTA</th>
                                <th>TELEPON</th>
                                <th>TANGGAL DAFTAR</th>
                                <th>STATUS MEMBERSHIP</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php if (!$anggotaTerbaru): ?>

                            <tr>
                                <td colspan="5" class="empty">
                                    Belum ada anggota terdaftar.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($anggotaTerbaru as $m): ?>

                                <?php
                                $aktif = !empty($m['is_active'])
                                    && !empty($m['start_date'])
                                    && $m['start_date'] <= $today
                                    && $m['end_date'] >= $today;

                                $expired = !empty($m['end_date'])
                                    && $m['end_date'] < $today;
                                ?>

                                <tr>
                                    <td><?= e($m['member_code']) ?></td>

                                    <td>
                                        <span class="member-name">
                                            <?= e($m['full_name']) ?>
                                        </span>

                                        <?php if (!empty($m['email'])): ?>
                                            <span class="subtext">
                                                <?= e($m['email']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td><?= e($m['phone']) ?></td>

                                    <td>
                                        <?= e(
                                            $m['created_at']
                                                ? tanggalID($m['created_at'])
                                                : '-'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?php if ($aktif): ?>

                                            <span class="status">Aktif</span>

                                        <?php elseif ($expired): ?>

                                            <span class="status off">
                                                Kedaluwarsa
                                            </span>

                                        <?php else: ?>

                                            <span class="status wait">
                                                Belum aktif
                                            </span>

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
                FitDesk Gym © <?= date('Y') ?> — Dashboard operasional.
            </div>

        </div>
    </main>
</div>

<!-- JAVASCRIPT GRAFIK -->
<script>
const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
const pemasukanData = <?= json_encode($dataPemasukan) ?>;
const anggotaData = <?= json_encode($dataAnggota) ?>;

const countLunas = <?= (int) $countLunas ?>;
const countBelumLunas = <?= (int) $totalMemberBelumLunas ?>;
const countTanpaPaket = <?= (int) $countTanpaPaket ?>;

if (window.Chart) {

    // Grafik Tren Pemasukan & Pendaftaran (Kombinasi Bar & Line)
    new Chart(document.getElementById('trendChart'), {
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Pemasukan (Rp)',
                    data: pemasukanData,
                    backgroundColor: 'rgba(92, 152, 94, 0.72)',
                    borderColor: '#437545',
                    borderWidth: 1,
                    borderRadius: 6,
                    yAxisID: 'yPemasukan',
                    order: 2
                },
                {
                    type: 'line',
                    label: 'Pendaftaran Anggota',
                    data: anggotaData,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.1)',
                    fill: false,
                    tension: .35,
                    borderWidth: 2.5,
                    pointRadius: 4,
                    pointBackgroundColor: '#2563eb',
                    yAxisID: 'yAnggota',
                    order: 1
                }
            ]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,

            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8,
                        font: { size: 11 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            if (context.dataset.yAxisID === 'yPemasukan') {
                                return context.dataset.label + ': Rp ' + new Intl.NumberFormat('id-ID').format(context.raw);
                            }
                            return context.dataset.label + ': ' + context.raw + ' Orang';
                        }
                    }
                }
            },

            scales: {
                yAnggota: {
                    type: 'linear',
                    position: 'left',
                    beginAtZero: true,
                    ticks: { precision: 0 },
                    grid: { color: '#edf0ed' },
                    title: { display: true, text: 'Anggota Baru (Orang)', font: { size: 10 } }
                },
                yPemasukan: {
                    type: 'linear',
                    position: 'right',
                    beginAtZero: true,
                    grid: { display: false },
                    ticks: {
                        callback: function(val) {
                            if (val >= 1000000) return 'Rp ' + (val / 1000000).toFixed(1) + ' jt';
                            if (val >= 1000) return 'Rp ' + (val / 1000) + ' rb';
                            return 'Rp ' + val;
                        }
                    },
                    title: { display: true, text: 'Pemasukan (Rp)', font: { size: 10 } }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });

    // Grafik donat status pembayaran member
    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',

        data: {
            labels: [
                'Member Lunas (' + countLunas + ')',
                'Belum Lunas / Cicil (' + countBelumLunas + ')',
                'Belum Ada Paket (' + countTanpaPaket + ')'
            ],

            datasets: [{
                data: [countLunas, countBelumLunas, countTanpaPaket],
                backgroundColor: [
                    '#15803d',
                    '#f59e0b',
                    '#94a3b8'
                ],
                borderWidth: 0,
                hoverOffset: 6
            }]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',

            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8,
                        padding: 12,
                        font: { size: 10 }
                    }
                }
            }
        }
    });
}
</script>

</body>
</html>
