<?php

require_once __DIR__ . '/../api-routes.php';

$routes = api_routes();
$expectedActions = [
  'me', 'admin_bootstrap_status', 'admin_bootstrap_create', 'login', 'logout',
  'change_password', 'users_list', 'user_create', 'user_delete',
  'user_update_role', 'user_reset_password', 'standorte', 'hives',
  'hive_movements', 'queens', 'queen_options', 'queen', 'hive',
  'hives_by_standort', 'visits_by_hive', 'visit', 'visit_defaults',
  'visit_create', 'visit_update', 'visit_delete', 'hive_create', 'hive_update',
  'queen_create', 'queen_update', 'queen_delete'
];

sort($expectedActions);
$actualActions = array_keys($routes);
sort($actualActions);
if ($expectedActions !== $actualActions) {
  throw new RuntimeException('The API route policy does not contain the expected actions.');
}

foreach ($routes as $action => $route) {
  if (!in_array($route['method'] ?? null, ['GET', 'POST'], true)) {
    throw new RuntimeException("{$action} has an invalid HTTP method.");
  }
  if (!array_key_exists('roles', $route) || ($route['roles'] !== null && !is_array($route['roles']))) {
    throw new RuntimeException("{$action} has an invalid role policy.");
  }
}

$apiSource = file_get_contents(__DIR__ . '/../api.php');
foreach (array_keys($routes) as $action) {
  if (strpos($apiSource, "if (\$action === '{$action}')") === false) {
    throw new RuntimeException("{$action} has route policy but no API handler.");
  }
}

echo "OK (" . count($routes) . " routes with handlers)\n";
