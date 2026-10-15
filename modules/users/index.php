<?php
session_start();

require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_admin();

date_default_timezone_set('Asia/Jakarta');

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
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

$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$users = $pdo->query(
    'SELECT id, username, role FROM users ORDER BY id DESC'
)->fetchAll(PDO::FETCH_ASSOC);

$totalUsers = count($users);
$totalAdmin = 0;
$totalResepsionis = 0;

foreach ($users as $u) {
    if ($u['role'] === 'admin') {
        $totalAdmin++;
    } else {
        $totalResepsionis++;
    }
}

$currentUserId = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengguna | FitDesk Gym</title>

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

        /* SIDEBAR (IDENTIK DENGAN DASHBOARD) */
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
            margin-bottom: 22px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 25px;
        }

        .subtitle {
            margin: 0;
            color: #818b83;
            font-size: 12px;
            line-height: 1.6;
        }

        /* KARTU STATISTIK */
        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat {
            padding: 20px;
            border: 1px solid #e5eae5;
            border-radius: 12px;
            background: #fff;
            transition: .2s;
        }

        .stat:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(24, 39, 32, 0.04);
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
            font-size: 17px;
        }

        .stat strong {
            display: block;
            margin-top: 13px;
            font-size: 26px;
            letter-spacing: -.5px;
        }

        .stat small {
            display: block;
            margin-top: 7px;
            color: #8b938d;
            font-size: 10px;
        }

        /* PANEL */
        .panel {
            padding: 21px;
            border: 1px solid #e5eae5;
            border-radius: 13px;
            background: #fff;
            min-width: 0;
            margin-bottom: 17px;
        }

        .panel-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 18px;
            flex-wrap: wrap;
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

        /* TOMBOL */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border: 0;
            border-radius: 9px;
            padding: 12px 17px;
            background: #c5f578;
            color: #1d2a20;
            font-weight: bold;
            font-family: inherit;
            font-size: 12px;
            cursor: pointer;
            transition: .2s;
        }

        .btn:hover {
            background: #b3e962;
            transform: translateY(-1px);
        }

        .btn-dark {
            background: #1c3026;
            color: #fff;
        }

        .btn-dark:hover {
            background: #294535;
        }

        .btn-light {
            background: #eef2ed;
            color: #344239;
        }

        .btn-danger {
            background: #fff0ed;
            color: #a44a3f;
            border: 1px solid #f2d2cc;
        }

        .btn-danger:hover {
            background: #fee2e2;
        }

        .btn-small {
            padding: 6px 11px;
            font-size: 10px;
            border-radius: 6px;
        }

        /* ALERT */
        .alert {
            padding: 13px 16px;
            margin-bottom: 18px;
            border-radius: 9px;
            font-size: 12px;
            line-height: 1.5;
        }

        .alert.success {
            color: #267448;
            background: #eaf7ee;
            border: 1px solid #cce9d4;
        }

        .alert.error {
            color: #a44a3f;
            background: #fff0ed;
            border: 1px solid #f2d2cc;
        }

        /* TABEL */
        .table-wrap {
            overflow-x: auto;
            margin-top: 12px;
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

        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: bold;
            white-space: nowrap;
        }

        .badge-admin {
            background: #eaf7ee;
            color: #267448;
            border: 1px solid #cce9d4;
        }

        .badge-resepsionis {
            background: #fff1dc;
            color: #9a620e;
            border: 1px solid #fed7aa;
        }

        .subtext {
            color: #8b938d;
            font-size: 11px;
            font-style: italic;
        }

        .foot {
            margin-top: 18px;
            color: #8a938c;
            font-size: 10px;
        }

        @media (max-width: 900px) {
            .stats {
                grid-template-columns: 1fr;
            }

            .layout {
                display: block;
            }

            .sidebar {
                width: 100%;
                padding: 15px;
            }

            .topbar {
                padding: 14px 15px;
            }

            .content {
                padding: 20px 14px;
            }
        }
    </style>
</head>

<body>
<div class="layout">

    <!-- SIDEBAR TERPUSAT SESUAI ROLE (AUTHENTICATED LAYOUT) -->
    <?php render_sidebar('users'); ?>

    <!-- KONTEN UTAMA -->
    <main class="main">

        <header class="topbar">
            <div>Administrasi &nbsp; › &nbsp; <strong>Pengguna</strong></div>
            <div><?= e(tanggalID($today)) ?></div>
        </header>

        <div class="content">

            <section class="heading">
                <div>
                    <h1>Pengguna Sistem</h1>
                    <p class="subtitle">
                        Kelola akun Admin dan Resepsionis untuk akses operasional FitDesk Gym.
                    </p>
                </div>

                <a href="user_add.php" class="btn">
                    ＋ Tambah Pengguna
                </a>
            </section>

            <?php if ($flashMessage): ?>
                <div class="alert <?= $flashMessage['type'] === 'success' ? 'success' : 'error' ?>">
                    <?= e($flashMessage['message']) ?>
                </div>
            <?php endif; ?>

            <!-- STATISTIK RINGKAS PENGGUNA -->
            <section class="stats">
                <article class="stat">
                    <div class="stat-top">
                        <span>Total pengguna</span>
                        <span class="stat-icon" style="background:#eff6ff;color:#2563eb;">⚙</span>
                    </div>
                    <strong><?= number_format($totalUsers) ?></strong>
                    <small>Seluruh akun terdaftar di sistem</small>
                </article>

                <article class="stat">
                    <div class="stat-top">
                        <span>Akun Admin</span>
                        <span class="stat-icon" style="background:#eaf7ee;color:#267448;">✓</span>
                    </div>
                    <strong><?= number_format($totalAdmin) ?></strong>
                    <small>Akses penuh manajemen dan paket</small>
                </article>

                <article class="stat">
                    <div class="stat-top">
                        <span>Akun Resepsionis</span>
                        <span class="stat-icon" style="background:#fff1dc;color:#9a620e;">♙</span>
                    </div>
                    <strong><?= number_format($totalResepsionis) ?></strong>
                    <small>Akses operasional anggota & pembayaran</small>
                </article>
            </section>

            <!-- TABEL DAFTAR PENGGUNA -->
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h3>Daftar Pengguna</h3>
                        <p>Kelola dan pantau seluruh akun pengguna sistem FitDesk Gym.</p>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:70px;">ID</th>
                                <th>USERNAME</th>
                                <th>ROLE</th>
                                <th style="width:120px;">AKSI</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$users): ?>
                            <tr>
                                <td colspan="4" style="text-align:center;padding:25px;color:#858e87;">
                                    Belum ada akun pengguna terdaftar.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td><strong><?= (int)$u['id'] ?></strong></td>
                                    <td>
                                        <strong style="color:#26342b;"><?= e($u['username']) ?></strong>
                                    </td>
                                    <td>
                                        <?php if ($u['role'] === 'admin'): ?>
                                            <span class="badge badge-admin">Admin</span>
                                        <?php else: ?>
                                            <span class="badge badge-resepsionis">Resepsionis</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$u['id'] !== $currentUserId): ?>
                                            <form method="post" action="user_delete.php" style="margin:0;" onsubmit="return confirm('Yakin ingin menghapus pengguna <?= e(addslashes($u['username'])) ?>?')">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-small">
                                                    Hapus
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="subtext">Akun Anda</span>
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
                FitDesk Gym © <?= date('Y') ?> — Manajemen pengguna sistem.
            </div>

        </div>
    </main>

</div>
</body>
</html>
