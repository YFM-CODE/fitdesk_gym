<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_login();

$pageTitle = 'Cek Status';
$activeMenu = 'status';

$q = trim($_GET['q'] ?? '');
$member = null;
$searched = false;

if ($q !== '') {
    $searched = true;
    $stmt = $pdo->prepare(
        'SELECT m.*,
                ms.start_date, ms.end_date,
                ms.package_duration_months, ms.is_active
         FROM members m
         LEFT JOIN memberships ms ON ms.id = (
            SELECT MAX(ms2.id) FROM memberships ms2
            WHERE ms2.member_id = m.id
         )
         WHERE m.member_code = ? OR m.full_name LIKE ?
         ORDER BY (m.member_code = ?) DESC
         LIMIT 1'
    );
    $stmt->execute([$q, "%{$q}%", $q]);
    $member = $stmt->fetch();
}

require dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="page-heading">
    <div><h1>Cek Status Member</h1><p>Cari menggunakan kode anggota atau nama lengkap.</p></div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-9">
                <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Contoh: MEM-A1B2C3 atau nama member" required>
            </div>
            <div class="col-md-3 d-grid">
                <button class="btn btn-primary"><i class="bi bi-search me-1"></i> Periksa Status</button>
            </div>
        </form>
    </div>
</div>

<?php if ($searched && !$member): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-circle me-2"></i>Member tidak ditemukan.</div>
<?php elseif ($member): ?>
    <?php $status = $member['end_date'] ? membership_status($member) : 'Belum ada paket'; ?>
    <div class="card">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div class="d-flex gap-3 align-items-center">
                    <div class="stat-icon blue"><i class="bi bi-person-fill"></i></div>
                    <div>
                        <h4 class="fw-bold mb-1"><?= e($member['full_name']) ?></h4>
                        <span class="code-pill"><?= e($member['member_code']) ?></span>
                    </div>
                </div>
                <span class="badge-status <?= $status === 'Aktif' ? 'status-active' : 'status-expired' ?>">
                    <?= e($status) ?>
                </span>
            </div>
            <hr class="my-4">
            <div class="row g-4">
                <div class="col-sm-6"><div class="text-secondary small">Nomor HP</div><strong><?= e($member['phone']) ?></strong></div>
                <div class="col-sm-6"><div class="text-secondary small">Email</div><strong><?= e($member['email'] ?: '-') ?></strong></div>
                <div class="col-sm-6"><div class="text-secondary small">Tanggal Mulai</div><strong><?= e($member['start_date'] ?? '-') ?></strong></div>
                <div class="col-sm-6"><div class="text-secondary small">Tanggal Akhir</div><strong><?= e($member['end_date'] ?? '-') ?></strong></div>
                <div class="col-sm-6"><div class="text-secondary small">Durasi Paket</div><strong><?= $member['package_duration_months'] ? (int)$member['package_duration_months'] . ' bulan' : '-' ?></strong></div>
            </div>
            <div class="mt-4">
                <a class="btn btn-primary" href="../memberships/index.php?q=<?= urlencode($member['member_code']) ?>"><i class="bi bi-arrow-repeat me-1"></i> Perpanjang Membership</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__, 2) . '/includes/footer.php'; ?>
