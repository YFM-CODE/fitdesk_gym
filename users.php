
<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();

$pageTitle = 'Pengguna';
$activeMenu = 'users';

$users = $pdo->query(
    'SELECT id, username, role FROM users ORDER BY id DESC'
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<div class="page-heading">
    <div><h1>Pengguna Sistem</h1><p>Kelola akun Admin dan Resepsionis.</p></div>
    <a href="user_add.php" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i> Tambah Pengguna</a>
</div>

<div class="card">
    <div class="card-header">Daftar Pengguna</div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>ID</th><th>USERNAME</th><th>ROLE</th><th>AKSI</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= (int)$u['id'] ?></td>
                    <td class="fw-semibold"><?= e($u['username']) ?></td>
                    <td><span class="badge-status <?= $u['role'] === 'admin' ? 'status-active' : 'status-expired' ?>"><?= e(ucfirst($u['role'])) ?></span></td>
                    <td>
                        <?php if ((int)$u['id'] !== (int)$_SESSION['user']['id']): ?>
                            <form method="post" action="user_delete.php" onsubmit="return confirm('Yakin menghapus pengguna ini?')">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i> Hapus</button>
                            </form>
                        <?php else: ?>
                            <span class="text-secondary small">Akun Anda</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
