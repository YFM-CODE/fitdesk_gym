
<?php
session_start();

if (!isset($_SESSION['user_id']) && !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

function tampilkanPesan($judul, $pesan, $berhasil = false)
{
    $warna = $berhasil ? '#287c45' : '#c43e3e';
    $ikon = $berhasil ? '✓' : '!';

    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($judul, ENT_QUOTES, 'UTF-8') ?> - FitDesk Gym</title>
        <style>
            * { box-sizing: border-box; }
            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 20px;
                background: #f4f6f4;
                font-family: Arial, sans-serif;
                color: #26352c;
            }
            .card {
                width: 100%;
                max-width: 520px;
                background: #fff;
                border-radius: 16px;
                padding: 30px;
                box-shadow: 0 8px 30px rgba(0,0,0,.07);
            }
            .icon {
                width: 50px;
                height: 50px;
                display: flex;
                justify-content: center;
                align-items: center;
                border-radius: 50%;
                background: <?= json_encode($berhasil ? '#e5f5e7' : '#fff0f0') ?>;
                color: <?= json_encode($warna) ?>;
                font-size: 27px;
                font-weight: bold;
            }
            h1 { font-size: 22px; margin: 18px 0 10px; }
            p { line-height: 1.7; font-size: 14px; }
            .button {
                display: inline-block;
                margin-top: 15px;
                padding: 12px 18px;
                border-radius: 8px;
                background: #182720;
                color: #c5f578;
                text-decoration: none;
                font-size: 13px;
                font-weight: bold;
            }
            .detail {
                margin-top: 15px;
                padding: 12px;
                border-radius: 8px;
                background: #f4f6f4;
                font-size: 12px;
                line-height: 1.6;
                overflow-wrap: anywhere;
            }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon"><?= $ikon ?></div>
            <h1><?= htmlspecialchars($judul, ENT_QUOTES, 'UTF-8') ?></h1>
            <p><?= nl2br(htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8')) ?></p>
            <a class="button" href="pembayaran.php">Kembali ke Pembayaran</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function normalisasiNomor($nomor)
{
    $nomor = preg_replace('/\D+/', '', (string) $nomor);

    if (str_starts_with($nomor, '0')) {
        $nomor = '62' . substr($nomor, 1);
    } elseif (str_starts_with($nomor, '8')) {
        $nomor = '62' . $nomor;
    }

    if (!preg_match('/^62[0-9]{8,13}$/', $nomor)) {
        return false;
    }

    return $nomor;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tampilkanPesan(
        'Permintaan Tidak Valid',
        'Halaman ini harus dibuka melalui tombol Kirim WA pada riwayat pembayaran.'
    );
}

$paymentId = filter_input(INPUT_POST, 'payment_id', FILTER_VALIDATE_INT);

if (!$paymentId || $paymentId < 1) {
    tampilkanPesan(
        'ID Pembayaran Tidak Valid',
        'ID pembayaran tidak ditemukan. Silakan kembali dan pilih transaksi yang benar.'
    );
}

/*
|--------------------------------------------------------------------------
| BACA KONFIGURASI FONNTE
|--------------------------------------------------------------------------
*/
$configFile = __DIR__ . '/config/fonnte.php';

if (!file_exists($configFile)) {
    tampilkanPesan(
        'Konfigurasi Belum Ada',
        'File config/fonnte.php belum ditemukan. Buat file tersebut dan isi token Fonnte terlebih dahulu.'
    );
}

$config = require $configFile;

$token = trim((string) ($config['token'] ?? ''));
$baseUrl = rtrim(trim((string) ($config['public_base_url'] ?? '')), '/');

if (
    $token === '' ||
    str_contains($token, 'MASUKKAN_TOKEN')
) {
    tampilkanPesan(
        'Token Fonnte Belum Diisi',
        'Buka config/fonnte.php dan masukkan token API Fonnte yang benar.'
    );
}

/*
|--------------------------------------------------------------------------
| AMBIL DATA TRANSAKSI DAN ANGGOTA
|--------------------------------------------------------------------------
*/
try {
    $sql = "
        SELECT
            p.id AS payment_id,
            p.installment_number,
            p.amount,
            p.payment_method,
            p.payment_date,
            p.notes,
            m.id AS member_id,
            m.member_code,
            m.full_name,
            m.phone,
            m.email,
            ms.id AS membership_id,
            ms.start_date,
            ms.end_date,
            ms.payment_plan,
            ms.package_duration_months,
            mp.package_name,
            mp.price
        FROM payments p
        INNER JOIN memberships ms
            ON ms.id = p.membership_id
        INNER JOIN members m
            ON m.id = ms.member_id
        LEFT JOIN membership_packages mp
            ON mp.duration_months = ms.package_duration_months
        WHERE p.id = :payment_id
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['payment_id' => $paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    tampilkanPesan(
        'Gagal Membaca Pembayaran',
        'Data transaksi tidak dapat dibaca. Periksa struktur tabel database FitDesk Gym.'
    );
}

if (!$payment) {
    tampilkanPesan(
        'Pembayaran Tidak Ditemukan',
        'Transaksi dengan ID tersebut tidak ditemukan di database.'
    );
}

$nomorWhatsApp = normalisasiNomor($payment['phone']);

if (!$nomorWhatsApp) {
    tampilkanPesan(
        'Nomor WhatsApp Tidak Valid',
        'Nomor anggota ' . $payment['full_name'] .
        ' belum valid. Periksa nomor telepon di Data Anggota.'
    );
}

/*
|--------------------------------------------------------------------------
| SIAPKAN URL PDF
|--------------------------------------------------------------------------
|
| Fonnte harus dapat mengakses URL PDF melalui internet.
| URL localhost tidak dapat diakses oleh server Fonnte.
|
*/
if (
    !filter_var($baseUrl, FILTER_VALIDATE_URL) ||
    !str_starts_with($baseUrl, 'https://')
) {
    tampilkanPesan(
        'Alamat PDF Belum Dikonfigurasi',
        'Isi public_base_url di config/fonnte.php dengan alamat HTTPS publik website yang dapat diakses Fonnte. Alamat localhost tidak bisa digunakan.'
    );
}

/*
 * Pastikan file pembuat PDF tersedia.
 */
$pdfScript = __DIR__ . '/bukti_pembayaran.php';

if (!file_exists($pdfScript)) {
    tampilkanPesan(
        'File PDF Tidak Ditemukan',
        'File bukti_pembayaran.php belum ada di folder utama FitDesk Gym.'
    );
}

/*
 * Buat URL publik bukti pembayaran.
 * Endpoint PDF harus dapat diakses oleh Fonnte tanpa sesi login.
 * Gunakan endpoint publik khusus yang aman dengan token akses terbatas
 * jika diperlukan; jangan membuka seluruh transaksi ke publik.
 */
$pdfUrl = $baseUrl . '/bukti_pembayaran.php?id=' . $paymentId;

/*
|--------------------------------------------------------------------------
| KIRIM PESAN WHATSAPP MELALUI FONNTE
|--------------------------------------------------------------------------
|
| Versi ini mengirim tautan bukti PDF melalui pesan WhatsApp.
| Ini bukan unggahan lampiran PDF secara langsung.
|
*/
$nama = $payment['full_name'];
$nomorBukti = 'FD-' . str_pad(
    (string) $payment['payment_id'],
    6,
    '0',
    STR_PAD_LEFT
);

$jumlah = 'Rp ' . number_format(
    (float) $payment['amount'],
    0,
    ',',
    '.'
);

$metode = $payment['payment_method'] === 'transfer'
    ? 'Transfer Bank'
    : 'Tunai';

$tanggal = date(
    'd-m-Y H:i',
    strtotime($payment['payment_date'])
);

$pesan =
    "Halo {$nama},\n\n" .
    "Berikut informasi pembayaran membership FitDesk Gym.\n\n" .
    "Nomor bukti: {$nomorBukti}\n" .
    "Tanggal: {$tanggal}\n" .
    "Paket: " . ($payment['package_name'] ?? 'Membership') . "\n" .
    "Jumlah dibayar: {$jumlah}\n" .
    "Metode: {$metode}\n\n" .
    "Buka tautan berikut untuk melihat bukti pembayaran PDF:\n" .
    $pdfUrl . "\n\n" .
    "Terima kasih telah menjadi anggota FitDesk Gym.";

/*
|--------------------------------------------------------------------------
| REQUEST KE FONNTE
|--------------------------------------------------------------------------
*/
if (!function_exists('curl_init')) {
    tampilkanPesan(
        'cURL Belum Aktif',
        'Aktifkan ekstensi cURL pada konfigurasi PHP XAMPP, kemudian restart Apache.'
    );
}

$postData = [
    'target' => $nomorWhatsApp,
    'message' => $pesan
];

$ch = curl_init('https://api.fonnte.com/send');

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($postData),
    CURLOPT_HTTPHEADER => [
        'Authorization: ' . $token
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 45
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($response === false) {
    tampilkanPesan(
        'Koneksi Fonnte Gagal',
        'Server tidak berhasil menghubungi Fonnte. Periksa koneksi internet dan konfigurasi cURL. Detail: ' . $curlError
    );
}

$result = json_decode($response, true);

if ($httpCode < 200 || $httpCode >= 300 || !is_array($result)) {
    tampilkanPesan(
        'Respons Fonnte Tidak Valid',
        'Fonnte mengembalikan respons yang tidak sesuai. Kode HTTP: ' . $httpCode
    );
}

if (empty($result['status'])) {
    $detail = $result['reason'] ?? $result['detail'] ?? 'Periksa token dan status perangkat WhatsApp Fonnte.';

    tampilkanPesan(
        'Pengiriman Belum Berhasil',
        'Fonnte belum mengonfirmasi pengiriman pesan. ' . (string) $detail
    );
}

tampilkanPesan(
    'Permintaan WhatsApp Diterima',
    'Fonnte menerima permintaan pesan untuk ' . $nama .
    ' (' . $nomorWhatsApp . '). Pesan berisi tautan bukti pembayaran PDF. ' .
    'Periksa status pesan di dashboard Fonnte untuk memastikan pesan benar-benar terkirim.',
    true
);
