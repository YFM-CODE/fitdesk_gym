<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/sidebar.php';

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function require_login(): void {
    if (empty($_SESSION['user']['id']) && empty($_SESSION['user_id'])) {
        $isModule = (basename(dirname($_SERVER['SCRIPT_FILENAME'] ?? '')) !== 'fitdesk_gym');
        $redirectUrl = $isModule ? '../auth/login.php' : 'modules/auth/login.php';
        redirect($redirectUrl);
    }
}

function require_admin(): void {
    require_login();
    $role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? '';
    if ($role !== 'admin') {
        http_response_code(403);
        exit('Akses ditolak. Halaman ini khusus Admin.');
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($token) || $sessionToken === '' ||
        !hash_equals($sessionToken, $token)) {
        http_response_code(419);
        exit('Formulir kedaluwarsa atau tidak valid. Muat ulang halaman.');
    }
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function make_member_code(PDO $pdo): string {
    do {
        $code = 'MEM-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM members WHERE member_code = ?'
        );
        $stmt->execute([$code]);
    } while ((int)$stmt->fetchColumn() > 0);

    return $code;
}

function add_months_to_date(string $date, int $months): string {
    $start = new DateTimeImmutable($date);
    $day = (int)$start->format('d');

    $target = $start->modify('first day of this month')
                    ->modify("+{$months} months");

    return $target->setDate(
        (int)$target->format('Y'),
        (int)$target->format('m'),
        min($day, (int)$target->format('t'))
    )->format('Y-m-d');
}

function membership_status(array $membership): string {
    if (empty($membership['end_date'])) {
        return 'Belum ada paket';
    }

    return $membership['end_date'] >= date('Y-m-d')
        ? 'Aktif'
        : 'Kedaluwarsa';
}

function rupiah($amount): string {
    return 'Rp ' . number_format((float)$amount, 0, ',', '.');
}
