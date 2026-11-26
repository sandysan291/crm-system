<?php
require_once __DIR__ . '/includes/Bootstrap.php';
\App\Core\Bootstrap::run($_GET['page'] ?? null);
