
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

function tanggalID($tanggal)
{
    if (!$tanggal) {
        return '-';
    }

    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April',
        'Mei', 'Juni', 'Juli', 'Agustus',
        'September', 'Oktober', 'November', 'Desember'
    ];

    $waktu = strtotime($tanggal);

    return date('j', $waktu) . ' ' .
        $bulan[(int)date('n', $waktu)] . ' ' .
        date('Y', $waktu);
}

function tanggalValid($tanggal)
{
    if (!$tanggal) {
        return false;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $tanggal);

    return $date && $date->format('Y-m-d') === $tanggal;
}

$today = date('Y-m-d');
$tanggalMulai = $_GET['mulai'] ?? date('Y-m-01');
$tanggalSelesai = $_GET['selesai'] ?? $today;
$cari = trim($_GET['cari'] ?? '');
$aksi = $_GET['aksi'] ?? '';

if (!tanggalValid($tanggalMulai)) {
    $tanggalMulai = date('Y-m-01');
}

if (!tanggalValid($tanggalSelesai)) {
    $tanggalSelesai = $today;
}

if ($tanggalMulai > $tanggalSelesai) {
    $temp = $tanggalMulai;
    $tanggalMulai = $tanggalSelesai;
    $tanggalSelesai = $temp;
}

if (mb_strlen($cari) > 100) {
    $cari = mb_substr($cari, 0, 100);
}

$namaPengguna = $_SESSION['nama_lengkap']
    ?? $_SESSION['username']
    ?? 'Pengguna';

try {
    /*
     * LAPORAN MEMBERSHIP
     * Rentang tanggal menggunakan tanggal mulai membership.
     */
    $sql = "
        SELECT
            ms.id AS membership_id,
            ms.member_id,
            ms.package_duration_months,
            ms.start_date,
            ms.end_date,
            ms.is_active,
            ms.created_at AS membership_created_at,
            m.member_code,
            m.full_name,
            m.phone,
            m.email,
            m.created_at AS member_created_at
        FROM memberships ms
        INNER JOIN members m ON m.id = ms.member_id
        WHERE ms.start_date >= ?
          AND ms.start_date <= ?
    ";

    $params = [
        $tanggalMulai,
        $tanggalSelesai
    ];

    if ($cari !== '') {
        $sql .= "
            AND (
                m.member_code LIKE ?
                OR m.full_name LIKE ?
                OR m.phone LIKE ?
                OR m.email LIKE ?
            )
        ";

        $kata = '%' . $cari . '%';

        array_push(
            $params,
            $kata,
            $kata,
            $kata,
            $kata
        );
    }

    $sql .= "
        ORDER BY ms.start_date DESC, ms.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $laporan = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Statistik keseluruhan
    $totalAnggota = (int)$pdo
        ->query("SELECT COUNT(*) FROM members")
        ->fetchColumn();

    $totalMembership = (int)$pdo
        ->query("SELECT COUNT(*) FROM memberships")
        ->fetchColumn();

    $totalAktif = (int)$pdo->query("
        SELECT COUNT(DISTINCT member_id)
        FROM memberships
        WHERE is_active = 1
          AND start_date <= CURDATE()
          AND end_date >= CURDATE()
    ")->fetchColumn();

    $totalKedaluwarsa = (int)$pdo->query("
        SELECT COUNT(DISTINCT member_id)
        FROM memberships
        WHERE end_date < CURDATE()
    ")->fetchColumn();

    // Statistik untuk rentang laporan yang dipilih
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM memberships ms
        INNER JOIN members m ON m.id = ms.member_id
        WHERE ms.start_date >= ?
          AND ms.start_date <= ?
    ");
    $stmt->execute([$tanggalMulai, $tanggalSelesai]);
    $totalPeriode = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM memberships ms
        INNER JOIN members m ON m.id = ms.member_id
        WHERE ms.start_date >= ?
          AND ms.start_date <= ?
          AND ms.end_date >= CURDATE()
          AND ms.start_date <= CURDATE()
          AND ms.is_active = 1
    ");
    $stmt->execute([$tanggalMulai, $tanggalSelesai]);
    $aktifPeriode = (int)$stmt->fetchColumn();

    // Rekap berdasarkan durasi membership
    $stmt = $pdo->prepare("
        SELECT
            ms.package_duration_months,
            COUNT(*) AS jumlah
        FROM memberships ms
        WHERE ms.start_date >= ?
          AND ms.start_date <= ?
        GROUP BY ms.package_duration_months
        ORDER BY ms.package_duration_months ASC
    ");
    $stmt->execute([$tanggalMulai, $tanggalSelesai]);
    $rekapPaket = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Grafik jumlah membership per bulan selama enam bulan terakhir
    $labelGrafik = [];
    $dataGrafik = [];

    for ($i = 5; $i >= 0; $i--) {
        $bulan = date(
            'Y-m',
            strtotime("first day of this month -{$i} months")
        );

        $labelGrafik[] = date('M Y', strtotime($bulan . '-01'));

        $awal = $bulan . '-01';
        $akhir = date('Y-m-d', strtotime($awal . ' +1 month'));

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM memberships
            WHERE start_date >= ?
              AND start_date < ?
        ");

        $stmt->execute([$awal, $akhir]);
        $dataGrafik[] = (int)$stmt->fetchColumn();
    }

} catch (PDOException $ex) {
    error_log('Kesalahan laporan FitDesk: ' . $ex->getMessage());

    die(
        '<h2>Gagal memuat laporan</h2>
        <p>Pastikan tabel members dan memberships tersedia serta koneksi database benar.</p>'
    );
}

/*
 * EKSPOR CSV
 */
if ($aksi === 'csv') {
    $namaFile = 'laporan-membership-' . date('Ymd-His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $namaFile . '"');

    $output = fopen('php://output', 'w');

    // BOM agar karakter Indonesia terbaca baik di Excel.
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'No',
        'Kode Anggota',
        'Nama Anggota',
        'Telepon',
        'Email',
        'Durasi Membership (Bulan)',
        'Tanggal Mulai',
        'Tanggal Berakhir',
        'Status'
    ], ';');

    foreach ($laporan as $index => $row) {
        $aktif = (int)$row['is_active'] === 1
            && $row['start_date'] <= $today
            && $row['end_date'] >= $today;

        $status = $aktif
            ? 'Aktif'
            : (
                $row['end_date'] < $today
                    ? 'Kedaluwarsa'
                    : 'Tidak aktif / belum mulai'
            );

        fputcsv($output, [
            $index + 1,
            $row['member_code'],
            $row['full_name'],
            $row['phone'],
            $row['email'],
            $row['package_duration_months'],
            $row['start_date'],
            $row['end_date'],
            $status
        ], ';');
    }

    fclose($output);
    exit;
}

$jumlahHasil = count($laporan);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Laporan | FitDesk Gym</title>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f6f4;color:#202923;font-family:Arial,Helvetica,sans-serif;font-size:13px}
a{text-decoration:none;color:inherit}
button,input{font:inherit}
.layout{display:flex;min-height:100vh}
.sidebar{width:235px;flex-shrink:0;padding:26px 15px;background:#182720;color:#fff}
.brand{font-size:23px;font-weight:800;padding:0 12px 30px}
.brand span{color:#c5f578}
.menu-label{margin:18px 12px 10px;color:#a4b1a8;font-size:10px;text-transform:uppercase;letter-spacing:1px}
.menu a{display:block;margin:5px 0;padding:13px 12px;border-radius:9px;color:#e0e7e2;font-size:13px;transition:.2s}
.menu a:hover,.menu a.active{background:#c5f578;color:#17251f;font-weight:bold}
.main{flex:1;min-width:0}
.topbar{min-height:58px;padding:14px 28px;background:#fff;border-bottom:1px solid #e4e9e5;display:flex;align-items:center;justify-content:space-between;gap:12px;color:#737d75;font-size:11px}
.content{max-width:1500px;margin:auto;padding:27px 28px 35px}
.heading{display:flex;align-items:center;justify-content:space-between;gap:15px;flex-wrap:wrap;margin-bottom:22px}
h1{margin:0 0 8px;font-size:25px}
.subtitle{margin:0;color:#818b83;font-size:12px;line-height:1.6}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:11px 14px;border:0;border-radius:8px;background:#c5f578;color:#1c3026;font-weight:bold;font-size:11px;cursor:pointer}
.btn:hover{filter:brightness(.96)}
.btn-dark{background:#1c3026;color:#fff}
.btn-light{background:#eef2ed;color:#344239}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-bottom:18px}
.stat{padding:19px;border:1px solid #e5eae5;border-radius:12px;background:#fff}
.stat-label{display:flex;justify-content:space-between;align-items:center;gap:8px;color:#758078;font-size:11px}
.stat-icon{display:grid;place-items:center;width:35px;height:35px;border-radius:10px;background:#eff6e7;color:#315a37;font-size:17px}
.stat strong{display:block;margin-top:12px;font-size:26px}
.stat small{display:block;margin-top:7px;color:#89928b;font-size:10px}
.grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(270px,1fr);gap:17px}
.panel{padding:21px;border:1px solid #e5eae5;border-radius:13px;background:#fff;margin-bottom:17px;min-width:0}
.panel-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.panel h3{margin:0 0 6px;font-size:15px}
.panel p{margin:0;color:#858e87;font-size:11px;line-height:1.5}
.chart-box{position:relative;height:270px}
.filter{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap}
.field{display:flex;flex-direction:column;gap:7px}
.field label{font-size:10px;font-weight:bold;color:#5d685f}
.field input{min-width:145px;padding:10px;border:1px solid #dfe5df;border-radius:8px;background:#fff;outline:none}
.field input:focus{border-color:#8fb65a}
.search{flex:1;min-width:190px}
.search input{width:100%}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;white-space:nowrap}
th{text-align:left;padding:13px 10px;background:#f7f8f6;color:#7e887f;font-size:10px}
td{padding:13px 10px;border-bottom:1px solid #edf0ed;font-size:11px}
tr:last-child td{border-bottom:0}
.member-name{font-weight:bold;color:#26342b}
.subtext{display:block;margin-top:4px;color:#89928b;font-size:10px}
.status{display:inline-block;padding:6px 9px;border-radius:20px;background:#eaf7ee;color:#267448;font-size:10px}
.status.off{background:#fff0ed;color:#a44a3f}
.status.wait{background:#fff6df;color:#8b6a20}
.empty{text-align:center;padding:30px 15px;color:#858e87}
.summary-row{display:flex;justify-content:space-between;gap:12px;padding:13px 0;border-bottom:1px solid #edf0ed;font-size:11px}
.summary-row:last-child{border-bottom:0}
.summary-row span{color:#7c867e}
.summary-row strong{color:#26342b}
.badge{display:inline-block;padding:6px 9px;border-radius:20px;background:#eff6e7;color:#315a37;font-size:10px}
.foot{margin-top:18px;color:#8a938c;font-size:10px}
@media(max-width:1100px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.grid{grid-template-columns:1fr}}
@media(max-width:700px){.layout{display:block}.sidebar{width:100%;padding:15px}.brand{padding:0 10px 12px}.menu{display:flex;gap:5px;overflow-x:auto}.menu-label{display:none}.menu a{white-space:nowrap;padding:10px}.topbar{padding:14px 15px}.content{padding:20px 14px}.stats{gap:10px}.stat{padding:14px}.stat strong{font-size:23px}.panel{padding:15px}.filter{align-items:stretch}.field,.field input{width:100%}.chart-box{height:240px}}
@media print{
    body{background:white;font-size:10px}
    .sidebar,.topbar,.no-print,.filter,.foot{display:none!important}
    .layout{display:block}
    .content{max-width:none;padding:0}
    .panel,.stat{box-shadow:none;border:1px solid #ddd;break-inside:avoid}
    .stats{grid-template-columns:repeat(4,1fr)}
    .grid{grid-template-columns:1fr 1fr}
    .chart-box{height:200px}
    th,td{padding:7px 5px}
}
</style>
</head>

<body>
<div class="layout">

<aside class="sidebar no-print">
    <div class="brand">FitDesk<span> Gym.</span></div>

    <div class="menu-label">Menu utama</div>
    <nav class="menu">
        <a href="dashboard.php">▦ Dashboard</a>
        <a href="anggota.php">♙ Data Anggota</a>
        <a href="memberships.php">♧ Paket Membership</a>
        <a href="laporan.php" class="active">▤ Laporan</a>
    </nav>

    <div class="menu-label">Akun</div>
    <nav class="menu">
        <a href="logout.php">↪ Keluar</a>
    </nav>
</aside>

<main class="main">

<header class="topbar">
    <div>Operasional &nbsp; › &nbsp; <strong>Laporan</strong></div>
    <div><?= e($namaPengguna) ?></div>
</header>

<div class="content">

    <div class="heading">
        <div>
            <h1>Laporan Membership</h1>
            <p class="subtitle">
                Pantau data membership, masa berlaku, dan tren pendaftaran anggota.
            </p>
        </div>

        <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn btn-dark" type="button" onclick="window.print()">
                ⎙ Cetak Laporan
            </button>

            <a class="btn" href="?mulai=<?= e($tanggalMulai) ?>&selesai=<?= e($tanggalSelesai) ?>&cari=<?= urlencode($cari) ?>&aksi=csv">
                ↓ Ekspor CSV
            </a>
        </div>
    </div>

    <section class="stats">
        <article class="stat">
            <div class="stat-label">
                <span>Total anggota</span>
                <span class="stat-icon">♙</span>
            </div>
            <strong><?= number_format($totalAnggota) ?></strong>
            <small>Semua anggota terdaftar</small>
        </article>

        <article class="stat">
            <div class="stat-label">
                <span>Membership aktif</span>
                <span class="stat-icon">✓</span>
            </div>
            <strong><?= number_format($totalAktif) ?></strong>
            <small>Masih dalam masa berlaku</small>
        </article>

        <article class="stat">
            <div class="stat-label">
                <span>Kedaluwarsa</span>
                <span class="stat-icon">◷</span>
            </div>
            <strong><?= number_format($totalKedaluwarsa) ?></strong>
            <small>Memiliki membership yang sudah berakhir</small>
        </article>

        <article class="stat">
            <div class="stat-label">
                <span>Membership periode ini</span>
                <span class="stat-icon">▤</span>
            </div>
            <strong><?= number_format($totalPeriode) ?></strong>
            <small>Dimulai pada rentang tanggal pilihan</small>
        </article>
    </section>

    <section class="panel no-print">
        <div class="panel-head">
            <div>
                <h3>Filter laporan</h3>
                <p>Pilih rentang tanggal mulai membership dan kata kunci anggota.</p>
            </div>
        </div>

        <form method="GET" action="laporan.php" class="filter">
            <div class="field">
                <label for="mulai">Tanggal mulai</label>
                <input type="date" id="mulai" name="mulai" value="<?= e($tanggalMulai) ?>" required>
            </div>

            <div class="field">
                <label for="selesai">Tanggal selesai</label>
                <input type="date" id="selesai" name="selesai" value="<?= e($tanggalSelesai) ?>" required>
            </div>

            <div class="field search">
                <label for="cari">Cari anggota</label>
                <input
                    type="search"
                    id="cari"
                    name="cari"
                    value="<?= e($cari) ?>"
                    placeholder="Kode, nama, telepon, atau email"
                >
            </div>

            <button class="btn btn-dark" type="submit">Terapkan</button>
            <a class="btn btn-light" href="laporan.php">Reset</a>
        </form>
    </section>

    <section class="grid">
        <article class="panel">
            <div class="panel-head">
                <div>
                    <h3>Tren membership</h3>
                    <p>Jumlah membership yang dimulai selama enam bulan terakhir.</p>
                </div>
            </div>

            <div class="chart-box">
                <canvas id="grafikMembership"></canvas>
            </div>
        </article>

        <article class="panel">
            <div class="panel-head">
                <div>
                    <h3>Ringkasan periode</h3>
                    <p>Informasi untuk filter laporan yang sedang dipilih.</p>
                </div>
            </div>

            <div class="summary-row">
                <span>Tanggal mulai</span>
                <strong><?= e(tanggalID($tanggalMulai)) ?></strong>
            </div>

            <div class="summary-row">
                <span>Tanggal selesai</span>
                <strong><?= e(tanggalID($tanggalSelesai)) ?></strong>
            </div>

            <div class="summary-row">
                <span>Total hasil pencarian</span>
                <strong><?= number_format($jumlahHasil) ?></strong>
            </div>

            <div class="summary-row">
                <span>Membership aktif dalam periode</span>
                <strong><?= number_format($aktifPeriode) ?></strong>
            </div>

            <div class="summary-row">
                <span>Total semua membership</span>
                <strong><?= number_format($totalMembership) ?></strong>
            </div>

            <div style="margin-top:17px">
                <span class="badge">Laporan FitDesk Gym</span>
            </div>
        </article>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h3>Detail laporan membership</h3>
                <p>
                    Periode <?= e(tanggalID($tanggalMulai)) ?>
                    sampai <?= e(tanggalID($tanggalSelesai)) ?>.
                </p>
            </div>
            <span class="badge"><?= number_format($jumlahHasil) ?> data</span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>NO.</th>
                    <th>KODE ANGGOTA</th>
                    <th>NAMA ANGGOTA</th>
                    <th>TELEPON</th>
                    <th>DURASI</th>
                    <th>TANGGAL MULAI</th>
                    <th>TANGGAL BERAKHIR</th>
                    <th>STATUS</th>
                </tr>
                </thead>

                <tbody>
                <?php if (!$laporan): ?>
                    <tr>
                        <td colspan="8" class="empty">
                            Tidak ada data membership untuk filter yang dipilih.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($laporan as $index => $row): ?>
                        <?php
                        $aktif = (int)$row['is_active'] === 1
                            && $row['start_date'] <= $today
                            && $row['end_date'] >= $today;

                        if ($aktif) {
                            $status = 'Aktif';
                            $kelas = '';
                        } elseif ($row['end_date'] < $today) {
                            $status = 'Kedaluwarsa';
                            $kelas = 'off';
                        } else {
                            $status = 'Belum aktif';
                            $kelas = 'wait';
                        }
                        ?>

                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= e($row['member_code']) ?></td>

                            <td>
                                <span class="member-name">
                                    <?= e($row['full_name']) ?>
                                </span>
                                <?php if (!empty($row['email'])): ?>
                                    <span class="subtext"><?= e($row['email']) ?></span>
                                <?php endif; ?>
                            </td>

                            <td><?= e($row['phone']) ?></td>

                            <td>
                                <?= (int)$row['package_duration_months'] ?> bulan
                            </td>

                            <td><?= e(tanggalID($row['start_date'])) ?></td>

                            <td><?= e(tanggalID($row['end_date'])) ?></td>

                            <td>
                                <span class="status <?= e($kelas) ?>">
                                    <?= e($status) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="foot">
        FitDesk Gym © <?= date('Y') ?> — Laporan dibuat pada <?= e(tanggalID($today)) ?>.
    </div>

</div>
</main>
</div>

<script>
const labelGrafik = <?= json_encode($labelGrafik, JSON_UNESCAPED_UNICODE) ?>;
const dataGrafik = <?= json_encode($dataGrafik) ?>;

if (window.Chart) {
    new Chart(document.getElementById('grafikMembership'), {
        type: 'bar',
        data: {
            labels: labelGrafik,
            datasets: [{
                label: 'Membership baru',
                data: dataGrafik,
                backgroundColor: '#c5f578',
                borderColor: '#8fb65a',
                borderWidth: 1,
                borderRadius: 7,
                maxBarThickness: 42
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y + ' membership';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    },
                    grid: {
                        color: '#edf0ed'
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}
</script>

</body>
</html>
