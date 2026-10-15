<?php
session_start();

if (!isset($_SESSION['user_id']) && !isset($_SESSION['username'])) {
    header('Location: ../auth/login.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/config/database.php';

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header('Location: index.php');
    exit;
}

try {
    // Soft delete: tandai deleted_at agar terhapus dari daftar anggota aktif,
    // namun riwayat keanggotaan dan pembayaran tetap tersimpan dan dapat diakses oleh Admin.
    $stmt = $pdo->prepare("UPDATE members SET deleted_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);

    header('Location: index.php?status=dihapus');
    exit;
} catch (PDOException $e) {
    error_log('Hapus member error: ' . $e->getMessage());
    header('Location: index.php?status=gagal');
    exit;
}
