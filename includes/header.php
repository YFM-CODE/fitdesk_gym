<?php
require_once __DIR__ . '/functions.php';
require_login();

$pageTitle = $pageTitle ?? 'Dashboard';
$activeMenu = $activeMenu ?? 'dashboard';

$isModule = (basename(dirname($_SERVER['SCRIPT_FILENAME'] ?? '')) !== 'fitdesk_gym');
$prefix = $isModule ? '../' : 'modules/';
$authPrefix = $isModule ? '../auth/' : 'modules/auth/';
$rootPrefix = $isModule ? '../../' : '';

$user = $_SESSION['user'] ?? [
    'id' => $_SESSION['user_id'] ?? 0,
    'username' => $_SESSION['username'] ?? 'User',
    'role' => $_SESSION['role'] ?? 'resepsionis',
    'nama_lengkap' => $_SESSION['nama_lengkap'] ?? ''
];

$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($user['role'] === 'admin') {
    $menus = [
        ['id'=>'dashboard', 'label'=>'Dashboard', 'icon'=>'bi-grid-1x2-fill', 'url'=> $prefix . 'dashboard/index.php'],
        ['id'=>'memberships', 'label'=>'Paket Membership', 'icon'=>'bi-calendar2-check-fill', 'url'=> $prefix . 'memberships/index.php'],
        ['id'=>'reports', 'label'=>'Laporan', 'icon'=>'bi-file-earmark-bar-graph-fill', 'url'=> $prefix . 'reports/index.php']
    ];
} else {
    $menus = [
        ['id'=>'dashboard', 'label'=>'Dashboard', 'icon'=>'bi-grid-1x2-fill', 'url'=> $prefix . 'dashboard/index.php'],
        ['id'=>'members', 'label'=>'Data Member', 'icon'=>'bi-people-fill', 'url'=> $prefix . 'members/index.php'],
        ['id'=>'status', 'label'=>'Cek Status', 'icon'=>'bi-patch-check-fill', 'url'=> $prefix . 'members/status.php'],
        ['id'=>'memberships', 'label'=>'Membership', 'icon'=>'bi-calendar2-check-fill', 'url'=> $prefix . 'memberships/index.php'],
        ['id'=>'payments', 'label'=>'Pembayaran', 'icon'=>'bi-credit-card-fill', 'url'=> $prefix . 'payments/index.php']
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | FitDesk Gym</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= $rootPrefix ?>assets/style.css" rel="stylesheet">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a href="<?= $prefix ?>dashboard/index.php" class="brand">
            FitDesk<span> Gym.</span>
        </a>

        <div class="sidebar-caption">MENU UTAMA</div>
        <nav>
            <?php foreach ($menus as $menu): ?>
                <a class="nav-link-custom <?= $activeMenu === $menu['id'] ? 'active' : '' ?>"
                   href="<?= e($menu['url']) ?>">
                    <i class="bi <?= e($menu['icon']) ?>"></i>
                    <span><?= e($menu['label']) ?></span>
                </a>
            <?php endforeach; ?>

            <?php if ($user['role'] === 'admin'): ?>
                <div class="sidebar-caption mt-4">ADMINISTRASI</div>
                <a class="nav-link-custom <?= $activeMenu === 'users' ? 'active' : '' ?>"
                   href="<?= $prefix ?>users/index.php">
                    <i class="bi bi-person-gear"></i>
                    <span>Pengguna</span>
                </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-bottom">
            <div class="user-mini">
                <div class="avatar"><i class="bi bi-person-fill"></i></div>
                <div class="flex-grow-1">
                    <strong><?= e($user['username']) ?></strong>
                    <small><?= e(ucfirst($user['role'])) ?></small>
                </div>
            </div>
            <a href="<?= $authPrefix ?>logout.php" class="nav-link-custom logout-link">
                <i class="bi bi-box-arrow-left"></i> Keluar
            </a>
        </div>
    </aside>

    <main class="main-area">
        <header class="topbar">
            <div>
                <div class="fw-bold"><?= e($pageTitle) ?></div>
                <small class="text-secondary">Sistem Manajemen Membership Gym</small>
            </div>
            <div class="topbar-user">
                <div class="text-end">
                    <strong><?= e($user['username']) ?></strong>
                    <small><?= e(ucfirst($user['role'])) ?></small>
                </div>
                <div class="avatar"><i class="bi bi-person-fill"></i></div>
            </div>
        </header>

        <section class="content-area">
            <?php if ($flashMessage): ?>
                <div class="alert alert-<?= e($flashMessage['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($flashMessage['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
                </div>
            <?php endif; ?>
