<?php
// Shared helpers for the AWS Poll App.
// Config and instance info live OUTSIDE the web root (/etc/poll-app/),
// so they can never be downloaded even if PHP stops executing.

declare(strict_types=1);

const CONFIG_PATH   = '/etc/poll-app/config.php';
const INSTANCE_PATH = '/etc/poll-app/instance.json';

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $path = getenv('POLL_CONFIG') ?: CONFIG_PATH;
        $cfg = require $path;
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $c = config();
    $pdo = new PDO($c['db_dsn'], $c['db_user'] ?? null, $c['db_pass'] ?? null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    ensure_schema($pdo);
    return $pdo;
}

// Creates the tables on first use, so there is no manual SQL step.
// (The database is private: nobody can connect to it from a laptop.)
function ensure_schema(PDO $pdo): void
{
    $flag = sys_get_temp_dir() . '/poll_app_schema_ok';
    if (is_file($flag)) {
        return;
    }
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    if ($sqlite) {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec("CREATE TABLE IF NOT EXISTS polls (
            id TEXT PRIMARY KEY,
            question TEXT NOT NULL,
            is_open INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS poll_options (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id TEXT NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
            label TEXT NOT NULL,
            position INTEGER NOT NULL,
            votes INTEGER NOT NULL DEFAULT 0)");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS polls (
            id VARCHAR(12) PRIMARY KEY,
            question VARCHAR(200) NOT NULL,
            is_open TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS poll_options (
            id INT AUTO_INCREMENT PRIMARY KEY,
            poll_id VARCHAR(12) NOT NULL,
            label VARCHAR(100) NOT NULL,
            position INT NOT NULL,
            votes INT NOT NULL DEFAULT 0,
            INDEX idx_poll (poll_id),
            CONSTRAINT fk_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    @touch($flag);
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function new_poll_id(): string
{
    // 8 random characters: links can't be guessed by counting 1, 2, 3...
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $id = '';
    for ($i = 0; $i < 8; $i++) {
        $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $id;
}

// ---------- Admin sign-in (stateless) ----------
// No PHP sessions: they live on one server's disk, and the load balancer
// may send the next request to a different server. Instead the cookie
// holds a signature that ANY server can check using the shared admin key.

function admin_token(): string
{
    return hash_hmac('sha256', 'poll-app-admin', config()['admin_key']);
}

function is_admin(): bool
{
    $t = $_COOKIE['poll_admin'] ?? '';
    return is_string($t) && hash_equals(admin_token(), $t);
}

function cookie_opts(int $expires): array
{
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function is_https(): bool
{
    return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
}

function base_url(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (is_https() ? 'https' : 'http') . '://' . $host;
}

function poll_url(string $id): string
{
    return base_url() . '/poll.php?id=' . rawurlencode($id);
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

// ---------- Data access ----------

function find_poll(string $id): ?array
{
    $st = db()->prepare('SELECT * FROM polls WHERE id = ?');
    $st->execute([$id]);
    $poll = $st->fetch();
    if (!$poll) {
        return null;
    }
    $st = db()->prepare('SELECT id, label, votes FROM poll_options WHERE poll_id = ? ORDER BY position');
    $st->execute([$id]);
    $poll['options'] = $st->fetchAll();
    $poll['total'] = array_sum(array_map(fn($o) => (int) $o['votes'], $poll['options']));
    return $poll;
}

function voted_option(string $pollId): ?int
{
    $v = $_COOKIE['voted_' . $pollId] ?? null;
    return is_numeric($v) ? (int) $v : null;
}

// ---------- Which server answered? (the HA demo) ----------

function instance_info(): array
{
    static $info = null;
    if ($info === null) {
        $path = getenv('POLL_INSTANCE') ?: INSTANCE_PATH;
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $info = [
            'instance' => $data['instance'] ?? gethostname() ?: 'local',
            'az'       => $data['az'] ?? 'local',
        ];
    }
    return $info;
}

// ---------- Layout ----------

function page_start(string $title): void
{
    $full = $title === '' ? 'AWS Poll App' : $title . ' | AWS Poll App';
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($full) ?></title>
<script>
  // Apply the saved theme before the page paints, so there is no flash.
  (function () {
    try {
      var t = localStorage.getItem('theme');
      if (t === 'light' || t === 'dark') document.documentElement.dataset.theme = t;
    } catch (e) {}
  })();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Public+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css?v=1">
</head>
<body>
<header class="topbar">
  <a class="brand" href="/">
    <svg class="brand-mark" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="12" width="4" height="9" rx="1.5"/><rect x="10" y="7" width="4" height="14" rx="1.5"/><rect x="17" y="3" width="4" height="18" rx="1.5"/></svg>
    <span>AWS Poll App</span>
  </a>
  <nav class="topnav">
    <?php if (is_admin()): ?><a href="/admin.php">Dashboard</a><?php else: ?><a href="/admin.php">Admin</a><?php endif; ?>
    <button class="theme-toggle" type="button" aria-label="Switch theme" title="Switch theme">
      <svg class="icon-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4.5"/><path d="M12 1.5v3M12 19.5v3M1.5 12h3M19.5 12h3M4.6 4.6l2.1 2.1M17.3 17.3l2.1 2.1M4.6 19.4l2.1-2.1M17.3 6.7l2.1-2.1"/></svg>
      <svg class="icon-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5a8.5 8.5 0 1 0 11 11z"/></svg>
    </button>
  </nav>
</header>
<main class="page">
<?php
}

function page_end(): void
{
    $i = instance_info();
    ?>
</main>
<footer class="footer">
  <p class="served" data-served>Page served by <strong data-instance><?= h($i['instance']) ?></strong> in <strong data-az><?= h($i['az']) ?></strong></p>
</footer>
<script src="/assets/app.js?v=1"></script>
</body>
</html>
<?php
}
