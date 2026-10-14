
<?php
require_once __DIR__ . '/functions.php';
require_login();

$pageTitle = $pageTitle ?? 'Dashboard';
$activeMenu = $activeMenu ?? 'dashboard';
$user = $_SESSION['user'];

$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$menus = [
    ['id'=>'dashboard', 'label'=>'Dashboard', 'icon'=>'bi-grid-1x2-fill', 'url'=>'dashboard.php'],
    ['id'=>'member_add', 'label'=>'Tambah Member', 'icon'=>'bi-person-plus-fill', 'url'=>'member_add.php'],
    ['id'=>'members', 'label'=>'Data Member', 'icon'=>'bi-people-fill', 'url'=>'members.php'],
    ['id'=>'status', 'label'=>'Cek Status', 'icon'=>'bi-patch-check-fill', 'url'=>'status.php'],
    ['id'=>'memberships', 'label'=>'Membership', 'icon'=>'bi-calendar2-check-fill', 'url'=>'memberships.php'],
    ['id'=>'reports', 'label'=>'Laporan', 'icon'=>'bi-file-earmark-bar-graph-fill', 'url'=>'reports.php']
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | FitDesk Gym</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/style.css" rel="stylesheet">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a href="dashboard.php" class="brand">
            <span class="brand-icon"><i class="bi bi-lightning-charge-fill"></i></span>
            <span>FitDesk <b>Gym</b></span>
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
                   href="users.php">
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
            <a href="logout.php" class="nav-link-custom logout-link">
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
