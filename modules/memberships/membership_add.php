<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);
    $duration = filter_input(INPUT_POST, 'duration', FILTER_VALIDATE_INT);
    $start_date = $_POST['start_date'] ?? '';

    if (!$member_id || !in_array($duration, [1, 3, 6, 12], true) || !$start_date) {
        $error = 'Semua data wajib diisi dengan benar.';
    } else {
        try {
            $check = $pdo->prepare('SELECT id FROM members WHERE id = ?');
            $check->execute([$member_id]);

            if (!$check->fetch()) {
                $error = 'Anggota tidak ditemukan.';
            } else {
                $start = new DateTime($start_date);
                $end = clone $start;
                $end->modify('+' . $duration . ' months');
                $end->modify('-1 day');

                $today = new DateTime('today');
                $active = ($end >= $today) ? 1 : 0;

                $stmt = $pdo->prepare(
                    'INSERT INTO memberships
                    (member_id, package_duration_months, start_date, end_date, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())'
                );

                $stmt->execute([
                    $member_id,
                    $duration,
                    $start->format('Y-m-d'),
                    $end->format('Y-m-d'),
                    $active
                ]);

                header('Location: index.php?success=tambah');
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Gagal menyimpan membership. Periksa kembali data database.';
        } catch (Exception $e) {
            $error = 'Tanggal yang dimasukkan tidak valid.';
        }
    }
}

$members = $pdo->query(
    'SELECT id, member_code, full_name FROM members WHERE deleted_at IS NULL ORDER BY full_name ASC'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Membership | FitDesk Gym</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f6fb;
            color: #202938;
        }
        .sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            width: 270px;
            padding: 28px 24px;
            background: #142340;
            color: white;
        }
        .brand { font-size: 27px; font-weight: bold; margin-bottom: 10px; }
        .brand span { color: #ffa20b; }
        .subtitle { color: #cbd5e1; font-size: 12px; margin-bottom: 42px; }
        .sidebar a {
            display: block;
            padding: 17px;
            margin: 8px 0;
            color: white;
            text-decoration: none;
            border-radius: 11px;
        }
        .sidebar a:hover, .sidebar a.active {
            background: #ffa20b;
            color: #142340;
        }
        main { margin-left: 270px; padding: 35px 32px; }
        h1 { margin: 0 0 10px; font-size: 36px; }
        .description { color: #64748b; margin-bottom: 28px; }
        .card {
            background: white;
            padding: 28px;
            border-radius: 18px;
            box-shadow: 0 8px 28px #1725540b;
            max-width: 760px;
        }
        label { display: block; font-weight: bold; margin: 18px 0 8px; }
        input, select {
            width: 100%;
            padding: 13px;
            border: 1px solid #d5dce7;
            border-radius: 9px;
            font-size: 15px;
        }
        button, .back {
            display: inline-block;
            margin-top: 24px;
            padding: 13px 20px;
            border: 0;
            border-radius: 9px;
            background: #ffa20b;
            color: #142340;
            font-weight: bold;
            font-size: 15px;
            text-decoration: none;
            cursor: pointer;
        }
        .back { background: #e8edf5; margin-left: 8px; }
        .error {
            padding: 13px;
            border-radius: 8px;
            color: #991b1b;
            background: #fee2e2;
        }
        .hint { color: #64748b; font-size: 13px; margin-top: 7px; }
        @media(max-width: 750px) {
            .sidebar { position: static; width: 100%; }
            main { margin-left: 0; padding: 22px; }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="brand">FitDesk <span>Gym</span></div>
    <div class="subtitle">GYM MANAGEMENT SYSTEM</div>
    <a href="../dashboard/index.php">🏠 Dashboard</a>
    <a href="../members/index.php">👥 Data Anggota</a>
    <a href="index.php" class="active">🎫 Membership</a>
    <a href="../auth/logout.php">↪ Logout</a>
</aside>

<main>
    <h1>Tambah Membership</h1>
    <p class="description">Daftarkan paket membership untuk anggota gym.</p>

    <section class="card">
        <h2>Formulir Membership</h2>

        <?php if ($error !== ''): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <?php if (count($members) === 0): ?>
            <p>Belum ada anggota. Tambahkan anggota terlebih dahulu melalui menu Data Anggota.</p>
            <a class="back" href="../members/member_add.php">Tambah Anggota</a>
        <?php else: ?>
            <form method="POST">
                <label for="member_id">Pilih Anggota</label>
                <select id="member_id" name="member_id" required>
                    <option value="">-- Pilih Anggota --</option>
                    <?php foreach ($members as $member): ?>
                        <option value="<?= (int)$member['id'] ?>"
                            <?= (($_POST['member_id'] ?? '') == $member['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($member['member_code'] . ' - ' . $member['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="duration">Durasi Membership</label>
                <select id="duration" name="duration" required>
                    <option value="">-- Pilih Durasi --</option>
                    <option value="1">1 Bulan</option>
                    <option value="3">3 Bulan</option>
                    <option value="6">6 Bulan</option>
                    <option value="12">12 Bulan</option>
                </select>

                <label for="start_date">Tanggal Mulai</label>
                <input type="date" id="start_date" name="start_date"
                    value="<?= htmlspecialchars($_POST['start_date'] ?? date('Y-m-d')) ?>"
                    required>

                <p class="hint">Tanggal berakhir akan dihitung otomatis berdasarkan durasi paket.</p>

                <button type="submit">Simpan Membership</button>
                <a class="back" href="index.php">Kembali</a>
            </form>
        <?php endif; ?>
    </section>
</main>

</body>
</html>
```