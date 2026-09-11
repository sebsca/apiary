<?php

const LOGIN_RATE_RULES = [
  'account_ip' => ['limit' => 5, 'window_seconds' => 900],
  'ip' => ['limit' => 30, 'window_seconds' => 900],
  'account' => ['limit' => 20, 'window_seconds' => 3600],
];

function login_client_ip(): string {
  $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
  return $ip !== '' ? $ip : 'unknown';
}

function login_rate_keys(string $username, string $ip): array {
  $accountHash = hash('sha256', 'account:' . $username);
  $ipHash = hash('sha256', 'ip:' . $ip);
  return [
    'account_ip' => hash('sha256', 'account_ip:' . $accountHash . ':' . $ipHash),
    'ip' => $ipHash,
    'account' => $accountHash,
  ];
}

function process_login_attempt(PDO $pdo, array $keys, callable $verifyCredentials): array {
  $scopes = array_keys(LOGIN_RATE_RULES);
  sort($scopes, SORT_STRING);
  foreach ($scopes as $scope) {
    if (!isset($keys[$scope]) || !is_string($keys[$scope]) || strlen($keys[$scope]) !== 64) {
      throw new InvalidArgumentException('Invalid login rate-limit key.');
    }
  }

  $lockNames = [];
  foreach ($scopes as $scope) {
    $lockNames[] = 'apiary-login:' . substr(hash('sha256', $scope . ':' . $keys[$scope]), 0, 48);
  }
  sort($lockNames, SORT_STRING);
  $acquiredLocks = [];

  try {
    $acquire = $pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
    foreach ($lockNames as $lockName) {
      $acquire->execute(['lock_name' => $lockName]);
      if ((int)$acquire->fetchColumn() !== 1) {
        throw new RuntimeException('Login rate-limit lock could not be acquired.');
      }
      $acquiredLocks[] = $lockName;
    }

    $pdo->beginTransaction();
    $insert = $pdo->prepare(
      'INSERT IGNORE INTO LoginRateLimits
         (scope_type, scope_key, attempt_count, window_started_at)
       VALUES (:scope, :scope_key, 0, UTC_TIMESTAMP(6))'
    );
    $select = $pdo->prepare(
      'SELECT attempt_count,
              TIMESTAMPDIFF(SECOND, window_started_at, UTC_TIMESTAMP(6)) AS elapsed_seconds
       FROM LoginRateLimits
       WHERE scope_type = :scope AND scope_key = :scope_key
       FOR UPDATE'
    );
    $reset = $pdo->prepare(
      'UPDATE LoginRateLimits
       SET attempt_count = 0, window_started_at = UTC_TIMESTAMP(6)
       WHERE scope_type = :scope AND scope_key = :scope_key'
    );

    $states = [];
    foreach ($scopes as $scope) {
      $params = ['scope' => $scope, 'scope_key' => $keys[$scope]];
      $insert->execute($params);
      $select->execute($params);
      $state = $select->fetch();
      if (!$state) {
        throw new RuntimeException('Login rate-limit state could not be locked.');
      }
      $elapsed = max(0, (int)$state['elapsed_seconds']);
      if ($elapsed >= (int)LOGIN_RATE_RULES[$scope]['window_seconds']) {
        $reset->execute($params);
        $state['attempt_count'] = 0;
        $elapsed = 0;
      }
      $state['elapsed_seconds'] = $elapsed;
      $states[$scope] = $state;
    }

    $retryAfter = 0;
    foreach ($states as $scope => $state) {
      $rule = LOGIN_RATE_RULES[$scope];
      if ((int)$state['attempt_count'] >= (int)$rule['limit']) {
        $retryAfter = max(
          $retryAfter,
          max(1, (int)$rule['window_seconds'] - (int)$state['elapsed_seconds'])
        );
      }
    }
    if ($retryAfter > 0) {
      $pdo->commit();
      return ['status' => 'blocked', 'retry_after' => $retryAfter];
    }

    $valid = (bool)$verifyCredentials();
    if ($valid) {
      $clear = $pdo->prepare(
        'UPDATE LoginRateLimits
         SET attempt_count = 0, window_started_at = UTC_TIMESTAMP(6)
         WHERE scope_type = :scope AND scope_key = :scope_key'
      );
      foreach (['account', 'account_ip'] as $scope) {
        $clear->execute(['scope' => $scope, 'scope_key' => $keys[$scope]]);
      }
    } else {
      $increment = $pdo->prepare(
        'UPDATE LoginRateLimits
         SET attempt_count = attempt_count + 1
         WHERE scope_type = :scope AND scope_key = :scope_key'
      );
      foreach ($scopes as $scope) {
        $increment->execute(['scope' => $scope, 'scope_key' => $keys[$scope]]);
      }
    }

    $pdo->commit();
    return ['status' => $valid ? 'valid' : 'invalid'];
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $exception;
  } finally {
    $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
    foreach (array_reverse($acquiredLocks) as $lockName) {
      try {
        $release->execute(['lock_name' => $lockName]);
      } catch (Throwable $exception) {
        error_log('Login rate-limit lock could not be released: ' . $exception->getMessage());
      }
    }
  }
}
