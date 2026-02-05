<?php
// api.php - JSON API for Apiary database
// Requires PHP 7.4+ and MySQL 8+ (for window functions).
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if ($https) {
  ini_set('session.cookie_secure', '1');
}
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function load_env_file(string $path): void {
  if (!is_readable($path)) {
    return;
  }
  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if ($lines === false) {
    return;
  }
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
      continue;
    }
    if (strncmp($line, 'export ', 7) === 0) {
      $line = trim(substr($line, 7));
    }
    $pos = strpos($line, '=');
    if ($pos === false) {
      continue;
    }
    $key = trim(substr($line, 0, $pos));
    if ($key === '') {
      continue;
    }
    if (getenv($key) !== false) {
      continue;
    }
    $value = trim(substr($line, $pos + 1));
    $len = strlen($value);
    if ($len >= 2) {
      $first = $value[0];
      $last = $value[$len - 1];
      if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
        $value = substr($value, 1, -1);
        if ($first === '"') {
          $value = str_replace(
            ['\\n', '\\r', '\\t', '\\"', '\\\\'],
            ["\n", "\r", "\t", '"', '\\'],
            $value
          );
        }
      }
    }
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
  }
}

load_env_file(__DIR__ . '/.env');

function respond($data, int $status=200) {
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function login_failure_path(string $username): string {
  $dir = sys_get_temp_dir() . '/apiary_login_failures';
  if (!is_dir($dir)) {
    @mkdir($dir, 0700, true);
  }
  $key = hash('sha256', $username);
  return $dir . '/' . $key . '.json';
}

function increment_login_failures(string $username): int {
  $path = login_failure_path($username);
  $fh = @fopen($path, 'c+');
  if ($fh === false) {
    return 1;
  }
  if (!flock($fh, LOCK_EX)) {
    fclose($fh);
    return 1;
  }
  $raw = stream_get_contents($fh);
  $count = 0;
  if ($raw !== false && $raw !== '') {
    $data = json_decode($raw, true);
    if (is_array($data)) {
      $count = (int)($data['count'] ?? 0);
    }
  }
  $count++;
  ftruncate($fh, 0);
  rewind($fh);
  fwrite($fh, json_encode(['count' => $count]));
  fflush($fh);
  flock($fh, LOCK_UN);
  fclose($fh);
  return $count;
}

function clear_login_failures(string $username): void {
  $path = login_failure_path($username);
  if (is_file($path)) {
    @unlink($path);
  }
}

function require_auth(): void {
  if (empty($_SESSION['user_id'])) {
    respond(['error' => 'Unauthorized'], 401);
  }
}

function require_role(array $roles): void {
  $role = $_SESSION['role'] ?? '';
  if (!in_array($role, $roles, true)) {
    respond(['error' => 'Forbidden'], 403);
  }
}

function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return (string)$_SESSION['csrf_token'];
}

function require_csrf(): void {
  $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  $session = $_SESSION['csrf_token'] ?? '';
  if ($token === '' || $session === '' || !hash_equals($session, $token)) {
    respond(['error' => 'Invalid CSRF token'], 403);
  }
}

function get_pdo(): PDO {
  // TODO: set these for your server
  $db_host = getenv('APIARY_DB_HOST') ?: 'localhost';
  $db_name = getenv('APIARY_DB_NAME') ?: 'Apiary';
  $db_user = getenv('APIARY_DB_USER') ?: '';
  $db_pass = getenv('APIARY_DB_PASS') ?: '';

  $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
  $pdo = new PDO($dsn, $db_user, $db_pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

function require_param(string $key): string {
  $v = $_GET[$key] ?? $_POST[$key] ?? null;
  if ($v === null || $v === '') {
    respond(['error' => "Missing parameter: {$key}"], 400);
  }
  return (string)$v;
}

function latest_visits_cte(): string {
  // ROW_NUMBER picks the latest visit for each hive by date, then by ID.
  return "WITH latest AS (
            SELECT v.*,
                   ROW_NUMBER() OVER (PARTITION BY v.Hive_ID ORDER BY v.Datum DESC, v.ID DESC) AS rn
            FROM Visits v
          )";
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$public_actions = [
  'login',
  'logout',
  'me',
  'admin_bootstrap_status',
  'admin_bootstrap_create'
];
$write_actions = [
  'visit_create',
  'visit_update',
  'hive_create',
  'hive_update',
  'visit_delete',
  'queen_delete',
  'queen_create',
  'queen_update'
];
$admin_actions = [
  'users_list',
  'user_create',
  'user_delete',
  'user_update_role',
  'user_reset_password'
];

try {
  $pdo = get_pdo();

  if (!in_array($action, $public_actions, true)) {
    require_auth();
  }

  if (in_array($action, $admin_actions, true)) {
    require_role(['admin']);
  } elseif (in_array($action, $write_actions, true)) {
    require_role(['admin', 'contributor']);
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'login') {
    require_csrf();
  }

  if ($action === 'me') {
    $user = null;
    if (!empty($_SESSION['user_id'])) {
      $user = [
        'id' => (int)$_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? null,
        'role' => $_SESSION['role'] ?? null
      ];
    }
    respond(['user' => $user, 'csrf' => csrf_token()]);
  }

  if ($action === 'admin_bootstrap_status') {
    $stmt = $pdo->query("SELECT id FROM Users WHERE role = 'admin' LIMIT 1");
    $exists = (bool)$stmt->fetch();
    respond(['exists' => $exists]);
  }

  if ($action === 'admin_bootstrap_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;

    $confirm = (bool)($payload['confirm'] ?? false);
    if (!$confirm) {
      respond(['error' => 'Confirmation required'], 400);
    }

    $stmt = $pdo->query("SELECT id FROM Users WHERE role = 'admin' LIMIT 1");
    if ($stmt->fetch()) {
      respond(['error' => 'Admin already exists'], 409);
    }

    $hash = password_hash('admin', PASSWORD_DEFAULT);
    $existing = $pdo->prepare("SELECT id FROM Users WHERE username = :username LIMIT 1");
    $existing->execute(['username' => 'admin']);
    $row = $existing->fetch();
    if ($row) {
      $upd = $pdo->prepare("UPDATE Users SET password_hash = :hash, role = 'admin' WHERE id = :id");
      $upd->execute(['hash' => $hash, 'id' => (int)$row['id']]);
      respond(['ok' => true, 'id' => (int)$row['id'], 'updated' => true]);
    }

    $stmt = $pdo->prepare("INSERT INTO Users (username, password_hash, role) VALUES (:username, :hash, :role)");
    $stmt->execute([
      'username' => 'admin',
      'hash' => $hash,
      'role' => 'admin'
    ]);
    $new_id = (int)$pdo->lastInsertId();
    respond(['ok' => true, 'id' => $new_id]);
  }

  if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;

    $username = trim((string)($payload['username'] ?? ''));
    $password = (string)($payload['password'] ?? '');

    if ($username === '' || $password === '') {
      respond(['error' => 'Username and password required'], 400);
    }

    $stmt = $pdo->prepare("SELECT id, username, password_hash, role FROM Users WHERE username = :username LIMIT 1");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();
    $hash = $user['password_hash'] ?? null;
    if (!$user || $hash === null || $hash === '' || !password_verify($password, (string)$hash)) {
      if ($user) {
        $count = increment_login_failures($user['username']);
        if ($count >= 3 && $hash !== null && $hash !== '') {
          $lock = $pdo->prepare("UPDATE Users SET password_hash = NULL WHERE id = :id");
          $lock->execute(['id' => (int)$user['id']]);
        }
      }
      respond(['error' => 'Invalid credentials'], 401);
    }

    clear_login_failures($user['username']);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $pdo->prepare("UPDATE Users SET last_login = NOW() WHERE id = :id")
        ->execute(['id' => (int)$user['id']]);

    respond([
      'ok' => true,
      'user' => [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'role' => $user['role']
      ],
      'csrf' => $_SESSION['csrf_token']
    ]);
  }

  if ($action === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      respond(['error' => 'Method not allowed'], 405);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    respond(['ok' => true]);
  }

  if ($action === 'change_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;

    $current = (string)($payload['current_password'] ?? '');
    $next = (string)($payload['new_password'] ?? '');

    if ($current === '' || $next === '') {
      respond(['error' => 'Current and new password required'], 400);
    }
    if (strlen($next) < 7) {
      respond(['error' => 'New password must be at least 7 characters'], 400);
    }

    $stmt = $pdo->prepare("SELECT id, password_hash FROM Users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => (int)($_SESSION['user_id'] ?? 0)]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($current, $user['password_hash'])) {
      respond(['error' => 'Invalid credentials'], 401);
    }

    $hash = password_hash($next, PASSWORD_DEFAULT);
    $upd = $pdo->prepare("UPDATE Users SET password_hash = :hash WHERE id = :id");
    $upd->execute(['hash' => $hash, 'id' => (int)$user['id']]);

    respond(['ok' => true]);
  }

  if ($action === 'users_list') {
    $sql = "SELECT id, username, role, created_at, last_login
            FROM Users
            ORDER BY id ASC";
    $rows = $pdo->query($sql)->fetchAll();
    respond(['users' => $rows]);
  }

  if ($action === 'user_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;

    $username = trim((string)($payload['username'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    $role = (string)($payload['role'] ?? 'contributor');
    $allowed_roles = ['admin', 'contributor', 'readonly'];

    if ($username === '' || $password === '') {
      respond(['error' => 'Username and password required'], 400);
    }
    if (strlen($password) < 7) {
      respond(['error' => 'Password must be at least 7 characters'], 400);
    }
    if (!in_array($role, $allowed_roles, true)) {
      respond(['error' => 'Invalid role'], 400);
    }

    $exists = $pdo->prepare("SELECT id FROM Users WHERE username = :username LIMIT 1");
    $exists->execute(['username' => $username]);
    if ($exists->fetch()) {
      respond(['error' => 'Username already exists'], 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO Users (username, password_hash, role) VALUES (:username, :hash, :role)");
    $stmt->execute([
      'username' => $username,
      'hash' => $hash,
      'role' => $role
    ]);
    $new_id = (int)$pdo->lastInsertId();
    respond(['ok' => true, 'id' => $new_id], 201);
  }

  if ($action === 'user_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;

    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
      respond(['error' => 'Valid id required'], 400);
    }
    if ((int)$_SESSION['user_id'] === $id) {
      respond(['error' => 'Cannot delete current user'], 400);
    }

    $stmt = $pdo->prepare("DELETE FROM Users WHERE id = :id");
    $stmt->execute(['id' => $id]);
    respond(['ok' => true]);
  }

  if ($action === 'user_update_role' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;

    $id = (int)($payload['id'] ?? 0);
    $role = (string)($payload['role'] ?? '');
    $allowed_roles = ['admin', 'contributor', 'readonly'];

    if ($id <= 0) {
      respond(['error' => 'Valid id required'], 400);
    }
    if (!in_array($role, $allowed_roles, true)) {
      respond(['error' => 'Invalid role'], 400);
    }
    if ((int)($_SESSION['user_id'] ?? 0) === $id) {
      respond(['error' => 'Cannot change your own role'], 403);
    }

    $stmt = $pdo->prepare("UPDATE Users SET role = :role WHERE id = :id");
    $stmt->execute(['role' => $role, 'id' => $id]);

    respond(['ok' => true]);
  }

  if ($action === 'user_reset_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;

    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
      respond(['error' => 'Valid id required'], 400);
    }

    $hash = password_hash('12345678', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE Users SET password_hash = :hash WHERE id = :id");
    $stmt->execute(['hash' => $hash, 'id' => $id]);

    respond(['ok' => true]);
  }

  if ($action === 'standorte') {
    $sql = latest_visits_cte() . "
      SELECT COALESCE(l.Standort, '—') AS Standort,
             COUNT(*) AS active_hives,
             SUM(CASE WHEN l.ToDo IS NOT NULL AND l.ToDo <> '' THEN 1 ELSE 0 END) AS todo_hives
      FROM latest l
      JOIN Hives h ON h.ID = l.Hive_ID
      WHERE l.rn = 1 AND h.inactive = 0
      GROUP BY COALESCE(l.Standort, '—')
      ORDER BY Standort ASC
    ";
    $rows = $pdo->query($sql)->fetchAll();
    respond(['standorte' => $rows]);
  }

  if ($action === 'queens') {

$sql = "SELECT
    aq.`ID` AS `ID`,
    aq.`gezeichnet` AS `gezeichnet`,
    aq.`Lebensnummer` AS `Lebensnummer`,
    aq.`Geburtsjahr` AS `Geburtsjahr`,
    aq.`Rasse` AS `Rasse`,
    aq.`Züchter` AS `Züchter`,
    aq.`LN_Mutter` AS `LN_Mutter`,
    aq.`LN_Vatermutter` AS `LN_Vatermutter`,
    aq.`Belegstelle` AS `Belegstelle`,
    vl.`Hive_nr` AS `Hive_nr`,
    vl.`Standort` AS `Standort`
FROM Apiary.Queens aq
LEFT JOIN (
    SELECT
        l.`Queen_ID`,
        h.`Hive_nr`,
        l.`Standort`
    FROM (
        SELECT
            v.*,
            ROW_NUMBER() OVER (
                PARTITION BY v.`Hive_ID`
                ORDER BY v.`Datum` DESC, v.`ID` DESC
            ) AS rn
        FROM Visits v
    ) l
    JOIN Hives h ON h.`ID` = l.`Hive_ID`
    WHERE l.rn = 1 AND h.`inactive` = 0
) vl
  ON vl.`Queen_ID` = aq.`ID`
ORDER BY aq.`Geburtsjahr` DESC, aq.`ID` DESC;";

    $rows = $pdo->query($sql)->fetchAll();
    respond(['queens' => $rows]);
  }

  if ($action === 'queen_options') {
    $sql = "SELECT ID, Lebensnummer, Geburtsjahr, gezeichnet, Rasse
            FROM Queens
            ORDER BY Geburtsjahr DESC, ID DESC";
    $rows = $pdo->query($sql)->fetchAll();
    respond(['queens' => $rows]);
  }

  if ($action === 'queen') {
    $id = (int)require_param('id');
    $sql = "SELECT ID, Lebensnummer, Geburtsjahr, gezeichnet, Rasse, Züchter,
                   LN_Mutter, LN_Vatermutter, Belegstelle
            FROM Queens
            WHERE ID = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) respond(['error' => 'Queen not found'], 404);
    respond(['queen' => $row]);
  }

  if ($action === 'hive') {
    $id = (int)require_param('id');
    $stmt = $pdo->prepare("SELECT ID, Hive_nr, inactive FROM Hives WHERE ID = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) respond(['error' => 'Hive not found'], 404);
    respond(['hive' => $row]);
  }

  if ($action === 'hives_by_standort') {
    $standort = require_param('standort');
    $sql = latest_visits_cte() . "
      SELECT h.ID AS Hive_ID,
             h.Hive_nr,
             l.Datum AS last_visit_date,
             l.Aufbau,
             l.`Volksstärke` AS Volksstaerke,
             l.Schwarmneigung,
             l.Bemerkungen,
             l.ToDo,
             l.Queen_ID,
             q.Geburtsjahr AS queen_birth_year,
             q.gezeichnet AS queen_marked,
             q.Rasse AS queen_breed
      FROM latest l
      JOIN Hives h ON h.ID = l.Hive_ID
      LEFT JOIN Queens q ON q.ID = l.Queen_ID
      WHERE l.rn = 1 AND h.inactive = 0 AND COALESCE(l.Standort,'—') = :standort
      ORDER BY h.Hive_nr ASC, h.ID ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['standort' => $standort]);
    $rows = $stmt->fetchAll();
    respond(['standort' => $standort, 'hives' => $rows]);
  }

  if ($action === 'visits_by_hive') {
    $hive_id = (int)require_param('hive_id');
    $sql = "SELECT v.ID,
                   v.Datum,
                   v.Standort,
                   v.Aufbau,
                   v.`Volksstärke` AS Volksstaerke,
                   v.`Königin` AS Koenigin_status,
                   v.Queen_ID,
                   q.Geburtsjahr AS queen_birth_year,
                   q.gezeichnet AS queen_marked,
                   q.Rasse AS queen_breed,
                   q.`Züchter` AS queen_breeder,
                   q.Belegstelle AS queen_belegstelle,
                   v.Brut_Stifte,
                   v.Brut_offen,
                   v.Brut_verdeckelt,
                   v.Sanftmut,
                   v.Wabensitz,
                   v.Schwarmneigung,
                   v.Honig,
                   v.Futter,
                   v.Bemerkungen,
                   v.ToDo
            FROM Visits v
            LEFT JOIN Queens q ON q.ID = v.Queen_ID
            WHERE v.Hive_ID = :hive_id
            ORDER BY v.Datum DESC, v.ID DESC
            LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['hive_id' => $hive_id]);
    $rows = $stmt->fetchAll();

    $hive = $pdo->prepare("SELECT ID, Hive_nr, inactive FROM Hives WHERE ID = :id");
    $hive->execute(['id' => $hive_id]);
    $hive_row = $hive->fetch();

    respond(['hive' => $hive_row, 'visits' => $rows]);
  }

  if ($action === 'visit') {
    $id = (int)require_param('id');
    $sql = "SELECT v.*,
                   v.`Volksstärke` AS Volksstaerke,
                   v.`Königin` AS Koenigin_status
            FROM Visits v
            WHERE v.ID = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) respond(['error' => 'Visit not found'], 404);

    // add queen details if present
    $queen = null;
    if (!empty($row['Queen_ID'])) {
      $q = $pdo->prepare("SELECT ID, Lebensnummer, Geburtsjahr, gezeichnet, Rasse, Züchter, LN_Mutter, LN_Vatermutter, Belegstelle
                          FROM Queens WHERE ID = :id");
      $q->execute(['id' => (int)$row['Queen_ID']]);
      $queen = $q->fetch();
    }

    respond(['visit' => $row, 'queen' => $queen]);
  }

  if ($action === 'visit_defaults') {
    $hive_id = (int)require_param('hive_id');
    // Last visit for hive (for prefill)
    $sql = "SELECT v.*
            FROM Visits v
            WHERE v.Hive_ID = :hive_id
            ORDER BY v.Datum DESC, v.ID DESC
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['hive_id' => $hive_id]);
    $last = $stmt->fetch();

    $defaults = [
      'Hive_ID' => $hive_id,
      'Queen_ID' => $last['Queen_ID'] ?? null,
      'Datum' => date('Y-m-d'),
      'Standort' => $last['Standort'] ?? null,
      'Aufbau' => $last['Aufbau'] ?? null,
      'Volksstaerke' => $last['Volksstärke'] ?? null,
      'Koenigin_status' => $last['Königin'] ?? null,
      'Brut_Stifte' => $last['Brut_Stifte'] ?? null,
      'Brut_offen' => $last['Brut_offen'] ?? null,
      'Brut_verdeckelt' => $last['Brut_verdeckelt'] ?? null,
      'Sanftmut' => $last['Sanftmut'] ?? null,
      'Wabensitz' => $last['Wabensitz'] ?? null,
      'Schwarmneigung' => $last['Schwarmneigung'] ?? null,
      'Honig' => $last['Honig'] ?? null,
      'Futter' => $last['Futter'] ?? null,
      'Bemerkungen' => $last['Bemerkungen'] ?? null,
      'ToDo' => $last['ToDo'] ?? null,
    ];
    respond(['defaults' => $defaults, 'has_last_visit' => (bool)$last]);
  }

  if ($action === 'visit_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;

    $hive_id = (int)($payload['Hive_ID'] ?? 0);
    if ($hive_id <= 0) respond(['error' => 'Hive_ID required'], 400);

    $sql = "INSERT INTO Visits
              (Hive_ID, Queen_ID, Datum, Standort, Aufbau, `Volksstärke`, `Königin`,
               Brut_Stifte, Brut_offen, Brut_verdeckelt,
               Sanftmut, Wabensitz, Schwarmneigung,
               Honig, Futter, Bemerkungen, ToDo)
            VALUES
              (:Hive_ID, :Queen_ID, :Datum, :Standort, :Aufbau, :Volksstaerke, :Koenigin_status,
               :Brut_Stifte, :Brut_offen, :Brut_verdeckelt,
               :Sanftmut, :Wabensitz, :Schwarmneigung,
               :Honig, :Futter, :Bemerkungen, :ToDo)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      'Hive_ID' => $hive_id,
      'Queen_ID' => $payload['Queen_ID'] !== '' ? ($payload['Queen_ID'] ?? null) : null,
      'Datum' => $payload['Datum'] ?? date('Y-m-d'),
      'Standort' => $payload['Standort'] ?? null,
      'Aufbau' => $payload['Aufbau'] ?? null,
      'Volksstaerke' => $payload['Volksstaerke'] ?? null,
      'Koenigin_status' => $payload['Koenigin_status'] ?? null,
      'Brut_Stifte' => $payload['Brut_Stifte'] ?? null,
      'Brut_offen' => $payload['Brut_offen'] ?? null,
      'Brut_verdeckelt' => $payload['Brut_verdeckelt'] ?? null,
      'Sanftmut' => $payload['Sanftmut'] ?? null,
      'Wabensitz' => $payload['Wabensitz'] ?? null,
      'Schwarmneigung' => $payload['Schwarmneigung'] ?? null,
      'Honig' => $payload['Honig'] ?? null,
      'Futter' => $payload['Futter'] ?? null,
      'Bemerkungen' => $payload['Bemerkungen'] ?? null,
      'ToDo' => $payload['ToDo'] ?? null,
    ]);
    $new_id = (int)$pdo->lastInsertId();
    respond(['ok' => true, 'id' => $new_id], 201);
  }

  if ($action === 'visit_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)require_param('id');
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;

    $sql = "UPDATE Visits SET
              Queen_ID = :Queen_ID,
              Datum = :Datum,
              Standort = :Standort,
              Aufbau = :Aufbau,
              `Volksstärke` = :Volksstaerke,
              `Königin` = :Koenigin_status,
              Brut_Stifte = :Brut_Stifte,
              Brut_offen = :Brut_offen,
              Brut_verdeckelt = :Brut_verdeckelt,
              Sanftmut = :Sanftmut,
              Wabensitz = :Wabensitz,
              Schwarmneigung = :Schwarmneigung,
              Honig = :Honig,
              Futter = :Futter,
              Bemerkungen = :Bemerkungen,
              ToDo = :ToDo
            WHERE ID = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      'id' => $id,
      'Queen_ID' => $payload['Queen_ID'] !== '' ? ($payload['Queen_ID'] ?? null) : null,
      'Datum' => $payload['Datum'] ?? date('Y-m-d'),
      'Standort' => $payload['Standort'] ?? null,
      'Aufbau' => $payload['Aufbau'] ?? null,
      'Volksstaerke' => $payload['Volksstaerke'] ?? null,
      'Koenigin_status' => $payload['Koenigin_status'] ?? null,
      'Brut_Stifte' => $payload['Brut_Stifte'] ?? null,
      'Brut_offen' => $payload['Brut_offen'] ?? null,
      'Brut_verdeckelt' => $payload['Brut_verdeckelt'] ?? null,
      'Sanftmut' => $payload['Sanftmut'] ?? null,
      'Wabensitz' => $payload['Wabensitz'] ?? null,
      'Schwarmneigung' => $payload['Schwarmneigung'] ?? null,
      'Honig' => $payload['Honig'] ?? null,
      'Futter' => $payload['Futter'] ?? null,
      'Bemerkungen' => $payload['Bemerkungen'] ?? null,
      'ToDo' => $payload['ToDo'] ?? null,
    ]);
    respond(['ok' => true]);
  }

  if ($action === 'hive_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;

    $inactive = isset($payload['inactive']) ? (int)$payload['inactive'] : 0;

    $stmt = $pdo->prepare("INSERT INTO Hives (Hive_nr, inactive) VALUES (:Hive_nr, :inactive)");
    $stmt->execute([
      'Hive_nr' => $payload['Hive_nr'] ?? null,
      'inactive' => $inactive ? 1 : 0,
    ]);
    $new_id = (int)$pdo->lastInsertId();

    $today = date('Y-m-d');
    $visit = $pdo->prepare("INSERT INTO Visits
              (Hive_ID, Queen_ID, Datum, Standort, Aufbau, `Volksstärke`, `Königin`,
               Brut_Stifte, Brut_offen, Brut_verdeckelt,
               Sanftmut, Wabensitz, Schwarmneigung,
               Honig, Futter, Bemerkungen, ToDo)
            VALUES
              (:Hive_ID, :Queen_ID, :Datum, :Standort, :Aufbau, :Volksstaerke, :Koenigin_status,
               :Brut_Stifte, :Brut_offen, :Brut_verdeckelt,
               :Sanftmut, :Wabensitz, :Schwarmneigung,
               :Honig, :Futter, :Bemerkungen, :ToDo)");
    $visit->execute([
      'Hive_ID' => $new_id,
      'Queen_ID' => null,
      'Datum' => $today,
      'Standort' => 'NEW',
      'Aufbau' => null,
      'Volksstaerke' => null,
      'Koenigin_status' => null,
      'Brut_Stifte' => null,
      'Brut_offen' => null,
      'Brut_verdeckelt' => null,
      'Sanftmut' => null,
      'Wabensitz' => null,
      'Schwarmneigung' => null,
      'Honig' => null,
      'Futter' => null,
      'Bemerkungen' => null,
      'ToDo' => null,
    ]);

    respond(['ok' => true, 'id' => $new_id], 201);
  }

  if ($action === 'hive_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)require_param('id');
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;

    $inactive = isset($payload['inactive']) ? (int)$payload['inactive'] : 0;

    $sql = "UPDATE Hives SET
              Hive_nr = :Hive_nr,
              inactive = :inactive
            WHERE ID = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      'id' => $id,
      'Hive_nr' => $payload['Hive_nr'] ?? null,
      'inactive' => $inactive ? 1 : 0,
    ]);
    respond(['ok' => true]);
  }

  if ($action === 'visit_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)require_param('id');
    $stmt = $pdo->prepare("DELETE FROM Visits WHERE ID = :id");
    $stmt->execute(['id' => $id]);
    respond(['ok' => true]);
  }

  if ($action === 'queen_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)require_param('id');
    $stmt = $pdo->prepare("DELETE FROM Queens WHERE ID = :id");
    $stmt->execute(['id' => $id]);
    respond(['ok' => true]);
  }

  if ($action === 'queen_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;

    $sql = "INSERT INTO Queens
              (Lebensnummer, Geburtsjahr, gezeichnet, Rasse, `Züchter`,
               LN_Mutter, LN_Vatermutter, Belegstelle)
            VALUES
              (:Lebensnummer, :Geburtsjahr, :gezeichnet, :Rasse, :Zuechter,
               :LN_Mutter, :LN_Vatermutter, :Belegstelle)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      'Lebensnummer' => $payload['Lebensnummer'] ?? null,
      'Geburtsjahr' => $payload['Geburtsjahr'] ?? null,
      'gezeichnet' => $payload['gezeichnet'] ?? null,
      'Rasse' => $payload['Rasse'] ?? null,
      'Zuechter' => $payload['Züchter'] ?? ($payload['Zuechter'] ?? null),
      'LN_Mutter' => $payload['LN_Mutter'] ?? null,
      'LN_Vatermutter' => $payload['LN_Vatermutter'] ?? null,
      'Belegstelle' => $payload['Belegstelle'] ?? null,
    ]);
    $new_id = (int)$pdo->lastInsertId();
    respond(['ok' => true, 'id' => $new_id], 201);
  }

  if ($action === 'queen_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)require_param('id');
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;

    $sql = "UPDATE Queens SET
              Lebensnummer = :Lebensnummer,
              Geburtsjahr = :Geburtsjahr,
              gezeichnet = :gezeichnet,
              Rasse = :Rasse,
              Züchter = :Zuechter,
              LN_Mutter = :LN_Mutter,
              LN_Vatermutter = :LN_Vatermutter,
              Belegstelle = :Belegstelle
            WHERE ID = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      'id' => $id,
      'Lebensnummer' => $payload['Lebensnummer'] ?? null,
      'Geburtsjahr' => $payload['Geburtsjahr'] ?? null,
      'gezeichnet' => $payload['gezeichnet'] ?? null,
      'Rasse' => $payload['Rasse'] ?? null,
      'Zuechter' => $payload['Züchter'] ?? ($payload['Zuechter'] ?? null),
      'LN_Mutter' => $payload['LN_Mutter'] ?? null,
      'LN_Vatermutter' => $payload['LN_Vatermutter'] ?? null,
      'Belegstelle' => $payload['Belegstelle'] ?? null,
    ]);
    respond(['ok' => true]);
  }

  respond(['error' => 'Unknown action'], 404);

} catch (Throwable $e) {
  respond(['error' => 'Server error', 'details' => $e->getMessage()], 500);
}
