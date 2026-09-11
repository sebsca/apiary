<?php

require_once __DIR__ . '/../api-bootstrap.php';
require_once __DIR__ . '/../login-security.php';

$pdo = get_pdo();
$suffix = bin2hex(random_bytes(16));
$keys = login_rate_keys('test-account-' . $suffix, 'test-ip-' . $suffix);
$callbackCalls = 0;

function assert_login_security($condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

try {
  for ($attempt = 1; $attempt <= 5; $attempt++) {
    $result = process_login_attempt($pdo, $keys, function () use (&$callbackCalls) {
      $callbackCalls++;
      return false;
    });
    assert_login_security($result['status'] === 'invalid', "Attempt {$attempt} was not rejected normally.");
  }

  $result = process_login_attempt($pdo, $keys, function () use (&$callbackCalls) {
    $callbackCalls++;
    return false;
  });
  assert_login_security($result['status'] === 'blocked', 'Sixth attempt was not rate-limited.');
  assert_login_security($callbackCalls === 5, 'Credentials were checked after the limit was reached.');
  assert_login_security((int)$result['retry_after'] > 0, 'Retry-After is missing.');

  $successKeys = login_rate_keys('test-success-' . $suffix, 'test-ip-' . $suffix);
  process_login_attempt($pdo, $successKeys, function () { return false; });
  $result = process_login_attempt($pdo, $successKeys, function () { return true; });
  assert_login_security($result['status'] === 'valid', 'Valid credentials were not accepted.');

  $stmt = $pdo->prepare(
    'SELECT scope_type, attempt_count FROM LoginRateLimits
     WHERE (scope_type = :account_scope AND scope_key = :account_key)
        OR (scope_type = :pair_scope AND scope_key = :pair_key)'
  );
  $stmt->execute([
    'account_scope' => 'account',
    'account_key' => $successKeys['account'],
    'pair_scope' => 'account_ip',
    'pair_key' => $successKeys['account_ip'],
  ]);
  foreach ($stmt->fetchAll() as $row) {
    assert_login_security((int)$row['attempt_count'] === 0, 'Successful login did not reset account counters.');
  }

  echo "OK (atomic login rate limiting)\n";
} finally {
  $allKeys = array_values(array_unique(array_merge(array_values($keys), array_values($successKeys ?? []))));
  if ($allKeys) {
    $placeholders = implode(',', array_fill(0, count($allKeys), '?'));
    $pdo->prepare("DELETE FROM LoginRateLimits WHERE scope_key IN ({$placeholders})")->execute($allKeys);
  }
}
