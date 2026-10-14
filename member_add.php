
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $memberCode = trim($_POST['member_code'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($memberCode === '' || $fullName === '' || $phone === '') {
        $error = 'Kode anggota, nama, dan nomor telepon wajib diisi.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } else {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO members
                 (member_code, full_name, phone, email, created_at)
                 VALUES (?, ?, ?, ?, NOW())"
            );

            $stmt->execute([
                $memberCode,
                $fullName,
                $phone,
                $email !== '' ? $email : null
            ]);

            header('Location: members.php?success=tambah');
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = 'Kode anggota sudah digunakan. Gunakan kode lain.';
            } else {
                error_log($e->getMessage());
                $error = 'Anggota gagal ditambahkan. Periksa struktur tabel database.';
            }
        }
    }
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tambah Anggota | FitDesk Gym</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{background:#f4f7fb;font-family:Arial,sans-serif}
        .container{max-width:700px;margin:45px auto}
        .panel{background:white;padding:30px;border-radius:16px;box-shadow:0 4px 18px #14213d0d}
        .btn-primary{background:#14213d;border-color:#14213d}
    </style>
</head>
<body>
<div class="container">
    <div class="panel">
        <h2 class="fw-bold">Tambah Anggota</h2>
        <p class="text-secondary">Masukkan data anggota FitDesk Gym.</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Kode Anggota *</label>
                <input type="text" name="member_code" class="form-control"
                       placeholder="Contoh: MB001" required
                       value="<?= e($_POST['member_code'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Nama Lengkap *</label>
                <input type="text" name="full_name" class="form-control"
                       required value="<?= e($_POST['full_name'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Nomor Telepon *</label>
                <input type="tel" name="phone" class="form-control"
                       required value="<?= e($_POST['phone'] ?? '') ?>">
            </div>

            <div class="mb-4">
                <label class="form-label">Email (opsional)</label>
                <input type="email" name="email" class="form-control"
                       value="<?= e($_POST['email'] ?? '') ?>">
            </div>

            <button class="btn btn-primary" type="submit">Simpan Anggota</button>
            <a href="members.php" class="btn btn-secondary">Kembali</a>
        </form>
    </div>
</div>
</body>
</html>
