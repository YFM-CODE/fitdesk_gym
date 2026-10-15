<?php
/**
 * FitDesk Gym - Authenticated Sidebar Component
 * Pusat kendali navigasi berbasis role (Admin vs Resepsionis)
 */

if (!function_exists('get_sidebar_menus')) {
    /**
     * Daftar menu navigasi terpusat.
     * Untuk menambah fitur baru bagi Admin / Resepsionis, cukup tambahkan 1 baris di array ini.
     */
    function get_sidebar_menus(): array
    {
        return [
            [
                'id'       => 'dashboard',
                'label'    => 'Dashboard',
                'icon'     => '▦',
                'path'     => 'dashboard/index.php',
                'roles'    => ['admin', 'resepsionis'],
                'section'  => 'Menu utama'
            ],
            [
                'id'       => 'members',
                'label'    => 'Data Anggota',
                'icon'     => '♙',
                'path'     => 'members/index.php',
                'roles'    => ['admin', 'resepsionis'], // Admin dan Resepsionis
                'section'  => 'Menu utama'
            ],
            [
                'id'       => 'memberships',
                'label'    => [
                    'admin'       => 'Paket Membership',
                    'resepsionis' => 'Membership'
                ],
                'icon'     => '♧',
                'path'     => 'memberships/index.php',
                'roles'    => ['admin', 'resepsionis'],
                'section'  => 'Menu utama'
            ],
            [
                'id'       => 'payments',
                'label'    => 'Pembayaran',
                'icon'     => '💳',
                'path'     => 'payments/index.php',
                'roles'    => ['resepsionis'], // Khusus Resepsionis
                'section'  => 'Menu utama'
            ],
            [
                'id'       => 'reports',
                'label'    => 'Laporan',
                'icon'     => '▤',
                'path'     => 'reports/index.php',
                'roles'    => ['admin'], // Khusus Admin
                'section'  => 'Menu utama'
            ],
            [
                'id'       => 'users',
                'label'    => 'Pengguna',
                'icon'     => '⚙',
                'path'     => 'users/index.php',
                'roles'    => ['admin'], // Khusus Admin
                'section'  => 'Administrasi'
            ],
        ];
    }
}

if (!function_exists('render_sidebar')) {
    /**
     * Render sidebar secara dinamis menyesuaikan role pengguna dan menu aktif.
     *
     * @param string $activePage ID halaman yang sedang aktif (misal: 'dashboard', 'memberships', 'payments', dll)
     */
    function render_sidebar(string $activePage = ''): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $currentRole = $_SESSION['role'] ?? $_SESSION['user']['role'] ?? 'resepsionis';

        // Tentukan prefix jalur berdasarkan lokasi file saat ini
        $scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
        $isInsideModule = (strpos($scriptPath, '/modules/') !== false);
        $prefix = $isInsideModule ? '../' : 'modules/';
        $logoutUrl = $isInsideModule ? '../auth/logout.php' : 'modules/auth/logout.php';

        $allMenus = get_sidebar_menus();

        // Kelompokkan menu yang diizinkan untuk role saat ini berdasarkan section
        $groupedMenus = [];
        foreach ($allMenus as $menu) {
            if (!in_array($currentRole, $menu['roles'], true)) {
                // Jika user sedang berada di halaman yang tidak masuk daftar default role-nya,
                // tampilkan menu tersebut sebagai menu aktif khusus sesi ini.
                if ($menu['id'] !== $activePage) {
                    continue;
                }
            }

            $section = $menu['section'] ?? 'Menu utama';

            // Menyesuaikan label per-role jika didefinisikan sebagai array
            $label = is_array($menu['label'])
                ? ($menu['label'][$currentRole] ?? reset($menu['label']))
                : $menu['label'];

            $groupedMenus[$section][] = [
                'id'     => $menu['id'],
                'label'  => $label,
                'icon'   => $menu['icon'],
                'url'    => $prefix . $menu['path'],
                'active' => ($menu['id'] === $activePage)
            ];
        }
        ?>
        <aside class="sidebar" id="appSidebar">
            <div class="brand">FitDesk<span> Gym.</span></div>

            <?php foreach ($groupedMenus as $sectionTitle => $items): ?>
                <div class="menu-label"><?= htmlspecialchars($sectionTitle, ENT_QUOTES, 'UTF-8') ?></div>
                <nav class="menu">
                    <?php foreach ($items as $item): ?>
                        <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>"
                           class="<?= $item['active'] ? 'active' : '' ?>">
                            <?= $item['icon'] ?> <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            <?php endforeach; ?>

            <div class="menu-label">Akun</div>
            <nav class="menu">
                <a href="<?= htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') ?>">
                    ↪ Keluar
                </a>
            </nav>
        </aside>

        <style>
            /* STICKY SIDEBAR: Tidak ikut tergulung hilang saat halaman di-scroll */
            #appSidebar.sidebar {
                position: sticky;
                top: 0;
                height: 100vh;
                overflow-y: auto;
                flex-shrink: 0;
                width: 235px;
                padding: 26px 15px;
                background: #182720;
                color: #fff;
                box-sizing: border-box;
                z-index: 100;
            }

            #appSidebar.sidebar::-webkit-scrollbar {
                width: 4px;
            }
            #appSidebar.sidebar::-webkit-scrollbar-thumb {
                background: rgba(255, 255, 255, 0.2);
                border-radius: 4px;
            }

            @media (max-width: 900px) {
                #appSidebar.sidebar {
                    position: relative;
                    height: auto;
                    width: 100%;
                }
            }
        </style>
        <?php
    }
}

// Jika file ini dipanggil langsung via require, otomatis render dengan $activePage (jika ada)
if (isset($activePage)) {
    render_sidebar($activePage);
}
