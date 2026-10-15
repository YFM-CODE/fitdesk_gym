<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_login();

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

$validFrom = DateTime::createFromFormat('!Y-m-d', $from);
$validTo = DateTime::createFromFormat('!Y-m-d', $to);

if (!$validFrom || $validFrom->format('Y-m-d') !== $from ||
    !$validTo || $validTo->format('Y-m-d') !== $to ||
    $from > $to) {
    $from = date('Y-m-01');
    $to = date('Y-m-d');
}

$stmt = $pdo->prepare(
    'SELECT ms.id, m.member_code, m.full_name, m.phone,
            ms.package_duration_months, ms.start_date, ms.end_date
     FROM memberships ms
     JOIN members m ON m.id = ms.member_id
     WHERE ms.start_date BETWEEN ? AND ?
     ORDER BY ms.start_date DESC'
);
$stmt->execute([$from, $to]);
$rows = $stmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="laporan_fitdesk_gym.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Kode Member', 'Nama', 'No HP', 'Durasi Bulan', 'Tanggal Mulai', 'Tanggal Akhir', 'Status']);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['member_code'],
            $r['full_name'],
            $r['phone'],
            $r['package_duration_months'],
            $r['start_date'],
            $r['end_date'],
            membership_status($r)
        ]);
    }

    fclose($out);
    exit;
}

$pageTitle = 'Laporan';
$activeMenu = 'reports';
require dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="page-heading">
    <div><h1>Laporan Membership</h1><p>Lihat riwayat paket berdasarkan periode tanggal mulai.</p></div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Dari Tanggal</label>
                <input type="date" class="form-control" name="from" value="<?= e($from) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Sampai Tanggal</label>
                <input type="date" class="form-control" name="to" value="<?= e($to) ?>" required>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-filter me-1"></i> Tampilkan</button>
                <button class="btn btn-outline-success" type="submit" name="export" value="csv">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i> Ekspor CSV
                </button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="bi bi-card-list"></i></div>
            <div><div class="stat-label">Paket pada periode ini</div><div class="stat-number"><?= count($rows) ?></div></div>
        </div>
    </div>
    <div class="col-sm-6">
        <div class="stat-card">
            <div class="stat-icon green"><i class="bi bi-calendar-check"></i></div>
            <div><div class="stat-label">Periode laporan</div><div class="fw-bold mt-2"><?= e($from) ?> — <?= e($to) ?></div></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Detail Laporan</div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>KODE</th><th>NAMA</th><th>NO. HP</th><th>DURASI</th><th>MULAI</th><th>AKHIR</th><th>STATUS</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="7" class="empty-state">Tidak ada data pada periode tersebut.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): $status = membership_status($r); ?>
                <tr>
                    <td><span class="code-pill"><?= e($r['member_code']) ?></span></td>
                    <td><?= e($r['full_name']) ?></td>
                    <td><?= e($r['phone']) ?></td>
                    <td><?= (int)$r['package_duration_months'] ?> bulan</td>
                    <td><?= e($r['start_date']) ?></td>
                    <td><?= e($r['end_date']) ?></td>
                    <td><span class="badge-status <?= $status === 'Aktif' ? 'status-active' : 'status-expired' ?>"><?= e($status) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require dirname(__DIR__, 2) . '/includes/footer.php'; ?>
