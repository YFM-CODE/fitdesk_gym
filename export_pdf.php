
<?php
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Pastikan pengguna sudah login
if (!isset($_SESSION['user_id']) && !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

// Ambil ID membership dari URL
$membershipId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$membershipId || $membershipId < 1) {
    http_response_code(400);
    exit('ID membership tidak valid.');
}

try {
    /*
     * Mengambil data anggota dan membership.
     * Nama kolom disesuaikan dengan struktur database kamu.
     */
    $sql = "
        SELECT
            ms.id AS membership_id,
            ms.package_duration_months,
            ms.start_date,
            ms.end_date,
            ms.is_active,
            ms.created_at,
            m.member_code,
            m.full_name,
            m.phone,
            m.email
        FROM memberships ms
        INNER JOIN members m ON m.id = ms.member_id
        WHERE ms.id = :id
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $membershipId]);
    $membership = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$membership) {
        http_response_code(404);
        exit('Data membership tidak ditemukan.');
    }

    // Ambil riwayat pembayaran
    $stmtPayment = $pdo->prepare("
        SELECT
            installment_number,
            amount,
            payment_method,
            payment_date,
            notes
        FROM payments
        WHERE membership_id = :id
        ORDER BY installment_number ASC, payment_date ASC
    ");

    $stmtPayment->execute(['id' => $membershipId]);
    $payments = $stmtPayment->fetchAll(PDO::FETCH_ASSOC);

    // Total pembayaran berdasarkan transaksi yang tercatat
    $totalPaid = 0;
    foreach ($payments as $payment) {
        $totalPaid += (float) $payment['amount'];
    }

    // Format rupiah
    $rupiah = static function ($amount) {
        return 'Rp ' . number_format((float) $amount, 0, ',', '.');
    };

    // Escape teks agar aman saat dimasukkan ke HTML PDF
    $esc = static function ($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };

    $duration = (int) $membership['package_duration_months'];
    $memberCode = $esc($membership['member_code']);
    $memberName = $esc($membership['full_name']);
    $phone = $esc($membership['phone']);
    $email = $esc($membership['email'] ?? '-');
    $startDate = date('d-m-Y', strtotime($membership['start_date']));
    $endDate = date('d-m-Y', strtotime($membership['end_date']));

    $statusMembership = ((int) $membership['is_active'] === 1)
        ? 'Aktif'
        : 'Tidak Aktif';

    /*
     * Tabel memberships saat ini belum memiliki kolom harga.
     * Karena itu, total tagihan belum bisa dihitung dengan benar
     * dari struktur tabel yang diberikan.
     */
    $totalTagihan = null;
    $sisaTagihan = null;

    $rows = '';

    if (count($payments) > 0) {
        foreach ($payments as $payment) {
            $method = $payment['payment_method'] === 'transfer'
                ? 'Transfer'
                : 'Cash';

            $date = !empty($payment['payment_date'])
                ? date('d-m-Y H:i', strtotime($payment['payment_date']))
                : '-';

            $rows .= '<tr>
                <td>' . (int) $payment['installment_number'] . '</td>
                <td>' . $esc($date) . '</td>
                <td>' . $esc($method) . '</td>
                <td class="right">' . $rupiah($payment['amount']) . '</td>
                <td>' . $esc($payment['notes'] ?? '-') . '</td>
            </tr>';
        }
    } else {
        $rows = '<tr><td colspan="5" class="center">
            Belum ada pembayaran tercatat
        </td></tr>';
    }

    $totalPaidText = $rupiah($totalPaid);

    $html = '
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <style>
            @page { margin: 35px 40px; }
            body {
                font-family: DejaVu Sans, sans-serif;
                font-size: 10px;
                color: #263244;
            }
            .header {
                text-align: center;
                border-bottom: 3px solid #2563eb;
                padding-bottom: 14px;
                margin-bottom: 22px;
            }
            .header h1 {
                margin: 0 0 6px;
                font-size: 22px;
                color: #1d4ed8;
            }
            .header p { margin: 3px 0; color: #64748b; }
            h2 {
                font-size: 13px;
                margin-top: 20px;
                color: #1e40af;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 8px;
            }
            th, td {
                border: 1px solid #dbe3ee;
                padding: 8px;
                vertical-align: top;
            }
            th {
                background: #eff6ff;
                text-align: left;
            }
            .details td:first-child {
                width: 32%;
                background: #f8fafc;
                font-weight: bold;
            }
            .right { text-align: right; }
            .center { text-align: center; }
            .summary {
                margin-top: 16px;
                padding: 12px;
                border: 1px solid #bfdbfe;
                background: #eff6ff;
            }
            .footer {
                margin-top: 30px;
                padding-top: 10px;
                border-top: 1px solid #cbd5e1;
                text-align: center;
                color: #64748b;
                font-size: 9px;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>FITDESK GYM</h1>
            <p>Laporan Membership Anggota</p>
            <p>Dokumen informasi keanggotaan dan riwayat pembayaran</p>
        </div>

        <h2>Informasi Anggota</h2>
        <table class="details">
            <tr><td>Kode Anggota</td><td>' . $memberCode . '</td></tr>
            <tr><td>Nama Lengkap</td><td>' . $memberName . '</td></tr>
            <tr><td>Nomor WhatsApp</td><td>' . $phone . '</td></tr>
            <tr><td>Email</td><td>' . $email . '</td></tr>
        </table>

        <h2>Informasi Membership</h2>
        <table class="details">
            <tr><td>Nomor Membership</td><td>MS-' . (int) $membership['membership_id'] . '</td></tr>
            <tr><td>Durasi Paket</td><td>' . $duration . ' bulan</td></tr>
            <tr><td>Tanggal Mulai</td><td>' . $esc($startDate) . '</td></tr>
            <tr><td>Tanggal Berakhir</td><td>' . $esc($endDate) . '</td></tr>
            <tr><td>Status Membership</td><td>' . $esc($statusMembership) . '</td></tr>
        </table>

        <h2>Riwayat Pembayaran</h2>
        <table>
            <thead>
                <tr>
                    <th>Cicilan ke-</th>
                    <th>Tanggal</th>
                    <th>Metode</th>
                    <th>Jumlah</th>
                    <th>Catatan</th>
                </tr>
            </thead>
            <tbody>' . $rows . '</tbody>
        </table>

        <div class="summary">
            <strong>Total pembayaran tercatat: ' . $totalPaidText . '</strong><br><br>
            Total tagihan dan sisa tagihan belum ditampilkan karena tabel
            memberships belum memiliki kolom harga paket.
        </div>

        <div class="footer">
            Dokumen dibuat otomatis oleh sistem FitDesk Gym.
        </div>
    </body>
    </html>';

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = 'membership_' .
        preg_replace('/[^A-Za-z0-9_-]/', '_', $membership['member_code']) .
        '.pdf';

    $dompdf->stream($filename, ['Attachment' => true]);
    exit;

} catch (Throwable $e) {
    error_log('Export PDF FitDesk Gym: ' . $e->getMessage());
    http_response_code(500);
    exit('PDF gagal dibuat. Periksa konfigurasi database dan log error PHP.');
}
