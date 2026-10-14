
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header('Location: members.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
$stmt->execute([$id]);
$member = $stmt->fetch();

if (!$member) {
    header('Location: members.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['member_code'] ?? '');
    $name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($code === '' || $name === '' || $phone === '') {
        $error = 'Kode anggota, nama, dan telepon wajib diisi.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } else {
        try {
            $stmt = $pdo->prepare(
                "UPDATE members
                 SET member_code = ?, full_name = ?, phone = ?, email = ?
                 WHERE id = ?"
            );
            $stmt->execute([
                $code,
                $name,
                $phone,
                $email !== '' ? $email : null,
                $id
            ]);

            header('Location: members.php?success=edit');
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = 'Kode anggota sudah digunakan oleh anggota lain.';
            } else {
                error_log($e->getMessage());
                $error = 'Data gagal diperbarui.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Anggota | FitDesk Gym</title>
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
        <h2 class="fw-bold">Edit Anggota</h2>
        <p class="text-secondary">Perbarui informasi anggota.</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Kode Anggota *</label>
                <input name="member_code" class="form-control" required
                       value="<?= e($_POST['member_code'] ?? $member['member_code']) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Nama Lengkap *</label>
                <input name="full_name" class="form-control" required
                       value="<?= e($_POST['full_name'] ?? $member['full_name']) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Nomor Telepon *</label>
                <input name="phone" class="form-control" required
                       value="<?= e($_POST['phone'] ?? $member['phone']) ?>">
            </div>
            <div class="mb-4">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control"
                       value="<?= e($_POST['email'] ?? $member['email']) ?>">
            </div>
            <button class="btn btn-primary" type="submit">Simpan Perubahan</button>
            <a href="members.php" class="btn btn-secondary">Batal</a>
        </form>
    </div>
</div>
</body>
</html>
