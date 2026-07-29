<?php

function api_routes(): array {
  return [
    'me' => ['method' => 'GET', 'roles' => null],
    'admin_bootstrap_status' => ['method' => 'GET', 'roles' => null],
    'admin_bootstrap_create' => ['method' => 'POST', 'roles' => null],
    'login' => ['method' => 'POST', 'roles' => null],
    'logout' => ['method' => 'POST', 'roles' => null],
    'change_password' => ['method' => 'POST', 'roles' => []],
    'users_list' => ['method' => 'GET', 'roles' => ['admin']],
    'user_create' => ['method' => 'POST', 'roles' => ['admin']],
    'user_delete' => ['method' => 'POST', 'roles' => ['admin']],
    'user_update_role' => ['method' => 'POST', 'roles' => ['admin']],
    'user_reset_password' => ['method' => 'POST', 'roles' => ['admin']],
    'standorte' => ['method' => 'GET', 'roles' => []],
    'hives' => ['method' => 'GET', 'roles' => []],
    'hive_movements' => ['method' => 'GET', 'roles' => []],
    'queens' => ['method' => 'GET', 'roles' => []],
    'queen_options' => ['method' => 'GET', 'roles' => []],
    'queen' => ['method' => 'GET', 'roles' => []],
    'hive' => ['method' => 'GET', 'roles' => []],
    'hives_by_standort' => ['method' => 'GET', 'roles' => []],
    'visits_by_hive' => ['method' => 'GET', 'roles' => []],
    'visit' => ['method' => 'GET', 'roles' => []],
    'visit_defaults' => ['method' => 'GET', 'roles' => []],
    'visit_create' => ['method' => 'POST', 'roles' => ['admin', 'contributor']],
    'visit_update' => ['method' => 'POST', 'roles' => ['admin', 'contributor']],
    'visit_delete' => ['method' => 'POST', 'roles' => ['admin', 'contributor']],
    'hive_create' => ['method' => 'POST', 'roles' => ['admin', 'contributor']],
    'hive_update' => ['method' => 'POST', 'roles' => ['admin', 'contributor']],
    'queen_create' => ['method' => 'POST', 'roles' => ['admin', 'contributor']],
    'queen_update' => ['method' => 'POST', 'roles' => ['admin', 'contributor']],
    'queen_delete' => ['method' => 'POST', 'roles' => ['admin', 'contributor']]
  ];
}
