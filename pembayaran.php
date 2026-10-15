
<?php
session_start();

if (!isset($_SESSION['user_id']) && !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah($value)
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function redirectWithMessage($type, $message)
{
    header(
        'Location: pembayaran.php?' .
        http_build_query([$type => $message])
    );
    exit;
}

$pesanSukses = $_GET['sukses'] ?? '';
$pesanError = $_GET['error'] ?? '';

/*
|--------------------------------------------------------------------------
| PROSES PENYIMPANAN PEMBAYARAN
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['aksi'] ?? '') === 'bayar') {

    $membershipId = filter_input(
        INPUT_POST,
        'membership_id',
        FILTER_VALIDATE_INT
    );

    $metode = $_POST['payment_method'] ?? '';
    $rencana = $_POST['payment_plan'] ?? '';
    $catatan = trim($_POST['notes'] ?? '');

    if (!$membershipId || $membershipId < 1) {
        redirectWithMessage('error', 'Pilih membership yang valid.');
    }

    if (!in_array($metode, ['cash', 'transfer'], true)) {
        redirectWithMessage('error', 'Metode pembayaran tidak valid.');
    }

    if (!in_array($rencana, ['full', 'installment_3'], true)) {
        redirectWithMessage('error', 'Jenis pembayaran tidak valid.');
    }

    if (strlen($catatan) > 255) {
        redirectWithMessage('error', 'Catatan maksimal 255 karakter.');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT
                ms.id,
                ms.member_id,
                ms.package_duration_months,
                ms.payment_plan,
                m.full_name,
                mp.package_name,
                mp.price
             FROM memberships ms
             INNER JOIN members m ON m.id = ms.member_id
             LEFT JOIN membership_packages mp
                ON mp.duration_months = ms.package_duration_months
             WHERE ms.id = :id
             FOR UPDATE"
        );

        $stmt->execute(['id' => $membershipId]);
        $membership = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$membership) {
            throw new RuntimeException('Membership tidak ditemukan.');
        }

        $harga = (float) $membership['price'];

        if ($harga <= 0) {
            throw new RuntimeException(
                'Harga paket tidak ditemukan. Periksa data paket membership.'
            );
        }

        /*
         * Periksa apakah pembayaran sudah dimulai.
         */
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS jumlah,
                COALESCE(SUM(amount), 0) AS total_bayar,
                MAX(installment_number) AS cicilan_terakhir
             FROM payments
             WHERE membership_id = :id"
        );

        $stmt->execute(['id' => $membershipId]);
        $riwayat = $stmt->fetch(PDO::FETCH_ASSOC);

        $jumlahPembayaran = (int) $riwayat['jumlah'];
        $totalBayar = (float) $riwayat['total_bayar'];
        $cicilanTerakhir = (int) ($riwayat['cicilan_terakhir'] ?? 0);

        /*
         * Rencana pembayaran tidak boleh berubah setelah pembayaran pertama.
         */
        if ($jumlahPembayaran > 0 &&
            $membership['payment_plan'] !== $rencana) {
            throw new RuntimeException(
                'Jenis pembayaran tidak dapat diubah setelah pembayaran pertama.'
            );
        }

        if ($jumlahPembayaran === 0) {
            $stmt = $pdo->prepare(
                "UPDATE memberships
                 SET payment_plan = :plan
                 WHERE id = :id"
            );

            $stmt->execute([
                'plan' => $rencana,
                'id' => $membershipId
            ]);
        }

        if ($rencana === 'full') {
            if ($jumlahPembayaran > 0) {
                throw new RuntimeException(
                    'Pembayaran lunas untuk membership ini sudah tercatat.'
                );
            }

            $nominal = $harga;
            $nomorCicilan = 1;
        } else {
            if ($jumlahPembayaran >= 3 || $totalBayar >= $harga) {
                throw new RuntimeException(
                    'Pembayaran membership ini sudah lunas.'
                );
            }

            $nomorCicilan = $cicilanTerakhir + 1;

            if ($nomorCicilan > 3) {
                throw new RuntimeException(
                    'Jumlah cicilan sudah mencapai batas.'
                );
            }

            /*
             * Cicilan pertama dan kedua menggunakan pembulatan ke bawah.
             * Cicilan terakhir membayar seluruh sisa tagihan.
             */
            $nominalDasar = floor($harga / 3);

            if ($nomorCicilan < 3) {
                $nominal = $nominalDasar;
            } else {
                $nominal = $harga - $totalBayar;
            }
        }

        if ($nominal <= 0) {
            throw new RuntimeException('Nominal pembayaran tidak valid.');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO payments
                (membership_id, installment_number, amount,
                 payment_method, payment_date, notes)
             VALUES
                (:membership_id, :installment_number, :amount,
                 :payment_method, NOW(), :notes)"
        );

        $stmt->execute([
            'membership_id' => $membershipId,
            'installment_number' => $nomorCicilan,
            'amount' => $nominal,
            'payment_method' => $metode,
            'notes' => $catatan !== '' ? $catatan : null
        ]);

        $paymentId = (int) $pdo->lastInsertId();

        $pdo->commit();

        header(
            'Location: pembayaran.php?' .
            http_build_query([
                'sukses' => 'Pembayaran berhasil disimpan.',
                'payment_id' => $paymentId
            ])
        );
        exit;

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        redirectWithMessage('error', $exception->getMessage());
    }
}

/*
|--------------------------------------------------------------------------
| DATA MEMBERSHIP UNTUK FORM PEMBAYARAN
|--------------------------------------------------------------------------
*/
$sqlMembership = "
    SELECT
        ms.id,
        ms.member_id,
        ms.payment_plan,
        ms.package_duration_months,
        m.member_code,
        m.full_name,
        mp.package_name,
        mp.price,
        COALESCE(pay.total_bayar, 0) AS total_bayar,
        COALESCE(pay.jumlah_bayar, 0) AS jumlah_bayar,
        COALESCE(pay.cicilan_terakhir, 0) AS cicilan_terakhir
    FROM memberships ms
    INNER JOIN members m ON m.id = ms.member_id
    LEFT JOIN membership_packages mp
        ON mp.duration_months = ms.package_duration_months
    LEFT JOIN (
        SELECT
            membership_id,
            SUM(amount) AS total_bayar,
            COUNT(*) AS jumlah_bayar,
            MAX(installment_number) AS cicilan_terakhir
        FROM payments
        GROUP BY membership_id
    ) pay ON pay.membership_id = ms.id
    ORDER BY ms.id DESC
";

$stmt = $pdo->query($sqlMembership);
$memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| RIWAYAT PEMBAYARAN
|--------------------------------------------------------------------------
*/
$sqlPayments = "
    SELECT
        p.id,
        p.membership_id,
        p.installment_number,
        p.amount,
        p.payment_method,
        p.payment_date,
        p.notes,
        m.member_code,
        m.full_name,
        ms.payment_plan,
        mp.package_name,
        mp.price
    FROM payments p
    INNER JOIN memberships ms ON ms.id = p.membership_id
    INNER JOIN members m ON m.id = ms.member_id
    LEFT JOIN membership_packages mp
        ON mp.duration_months = ms.package_duration_months
    ORDER BY p.id DESC
";

$stmt = $pdo->query($sqlPayments);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| RINGKASAN
|--------------------------------------------------------------------------
*/
$totalTransaksi = count($payments);
$totalPendapatan = 0;
$totalCash = 0;
$totalTransfer = 0;

foreach ($payments as $payment) {
    $amount = (float) $payment['amount'];
    $totalPendapatan += $amount;

    if ($payment['payment_method'] === 'cash') {
        $totalCash += $amount;
    } else {
        $totalTransfer += $amount;
    }
}

$totalTunggakan = 0;

foreach ($memberships as $membership) {
    $harga = (float) $membership['price'];
    $dibayar = (float) $membership['total_bayar'];

    $totalTunggakan += max(0, $harga - $dibayar);
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pembayaran - FitDesk Gym</title>

<style>
:root {
    --dark: #182720;
    --dark-soft: #26382d;
    --lime: #c5f578;
    --bg: #f4f6f4;
    --white: #ffffff;
    --text: #26352c;
    --muted: #7a867d;
    --border: #e5eae5;
    --green: #287c45;
    --red: #c43e3e;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: var(--bg);
    color: var(--text);
}

.layout {
    display: flex;
    min-height: 100vh;
}

.sidebar {
    width: 235px;
    background: var(--dark);
    color: #fff;
    padding: 25px 16px;
    flex-shrink: 0;
}

.brand {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 0 9px 30px;
    border-bottom: 1px solid rgba(255,255,255,.12);
}

.brand-icon {
    width: 42px;
    height: 42px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--lime);
    color: var(--dark);
    border-radius: 12px;
    font-size: 22px;
    font-weight: bold;
}

.brand h2 {
    font-size: 17px;
    margin: 0 0 4px;
}

.brand small {
    color: #b7c3b9;
    font-size: 11px;
}

.menu-label {
    color: #9caa9f;
    font-size: 10px;
    font-weight: bold;
    letter-spacing: 1.5px;
    margin: 28px 10px 12px;
}

.menu {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.menu a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 12px;
    color: #d7e0d8;
    text-decoration: none;
    border-radius: 9px;
    font-size: 13px;
    transition: .2s;
}

.menu a:hover {
    background: rgba(255,255,255,.08);
    color: #fff;
}

.menu a.active {
    background: var(--lime);
    color: var(--dark);
    font-weight: bold;
}

.sidebar-bottom {
    margin-top: 35px;
    padding: 15px 10px;
    border-top: 1px solid rgba(255,255,255,.12);
    color: #cbd5cd;
    font-size: 12px;
}

.sidebar-bottom a {
    color: #ffb5b5;
    text-decoration: none;
    display: block;
    margin-top: 14px;
}

.main {
    flex: 1;
    min-width: 0;
}

.topbar {
    background: var(--white);
    padding: 19px 30px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
}

.topbar h1 {
    font-size: 21px;
    margin: 0 0 5px;
}

.topbar p {
    font-size: 12px;
    color: var(--muted);
    margin: 0;
}

.user-badge {
    background: #edf5e5;
    color: #315522;
    border-radius: 30px;
    padding: 10px 14px;
    font-size: 12px;
    font-weight: bold;
}

.content {
    padding: 28px 30px;
    max-width: 1600px;
    margin: auto;
}

.page-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 22px;
}

.page-heading h2 {
    margin: 0 0 7px;
    font-size: 23px;
}

.page-heading p {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 23px;
}

.stat-card {
    background: #fff;
    padding: 21px;
    border: 1px solid var(--border);
    border-radius: 14px;
    box-shadow: 0 3px 12px rgba(20,40,25,.025);
}

.stat-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.stat-label {
    color: var(--muted);
    font-size: 12px;
}

.stat-icon {
    width: 38px;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #edf5e5;
    border-radius: 11px;
    font-size: 18px;
}

.stat-value {
    font-size: 23px;
    font-weight: bold;
    margin-top: 16px;
    overflow-wrap: anywhere;
}

.stat-note {
    margin-top: 7px;
    color: var(--muted);
    font-size: 11px;
}

.card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 23px;
    margin-bottom: 22px;
    box-shadow: 0 3px 12px rgba(20,40,25,.025);
}

.card-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    margin-bottom: 21px;
    flex-wrap: wrap;
}

.card-heading h3 {
    font-size: 16px;
    margin: 0 0 5px;
}

.card-heading p {
    margin: 0;
    color: var(--muted);
    font-size: 12px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-group.full {
    grid-column: 1 / -1;
}

label {
    font-size: 12px;
    font-weight: bold;
}

input, select, textarea {
    width: 100%;
    padding: 12px 13px;
    border: 1px solid #dce4dc;
    border-radius: 8px;
    font: inherit;
    font-size: 13px;
    color: var(--text);
    background: #fff;
    outline: none;
}

input:focus, select:focus, textarea:focus {
    border-color: #8ab85b;
    box-shadow: 0 0 0 3px rgba(197,245,120,.2);
}

textarea {
    min-height: 80px;
    resize: vertical;
}

.help {
    font-size: 11px;
    color: var(--muted);
    line-height: 1.5;
}

.preview {
    background: #f4f8ef;
    border: 1px solid #e0ebd5;
    border-radius: 10px;
    padding: 16px;
    grid-column: 1 / -1;
}

.preview-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 7px 0;
    font-size: 12px;
}

.preview-row strong {
    text-align: right;
}

.preview-total {
    border-top: 1px solid #dbe6d0;
    margin-top: 7px;
    padding-top: 13px;
    font-size: 15px;
}

.preview-total strong {
    font-size: 20px;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border: none;
    border-radius: 8px;
    padding: 11px 14px;
    font-size: 12px;
    font-weight: bold;
    cursor: pointer;
    text-decoration: none;
    transition: .2s;
}

.btn-primary {
    background: var(--dark);
    color: var(--lime);
}

.btn-primary:hover {
    background: var(--dark-soft);
}

.btn-lime {
    background: var(--lime);
    color: var(--dark);
}

.btn-light {
    background: #edf1ed;
    color: var(--dark);
}

.btn-wa {
    background: #e7f6e9;
    color: #1c7135;
}

.btn:disabled {
    opacity: .45;
    cursor: not-allowed;
}

.form-actions {
    grid-column: 1 / -1;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
    padding-top: 5px;
}

.alert {
    padding: 14px 16px;
    border-radius: 9px;
    margin-bottom: 20px;
    font-size: 13px;
    line-height: 1.5;
}

.alert-success {
    background: #e9f7e9;
    border: 1px solid #cce9ce;
    color: #276637;
}

.alert-error {
    background: #fff0f0;
    border: 1px solid #f2cece;
    color: #a52c2c;
}

.table-wrap {
    overflow-x: auto;
    width: 100%;
}

table {
    width: 100%;
    border-collapse: collapse;
    white-space: nowrap;
}

th {
    background: #f6f8f6;
    text-align: left;
    color: #6d796f;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .4px;
    padding: 14px 12px;
    border-bottom: 1px solid var(--border);
}

td {
    padding: 14px 12px;
    border-bottom: 1px solid #edf0ed;
    font-size: 12px;
    vertical-align: middle;
}

tbody tr:hover {
    background: #fbfcfb;
}

.member-name {
    font-weight: bold;
    color: var(--dark);
    margin-bottom: 4px;
}

.subtext {
    font-size: 11px;
    color: var(--muted);
}

.badge {
    display: inline-block;
    padding: 6px 9px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: bold;
}

.badge-green {
    background: #e6f5e8;
    color: #26713a;
}

.badge-orange {
    background: #fff1dc;
    color: #9a620e;
}

.badge-blue {
    background: #e5f0ff;
    color: #275c9b;
}

.badge-gray {
    background: #edf0ed;
    color: #657066;
}

.action-group {
    display: flex;
    align-items: center;
    gap: 7px;
}

.action-group .btn {
    padding: 8px 10px;
    font-size: 11px;
}

.empty {
    padding: 35px 15px;
    text-align: center;
    color: var(--muted);
    font-size: 13px;
}

.search-box {
    max-width: 270px;
}

.footer-note {
    color: var(--muted);
    text-align: center;
    font-size: 11px;
    padding: 8px 0 25px;
}

@media (max-width: 1100px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .sidebar {
        width: 205px;
    }

    .content {
        padding: 23px 20px;
    }
}

@media (max-width: 760px) {
    .layout {
        display: block;
    }

    .sidebar {
        width: 100%;
        padding: 15px;
    }

    .brand {
        padding-bottom: 15px;
    }

    .menu-label {
        margin-top: 15px;
    }

    .menu {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .sidebar-bottom {
        margin-top: 15px;
    }

    .topbar {
        padding: 17px;
    }

    .content {
        padding: 18px 13px;
    }

    .stats {
        gap: 10px;
    }

    .stat-card {
        padding: 15px;
    }

    .stat-value {
        font-size: 18px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-group.full,
    .preview,
    .form-actions {
        grid-column: 1;
    }

    .card {
        padding: 16px;
    }

    .user-badge {
        display: none;
    }
}
</style>
</head>

<body>
<div class="layout">

    <aside class="sidebar">
        <div class="brand">
            <div class="brand-icon">F</div>
            <div>
                <h2>FitDesk Gym</h2>
                <small>Management System</small>
            </div>
        </div>

        <div class="menu-label">MENU UTAMA</div>

        <nav class="menu">
            <a href="dashboard.php">▦ Dashboard</a>
            <a href="anggota.php">♙ Data Anggota</a>
            <a href="memberships.php">♧ Paket Membership</a>
            <a class="active" href="pembayaran.php">▣ Pembayaran</a>
            <a href="laporan.php">▤ Laporan</a>
        </nav>

        <div class="sidebar-bottom">
            <div>Masuk sebagai</div>
            <strong><?= e($_SESSION['username'] ?? $_SESSION['nama_lengkap'] ?? 'Pengguna') ?></strong>
            <a href="logout.php">↪ Keluar</a>
        </div>
    </aside>

    <main class="main">

        <header class="topbar">
            <div>
                <h1>Pembayaran</h1>
                <p>Kelola transaksi dan bukti pembayaran anggota.</p>
            </div>
            <div class="user-badge">● Sistem Aktif</div>
        </header>

        <div class="content">

            <div class="page-heading">
                <div>
                    <h2>Manajemen Pembayaran</h2>
                    <p>Catat pembayaran membership dan unduh bukti transaksi.</p>
                </div>
            </div>

            <?php if ($pesanSukses !== ''): ?>
                <div class="alert alert-success">
                    <?= e($pesanSukses) ?>
                    <?php if (isset($_GET['payment_id']) &&
                        ctype_digit((string) $_GET['payment_id'])): ?>
                        <br><br>
                        <a class="btn btn-lime"
                           href="bukti_pembayaran.php?id=<?= (int) $_GET['payment_id'] ?>"
                           target="_blank">Unduh Bukti PDF</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($pesanError !== ''): ?>
                <div class="alert alert-error"><?= e($pesanError) ?></div>
            <?php endif; ?>

            <section class="stats">
                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Total Transaksi</span>
                        <span class="stat-icon">▤</span>
                    </div>
                    <div class="stat-value"><?= number_format($totalTransaksi, 0, ',', '.') ?></div>
                    <div class="stat-note">Jumlah transaksi tercatat</div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Total Pendapatan</span>
                        <span class="stat-icon">↗</span>
                    </div>
                    <div class="stat-value"><?= rupiah($totalPendapatan) ?></div>
                    <div class="stat-note">Akumulasi seluruh pembayaran</div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Pembayaran Tunai</span>
                        <span class="stat-icon">▣</span>
                    </div>
                    <div class="stat-value"><?= rupiah($totalCash) ?></div>
                    <div class="stat-note">Total transaksi tunai</div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <span class="stat-label">Sisa Tagihan</span>
                        <span class="stat-icon">◷</span>
                    </div>
                    <div class="stat-value"><?= rupiah($totalTunggakan) ?></div>
                    <div class="stat-note">Akumulasi sisa harga membership</div>
                </div>
            </section>

            <section class="card">
                <div class="card-heading">
                    <div>
                        <h3>Catat Pembayaran Baru</h3>
                        <p>Pilih membership dan metode pembayaran anggota.</p>
                    </div>
                </div>

                <form method="POST" action="pembayaran.php" class="form-grid"
                      id="paymentForm">

                    <input type="hidden" name="aksi" value="bayar">

                    <div class="form-group full">
                        <label for="membership_id">Anggota / Membership *</label>

                        <select name="membership_id" id="membership_id" required>
                            <option value="">-- Pilih anggota --</option>

                            <?php foreach ($memberships as $membership): ?>
                                <?php
                                $harga = (float) $membership['price'];
                                $dibayar = (float) $membership['total_bayar'];
                                $sisa = max(0, $harga - $dibayar);
                                $lunas = $sisa <= 0;
                                ?>
                                <option
                                    value="<?= (int) $membership['id'] ?>"
                                    data-harga="<?= e($harga) ?>"
                                    data-dibayar="<?= e($dibayar) ?>"
                                    data-sisa="<?= e($sisa) ?>"
                                    data-jumlah="<?= (int) $membership['jumlah_bayar'] ?>"
                                    data-plan="<?= e($membership['payment_plan']) ?>"
                                    data-cicilan="<?= (int) $membership['cicilan_terakhir'] ?>"
                                    <?= $lunas ? 'disabled' : '' ?>>

                                    <?= e($membership['member_code']) ?>
                                    —
                                    <?= e($membership['full_name']) ?>
                                    —
                                    <?= e($membership['package_name'] ?? 'Paket tidak ditemukan') ?>
                                    <?= $lunas ? '(LUNAS)' : '(Sisa ' . rupiah($sisa) . ')' ?>

                                </option>
                            <?php endforeach; ?>
                        </select>

                        <span class="help">
                            Membership yang sudah lunas tidak dapat dipilih lagi.
                        </span>
                    </div>

                    <div class="form-group">
                        <label for="payment_plan">Jenis Pembayaran *</label>
                        <select name="payment_plan" id="payment_plan" required>
                            <option value="full">Lunas / Sekali Bayar</option>
                            <option value="installment_3">Cicilan 3 Kali</option>
                        </select>
                        <span class="help">
                            Pilihan jenis pembayaran dikunci setelah transaksi pertama.
                        </span>
                    </div>

                    <div class="form-group">
                        <label for="payment_method">Metode Pembayaran *</label>
                        <select name="payment_method" id="payment_method" required>
                            <option value="cash">Tunai</option>
                            <option value="transfer">Transfer Bank</option>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label for="notes">Catatan (Opsional)</label>
                        <textarea name="notes" id="notes" maxlength="255"
                                  placeholder="Contoh: Pembayaran diterima oleh resepsionis."></textarea>
                    </div>

                    <div class="preview">
                        <div class="preview-row">
                            <span>Harga Membership</span>
                            <strong id="previewHarga">Rp 0</strong>
                        </div>
                        <div class="preview-row">
                            <span>Sudah Dibayar</span>
                            <strong id="previewDibayar">Rp 0</strong>
                        </div>
                        <div class="preview-row">
                            <span>Sisa Tagihan</span>
                            <strong id="previewSisa">Rp 0</strong>
                        </div>
                        <div class="preview-row preview-total">
                            <span>Nominal Transaksi Berikutnya</span>
                            <strong id="previewNominal">Rp 0</strong>
                        </div>
                        <div class="help" id="previewInfo" style="margin-top:8px">
                            Pilih membership untuk melihat rincian pembayaran.
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="reset" class="btn btn-light" id="resetBtn">
                            Reset
                        </button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            Simpan Pembayaran
                        </button>
                    </div>

                </form>
            </section>

            <section class="card">
                <div class="card-heading">
                    <div>
                        <h3>Riwayat Pembayaran</h3>
                        <p>Unduh bukti PDF atau kirim informasi pembayaran melalui WhatsApp.</p>
                    </div>

                    <input type="search" class="search-box" id="searchPayment"
                           placeholder="Cari nama atau kode anggota...">
                </div>

                <div class="table-wrap">
                    <table id="paymentTable">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Anggota</th>
                                <th>Paket</th>
                                <th>Tanggal</th>
                                <th>Cicilan</th>
                                <th>Metode</th>
                                <th>Jumlah</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php if (!$payments): ?>
                            <tr>
                                <td colspan="8" class="empty">
                                    Belum ada transaksi pembayaran.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payments as $index => $payment): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>

                                    <td>
                                        <div class="member-name">
                                            <?= e($payment['full_name']) ?>
                                        </div>
                                        <div class="subtext">
                                            <?= e($payment['member_code']) ?>
                                        </div>
                                    </td>

                                    <td><?= e($payment['package_name'] ?? 'Membership') ?></td>

                                    <td>
                                        <?= e(date(
                                            'd-m-Y H:i',
                                            strtotime($payment['payment_date'])
                                        )) ?>
                                    </td>

                                    <td>
                                        <?php if ($payment['payment_plan'] === 'installment_3'): ?>
                                            <span class="badge badge-orange">
                                                Ke-<?= (int) $payment['installment_number'] ?> / 3
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-green">Lunas</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ($payment['payment_method'] === 'transfer'): ?>
                                            <span class="badge badge-blue">Transfer</span>
                                        <?php else: ?>
                                            <span class="badge badge-gray">Tunai</span>
                                        <?php endif; ?>
                                    </td>

                                    <td><strong><?= rupiah($payment['amount']) ?></strong></td>

                                    <td>
                                        <div class="action-group">
                                            <a class="btn btn-lime"
                                               href="bukti_pembayaran.php?id=<?= (int) $payment['id'] ?>"
                                               target="_blank">
                                                Unduh PDF
                                            </a>

                                            <form method="POST"
                                                  action="kirim_bukti_whatsapp.php"
                                                  onsubmit="return confirm('Kirim informasi bukti pembayaran melalui WhatsApp?')">
                                                <input type="hidden" name="payment_id"
                                                       value="<?= (int) $payment['id'] ?>">

                                                <button type="submit" class="btn btn-wa">
                                                    Kirim WA
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

            <div class="footer-note">
                FitDesk Gym Management System &copy; <?= date('Y') ?>
            </div>

        </div>
    </main>
</div>

<script>
const membershipSelect = document.getElementById('membership_id');
const planSelect = document.getElementById('payment_plan');
const submitBtn = document.getElementById('submitBtn');

const previewHarga = document.getElementById('previewHarga');
const previewDibayar = document.getElementById('previewDibayar');
const previewSisa = document.getElementById('previewSisa');
const previewNominal = document.getElementById('previewNominal');
const previewInfo = document.getElementById('previewInfo');

function formatRupiah(value) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0
    }).format(value);
}

function updatePreview() {
    const option = membershipSelect.options[membershipSelect.selectedIndex];

    if (!option || !option.value) {
        previewHarga.textContent = formatRupiah(0);
        previewDibayar.textContent = formatRupiah(0);
        previewSisa.textContent = formatRupiah(0);
        previewNominal.textContent = formatRupiah(0);
        previewInfo.textContent = 'Pilih membership untuk melihat rincian pembayaran.';
        planSelect.disabled = false;
        submitBtn.disabled = true;
        return;
    }

    const harga = Number(option.dataset.harga || 0);
    const dibayar = Number(option.dataset.dibayar || 0);
    const sisa = Number(option.dataset.sisa || 0);
    const jumlah = Number(option.dataset.jumlah || 0);
    const cicilanTerakhir = Number(option.dataset.cicilan || 0);
    const planLama = option.dataset.plan || 'full';

    if (jumlah > 0) {
        planSelect.value = planLama;
        planSelect.disabled = true;
    } else {
        planSelect.disabled = false;
    }

    let nominal = 0;
    let info = '';

    if (sisa <= 0) {
        info = 'Membership ini sudah lunas.';
    } else if (planSelect.value === 'full') {
        nominal = jumlah > 0 ? 0 : harga;
        info = jumlah > 0
            ? 'Pembayaran lunas sudah tercatat.'
            : 'Nominal pembayaran adalah seluruh harga membership.';
    } else {
        const cicilanBerikutnya = cicilanTerakhir + 1;

        if (cicilanBerikutnya >= 3) {
            nominal = sisa;
        } else {
            nominal = Math.floor(harga / 3);
        }

        info = 'Cicilan berikutnya: ' + cicilanBerikutnya +
            ' dari 3 kali pembayaran.';
    }

    previewHarga.textContent = formatRupiah(harga);
    previewDibayar.textContent = formatRupiah(dibayar);
    previewSisa.textContent = formatRupiah(sisa);
    previewNominal.textContent = formatRupiah(nominal);
    previewInfo.textContent = info;

    submitBtn.disabled = sisa <= 0 || nominal <= 0;
}

membershipSelect.addEventListener('change', updatePreview);
planSelect.addEventListener('change', updatePreview);

document.getElementById('resetBtn').addEventListener('click', function() {
    setTimeout(updatePreview, 0);
});

document.getElementById('searchPayment').addEventListener('input', function() {
    const query = this.value.toLowerCase();
    const rows = document.querySelectorAll('#paymentTable tbody tr');

    rows.forEach(function(row) {
        row.style.display = row.textContent.toLowerCase().includes(query)
            ? ''
            : 'none';
    });
});

updatePreview();
</script>
</body>
</html>
