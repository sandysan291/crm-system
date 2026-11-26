<?php
declare(strict_types=1);

$dsn = getenv('CRM_DSN') ?: sprintf(
    'mysql:host=%s;dbname=%s;charset=utf8mb4',
    getenv('CRM_DB_HOST') ?: 'localhost',
    getenv('CRM_DB_NAME') ?: 'crm_database'
);
$username = getenv('CRM_DB_USER') ?: 'root';
$password = getenv('CRM_DB_PASSWORD') ?: '';

return new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
