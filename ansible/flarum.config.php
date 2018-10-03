<?php return array (
  'debug' => false,
  'database' => 
  array (
    'driver' => 'mysql',
    'host' => 'localhost',
    'database' => 'flarum',
    'username' => 'flarum',
    'password' => '{{ flarum_dbpwd }}',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'port' => '3306',
    'strict' => false,
  ),
  'url' => 'http://{{ inventory_hostname }}',
  'paths' => 
  array (
    'api' => 'api',
    'admin' => 'admin',
  ),
);

