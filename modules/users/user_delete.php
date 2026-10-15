<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

verify_csrf();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    flash('danger', 'ID pengguna tidak valid.');
    redirect('index.php');
}

if ($id === (int)$_SESSION['user']['id']) {
    flash('danger', 'Kamu tidak dapat menghapus akun yang sedang digunakan.');
    redirect('index.php');
}

$stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
$stmt->execute([$id]);

flash(
    $stmt->rowCount() ? 'success' : 'warning',
    $stmt->rowCount() ? 'Pengguna berhasil dihapus.' : 'Pengguna tidak ditemukan.'
);

redirect('index.php');
