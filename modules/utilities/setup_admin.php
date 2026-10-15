<?php
require_once dirname(__DIR__, 2) . '/config/database.php';

$count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

if ($count > 0) {
    exit('Tabel users sudah memiliki akun. Demi keamanan, file setup tidak membuat akun tambahan. Hapus setup_admin.php jika sudah tidak diperlukan.');
}

$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password, role) VALUES (?, ?, ?)'
    );

    $stmt->execute([
        'admin_fitdesk',
        password_hash('password123', PASSWORD_DEFAULT),
        'admin'
    ]);

    $stmt->execute([
        'resepsionis1',
        password_hash('password123', PASSWORD_DEFAULT),
        'resepsionis'
    ]);

    $pdo->commit();

    echo '<h2>Akun awal berhasil dibuat!</h2>';
    echo '<p>Admin: <b>admin_fitdesk</b></p>';
    echo '<p>Resepsionis: <b>resepsionis1</b></p>';
    echo '<p>Password keduanya: <b>password123</b></p>';
    echo '<p><a href="../auth/login.php">Buka halaman login</a></p>';
    echo '<p><b>Penting:</b> Hapus file setup_admin.php sekarang.</p>';
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($ex->getMessage());
    http_response_code(500);
    exit('Gagal membuat akun. Periksa konfigurasi database.');
}
