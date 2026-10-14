
<?php
session_start();

// Hapus seluruh data sesi pengguna.
$_SESSION = [];

// Hapus cookie sesi jika cookie digunakan.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Hapus sesi dari server.
session_destroy();

// Kembali ke halaman login dengan notifikasi.
header('Location: index.php?logout=1');
exit;
