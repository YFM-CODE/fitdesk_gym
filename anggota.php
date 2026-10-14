
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

function redirectAnggota($pesan, $jenis = 'success')
{
    $_SESSION['flash_anggota'] = [
        'pesan' => $pesan,
        'jenis' => $jenis
    ];

    header('Location: anggota.php');
    exit;
}

if (empty($_SESSION['csrf_anggota'])) {
    $_SESSION['csrf_anggota'] = bin2hex(random_bytes(32));
}

/* PROSES TAMBAH, EDIT, DAN HAPUS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_anggota'], $token)) {
        redirectAnggota('Permintaan tidak valid. Silakan coba lagi.', 'error');
    }

    $aksi = $_POST['aksi'] ?? '';

    try {
        if ($aksi === 'tambah' || $aksi === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $nama = trim($_POST['full_name'] ?? '');
            $telepon = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if ($nama === '' || $telepon === '') {
                redirectAnggota('Nama dan nomor telepon wajib diisi.', 'error');
            }

            if (mb_strlen($nama) > 100) {
                redirectAnggota('Nama maksimal 100 karakter.', 'error');
            }

            if (mb_strlen($telepon) > 15) {
                redirectAnggota('Nomor telepon maksimal 15 karakter.', 'error');
            }

            if (!preg_match('/^[0-9+\-\s()]+$/', $telepon)) {
                redirectAnggota('Format nomor telepon tidak valid.', 'error');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                redirectAnggota('Format email tidak valid.', 'error');
            }

            if (mb_strlen($email) > 254) {
                redirectAnggota('Email terlalu panjang.', 'error');
            }

            if ($aksi === 'tambah') {
                // Membuat kode anggota otomatis.
                $stmt = $pdo->query("
                    SELECT MAX(id) FROM members
                ");
                $nomor = (int)$stmt->fetchColumn() + 1;
                $kode = 'MBR' . str_pad((string)$nomor, 5, '0', STR_PAD_LEFT);

                $stmt = $pdo->prepare("
                    INSERT INTO members
                        (member_code, full_name, phone, email, created_at)
                    VALUES
                        (?, ?, ?, ?, NOW())
                ");

                $stmt->execute([
                    $kode,
                    $nama,
                    $telepon,
                    $email !== '' ? $email : null
                ]);

                redirectAnggota('Anggota berhasil ditambahkan.');
            }

            if ($id <= 0) {
                redirectAnggota('ID anggota tidak valid.', 'error');
            }

            $stmt = $pdo->prepare("
                UPDATE members
                SET full_name = ?, phone = ?, email = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $nama,
                $telepon,
                $email !== '' ? $email : null,
                $id
            ]);

            redirectAnggota('Data anggota berhasil diperbarui.');
        }

        if ($aksi === 'hapus') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                redirectAnggota('ID anggota tidak valid.', 'error');
            }

            // Riwayat membership dipertahankan dengan melarang penghapusan
            // anggota yang sudah memiliki riwayat membership.
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM memberships
                WHERE member_id = ?
            ");
            $stmt->execute([$id]);

            if ((int)$stmt->fetchColumn() > 0) {
                redirectAnggota(
                    'Anggota memiliki riwayat membership. Data tidak dihapus agar riwayat tetap aman.',
                    'error'
                );
            }

            $stmt = $pdo->prepare("DELETE FROM members WHERE id = ?");
            $stmt->execute([$id]);

            redirectAnggota(
                $stmt->rowCount() > 0
                    ? 'Anggota berhasil dihapus.'
                    : 'Data anggota tidak ditemukan.',
                $stmt->rowCount() > 0 ? 'success' : 'error'
            );
        }

        redirectAnggota('Aksi tidak dikenali.', 'error');

    } catch (PDOException $ex) {
        error_log('Kesalahan anggota FitDesk: ' . $ex->getMessage());

        if ($ex->getCode() === '23000') {
            redirectAnggota(
                'Data gagal disimpan karena kode anggota atau data lain sudah digunakan.',
                'error'
            );
        }

        redirectAnggota(
            'Terjadi kesalahan saat memproses data. Periksa struktur database.',
            'error'
        );
    }
}

/* PESAN NOTIFIKASI */
$flash = $_SESSION['flash_anggota'] ?? null;
unset($_SESSION['flash_anggota']);

/* PENCARIAN DAN FILTER */
$cari = trim($_GET['cari'] ?? '');
$halaman = max(1, (int)($_GET['halaman'] ?? 1));
$batas = 10;
$offset = ($halaman - 1) * $batas;

try {
    $where = '';
    $params = [];

    if ($cari !== '') {
        $where = "
            WHERE member_code LIKE ?
               OR full_name LIKE ?
               OR phone LIKE ?
               OR email LIKE ?
        ";

        $kata = '%' . $cari . '%';
        $params = [$kata, $kata, $kata, $kata];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM members
        $where
    ");
    $stmt->execute($params);
    $totalData = (int)$stmt->fetchColumn();

    $totalHalaman = max(1, (int)ceil($totalData / $batas));

    if ($halaman > $totalHalaman) {
        $halaman = $totalHalaman;
        $offset = ($halaman - 1) * $batas;
    }

    $sql = "
        SELECT
            m.id,
            m.member_code,
            m.full_name,
            m.phone,
            m.email,
            m.created_at,
            ms.start_date,
            ms.end_date,
            ms.is_active
        FROM members m
        LEFT JOIN memberships ms
            ON ms.id = (
                SELECT ms2.id
                FROM memberships ms2
                WHERE ms2.member_id = m.id
                ORDER BY ms2.start_date DESC, ms2.id DESC
                LIMIT 1
            )
        $where
        ORDER BY m.id DESC
        LIMIT $batas OFFSET $offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $anggota = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalAnggota = (int)$pdo
        ->query("SELECT COUNT(*) FROM members")
        ->fetchColumn();

    $totalMembershipAktif = (int)$pdo->query("
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

} catch (PDOException $ex) {
    error_log('Kesalahan memuat anggota FitDesk: ' . $ex->getMessage());
    die(
        '<h2>Gagal memuat data anggota</h2>
        <p>Pastikan tabel members dan memberships tersedia dan koneksi database benar.</p>'
    );
}

$namaPengguna = $_SESSION['nama_lengkap']
    ?? $_SESSION['username']
    ?? 'Pengguna';

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Anggota | FitDesk Gym</title>

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
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:11px 15px;border:0;border-radius:8px;background:#c5f578;color:#1c3026;font-weight:bold;font-size:12px;cursor:pointer}
.btn:hover{filter:brightness(.96)}
.btn-dark{background:#1c3026;color:white}
.btn-light{background:#eef2ed;color:#344239}
.btn-danger{background:#fff0ed;color:#a13e33}
.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px;margin-bottom:18px}
.stat{padding:19px;border:1px solid #e5eae5;border-radius:12px;background:#fff}
.stat-label{display:flex;justify-content:space-between;align-items:center;color:#758078;font-size:11px;gap:8px}
.stat-icon{display:grid;place-items:center;width:35px;height:35px;border-radius:10px;background:#eff6e7;color:#315a37;font-size:17px}
.stat strong{display:block;margin-top:12px;font-size:26px}
.panel{padding:21px;border:1px solid #e5eae5;border-radius:13px;background:#fff;margin-bottom:17px}
.panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:17px}
.panel h3{margin:0 0 6px;font-size:15px}
.panel p{margin:0;color:#858e87;font-size:11px;line-height:1.5}
.search-form{display:flex;gap:8px;flex-wrap:wrap}
.search-form input{width:250px;max-width:100%;padding:10px 12px;border:1px solid #dfe5df;border-radius:8px;outline:none}
.search-form input:focus,.form-group input:focus{border-color:#8fb65a;box-shadow:0 0 0 3px #c5f57833}
.alert{padding:13px 15px;border-radius:9px;margin-bottom:17px;font-size:12px;line-height:1.6}
.alert.success{background:#e9f7ec;color:#286a3b;border:1px solid #d1ecd8}
.alert.error{background:#fff0ed;color:#9b3c31;border:1px solid #f3d4cf}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;white-space:nowrap}
th{text-align:left;padding:13px 11px;background:#f7f8f6;color:#7e887f;font-size:10px;font-weight:bold}
td{padding:13px 11px;border-bottom:1px solid #edf0ed;font-size:11px}
tr:last-child td{border-bottom:0}
.member-name{font-weight:bold;color:#26342b}
.subtext{display:block;margin-top:4px;color:#89928b;font-size:10px}
.status{display:inline-block;padding:6px 9px;border-radius:20px;background:#eaf7ee;color:#267448;font-size:10px}
.status.off{background:#fff0ed;color:#a44a3f}
.status.wait{background:#fff6df;color:#8b6a20}
.actions{display:flex;gap:6px}
.action-btn{padding:7px 9px;border:0;border-radius:6px;cursor:pointer;font-size:10px;font-weight:bold}
.action-edit{background:#edf4e8;color:#38633a}
.action-delete{background:#fff0ed;color:#a13e33}
.empty{text-align:center;padding:35px 15px;color:#858e87}
.pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:17px;color:#818b83;font-size:11px}
.pages{display:flex;gap:5px;flex-wrap:wrap}
.pages a,.pages span{padding:8px 11px;border:1px solid #e3e8e3;border-radius:7px;background:white}
.pages .current{background:#1c3026;color:white;border-color:#1c3026}
.foot{margin-top:18px;color:#8a938c;font-size:10px}
.modal{display:none;position:fixed;inset:0;z-index:100;background:#0d1a13a8;padding:20px;align-items:center;justify-content:center;overflow-y:auto}
.modal.show{display:flex}
.modal-box{width:100%;max-width:510px;background:white;border-radius:14px;padding:24px;box-shadow:0 20px 60px #0002}
.modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px}
.modal-head h2{margin:0;font-size:19px}
.close{border:0;background:#eef2ed;width:33px;height:33px;border-radius:8px;cursor:pointer;font-size:19px}
.form-grid{display:grid;grid-template-columns:1fr;gap:14px}
.form-group label{display:block;margin-bottom:7px;font-size:11px;font-weight:bold;color:#465349}
.form-group input{width:100%;padding:11px 12px;border:1px solid #dfe5df;border-radius:8px;outline:none}
.form-group small{display:block;margin-top:5px;color:#89928b;font-size:10px}
.form-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:22px}
@media(max-width:1000px){.stats{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:700px){.layout{display:block}.sidebar{width:100%;padding:15px}.brand{padding:0 10px 12px}.menu{display:flex;gap:5px;overflow-x:auto}.menu-label{display:none}.menu a{white-space:nowrap;padding:10px}.topbar{padding:14px 15px}.content{padding:20px 14px}.stats{grid-template-columns:1fr}.stat{padding:15px}.search-form{width:100%}.search-form input{width:100%;flex:1}.panel{padding:15px}.heading h1{font-size:22px}}
</style>
</head>

<body>
<div class="layout">

<aside class="sidebar">
    <div class="brand">FitDesk<span> Gym.</span></div>

    <div class="menu-label">Menu utama</div>
    <nav class="menu">
        <a href="dashboard.php">▦ Dashboard</a>
        <a href="anggota.php" class="active">♙ Data Anggota</a>
        <a href="memberships.php">♧ Paket Membership</a>
        <a href="laporan.php">▤ Laporan</a>
    </nav>

    <div class="menu-label">Akun</div>
    <nav class="menu">
        <a href="logout.php">↪ Keluar</a>
    </nav>
</aside>

<main class="main">
<header class="topbar">
    <div>Operasional &nbsp; › &nbsp; <strong>Data Anggota</strong></div>
    <div><?= e($namaPengguna) ?></div>
</header>

<div class="content">

    <div class="heading">
        <div>
            <h1>Data Anggota</h1>
            <p class="subtitle">Kelola informasi anggota FitDesk Gym dengan mudah dan teratur.</p>
        </div>

        <button class="btn" type="button" onclick="bukaTambah()">
            ＋ Tambah Anggota
        </button>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= e($flash['jenis']) ?>">
            <?= e($flash['pesan']) ?>
        </div>
    <?php endif; ?>

    <section class="stats">
        <article class="stat">
            <div class="stat-label">
                <span>Total anggota</span>
                <span class="stat-icon">♙</span>
            </div>
            <strong><?= number_format($totalAnggota) ?></strong>
        </article>

        <article class="stat">
            <div class="stat-label">
                <span>Membership aktif</span>
                <span class="stat-icon">✓</span>
            </div>
            <strong><?= number_format($totalMembershipAktif) ?></strong>
        </article>

        <article class="stat">
            <div class="stat-label">
                <span>Membership kedaluwarsa</span>
                <span class="stat-icon">◷</span>
            </div>
            <strong><?= number_format($totalKedaluwarsa) ?></strong>
        </article>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h3>Daftar anggota</h3>
                <p>Gunakan pencarian untuk menemukan anggota berdasarkan kode, nama, telepon, atau email.</p>
            </div>

            <form class="search-form" method="GET" action="anggota.php">
                <input
                    type="search"
                    name="cari"
                    placeholder="Cari anggota..."
                    value="<?= e($cari) ?>"
                >
                <button class="btn btn-dark" type="submit">Cari</button>

                <?php if ($cari !== ''): ?>
                    <a href="anggota.php" class="btn btn-light">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>NO.</th>
                    <th>KODE ANGGOTA</th>
                    <th>NAMA ANGGOTA</th>
                    <th>TELEPON</th>
                    <th>EMAIL</th>
                    <th>TANGGAL DAFTAR</th>
                    <th>MEMBERSHIP</th>
                    <th>AKSI</th>
                </tr>
                </thead>

                <tbody>
                <?php if (!$anggota): ?>
                    <tr>
                        <td colspan="8" class="empty">
                            <?php if ($cari !== ''): ?>
                                Data anggota dengan kata kunci tersebut tidak ditemukan.
                            <?php else: ?>
                                Belum ada anggota. Klik "Tambah Anggota" untuk memulai.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($anggota as $index => $m): ?>
                        <?php
                        $aktif = !empty($m['is_active'])
                            && !empty($m['start_date'])
                            && $m['start_date'] <= $today
                            && $m['end_date'] >= $today;

                        $expired = !empty($m['end_date'])
                            && $m['end_date'] < $today;

                        $status = $aktif
                            ? 'Aktif'
                            : ($expired ? 'Kedaluwarsa' : 'Belum aktif');
                        ?>

                        <tr>
                            <td><?= $offset + $index + 1 ?></td>
                            <td><?= e($m['member_code']) ?></td>

                            <td>
                                <span class="member-name"><?= e($m['full_name']) ?></span>
                            </td>

                            <td><?= e($m['phone']) ?></td>
                            <td><?= e($m['email'] ?: '-') ?></td>

                            <td>
                                <?= e(
                                    $m['created_at']
                                        ? date('d-m-Y', strtotime($m['created_at']))
                                        : '-'
                                ) ?>
                            </td>

                            <td>
                                <span class="status <?= $aktif ? '' : ($expired ? 'off' : 'wait') ?>">
                                    <?= e($status) ?>
                                </span>
                            </td>

                            <td>
                                <div class="actions">
                                    <button
                                        type="button"
                                        class="action-btn action-edit"
                                        onclick='bukaEdit(<?= json_encode([
                                            'id' => (int)$m['id'],
                                            'full_name' => $m['full_name'],
                                            'phone' => $m['phone'],
                                            'email' => $m['email']
                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                    >
                                        Edit
                                    </button>

                                    <form method="POST" action="anggota.php"
                                          onsubmit="return confirm('Yakin ingin menghapus anggota ini? Data dengan riwayat membership tidak dapat dihapus.');">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_anggota']) ?>">
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                        <button class="action-btn action-delete" type="submit">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="pagination">
            <div>
                Menampilkan
                <?= $totalData > 0 ? $offset + 1 : 0 ?>
                –
                <?= min($offset + $batas, $totalData) ?>
                dari <?= number_format($totalData) ?> anggota
            </div>

            <?php if ($totalHalaman > 1): ?>
                <div class="pages">
                    <?php if ($halaman > 1): ?>
                        <a href="?cari=<?= urlencode($cari) ?>&halaman=<?= $halaman - 1 ?>">‹</a>
                    <?php endif; ?>

                    <?php
                    $mulai = max(1, $halaman - 2);
                    $akhir = min($totalHalaman, $halaman + 2);
                    ?>

                    <?php for ($p = $mulai; $p <= $akhir; $p++): ?>
                        <?php if ($p === $halaman): ?>
                            <span class="current"><?= $p ?></span>
                        <?php else: ?>
                            <a href="?cari=<?= urlencode($cari) ?>&halaman=<?= $p ?>"><?= $p ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($halaman < $totalHalaman): ?>
                        <a href="?cari=<?= urlencode($cari) ?>&halaman=<?= $halaman + 1 ?>">›</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="foot">
        FitDesk Gym © <?= date('Y') ?> — Manajemen data anggota.
    </div>

</div>
</main>
</div>

<!-- MODAL TAMBAH / EDIT ANGGOTA -->
<div class="modal" id="modalAnggota">
    <div class="modal-box">
        <div class="modal-head">
            <div>
                <h2 id="judulModal">Tambah Anggota</h2>
                <p>Lengkapi informasi anggota di bawah ini.</p>
            </div>
            <button type="button" class="close" onclick="tutupModal()">×</button>
        </div>

        <form method="POST" action="anggota.php">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_anggota']) ?>">
            <input type="hidden" name="aksi" id="aksiForm" value="tambah">
            <input type="hidden" name="id" id="anggotaId" value="">

            <div class="form-grid">
                <div class="form-group">
                    <label for="full_name">Nama lengkap *</label>
                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        maxlength="100"
                        placeholder="Masukkan nama lengkap"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="phone">Nomor telepon *</label>
                    <input
                        type="tel"
                        id="phone"
                        name="phone"
                        maxlength="15"
                        placeholder="Contoh: 081234567890"
                        required
                    >
                    <small>Masukkan nomor telepon yang dapat dihubungi.</small>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        maxlength="254"
                        placeholder="Contoh: nama@email.com"
                    >
                    <small>Email boleh dikosongkan jika anggota tidak memilikinya.</small>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-light" onclick="tutupModal()">Batal</button>
                <button type="submit" class="btn" id="tombolSimpan">Simpan Anggota</button>
            </div>
        </form>
    </div>
</div>

<script>
const modal = document.getElementById('modalAnggota');

function bukaTambah() {
    document.getElementById('judulModal').textContent = 'Tambah Anggota';
    document.getElementById('aksiForm').value = 'tambah';
    document.getElementById('anggotaId').value = '';
    document.getElementById('full_name').value = '';
    document.getElementById('phone').value = '';
    document.getElementById('email').value = '';
    document.getElementById('tombolSimpan').textContent = 'Simpan Anggota';

    modal.classList.add('show');
    document.getElementById('full_name').focus();
}

function bukaEdit(data) {
    document.getElementById('judulModal').textContent = 'Edit Anggota';
    document.getElementById('aksiForm').value = 'edit';
    document.getElementById('anggotaId').value = data.id;
    document.getElementById('full_name').value = data.full_name || '';
    document.getElementById('phone').value = data.phone || '';
    document.getElementById('email').value = data.email || '';
    document.getElementById('tombolSimpan').textContent = 'Simpan Perubahan';

    modal.classList.add('show');
    document.getElementById('full_name').focus();
}

function tutupModal() {
    modal.classList.remove('show');
}

modal.addEventListener('click', function(event) {
    if (event.target === modal) {
        tutupModal();
    }
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        tutupModal();
    }
});
</script>

</body>
</html>
