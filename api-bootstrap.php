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

load_env_file(__DIR__ . '/.env');
