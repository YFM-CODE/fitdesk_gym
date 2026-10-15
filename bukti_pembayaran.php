
<?php
session_start();

if (!isset($_SESSION['user_id']) && !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    exit('ID pembayaran tidak valid.');
}

$paymentId = (int) $_GET['id'];

$sql = "SELECT
            p.id AS payment_id,
            p.installment_number,
            p.amount,
            p.payment_method,
            p.payment_date,
            p.notes,
            m.member_code,
            m.full_name,
            m.phone,
            m.email,
            ms.start_date,
            ms.end_date,
            ms.payment_plan,
            mp.package_name,
            mp.price
        FROM payments p
        INNER JOIN memberships ms ON ms.id = p.membership_id
        INNER JOIN members m ON m.id = ms.member_id
        LEFT JOIN membership_packages mp
            ON mp.duration_months = ms.package_duration_months
        WHERE p.id = :id
        LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute(['id' => $paymentId]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    exit('Data pembayaran tidak ditemukan.');
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah($angka)
{
    return 'Rp ' . number_format((float) $angka, 0, ',', '.');
}

$metode = $data['payment_method'] === 'transfer'
    ? 'Transfer'
    : 'Tunai';

$rencana = $data['payment_plan'] === 'installment_3'
    ? 'Cicilan 3 kali'
    : 'Pembayaran lunas';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$pdf = new Dompdf($options);

$html = '
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 35px; }
    body {
        font-family: DejaVu Sans, sans-serif;
        color: #26352c;
        font-size: 12px;
    }
    .header {
        background: #182720;
        color: #ffffff;
        padding: 22px;
        text-align: center;
        border-radius: 8px;
    }
    .header h1 {
        margin: 0 0 7px;
        color: #c5f578;
        font-size: 24px;
    }
    .header p { margin: 0; }
    .status {
        margin: 22px 0;
        padding: 12px;
        background: #eff8e7;
        color: #315522;
        text-align: center;
        font-weight: bold;
    }
    h3 {
        color: #182720;
        border-bottom: 1px solid #dce3dc;
        padding-bottom: 8px;
    }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    td {
        padding: 9px 7px;
        border-bottom: 1px solid #e5eae5;
        vertical-align: top;
    }
    td:first-child {
        width: 38%;
        color: #667168;
    }
    .total {
        margin-top: 18px;
        padding: 16px;
        background: #f3f6f2;
        text-align: right;
    }
    .total strong {
        color: #182720;
        font-size: 20px;
    }
    .footer {
        margin-top: 35px;
        text-align: center;
        color: #7b857d;
        font-size: 10px;
    }
</style>
</head>
<body>
    <div class="header">
        <h1>FitDesk Gym</h1>
        <p>BUKTI PEMBAYARAN MEMBERSHIP</p>
    </div>

    <div class="status">BUKTI PEMBAYARAN RESMI</div>

    <h3>Informasi Transaksi</h3>
    <table>
        <tr><td>Nomor Pembayaran</td><td>FD-' .
            str_pad((string) $data['payment_id'], 6, '0', STR_PAD_LEFT) .
        '</td></tr>
        <tr><td>Tanggal Pembayaran</td><td>' .
            h(date('d-m-Y H:i', strtotime($data['payment_date']))) .
        '</td></tr>
        <tr><td>Metode Pembayaran</td><td>' . h($metode) . '</td></tr>
        <tr><td>Jenis Pembayaran</td><td>' . h($rencana) . '</td></tr>
        <tr><td>Cicilan Ke</td><td>' .
            (int) $data['installment_number'] .
        '</td></tr>
    </table>

    <h3>Informasi Anggota</h3>
    <table>
        <tr><td>Kode Anggota</td><td>' . h($data['member_code']) . '</td></tr>
        <tr><td>Nama Anggota</td><td>' . h($data['full_name']) . '</td></tr>
        <tr><td>Nomor Telepon</td><td>' . h($data['phone']) . '</td></tr>
        <tr><td>Email</td><td>' . h($data['email']) . '</td></tr>
    </table>

    <h3>Informasi Membership</h3>
    <table>
        <tr><td>Paket Membership</td><td>' .
            h($data['package_name'] ?? 'Paket Membership') .
        '</td></tr>
        <tr><td>Mulai Membership</td><td>' .
            h(date('d-m-Y', strtotime($data['start_date']))) .
        '</td></tr>
        <tr><td>Akhir Membership</td><td>' .
            h(date('d-m-Y', strtotime($data['end_date']))) .
        '</td></tr>
    </table>

    <div class="total">
        Jumlah Dibayarkan<br>
        <strong>' . rupiah($data['amount']) . '</strong>
    </div>

    <p><strong>Catatan:</strong> ' .
        h($data['notes'] ?: '-') .
    '</p>

    <div class="footer">
        Terima kasih telah menjadi anggota FitDesk Gym.<br>
        Simpan bukti pembayaran ini sebagai arsip transaksi.
    </div>
</body>
</html>';

$pdf->loadHtml($html, 'UTF-8');
$pdf->setPaper('A4', 'portrait');
$pdf->render();

$filename = 'Bukti-Pembayaran-FD-' .
    str_pad((string) $data['payment_id'], 6, '0', STR_PAD_LEFT) .
    '.pdf';

$pdf->stream($filename, ['Attachment' => true]);
exit;
