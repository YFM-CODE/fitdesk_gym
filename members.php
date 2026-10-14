```php
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

$stmt = $pdo->query(
    'SELECT id, member_code, full_name, phone, email, created_at
     FROM members
     ORDER BY id ASC'
);
$members = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Anggota | FitDesk Gym</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f6fb;
            color: #202938;
        }

        .sidebar {
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            width: 270px;
            padding: 28px 24px;
            background: #142340;
            color: white;
        }

        .brand {
            font-size: 27px;
            font-weight: bold;
        }

        .brand span {
            color: #ffa20b;
        }

        .subtitle {
            margin: 12px 0 42px;
            color: #cbd5e1;
            font-size: 12px;
        }

        .sidebar a {
            display: block;
            padding: 17px;
            margin: 8px 0;
            border-radius: 11px;
            color: white;
            text-decoration: none;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #ffa20b;
            color: #142340;
        }

        main {
            margin-left: 270px;
            padding: 35px 32px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 36px;
        }

        .description {
            margin-bottom: 28px;
            color: #64748b;
        }

        .card {
            padding: 26px;
            overflow-x: auto;
            background: white;
            border-radius: 18px;
            box-shadow: 0 8px 28px #1725540b;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .card-header h2 {
            margin: 0 0 8px;
            font-size: 22px;
        }

        .muted {
            color: #64748b;
        }

        .btn {
            display: inline-block;
            padding: 12px 16px;
            border: none;
            border-radius: 8px;
            background: #142340;
            color: white;
            text-decoration: none;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn-add {
            background: #ffa20b;
            color: #142340;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }

        th, td {
            padding: 14px 10px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }

        th {
            color: #64748b;
        }

        .btn-edit {
            padding: 8px 11px;
            background: #ffc107;
            color: #222;
        }

        .btn-delete {
            padding: 8px 11px;
            background: #dc3545;
            color: white;
        }

        .success {
            padding: 13px;
            margin-bottom: 18px;
            border-radius: 8px;
            background: #dcfce7;
            color: #166534;
        }

        footer {
            margin-top: 28px;
            text-align: center;
            color: #64748b;
        }

        @media (max-width: 750px) {
            .sidebar {
                position: relative;
                width: 100%;
            }

            main {
                margin-left: 0;
                padding: 22px;
            }

            .card-header {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<aside class="sidebar">
    <div class="brand">FitDesk <span>Gym</span></div>
    <div class="subtitle">GYM MANAGEMENT SYSTEM</div>

    <a href="dashboard.php">🏠 Dashboard</a>
    <a href="members.php" class="active">👥 Data Anggota</a>
    <a href="memberships.php">🎫 Membership</a>
    <a href="logout.php">↪ Logout</a>
</aside>

<main>
    <h1>Data Anggota</h1>
    <p class="description">Kelola data anggota FitDesk Gym.</p>

    <p class="muted">
        Login sebagai:
        <strong><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong>
    </p>

    <?php if (isset($_GET['success'])): ?>
        <div class="success">
            <?php
            $messages = [
                'tambah' => 'Anggota berhasil ditambahkan.',
                'edit' => 'Data anggota berhasil diperbarui.',
                'hapus' => 'Anggota berhasil dihapus.'
            ];

            echo htmlspecialchars(
                $messages[$_GET['success']] ?? 'Operasi berhasil.'
            );
            ?>
        </div>
    <?php endif; ?>

    <section class="card">
        <div class="card-header">
            <div>
                <h2>Daftar Anggota</h2>
                <span class="muted">
                    Total data: <?= count($members) ?> anggota
                </span>
            </div>

            <a href="member_add.php" class="btn btn-add">
                + Tambah Anggota
            </a>
        </div>

        <?php if (empty($members)): ?>
            <p class="muted">
                Belum ada anggota. Klik Tambah Anggota untuk mendaftarkan anggota baru.
            </p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode Anggota</th>
                        <th>Nama Lengkap</th>
                        <th>Nomor HP</th>
                        <th>Email</th>
                        <th>Tanggal Daftar</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($members as $i => $member): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($member['member_code']) ?></td>
                            <td>
                                <strong>
                                    <?= htmlspecialchars($member['full_name']) ?>
                                </strong>
                            </td>
                            <td><?= htmlspecialchars($member['phone'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($member['email'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($member['created_at'] ?? '-') ?></td>
                            <td>
                                <a
                                    class="btn btn-edit"
                                    href="member_edit.php?id=<?= (int)$member['id'] ?>"
                                >Edit</a>

                                <form
                                    action="member_delete.php"
                                    method="POST"
                                    style="display:inline"
                                    onsubmit="return confirm('Yakin ingin menghapus anggota ini?')"
                                >
                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int)$member['id'] ?>"
                                    >
                                    <button class="btn btn-delete" type="submit">
                                        Hapus
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <footer>© <?= date('Y') ?> FitDesk Gym</footer>
</main>

</body>
</html>
```