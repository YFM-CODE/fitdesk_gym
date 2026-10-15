<?php
require_once dirname(__DIR__, 2) . '/config/database.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['username'])) {
    header('Location: ../auth/login.php');
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    exit('ID membership tidak valid.');
}

$stmt = $pdo->prepare(
    'SELECT ms.id, m.name, m.member_code, p.package_name,
            ms.total_price, ms.status
     FROM memberships ms
     JOIN members m ON m.id = ms.member_id
     JOIN membership_packages p ON p.id = ms.package_id
     WHERE ms.id = ?'
);
$stmt->execute([$id]);
$ms = $stmt->fetch();

if (!$ms) {
    exit('Membership tidak ditemukan.');
}

$stmt = $pdo->prepare(
    'SELECT installment_number, amount, payment_method, payment_date, notes
     FROM payments WHERE membership_id = ?
     ORDER BY installment_number'
);
$stmt->execute([$id]);
$payments = $stmt->fetchAll();

$dibayar = array_sum(array_column($payments, 'amount'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Riwayat Pembayaran</title>
<style>
body{font-family:Arial;background:#f3f6fb;padding:24px;color:#1e293b}
main{max-width:900px;margin:auto;background:white;padding:24px;border-radius:12px}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:12px;border-bottom:1px solid #e5e7eb}
a{display:inline-block;margin:10px 8px 0 0}
</style>
</head>
<body>
<main>
<h1>Riwayat Pembayaran</h1>
<p>Nama: <?= htmlspecialchars($ms['name']) ?></p>
<p>Member Code: <?= htmlspecialchars($ms['member_code'] ?? '') ?></p>
<p>Paket: <?= htmlspecialchars($ms['package_name']) ?></p>
<p>Total tagihan: Rp<?= number_format($ms['total_price'],0,',','.') ?></p>
<p>Total dibayar: Rp<?= number_format($dibayar,0,',','.') ?></p>
<p>Sisa tagihan: Rp<?= number_format(max(0,$ms['total_price']-$dibayar),0,',','.') ?></p>
<p>Status: <?= htmlspecialchars($ms['status']) ?></p>

<table>
<thead><tr><th>Cicilan</th><th>Nominal</th><th>Metode</th><th>Tanggal</th><th>Catatan</th></tr></thead>
<tbody>
<?php foreach ($payments as $p): ?>
<tr>
<td><?= (int)$p['installment_number'] ?></td>
<td>Rp<?= number_format($p['amount'],0,',','.') ?></td>
<td><?= htmlspecialchars($p['payment_method']) ?></td>
<td><?= htmlspecialchars($p['payment_date']) ?></td>
<td><?= htmlspecialchars($p['notes'] ?? '') ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$payments): ?>
<tr><td colspan="5">Belum ada pembayaran.</td></tr>
<?php endif; ?>
</tbody>
</table>

<a href="index.php?id=<?= (int)$id ?>">Kembali ke Pembayaran</a>
<a href="../reports/export_pdf.php?id=<?= (int)$id ?>">Download PDF</a>
</main>
</body>
</html>
