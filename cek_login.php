
<?php
require_once __DIR__ . '/config/database.php';

$username = 'admin_fitdesk';
$newPassword = 'password';

try {
    // Cari akun admin
    $stmt = $pdo->prepare(
        "SELECT id FROM users WHERE username = ? LIMIT 1"
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        exit("Akun admin_fitdesk tidak ditemukan di database.");
    }

    // Buat hash password baru yang valid
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    // Perbarui password akun
    $stmt = $pdo->prepare(
        "UPDATE users SET password = ? WHERE id = ?"
    );
    $stmt->execute([$hash, $user['id']]);

    // Verifikasi password yang baru disimpan
    $stmt = $pdo->prepare(
        "SELECT password FROM users WHERE id = ?"
    );
    $stmt->execute([$user['id']]);
    $savedHash = $stmt->fetchColumn();

    if (password_verify($newPassword, $savedHash)) {
        echo "Password admin berhasil diperbarui dan diverifikasi.<br>";
        echo "Username: admin_fitdesk<br>";
        echo "Password: password<br><br>";
        echo "Silakan login di halaman FitDesk Gym.";
    } else {
        echo "Password belum berhasil diverifikasi.";
    }

} catch (PDOException $e) {
    error_log($e->getMessage());
    echo "Terjadi kesalahan saat memperbarui password.";
}
