<?php

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
    $position = strpos($line, '=');
    if ($position === false) {
      continue;
    }
    $key = trim(substr($line, 0, $position));
    if ($key === '' || getenv($key) !== false) {
      continue;
    }
    $value = trim(substr($line, $position + 1));
    $length = strlen($value);
    if ($length >= 2) {
      $first = $value[0];
      $last = $value[$length - 1];
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

function get_pdo(): PDO {
  $host = getenv('APIARY_DB_HOST') ?: 'localhost';
  $name = getenv('APIARY_DB_NAME') ?: 'Apiary';
  $user = getenv('APIARY_DB_USER') ?: '';
  $password = getenv('APIARY_DB_PASS') ?: '';
  $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

  return new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
}

function apiary_start_session(): void {
  if (session_status() === PHP_SESSION_ACTIVE) {
    return;
  }
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
  ini_set('session.cookie_httponly', '1');
  ini_set('session.cookie_samesite', 'Lax');
  if ($https) {
    ini_set('session.cookie_secure', '1');
  }
  session_start();
}

function apiary_password_fingerprint(string $passwordHash): string {
  return hash('sha256', $passwordHash);
}

function apiary_refresh_authenticated_user(PDO $pdo): ?array {
  $userId = (int)($_SESSION['user_id'] ?? 0);
  if ($userId <= 0) {
    return null;
  }

  $stmt = $pdo->prepare(
    'SELECT id, username, password_hash, role FROM Users WHERE id = :id LIMIT 1'
  );
  $stmt->execute(['id' => $userId]);
  $user = $stmt->fetch();
  $passwordHash = is_array($user) ? (string)($user['password_hash'] ?? '') : '';
  $sessionFingerprint = (string)($_SESSION['password_fingerprint'] ?? '');
  $currentFingerprint = $passwordHash !== ''
    ? apiary_password_fingerprint($passwordHash)
    : '';

  if (!$user || $sessionFingerprint === '' || $currentFingerprint === ''
      || !hash_equals($currentFingerprint, $sessionFingerprint)) {
    $_SESSION = [];
    return null;
  }

  $_SESSION['user_id'] = (int)$user['id'];
  $_SESSION['username'] = (string)$user['username'];
  $_SESSION['role'] = (string)$user['role'];

  return [
    'id' => (int)$user['id'],
    'username' => (string)$user['username'],
    'role' => (string)$user['role']
  ];
}

load_env_file(__DIR__ . '/.env');
