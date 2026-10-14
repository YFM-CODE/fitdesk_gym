<?php
require_once __DIR__ . '/config/database.php';

$username = 'admin_fitdesk';
$newPassword = 'password';

try {
    $stmt = $pdo->prepare(
        "SELECT id FROM users WHERE username = ? LIMIT 1"
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        exit("Akun admin_fitdesk tidak ditemukan di database.");
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
        "UPDATE users SET password = ? WHERE id = ?"
    );
    $stmt->execute([$hash, $user['id']]);

    $stmt = $pdo->prepare(
        "SELECT password FROM users WHERE id = ?"
    );
    $stmt->execute([$user['id']]);
    $savedHash = $stmt->fetchColumn();

    if (password_verify($newPassword, $savedHash)) {
        echo "Password admin berhasil diperbarui.<br>";
        echo "Username: admin_fitdesk<br>";
        echo "Password: password<br><br>";
        echo "Silakan login ke FitDesk Gym.";
    } else {
        echo "Password belum berhasil diverifikasi.";
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    echo "Terjadi kesalahan saat memperbarui password.";
}