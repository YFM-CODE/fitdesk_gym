
<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();

$pageTitle = 'Tambah Pengguna';
$activeMenu = 'users';
$errors = [];
$usernameInput = '';
$role = 'resepsionis';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $usernameInput = trim($_POST['username'] ?? '');
    $passwordInput = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';

    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $usernameInput)) {
        $errors[] = 'Username harus 3–50 karakter, hanya huruf, angka, titik, garis bawah, atau tanda hubung.';
    }

    if (strlen($passwordInput) < 8) {
        $errors[] = 'Password minimal 8 karakter.';
    }

    if (!in_array($role, ['admin', 'resepsionis'], true)) {
        $errors[] = 'Role tidak valid.';
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password, role) VALUES (?, ?, ?)'
            );
            $stmt->execute([
                $usernameInput,
                password_hash($passwordInput, PASSWORD_DEFAULT),
                $role
            ]);

            flash('success', 'Pengguna berhasil ditambahkan.');
            redirect('users.php');
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                $errors[] = 'Username sudah digunakan.';
            } else {
                error_log($ex->getMessage());
                $errors[] = 'Pengguna gagal disimpan.';
            }
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<div class="page-heading">
    <div><h1>Tambah Pengguna</h1><p>Buat akun baru untuk sistem FitDesk Gym.</p></div>
    <a href="users.php" class="btn btn-outline-secondary">Kembali</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">Informasi Akun</div>
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="mb-3">
                <label class="form-label">Username *</label>
                <input class="form-control" name="username" value="<?= e($usernameInput) ?>" minlength="3" maxlength="50" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Password *</label>
                <input class="form-control" type="password" name="password" minlength="8" required autocomplete="new-password">
                <small class="text-secondary">Minimal 8 karakter.</small>
            </div>
            <div class="mb-4">
                <label class="form-label">Role *</label>
                <select class="form-select" name="role" required>
                    <option value="resepsionis" <?= $role === 'resepsionis' ? 'selected' : '' ?>>Resepsionis</option>
                    <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admin</option>
                </select>
            </div>
            <button class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Simpan Pengguna</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
