<?php
// api.php - JSON API for Apiary database
// Requires PHP 7.4+ and MySQL 8+ (for window functions).
require_once __DIR__ . '/api-bootstrap.php';
require_once __DIR__ . '/api-routes.php';
require_once __DIR__ . '/movements.php';

const USER_ROLES = ['admin', 'contributor', 'readonly'];
const DEFAULT_RESET_PASSWORD = '12345678';
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

function require_param(string $key): string {
  $v = $_GET[$key] ?? $_POST[$key] ?? null;
  if ($v === null || $v === '') {
    respond(['error' => "Missing parameter: {$key}"], 400);
  }
  return (string)$v;
}

function request_payload(): array {
  $payload = json_decode(file_get_contents('php://input'), true);
  return is_array($payload) ? $payload : $_POST;
}

function payload_value(array $payload, string $key, $default=null) {
  if (!array_key_exists($key, $payload)) {
    return $default;
  }
  return $payload[$key] === '' ? null : $payload[$key];
}

function visit_params(array $payload): array {
  return [
    'Queen_ID' => payload_value($payload, 'Queen_ID'),
    'Datum' => payload_value($payload, 'Datum', date('Y-m-d')),
    'Standort' => payload_value($payload, 'Standort'),
    'Aufbau' => payload_value($payload, 'Aufbau'),
    'Volksstaerke' => payload_value($payload, 'Volksstaerke'),
    'Koenigin_status' => payload_value($payload, 'Koenigin_status'),
    'Brut_Stifte' => payload_value($payload, 'Brut_Stifte'),
    'Brut_offen' => payload_value($payload, 'Brut_offen'),
    'Brut_verdeckelt' => payload_value($payload, 'Brut_verdeckelt'),
    'Sanftmut' => payload_value($payload, 'Sanftmut'),
    'Wabensitz' => payload_value($payload, 'Wabensitz'),
    'Schwarmneigung' => payload_value($payload, 'Schwarmneigung'),
    'Honig' => payload_value($payload, 'Honig'),
    'Futter' => payload_value($payload, 'Futter'),
    'Bemerkungen' => payload_value($payload, 'Bemerkungen'),
    'ToDo' => payload_value($payload, 'ToDo')
  ];
}

function queen_params(array $payload): array {
  return [
    'Lebensnummer' => payload_value($payload, 'Lebensnummer'),
    'Geburtsjahr' => payload_value($payload, 'Geburtsjahr'),
    'gezeichnet' => payload_value($payload, 'gezeichnet'),
    'Rasse' => payload_value($payload, 'Rasse'),
    'Zuechter' => payload_value($payload, 'Zuechter', payload_value($payload, 'Züchter')),
    'LN_Mutter' => payload_value($payload, 'LN_Mutter'),
    'LN_Vatermutter' => payload_value($payload, 'LN_Vatermutter'),
    'Belegstelle' => payload_value($payload, 'Belegstelle')
  ];
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
$routes = api_routes();
$route = $routes[$action] ?? null;

try {
  if ($route === null) {
    respond(['error' => 'Unknown action'], 404);
  }
  if ($_SERVER['REQUEST_METHOD'] !== $route['method']) {
    header('Allow: ' . $route['method']);
    respond(['error' => 'Method not allowed'], 405);
  }

  $pdo = get_pdo();

  if ($route['roles'] !== null) {
    require_auth();
  }
  if (is_array($route['roles']) && count($route['roles']) > 0) {
    require_role($route['roles']);
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'login') {
    require_csrf();
  }
  $payload = $_SERVER['REQUEST_METHOD'] === 'POST' ? request_payload() : [];

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

  if ($action === 'admin_bootstrap_create') {
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

  if ($action === 'login') {
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
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    respond(['ok' => true]);
  }

  if ($action === 'change_password') {
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

  if ($action === 'user_create') {
    $username = trim((string)($payload['username'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    $role = (string)($payload['role'] ?? 'contributor');
    if ($username === '' || $password === '') {
      respond(['error' => 'Username and password required'], 400);
    }
    if (strlen($password) < 7) {
      respond(['error' => 'Password must be at least 7 characters'], 400);
    }
    if (!in_array($role, USER_ROLES, true)) {
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

  if ($action === 'user_delete') {
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

  if ($action === 'user_update_role') {
    $id = (int)($payload['id'] ?? 0);
    $role = (string)($payload['role'] ?? '');
    if ($id <= 0) {
      respond(['error' => 'Valid id required'], 400);
    }
    if (!in_array($role, USER_ROLES, true)) {
      respond(['error' => 'Invalid role'], 400);
    }
    if ((int)($_SESSION['user_id'] ?? 0) === $id) {
      respond(['error' => 'Cannot change your own role'], 403);
    }

    $stmt = $pdo->prepare("UPDATE Users SET role = :role WHERE id = :id");
    $stmt->execute(['role' => $role, 'id' => $id]);

    respond(['ok' => true]);
  }

  if ($action === 'user_reset_password') {
    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
      respond(['error' => 'Valid id required'], 400);
    }

    $hash = password_hash(DEFAULT_RESET_PASSWORD, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE Users SET password_hash = :hash WHERE id = :id");
    $stmt->execute(['hash' => $hash, 'id' => $id]);

    respond(['ok' => true, 'temporary_password' => DEFAULT_RESET_PASSWORD]);
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

  if ($action === 'hives') {
    $sql = latest_visits_cte() . "
      SELECT h.ID AS Hive_ID,
             h.Hive_nr,
             l.Standort,
             l.Datum AS last_visit_date,
             q.ID AS queen_id,
             q.Rasse AS queen_breed,
             q.Geburtsjahr AS queen_birth_year
      FROM Hives h
      LEFT JOIN latest l ON l.Hive_ID = h.ID AND l.rn = 1
      LEFT JOIN Queens q ON q.ID = l.Queen_ID
      WHERE h.inactive = 0
      ORDER BY h.Hive_nr ASC, h.ID ASC
    ";
    $rows = $pdo->query($sql)->fetchAll();
    respond(['hives' => $rows]);
  }

  if ($action === 'hive_movements') {
    $fromDate = (new DateTimeImmutable('first day of January'))->format('Y-m-d');
    respond(build_sankey_graph(load_movement_rows($pdo, $fromDate), $fromDate));
  }

  if ($action === 'queens') {
    $sort = $_GET['sort'] ?? 'birth';
    $orderBy = [
      'birth' => "q.`Geburtsjahr` DESC, q.`ID` DESC",
      'id' => "q.`ID` DESC",
      'location' => "(aql.`Standort` IS NULL OR aql.`Standort` = '') ASC, aql.`Standort` ASC, aql.`Hive_nr` ASC",
    ][$sort] ?? "q.`Geburtsjahr` DESC, q.`ID` DESC";

    $sql = latest_visits_cte() . ",
      active_queen_locations AS (
        SELECT l.Queen_ID, h.Hive_nr, l.Standort
        FROM latest l
        JOIN Hives h ON h.ID = l.Hive_ID
        WHERE l.rn = 1 AND h.inactive = 0
      )
      SELECT q.ID,
             q.gezeichnet,
             q.Lebensnummer,
             q.Geburtsjahr,
             q.Rasse,
             q.`Züchter` AS Zuechter,
             q.LN_Mutter,
             q.LN_Vatermutter,
             q.Belegstelle,
             aql.Hive_nr,
             aql.Standort
      FROM Queens q
      LEFT JOIN active_queen_locations aql ON aql.Queen_ID = q.ID
      ORDER BY $orderBy";

    $rows = $pdo->query($sql)->fetchAll();
    respond(['queens' => $rows]);
  }

  if ($action === 'queen_options') {
    $sql = "SELECT ID, Lebensnummer, Geburtsjahr, gezeichnet, Rasse
            FROM Queens
            ORDER BY ID DESC";
    $rows = $pdo->query($sql)->fetchAll();
    respond(['queens' => $rows]);
  }

  if ($action === 'queen') {
    $id = (int)require_param('id');
    $sql = "SELECT ID, Lebensnummer, Geburtsjahr, gezeichnet, Rasse, `Züchter` AS Zuechter,
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
    respond(['visit' => $row]);
  }

  if ($action === 'visit_defaults') {
    $hive_id = (int)require_param('hive_id');
    // Last visit for hive (for prefill)
    $sql = "SELECT v.Queen_ID, v.Standort, v.Aufbau, v.ToDo
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
      'ToDo' => $last['ToDo'] ?? null
    ];
    respond(['defaults' => $defaults]);
  }

  if ($action === 'visit_create') {
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
    $params = visit_params($payload);
    $params['Hive_ID'] = $hive_id;
    $stmt->execute($params);
    $new_id = (int)$pdo->lastInsertId();
    respond(['ok' => true, 'id' => $new_id], 201);
  }

  if ($action === 'visit_update') {
    $id = (int)require_param('id');

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
    $params = visit_params($payload);
    $params['id'] = $id;
    $stmt->execute($params);
    respond(['ok' => true]);
  }

  if ($action === 'hive_create') {
    $inactive = isset($payload['inactive']) ? (int)$payload['inactive'] : 0;
    $pdo->beginTransaction();
    try {
      $stmt = $pdo->prepare("INSERT INTO Hives (Hive_nr, inactive) VALUES (:Hive_nr, :inactive)");
      $stmt->execute([
        'Hive_nr' => payload_value($payload, 'Hive_nr'),
        'inactive' => $inactive ? 1 : 0,
      ]);
      $new_id = (int)$pdo->lastInsertId();

      // A newly created hive intentionally receives a synthetic first visit.
      $visit = $pdo->prepare("INSERT INTO Visits
                (Hive_ID, Queen_ID, Datum, Standort, Aufbau, `Volksstärke`, `Königin`,
                 Brut_Stifte, Brut_offen, Brut_verdeckelt,
                 Sanftmut, Wabensitz, Schwarmneigung,
                 Honig, Futter, Bemerkungen, ToDo)
              VALUES
                (:Hive_ID, NULL, :Datum, 'NEW', NULL, NULL, NULL,
                 NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL)");
      $visit->execute(['Hive_ID' => $new_id, 'Datum' => date('Y-m-d')]);
      $pdo->commit();
    } catch (Throwable $e) {
      $pdo->rollBack();
      throw $e;
    }

    respond(['ok' => true, 'id' => $new_id], 201);
  }

  if ($action === 'hive_update') {
    $id = (int)require_param('id');

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

  if ($action === 'visit_delete') {
    $id = (int)require_param('id');
    $stmt = $pdo->prepare("DELETE FROM Visits WHERE ID = :id");
    $stmt->execute(['id' => $id]);
    respond(['ok' => true]);
  }

  if ($action === 'queen_delete') {
    $id = (int)require_param('id');
    $stmt = $pdo->prepare("DELETE FROM Queens WHERE ID = :id");
    $stmt->execute(['id' => $id]);
    respond(['ok' => true]);
  }

  if ($action === 'queen_create') {
    $sql = "INSERT INTO Queens
              (Lebensnummer, Geburtsjahr, gezeichnet, Rasse, `Züchter`,
               LN_Mutter, LN_Vatermutter, Belegstelle)
            VALUES
              (:Lebensnummer, :Geburtsjahr, :gezeichnet, :Rasse, :Zuechter,
               :LN_Mutter, :LN_Vatermutter, :Belegstelle)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(queen_params($payload));
    $new_id = (int)$pdo->lastInsertId();
    respond(['ok' => true, 'id' => $new_id], 201);
  }

  if ($action === 'queen_update') {
    $id = (int)require_param('id');

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
    $params = queen_params($payload);
    $params['id'] = $id;
    $stmt->execute($params);
    respond(['ok' => true]);
  }

  throw new LogicException("No handler implemented for action: {$action}");

} catch (Throwable $e) {
  error_log('Apiary API error: ' . $e->getMessage());
  $error = ['error' => 'Server error'];
  if (getenv('APIARY_DEBUG') === '1') {
    $error['details'] = $e->getMessage();
  }
  respond($error, 500);
}
