
<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('users.php');
}

verify_csrf();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    flash('danger', 'ID pengguna tidak valid.');
    redirect('users.php');
}

if ($id === (int)$_SESSION['user']['id']) {
    flash('danger', 'Kamu tidak dapat menghapus akun yang sedang digunakan.');
    redirect('users.php');
}

$stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
$stmt->execute([$id]);

flash(
    $stmt->rowCount() ? 'success' : 'warning',
    $stmt->rowCount() ? 'Pengguna berhasil dihapus.' : 'Pengguna tidak ditemukan.'
);

redirect('users.php');
