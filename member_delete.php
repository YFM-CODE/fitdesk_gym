
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header('Location: members.php');
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM members WHERE id = ?");
    $stmt->execute([$id]);

    header('Location: members.php?success=hapus');
    exit;
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(409);
    echo 'Anggota tidak dapat dihapus. Periksa apakah anggota masih memiliki data membership.';
}
