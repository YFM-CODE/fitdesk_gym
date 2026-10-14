
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

try {
    // Statistik utama
    $totalAnggota = (int) $pdo
        ->query("SELECT COUNT(*) FROM members")
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

    // Mengambil tujuh anggota terbaru dan membership terakhirnya
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
        ORDER BY m.id DESC
        LIMIT 7
    ");

    $anggotaTerbaru = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Data grafik enam bulan terakhir
    $labels = [];
    $dataAnggota = [];
    $dataMembership = [];

    for ($i = 5; $i >= 0; $i--) {
        $bulan = date(
            'Y-m',
            strtotime("first day of this month -{$i} months")
        );

        $labels[] = date('M Y', strtotime($bulan . '-01'));

        $awal = $bulan . '-01';
        $akhir = date('Y-m-d', strtotime($awal . ' +1 month'));

        $q = $pdo->prepare("
            SELECT COUNT(*)
            FROM members
            WHERE created_at >= ? AND created_at < ?
        ");
        $q->execute([$awal, $akhir]);
        $dataAnggota[] = (int) $q->fetchColumn();

        $q = $pdo->prepare("
            SELECT COUNT(*)
            FROM memberships
            WHERE created_at >= ? AND created_at < ?
        ");
        $q->execute([$awal, $akhir]);
        $dataMembership[] = (int) $q->fetchColumn();
    }

} catch (PDOException $ex) {
    error_log('Kesalahan dashboard FitDesk: ' . $ex->getMessage());

    die(
        '<h2>Terjadi kesalahan database.</h2>
        <p>Pastikan database, tabel members, dan memberships sudah tersedia.</p>'
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

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="brand">FitDesk<span> Gym.</span></div>

        <div class="menu-label">Menu utama</div>

        <nav class="menu">
            <a class="active" href="dashboard.php">▦ Dashboard</a>
            <a href="anggota.php">♙ Data Anggota</a>
            <a href="memberships.php">♧ Paket Membership</a>
            <a href="laporan.php">▤ Laporan</a>
        </nav>

        <div class="menu-label">Akun</div>

        <nav class="menu">
            <a href="logout.php">↪ Keluar</a>
        </nav>
    </aside>

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

            <!-- STATISTIK -->
            <section class="stats">

                <article class="stat">
                    <div class="stat-top">
                        <span>Total anggota</span>
                        <span class="stat-icon">♙</span>
                    </div>
                    <strong><?= number_format($totalAnggota) ?></strong>
                    <small>Seluruh anggota terdaftar</small>
                </article>

                <article class="stat">
                    <div class="stat-top">
                        <span>Membership aktif</span>
                        <span class="stat-icon">✓</span>
                    </div>
                    <strong><?= number_format($totalAktif) ?></strong>
                    <small>Membership masih berlaku</small>
                </article>

                <article class="stat">
                    <div class="stat-top">
                        <span>Membership kedaluwarsa</span>
                        <span class="stat-icon">◷</span>
                    </div>
                    <strong><?= number_format($totalKedaluwarsa) ?></strong>
                    <small>Anggota dengan riwayat berakhir</small>
                </article>

                <article class="stat">
                    <div class="stat-top">
                        <span>Total riwayat membership</span>
                        <span class="stat-icon">▤</span>
                    </div>
                    <strong><?= number_format($totalMembership) ?></strong>
                    <small>Seluruh transaksi membership</small>
                </article>

            </section>

            <!-- GRAFIK -->
            <section class="grid">

                <article class="panel">
                    <div class="panel-head">
                        <div>
                            <h3>Tren pendaftaran</h3>
                            <p>
                                Jumlah anggota baru dan membership
                                selama enam bulan terakhir.
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
                            <h3>Status membership</h3>
                            <p>Ringkasan status keanggotaan saat ini.</p>
                        </div>
                    </div>

                    <div class="chart-box" style="height:220px">
                        <canvas id="statusChart"></canvas>
                    </div>

                    <div class="quick">
                        <a href="anggota.php">
                            <b>＋ Tambah anggota</b>
                            <span>Daftarkan anggota baru</span>
                        </a>

                        <a href="memberships.php">
                            <b>♧ Membership</b>
                            <span>Kelola paket dan masa aktif</span>
                        </a>

                        <a href="laporan.php">
                            <b>▤ Laporan</b>
                            <span>Lihat ringkasan operasional</span>
                        </a>

                        <a href="anggota.php">
                            <b>⌕ Data anggota</b>
                            <span>Cari dan kelola data</span>
                        </a>
                    </div>
                </article>

            </section>

            <!-- TABEL ANGGOTA TERBARU -->
            <section class="panel" style="margin-top:17px">

                <div class="panel-head">
                    <div>
                        <h3>Anggota terbaru</h3>
                        <p>Data anggota terakhir yang tercatat di sistem.</p>
                    </div>

                    <a class="view-all" href="anggota.php">
                        Lihat semua anggota →
                    </a>
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
const anggotaData = <?= json_encode($dataAnggota) ?>;
const membershipData = <?= json_encode($dataMembership) ?>;

const aktif = <?= (int) $totalAktif ?>;
const kedaluwarsa = <?= (int) $totalKedaluwarsa ?>;
const belumAktif = <?= (int) $belumAktif ?>;

if (window.Chart) {

    // Grafik garis pendaftaran
    new Chart(document.getElementById('trendChart'), {
        type: 'line',

        data: {
            labels: labels,

            datasets: [
                {
                    label: 'Anggota baru',
                    data: anggotaData,
                    borderColor: '#4b8b57',
                    backgroundColor: 'rgba(75,139,87,.12)',
                    fill: true,
                    tension: .35,
                    borderWidth: 2,
                    pointRadius: 3
                },
                {
                    label: 'Membership baru',
                    data: membershipData,
                    borderColor: '#a0c94f',
                    backgroundColor: 'rgba(160,201,79,.08)',
                    fill: true,
                    tension: .35,
                    borderWidth: 2,
                    pointRadius: 3
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
                        boxWidth: 7,
                        font: { size: 10 }
                    }
                }
            },

            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 },
                    grid: { color: '#edf0ed' }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });

    // Grafik donat status membership
    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',

        data: {
            labels: [
                'Membership aktif',
                'Kedaluwarsa',
                'Belum aktif / tanpa membership aktif'
            ],

            datasets: [{
                data: [aktif, kedaluwarsa, belumAktif],
                backgroundColor: [
                    '#5c985e',
                    '#e8a18e',
                    '#c5f578'
                ],
                borderWidth: 0,
                hoverOffset: 5
            }]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',

            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 7,
                        padding: 14,
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
