<?php
declare(strict_types=1);

$config = array_replace(require dirname(__DIR__) . '/config.example.php',
    is_file(dirname(__DIR__) . '/config.local.php') ? require dirname(__DIR__) . '/config.local.php' : []);
foreach (['dsn'=>'WORKSNOW_DB_DSN','db_user'=>'WORKSNOW_DB_USER','db_password'=>'WORKSNOW_DB_PASSWORD','timezone'=>'WORKSNOW_TIMEZONE','session_driver'=>'WORKSNOW_SESSION_DRIVER','db_ssl_ca'=>'WORKSNOW_DB_SSL_CA'] as $key=>$variable) {
    $value = getenv($variable);
    if ($value !== false) $config[$key] = $value;
}
// TiDB's Vercel integration supplies these values when its General preset is used.
if (getenv('WORKSNOW_DB_DSN') === false && getenv('TIDB_HOST') !== false) {
    $host = (string)getenv('TIDB_HOST');
    $database = (string)getenv('TIDB_DATABASE');
    $port = filter_var(getenv('TIDB_PORT') ?: '4000', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]]);
    if (!preg_match('/^[a-zA-Z0-9.-]+$/', $host) || !preg_match('/^[a-zA-Z0-9_]+$/', $database) || $port === false) {
        throw new RuntimeException('Invalid TiDB connection settings.');
    }
    $config['dsn'] = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4';
    $config['db_user'] = (string)getenv('TIDB_USER');
    $config['db_password'] = (string)getenv('TIDB_PASSWORD');
}
if (getenv('WORKSNOW_SECURE_COOKIES') !== false) $config['secure_cookies'] = getenv('WORKSNOW_SECURE_COOKIES') === '1';
$externalDatabaseRequired = getenv('WORKSNOW_REQUIRE_EXTERNAL_DB') === '1' || getenv('VERCEL') === '1';
if ($externalDatabaseRequired && !str_starts_with($config['dsn'], 'mysql:')) {
    throw new RuntimeException('Configure WORKSNOW_DB_DSN with a hosted MySQL database before using the deployment.');
}
date_default_timezone_set($config['timezone']);
ini_set('session.use_strict_mode', '1');
session_name('worksnow_session');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => (bool)$config['secure_cookies']]);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store');
if (str_starts_with($config['dsn'], 'sqlite:') && !is_dir(dirname(__DIR__) . '/storage')) mkdir(dirname(__DIR__) . '/storage', 0700, true);
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
if (str_starts_with($config['dsn'], 'mysql:') && (!empty($config['db_ssl_ca']) || $externalDatabaseRequired)) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $config['db_ssl_ca'] ?: '/etc/ssl/certs/ca-certificates.crt';
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
}
$db = new PDO($config['dsn'], $config['db_user'], $config['db_password'], $options);
$mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
if ($mysql) $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
if (!$mysql) { $db->exec('PRAGMA foreign_keys=ON'); $db->exec('PRAGMA busy_timeout=5000'); }
// Portable schema; no sample customers, accounts, or payments are inserted.
$id = $mysql ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$tables = [
    "businesses (id $id, name VARCHAR(150) NOT NULL, slug VARCHAR(80) NOT NULL UNIQUE, email VARCHAR(190) NOT NULL, phone VARCHAR(60) NOT NULL DEFAULT '', currency VARCHAR(3) NOT NULL, created_at VARCHAR(30) NOT NULL)",
    "users (id $id, business_id INTEGER NOT NULL, name VARCHAR(150) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY (business_id) REFERENCES businesses(id))",
    "services (id $id, business_id INTEGER NOT NULL, name VARCHAR(150) NOT NULL, description TEXT NOT NULL, price INTEGER NOT NULL, active INTEGER NOT NULL DEFAULT 1, FOREIGN KEY (business_id) REFERENCES businesses(id))",
    "customers (id $id, business_id INTEGER NOT NULL, name VARCHAR(150) NOT NULL, email VARCHAR(190) NOT NULL, phone VARCHAR(60) NOT NULL, address TEXT NOT NULL, FOREIGN KEY (business_id) REFERENCES businesses(id))",
    "tickets (id $id, business_id INTEGER NOT NULL, customer_id INTEGER NOT NULL, service_id INTEGER NOT NULL, assigned_to INTEGER NULL, title VARCHAR(180) NOT NULL, description TEXT NOT NULL, address TEXT NOT NULL, priority VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, scheduled_at VARCHAR(30) NOT NULL DEFAULT '', token VARCHAR(64) NOT NULL UNIQUE, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL, FOREIGN KEY (business_id) REFERENCES businesses(id), FOREIGN KEY (customer_id) REFERENCES customers(id), FOREIGN KEY (service_id) REFERENCES services(id), FOREIGN KEY (assigned_to) REFERENCES users(id))",
    "activities (id $id, business_id INTEGER NOT NULL, ticket_id INTEGER NOT NULL, message TEXT NOT NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY (ticket_id) REFERENCES tickets(id))",
    "documents (id $id, business_id INTEGER NOT NULL, ticket_id INTEGER NOT NULL, kind VARCHAR(10) NOT NULL, status VARCHAR(20) NOT NULL, items TEXT NOT NULL, subtotal INTEGER NOT NULL, tax_rate INTEGER NOT NULL, tax INTEGER NOT NULL, total INTEGER NOT NULL, paid INTEGER NOT NULL DEFAULT 0, due_date VARCHAR(20) NOT NULL, notes TEXT NOT NULL, quote_id INTEGER NULL UNIQUE, created_at VARCHAR(30) NOT NULL, FOREIGN KEY (ticket_id) REFERENCES tickets(id), FOREIGN KEY (quote_id) REFERENCES documents(id))",
    "payments (id $id, business_id INTEGER NOT NULL, document_id INTEGER NOT NULL, amount INTEGER NOT NULL, method VARCHAR(40) NOT NULL, reference VARCHAR(160) NOT NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY (document_id) REFERENCES documents(id))",
    "login_attempts (attempt_key VARCHAR(64) PRIMARY KEY, attempts INTEGER NOT NULL, started_at INTEGER NOT NULL)",
    "sessions (id VARCHAR(128) PRIMARY KEY, data TEXT NOT NULL, expires_at INTEGER NOT NULL)",
];
foreach ($tables as $table) $db->exec('CREATE TABLE IF NOT EXISTS ' . $table . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
if (($config['session_driver'] ?? ($mysql ? 'database' : 'file')) === 'database') {
    require __DIR__ . '/database-session.php';
    // A separate connection keeps session writes outside business-data transactions.
    $sessionConnection = new PDO($config['dsn'], $config['db_user'], $config['db_password'], $options);
    if (!$mysql) $sessionConnection->exec('PRAGMA busy_timeout=5000');
    session_set_save_handler(new DatabaseSessionHandler($sessionConnection, $mysql), true);
}
session_start();

function q(string $sql, array $params = []): PDOStatement { global $db; $s = $db->prepare($sql); $s->execute($params); return $s; }
function one(string $sql, array $params = []): ?array { return q($sql, $params)->fetch() ?: null; }
function all(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now(): string { return date('Y-m-d H:i:s'); }
function url(array $params = []): string { return 'index.php' . ($params ? '?' . http_build_query($params) : ''); }
function go(array $params = []): never { header('Location: ' . url($params)); exit; }
function csrf(): string { $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function input(string $key, int $max = 200, bool $required = true): string { $v = trim((string)($_POST[$key] ?? '')); if (($required && $v === '') || strlen($v) > $max) throw new DomainException('Please enter a valid ' . str_replace('_', ' ', $key) . '.'); return $v; }
function email_input(string $key = 'email'): string { $v = strtolower(input($key, 190)); if (!filter_var($v, FILTER_VALIDATE_EMAIL)) throw new DomainException('Please enter a valid email address.'); return $v; }
function cents(string $value): int { if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $value)) throw new DomainException('Enter an amount between 0 and 9,999,999.99 with up to two decimals.'); [$whole, $part] = array_pad(explode('.', $value), 2, ''); return (int)$whole * 100 + (int)str_pad($part, 2, '0'); }
function money(int $value): string { global $business, $config; return ($business['currency'] ?? $config['currency']) . ' ' . number_format($value / 100, 2); }
function flash(string $text, string $type = 'success'): void { $_SESSION['flash'] = [$text, $type]; }
function activity(int $ticket, string $message, ?int $biz = null): void { global $user; q('INSERT INTO activities (business_id,ticket_id,message,created_at) VALUES (?,?,?,?)', [$biz ?? $user['business_id'], $ticket, $message, now()]); }
function scoped(string $table, int $id): array { global $user; if (!in_array($table, ['tickets','services','customers','documents','users'], true)) throw new LogicException('Invalid table'); $row = one("SELECT * FROM $table WHERE id=? AND business_id=?", [$id, $user['business_id']]); if (!$row) throw new DomainException('That record could not be found.'); return $row; }
function owner(): void { global $user; if (!$user || $user['role'] !== 'owner') throw new DomainException('Only the business owner can perform this action.'); }
function ticket_access(int $id): array { global $user; $t = scoped('tickets', $id); if ($user['role'] !== 'owner' && (int)$t['assigned_to'] !== (int)$user['id']) throw new DomainException('This ticket is not assigned to you.'); return $t; }
function enum_value(string $key, array $allowed): string { $v = input($key); if (!in_array($v, $allowed, true)) throw new DomainException('Invalid ' . $key . '.'); return $v; }
function date_input(string $key, bool $required = false): string { $v = input($key, 16, $required); if ($v === '') return ''; $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v); if (!$d || $d->format('Y-m-d') !== $v) throw new DomainException('Please enter a valid date.'); return $v; }
function schedule_input(): string { $v = input('scheduled_at', 16, false); if ($v === '') return ''; $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $v); if (!$d || $d->format('Y-m-d\TH:i') !== $v) throw new DomainException('Please enter a valid appointment time.'); return str_replace('T', ' ', $v); }
function valid_password(string $key = 'password'): string { $v = (string)($_POST[$key] ?? ''); if (strlen($v) < 12 || strlen($v) > 72) throw new DomainException('Use a password of 12–72 characters.'); return $v; }
function ticket_number(int $id): string { return 'WS-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT); }
function doc_number(array $d): string { return ($d['kind'] === 'quote' ? 'QT-' : 'INV-') . str_pad((string)$d['id'], 4, '0', STR_PAD_LEFT); }
function badge(string $status): string { return '<span class="badge ' . e($status) . '">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>'; }
function public_link(string $token): string { return url(['page' => 'track', 'token' => $token]); }
$user = isset($_SESSION['user_id']) ? one('SELECT * FROM users WHERE id=?', [$_SESSION['user_id']]) : null;
$business = $user ? one('SELECT * FROM businesses WHERE id=?', [$user['business_id']]) : null;
