<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use Throwable;

final class Bootstrap
{
    private const NAVIGATION = [
        'dashboard' => ['Overview', '⌂'],
        'customers' => ['Customers', '◉'],
        'leads' => ['Leads', '◇'],
        'deals' => ['Deals', '▧'],
        'tasks' => ['Tasks', '✓'],
        'activities' => ['Activity', '◷'],
        'reports' => ['Reports', '▥'],
        'profile' => ['My profile', '○'],
    ];

    public static function run(?string $requestedPage = null): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            $cookie = session_get_cookie_params();
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $cookie['path'],
                'domain' => $cookie['domain'],
                'secure' => $cookie['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        $page = $requestedPage ?: self::pageFromScript();
        if ($page === 'index' || $page === '') {
            $page = isset($_SESSION['user_id']) ? 'dashboard' : 'login';
        }

        if ($page === 'logout') {
            self::logout();
            return;
        }

        if (!in_array($page, ['login', 'register'], true) && !isset($_SESSION['user_id'])) {
            if ($page === 'api') {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                http_response_code(401);
                echo json_encode(['error' => 'Please sign in again.']);
                return;
            }
            self::redirect('login');
        }

        try {
            $db = require __DIR__ . '/../config/database.php';
            self::ensureSchema($db);
            if ($page === 'api') {
                self::handleApi($db, (int)($_SESSION['user_id'] ?? 0));
                return;
            }
            self::dispatch($db, $page);
        } catch (PDOException $exception) {
            http_response_code(500);
            error_log($exception->__toString());
            self::renderUnavailable($exception);
        } catch (Throwable $exception) {
            http_response_code(500);
            error_log($exception->__toString());
            self::renderApplicationError();
        }
    }

    private static function pageFromScript(): string
    {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php', '.php');
        return in_array($script, ['index', 'dashboard', 'login', 'register', 'logout'], true)
            ? $script
            : 'dashboard';
    }

    private static function ensureSchema(PDO $db): void
    {
        $id = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
            : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';

        $statements = [
            "CREATE TABLE IF NOT EXISTS users (
                id $id,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(190) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS customers (
                id $id,
                user_id INT NOT NULL,
                name VARCHAR(160) NOT NULL,
                company VARCHAR(160) DEFAULT '',
                email VARCHAR(190) DEFAULT '',
                phone VARCHAR(50) DEFAULT '',
                status VARCHAR(30) NOT NULL DEFAULT 'Active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS leads (
                id $id,
                user_id INT NOT NULL,
                name VARCHAR(160) NOT NULL,
                company VARCHAR(160) DEFAULT '',
                email VARCHAR(190) DEFAULT '',
                source VARCHAR(80) DEFAULT '',
                value DECIMAL(12,2) NOT NULL DEFAULT 0,
                status VARCHAR(30) NOT NULL DEFAULT 'New',
                converted_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS deals (
                id $id,
                user_id INT NOT NULL,
                title VARCHAR(180) NOT NULL,
                customer_id INT DEFAULT NULL,
                customer_name VARCHAR(160) NOT NULL DEFAULT '',
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                stage VARCHAR(30) NOT NULL DEFAULT 'New',
                probability INT NOT NULL DEFAULT 10,
                expected_close DATE DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS tasks (
                id $id,
                user_id INT NOT NULL,
                title VARCHAR(200) NOT NULL,
                description TEXT,
                due_date DATE DEFAULT NULL,
                priority VARCHAR(20) NOT NULL DEFAULT 'Normal',
                status VARCHAR(30) NOT NULL DEFAULT 'To do',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS activities (
                id $id,
                user_id INT NOT NULL,
                type VARCHAR(20) NOT NULL,
                subject VARCHAR(160) NOT NULL,
                details TEXT,
                contact_type VARCHAR(20) DEFAULT NULL,
                contact_id INT DEFAULT NULL,
                contact_name VARCHAR(160) NOT NULL DEFAULT '',
                happened_at DATE NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS notifications (
                id $id,
                user_id INT NOT NULL,
                title VARCHAR(160) NOT NULL,
                message VARCHAR(255) NOT NULL,
                target VARCHAR(40) NOT NULL DEFAULT 'dashboard',
                target_id INT DEFAULT NULL,
                read_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
        ];

        foreach ($statements as $statement) {
            $db->exec($statement);
        }

        self::ensureLeadConversionColumn($db);
    }

    private static function ensureLeadConversionColumn(PDO $db): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $columns = $db->query('PRAGMA table_info(leads)')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                if ($column['name'] === 'converted_at') {
                    return;
                }
            }
        } else {
            $statement = $db->query("SHOW COLUMNS FROM leads LIKE 'converted_at'");
            if ($statement->fetch(PDO::FETCH_ASSOC)) {
                return;
            }
        }

        $db->exec('ALTER TABLE leads ADD COLUMN converted_at TIMESTAMP NULL DEFAULT NULL');
    }

    private static function dispatch(PDO $db, string $page): void
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $errors = [];
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        if ($page === 'login' || $page === 'register') {
            if (isset($_SESSION['user_id'])) {
                self::redirect('dashboard');
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $errors = $page === 'login'
                    ? self::authenticate($db)
                    : self::register($db);
                if (!$errors) {
                    self::redirect('dashboard');
                }
            }
            self::renderAuth($page, $errors);
            return;
        }

        if (!array_key_exists($page, self::NAVIGATION)) {
            $page = 'dashboard';
        }

        if (in_array($page, ['customers', 'leads', 'deals', 'tasks', 'activities'], true)
            && ($_GET['export'] ?? '') === 'csv'
            && $_SERVER['REQUEST_METHOD'] === 'GET') {
            self::exportCsv($page, self::pageData($db, $page, $userId)['records']);
            return;
        }

        if (in_array($page, ['customers', 'leads', 'deals', 'tasks', 'activities'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($page === 'leads' && ($_POST['action'] ?? '') === 'convert') {
                $errors = self::convertLead($db, $userId);
            } elseif ($page === 'activities') {
                $errors = self::saveActivity($db, $userId);
            } else {
                $errors = self::saveRecord($db, $page, $userId);
            }
            if (!$errors) {
                if (!isset($_SESSION['flash'])) {
                    $_SESSION['flash'] = 'Your changes have been saved.';
                }
                self::redirect($page);
            }
        }

        if ($page === 'profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $errors = self::updateProfile($db, $userId);
            if (!$errors) {
                $_SESSION['flash'] = 'Your profile has been updated.';
                self::redirect('profile');
            }
        }

        $data = self::pageData($db, $page, $userId);
        self::renderApp($db, $page, $data, $errors, $flash);
    }

    private static function authenticate(PDO $db): array
    {
        if (!self::verifyCsrf()) {
            return ['Your session expired. Refresh the page and try again.'];
        }

        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $statement = $db->prepare('SELECT id, name, email, password FROM users WHERE email = ?');
        $statement->execute([$email]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            return ['That email and password combination was not found.'];
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        return [];
    }

    private static function register(PDO $db): array
    {
        if (!self::verifyCsrf()) {
            return ['Your session expired. Refresh the page and try again.'];
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $errors = [];
        if ($name === '' || strlen($name) > 120) {
            $errors[] = 'Please enter your name (up to 120 characters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Choose a password with at least 8 characters.';
        }
        if ($errors) {
            return $errors;
        }

        try {
            $statement = $db->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?)');
            $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        } catch (\PDOException $exception) {
            if ((string)$exception->getCode() === '23000' || (string)$exception->getCode() === '19') {
                return ['An account with that email already exists.'];
            }
            throw $exception;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$db->lastInsertId();
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        return [];
    }

    private static function saveRecord(PDO $db, string $type, int $userId): array
    {
        if (!self::verifyCsrf()) {
            return ['Your session expired. Refresh the page and try again.'];
        }

        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (($_POST['action'] ?? '') === 'delete') {
            if (!$id) {
                return ['This record could not be found.'];
            }
            $statement = $db->prepare("DELETE FROM $type WHERE id = ? AND user_id = ?");
            $statement->execute([$id, $userId]);
            if ($statement->rowCount() === 0) {
                return ['This record could not be found.'];
            }
            $_SESSION['flash'] = 'The record has been removed.';
            self::redirect($type);
        }

        $record = self::validatedRecord($db, $type, $_POST, $userId);
        if (isset($record['error'])) {
            return [$record['error']];
        }
        $fields = array_keys($record);
        if ($id) {
            $previousStatus = null;
            if ($type === 'leads' || $type === 'deals') {
                $stageColumn = $type === 'deals' ? 'stage' : 'status';
                $current = $db->prepare("SELECT $stageColumn FROM $type WHERE id = ? AND user_id = ?");
                $current->execute([$id, $userId]);
                $previousStatus = $current->fetchColumn();
                if ($previousStatus === false) {
                    return ['This record could not be found.'];
                }
            }
            $assignments = implode(', ', array_map(static fn(string $field): string => "$field = ?", $fields));
            $statement = $db->prepare("UPDATE $type SET $assignments WHERE id = ? AND user_id = ?");
            $statement->execute([...array_values($record), $id, $userId]);
            $nextStatus = $type === 'deals' ? ($record['stage'] ?? null) : ($record['status'] ?? null);
            if (($type === 'leads' || $type === 'deals') && $previousStatus !== $nextStatus) {
                self::createNotification(
                    $db,
                    $userId,
                    $type === 'deals' ? 'Deal stage updated' : 'Lead status updated',
                    ($record['name'] ?? $record['title']) . ' moved to ' . $nextStatus . '.',
                    $type,
                    (int)$id
                );
            }
        } else {
            $columns = implode(', ', $fields);
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $statement = $db->prepare("INSERT INTO $type (user_id, $columns) VALUES (?, $placeholders)");
            $statement->execute([$userId, ...array_values($record)]);
            $recordId = (int)$db->lastInsertId();
            $title = match ($type) {
                'customers' => 'New customer added',
                'leads' => 'New lead added',
                'deals' => 'New deal created',
                default => 'New task created',
            };
            $name = $record[in_array($type, ['tasks', 'deals'], true) ? 'title' : 'name'];
            self::createNotification($db, $userId, $title, $name, $type, $recordId);
        }
        return [];
    }

    private static function createNotification(
        PDO $db,
        int $userId,
        string $title,
        string $message,
        string $target,
        ?int $targetId = null
    ): void {
        $statement = $db->prepare(
            'INSERT INTO notifications (user_id, title, message, target, target_id) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$userId, $title, $message, $target, $targetId]);
    }

    private static function handleApi(PDO $db, int $userId): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        if ($userId < 1) {
            http_response_code(401);
            echo json_encode(['error' => 'Please sign in again.']);
            exit;
        }

        $action = (string)($_GET['action'] ?? '');
        if ($action === 'dashboard' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            echo json_encode(self::dashboardSnapshot($db, $userId), JSON_THROW_ON_ERROR);
            exit;
        }

        if ($action === 'notifications' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $statement = $db->prepare('SELECT id, title, message, target, target_id, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 10');
            $statement->execute([$userId]);
            $items = $statement->fetchAll(PDO::FETCH_ASSOC);
            $count = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
            $count->execute([$userId]);
            echo json_encode(['unread' => (int)$count->fetchColumn(), 'items' => $items], JSON_THROW_ON_ERROR);
            exit;
        }

        if ($action === 'mark_notification_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!self::verifyCsrf()) {
                http_response_code(403);
                echo json_encode(['error' => 'Your session expired. Refresh the page and try again.']);
                exit;
            }
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Choose a notification.']);
                exit;
            }
            $statement = $db->prepare('UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND read_at IS NULL');
            $statement->execute([$id, $userId]);
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
            exit;
        }

        if ($action === 'mark_notifications_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!self::verifyCsrf()) {
                http_response_code(403);
                echo json_encode(['error' => 'Your session expired. Refresh the page and try again.']);
                exit;
            }
            $statement = $db->prepare('UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE user_id = ? AND read_at IS NULL');
            $statement->execute([$userId]);
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
            exit;
        }

        http_response_code(404);
        echo json_encode(['error' => 'This API action was not found.']);
        exit;
    }

    private static function dashboardSnapshot(PDO $db, int $userId): array
    {
        $metrics = [];
        $queries = [
            'customers' => 'SELECT COUNT(*) FROM customers WHERE user_id = ?',
            'new_customers' => 'SELECT COUNT(*) FROM customers WHERE user_id = ? AND created_at >= ?',
            'leads' => 'SELECT COUNT(*) FROM leads WHERE user_id = ?',
            'qualified_leads' => "SELECT COUNT(*) FROM leads WHERE user_id = ? AND status = 'Qualified'",
            'won_leads' => "SELECT COUNT(*) FROM leads WHERE user_id = ? AND status = 'Won'",
            'pending_deals' => "SELECT COUNT(*) FROM leads WHERE user_id = ? AND status NOT IN ('Won', 'Lost')",
            'open_deals' => "SELECT COUNT(*) FROM deals WHERE user_id = ? AND stage NOT IN ('Won', 'Lost')",
            'completed_tasks' => "SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = 'Done'",
            'overdue_tasks' => "SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status != 'Done' AND due_date < CURRENT_DATE",
            'open_tasks' => "SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status != 'Done'",
            'total_tasks' => 'SELECT COUNT(*) FROM tasks WHERE user_id = ?',
        ];
        foreach ($queries as $key => $query) {
            $statement = $db->prepare($query);
            $statement->execute($key === 'new_customers' ? [$userId, date('Y-m-01')] : [$userId]);
            $metrics[$key] = (int)$statement->fetchColumn();
        }
        $statement = $db->prepare("SELECT COALESCE(SUM(value), 0) FROM leads WHERE user_id = ? AND status NOT IN ('Won', 'Lost')");
        $statement->execute([$userId]);
        $metrics['pipeline'] = (float)$statement->fetchColumn();
        $statement = $db->prepare("SELECT COALESCE(SUM(amount * probability / 100), 0) FROM deals WHERE user_id = ? AND stage NOT IN ('Won', 'Lost')");
        $statement->execute([$userId]);
        $metrics['deal_pipeline'] = (float)$statement->fetchColumn();
        $statement = $db->prepare("SELECT COALESCE(SUM(value), 0) FROM leads WHERE user_id = ? AND status = 'Won'");
        $statement->execute([$userId]);
        $metrics['won_value'] = (float)$statement->fetchColumn();
        $statement = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
        $statement->execute([$userId]);
        $metrics['unread_notifications'] = (int)$statement->fetchColumn();
        $metrics['conversion_rate'] = $metrics['leads'] > 0
            ? round($metrics['won_leads'] / $metrics['leads'] * 100, 1)
            : 0;
        $metrics['task_completion_rate'] = $metrics['total_tasks'] > 0
            ? round($metrics['completed_tasks'] / $metrics['total_tasks'] * 100, 1)
            : 0;
        return $metrics;
    }

    private static function convertLead(PDO $db, int $userId): array
    {
        if (!self::verifyCsrf()) {
            return ['Your session expired. Refresh the page and try again.'];
        }

        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            return ['This lead could not be found.'];
        }

        $db->beginTransaction();
        try {
            $statement = $db->prepare('SELECT * FROM leads WHERE id = ? AND user_id = ?');
            $statement->execute([$id, $userId]);
            $lead = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$lead) {
                $db->rollBack();
                return ['This lead could not be found.'];
            }
            if ($lead['status'] !== 'Won') {
                $db->rollBack();
                return ['Only won leads can be converted into customers.'];
            }
            if ($lead['converted_at'] !== null) {
                $db->rollBack();
                return ['This lead has already been converted.'];
            }

            $statement = $db->prepare(
                "INSERT INTO customers (user_id, name, company, email, phone, status)
                 VALUES (?, ?, ?, ?, '', 'Active')"
            );
            $statement->execute([$userId, $lead['name'], $lead['company'], $lead['email']]);

            $statement = $db->prepare(
                "UPDATE leads SET converted_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND user_id = ? AND status = 'Won' AND converted_at IS NULL"
            );
            $statement->execute([$id, $userId]);
            if ($statement->rowCount() !== 1) {
                $db->rollBack();
                return ['This lead has already been converted.'];
            }

            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }

        $_SESSION['flash'] = 'Won lead converted into a customer.';
        return [];
    }

    private static function exportCsv(string $page, array $records): void
    {
        $columns = match ($page) {
            'customers' => ['name', 'company', 'email', 'phone', 'status', 'created_at'],
            'leads' => ['name', 'company', 'email', 'source', 'value', 'status', 'created_at', 'converted_at'],
            'deals' => ['title', 'customer_name', 'amount', 'stage', 'probability', 'expected_close', 'created_at'],
            'tasks' => ['title', 'description', 'due_date', 'priority', 'status', 'created_at'],
            'activities' => ['type', 'subject', 'details', 'contact_name', 'happened_at', 'created_at'],
        };

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="clarity-' . $page . '-' . date('Y-m-d') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new \RuntimeException('Unable to create the CSV export.');
        }

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $columns, ',', '"', '');
        foreach ($records as $record) {
            $row = [];
            foreach ($columns as $column) {
                $value = (string)($record[$column] ?? '');
                if (preg_match('/^\s*[=+\-@]/u', $value)) {
                    $value = "'" . $value;
                }
                $row[] = $value;
            }
            fputcsv($output, $row, ',', '"', '');
        }
        fclose($output);
    }

    private static function saveActivity(PDO $db, int $userId): array
    {
        if (!self::verifyCsrf()) {
            return ['Your session expired. Refresh the page and try again.'];
        }

        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (($_POST['action'] ?? '') === 'delete') {
            if (!$id) {
                return ['This activity could not be found.'];
            }
            $statement = $db->prepare('DELETE FROM activities WHERE id = ? AND user_id = ?');
            $statement->execute([$id, $userId]);
            if ($statement->rowCount() === 0) {
                return ['This activity could not be found.'];
            }
            $_SESSION['flash'] = 'Activity removed.';
            return [];
        }

        $type = (string)($_POST['type'] ?? '');
        $subject = trim((string)($_POST['subject'] ?? ''));
        $details = trim((string)($_POST['details'] ?? ''));
        $date = trim((string)($_POST['happened_at'] ?? ''));
        if (!in_array($type, ['Note', 'Call', 'Email', 'Meeting'], true)) {
            return ['Choose a valid activity type.'];
        }
        if ($subject === '' || strlen($subject) > 160) {
            return ['Add a subject (up to 160 characters).'];
        }
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
            return ['Choose a valid activity date.'];
        }

        $contact = trim((string)($_POST['contact'] ?? ''));
        $contactType = null;
        $contactId = null;
        $contactName = '';
        if ($contact !== '') {
            if (!preg_match('/^(customer|lead):([1-9]\d*)$/', $contact, $match)) {
                return ['Choose a valid customer or lead.'];
            }
            $contactType = $match[1];
            $contactId = (int)$match[2];
            $table = $contactType === 'customer' ? 'customers' : 'leads';
            $statement = $db->prepare("SELECT name FROM $table WHERE id = ? AND user_id = ?");
            $statement->execute([$contactId, $userId]);
            $contactName = (string)$statement->fetchColumn();
            if ($contactName === '') {
                return ['That contact could not be found in your account.'];
            }
        }

        $values = [$type, $subject, $details, $contactType, $contactId, $contactName, $date];
        if ($id) {
            $statement = $db->prepare(
                'UPDATE activities SET type = ?, subject = ?, details = ?, contact_type = ?, contact_id = ?, contact_name = ?, happened_at = ? WHERE id = ? AND user_id = ?'
            );
            $statement->execute([...$values, $id, $userId]);
            if ($statement->rowCount() === 0) {
                $statement = $db->prepare('SELECT COUNT(*) FROM activities WHERE id = ? AND user_id = ?');
                $statement->execute([$id, $userId]);
                if (!(int)$statement->fetchColumn()) {
                    return ['This activity could not be found.'];
                }
            }
        } else {
            $statement = $db->prepare(
                'INSERT INTO activities (user_id, type, subject, details, contact_type, contact_id, contact_name, happened_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([$userId, ...$values]);
        }
        $_SESSION['flash'] = 'Activity saved to your timeline.';
        return [];
    }

    private static function validatedRecord(PDO $db, string $type, array $input, int $userId): array
    {
        $requiredName = trim((string)($input['name'] ?? $input['title'] ?? ''));
        if ($requiredName === '') {
            return ['error' => $type === 'tasks' ? 'A task title is required.' : 'A name is required.'];
        }

        if ($type === 'customers') {
            $email = trim((string)($input['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['error' => 'Enter a valid email address, or leave it blank.'];
            }
            $status = in_array($input['status'] ?? '', ['Active', 'Inactive'], true) ? $input['status'] : 'Active';
            return [
                'name' => $requiredName, 'company' => trim((string)($input['company'] ?? '')),
                'email' => $email, 'phone' => trim((string)($input['phone'] ?? '')), 'status' => $status,
            ];
        }

        if ($type === 'leads') {
            $email = trim((string)($input['email'] ?? ''));
            $value = filter_var($input['value'] ?? 0, FILTER_VALIDATE_FLOAT);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['error' => 'Enter a valid email address, or leave it blank.'];
            }
            if ($value === false || $value < 0) {
                return ['error' => 'Lead value must be a positive number.'];
            }
            $status = in_array($input['status'] ?? '', ['New', 'Contacted', 'Qualified', 'Won', 'Lost'], true)
                ? $input['status'] : 'New';
            return [
                'name' => $requiredName, 'company' => trim((string)($input['company'] ?? '')),
                'email' => $email, 'source' => trim((string)($input['source'] ?? '')),
                'value' => $value, 'status' => $status,
            ];
        }

        if ($type === 'deals') {
            $amount = filter_var($input['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
            $probability = filter_var($input['probability'] ?? 10, FILTER_VALIDATE_INT);
            $expectedClose = trim((string)($input['expected_close'] ?? ''));
            if ($amount === false || $amount < 0) {
                return ['error' => 'Deal value must be a positive number.'];
            }
            if ($probability === false || $probability < 0 || $probability > 100) {
                return ['error' => 'Probability must be between 0 and 100.'];
            }
            if ($expectedClose !== '') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $expectedClose);
                if (!$date || $date->format('Y-m-d') !== $expectedClose) {
                    return ['error' => 'Enter a valid expected close date.'];
                }
            }

            $customerId = filter_var($input['customer_id'] ?? '', FILTER_VALIDATE_INT);
            $customerId = $customerId ?: null;
            $customerName = '';
            if ($customerId !== null) {
                $statement = $db->prepare('SELECT name FROM customers WHERE id = ? AND user_id = ?');
                $statement->execute([$customerId, $userId]);
                $customerName = (string)$statement->fetchColumn();
                if ($customerName === '') {
                    return ['error' => 'Choose a customer from your account.'];
                }
            }

            return [
                'title' => $requiredName,
                'customer_id' => $customerId,
                'customer_name' => $customerName,
                'amount' => $amount,
                'stage' => in_array($input['stage'] ?? '', ['New', 'Contacted', 'Qualified', 'Proposal', 'Negotiation', 'Won', 'Lost'], true) ? $input['stage'] : 'New',
                'probability' => $probability,
                'expected_close' => $expectedClose !== '' ? $expectedClose : null,
            ];
        }

        $dueDate = trim((string)($input['due_date'] ?? ''));
        if ($dueDate !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
            if (!$date || $date->format('Y-m-d') !== $dueDate) {
                return ['error' => 'Enter a valid due date.'];
            }
        }
        return [
            'title' => $requiredName,
            'description' => trim((string)($input['description'] ?? '')),
            'due_date' => $dueDate !== '' ? $dueDate : null,
            'priority' => in_array($input['priority'] ?? '', ['Low', 'Normal', 'High'], true) ? $input['priority'] : 'Normal',
            'status' => in_array($input['status'] ?? '', ['To do', 'In progress', 'Done'], true) ? $input['status'] : 'To do',
        ];
    }

    private static function updateProfile(PDO $db, int $userId): array
    {
        if (!self::verifyCsrf()) {
            return ['Your session expired. Refresh the page and try again.'];
        }
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        if ($name === '' || strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['Enter a valid name and email address.'];
        }
        try {
            if ($password !== '') {
                if (strlen($password) < 8) {
                    return ['Your new password must have at least 8 characters.'];
                }
                $statement = $db->prepare('UPDATE users SET name = ?, email = ?, password = ? WHERE id = ?');
                $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $userId]);
            } else {
                $statement = $db->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
                $statement->execute([$name, $email, $userId]);
            }
        } catch (\PDOException $exception) {
            if ((string)$exception->getCode() === '23000' || (string)$exception->getCode() === '19') {
                return ['That email address is already in use.'];
            }
            throw $exception;
        }
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        return [];
    }

    private static function pageData(PDO $db, string $page, int $userId): array
    {
        if ($page === 'dashboard') {
            $counts = [];
            foreach (['customers', 'tasks'] as $table) {
                $statement = $db->prepare("SELECT COUNT(*) FROM $table WHERE user_id = ?");
                $statement->execute([$userId]);
                $counts[$table] = (int)$statement->fetchColumn();
            }
            $statement = $db->prepare("SELECT COUNT(*) FROM leads WHERE user_id = ? AND status NOT IN ('Won', 'Lost')");
            $statement->execute([$userId]);
            $counts['leads'] = (int)$statement->fetchColumn();
            $statement = $db->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status != 'Done'");
            $statement->execute([$userId]);
            $counts['open_tasks'] = (int)$statement->fetchColumn();
            $statement = $db->prepare('SELECT COUNT(*) FROM customers WHERE user_id = ? AND created_at >= ?');
            $statement->execute([$userId, date('Y-m-01')]);
            $counts['new_customers'] = (int)$statement->fetchColumn();
            $statement = $db->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status != 'Done' AND due_date < CURRENT_DATE");
            $statement->execute([$userId]);
            $counts['overdue_tasks'] = (int)$statement->fetchColumn();
            $statement = $db->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = 'Done'");
            $statement->execute([$userId]);
            $counts['completed_tasks'] = (int)$statement->fetchColumn();
            $statement = $db->prepare("SELECT COALESCE(SUM(value), 0) FROM leads WHERE user_id = ? AND status NOT IN ('Won', 'Lost')");
            $statement->execute([$userId]);
            $counts['pipeline'] = (float)$statement->fetchColumn();
            $statement = $db->prepare("SELECT COUNT(*) FROM deals WHERE user_id = ? AND stage NOT IN ('Won', 'Lost')");
            $statement->execute([$userId]);
            $counts['deals'] = (int)$statement->fetchColumn();
            $statement = $db->prepare("SELECT COALESCE(SUM(amount * probability / 100), 0) FROM deals WHERE user_id = ? AND stage NOT IN ('Won', 'Lost')");
            $statement->execute([$userId]);
            $counts['deal_pipeline'] = (float)$statement->fetchColumn();
            $statement = $db->prepare("SELECT * FROM tasks WHERE user_id = ? ORDER BY (due_date IS NULL), due_date ASC, created_at DESC LIMIT 5");
            $statement->execute([$userId]);
            $counts['recent_tasks'] = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement = $db->prepare("SELECT * FROM leads WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
            $statement->execute([$userId]);
            $counts['recent_leads'] = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement = $db->prepare('SELECT * FROM activities WHERE user_id = ? ORDER BY happened_at DESC, id DESC LIMIT 5');
            $statement->execute([$userId]);
            $counts['recent_activities'] = $statement->fetchAll(PDO::FETCH_ASSOC);
            return $counts;
        }

        if ($page === 'reports') {
            $summary = [];
            foreach (['Active', 'Inactive'] as $status) {
                $statement = $db->prepare('SELECT COUNT(*) FROM customers WHERE user_id = ? AND status = ?');
                $statement->execute([$userId, $status]);
                $summary['customers'][$status] = (int)$statement->fetchColumn();
            }
            foreach (['New', 'Contacted', 'Qualified', 'Won', 'Lost'] as $status) {
                $statement = $db->prepare('SELECT COUNT(*) FROM leads WHERE user_id = ? AND status = ?');
                $statement->execute([$userId, $status]);
                $summary['leads'][$status] = (int)$statement->fetchColumn();
            }
            foreach (['New', 'Contacted', 'Qualified', 'Proposal', 'Negotiation', 'Won', 'Lost'] as $stage) {
                $statement = $db->prepare('SELECT COUNT(*) FROM deals WHERE user_id = ? AND stage = ?');
                $statement->execute([$userId, $stage]);
                $summary['deals'][$stage] = (int)$statement->fetchColumn();
            }
            $statement = $db->prepare("SELECT COALESCE(SUM(amount * probability / 100), 0) FROM deals WHERE user_id = ? AND stage NOT IN ('Won', 'Lost')");
            $statement->execute([$userId]);
            $summary['deal_pipeline'] = (float)$statement->fetchColumn();
            $statement = $db->prepare('SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = ?');
            $statement->execute([$userId, 'Done']);
            $summary['completed_tasks'] = (int)$statement->fetchColumn();
            $statement = $db->prepare("SELECT COALESCE(SUM(value), 0) FROM leads WHERE user_id = ? AND status NOT IN ('Won', 'Lost')");
            $statement->execute([$userId]);
            $summary['total_pipeline'] = (float)$statement->fetchColumn();
            return $summary;
        }

        if ($page === 'profile') {
            $statement = $db->prepare('SELECT name, email, created_at FROM users WHERE id = ?');
            $statement->execute([$userId]);
            return ['user' => $statement->fetch(PDO::FETCH_ASSOC)];
        }

        $query = trim((string)($_GET['q'] ?? ''));
        $search = '%' . $query . '%';
        if ($page === 'activities') {
            $statement = $db->prepare(
                'SELECT * FROM activities WHERE user_id = ? AND (subject LIKE ? OR details LIKE ? OR contact_name LIKE ? OR type LIKE ?) ORDER BY happened_at DESC, id DESC'
            );
            $statement->execute([$userId, $search, $search, $search, $search]);
            $records = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement = $db->prepare('SELECT id, name, company FROM customers WHERE user_id = ? ORDER BY name');
            $statement->execute([$userId]);
            $customers = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement = $db->prepare('SELECT id, name, company FROM leads WHERE user_id = ? ORDER BY name');
            $statement->execute([$userId]);
            $leads = $statement->fetchAll(PDO::FETCH_ASSOC);
            return ['records' => $records, 'query' => $query, 'customers' => $customers, 'leads' => $leads];
        }

        if ($page === 'deals') {
            $statement = $db->prepare(
                'SELECT * FROM deals WHERE user_id = ? AND (title LIKE ? OR customer_name LIKE ? OR stage LIKE ?) ORDER BY (stage IN (\'Won\', \'Lost\')), expected_close IS NULL, expected_close ASC, created_at DESC'
            );
            $statement->execute([$userId, $search, $search, $search]);
            $records = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement = $db->prepare('SELECT id, name, company FROM customers WHERE user_id = ? ORDER BY name');
            $statement->execute([$userId]);
            return ['records' => $records, 'query' => $query, 'customers' => $statement->fetchAll(PDO::FETCH_ASSOC)];
        }

        if ($page === 'customers') {
            $statement = $db->prepare('SELECT * FROM customers WHERE user_id = ? AND (name LIKE ? OR company LIKE ? OR email LIKE ?) ORDER BY created_at DESC');
            $statement->execute([$userId, $search, $search, $search]);
        } elseif ($page === 'leads') {
            $statement = $db->prepare('SELECT * FROM leads WHERE user_id = ? AND (name LIKE ? OR company LIKE ? OR email LIKE ?) ORDER BY created_at DESC');
            $statement->execute([$userId, $search, $search, $search]);
        } else {
            $statement = $db->prepare('SELECT * FROM tasks WHERE user_id = ? AND (title LIKE ? OR description LIKE ?) ORDER BY (status = \'Done\'), (due_date IS NULL), due_date ASC, created_at DESC');
            $statement->execute([$userId, $search, $search]);
        }
        return ['records' => $statement->fetchAll(PDO::FETCH_ASSOC), 'query' => $query];
    }

    private static function renderApp(PDO $db, string $page, array $data, array $errors, ?string $flash): void
    {
        $userName = (string)($_SESSION['user_name'] ?? 'there');
        $title = self::NAVIGATION[$page][0];
        $description = [
            'dashboard' => 'A clear picture of your customer relationships.',
            'customers' => 'Keep every customer detail in one easy-to-find place.',
            'leads' => 'Track opportunities from first contact to close.',
            'deals' => 'Track deal stages, expected close dates, and pipeline value.',
            'tasks' => 'Stay on top of the work that moves relationships forward.',
            'activities' => 'Keep a searchable history of every customer conversation.',
            'reports' => 'A quick, simple snapshot of your business activity.',
            'profile' => 'Manage your account details and password.',
        ][$page];
        ?><!doctype html>
        <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= self::e($title) ?> · Clarity CRM</title>
<meta name="csrf-token" content="<?= self::e(self::csrfToken()) ?>"><link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>"></head><body>
<div class="app-shell"><aside class="sidebar" id="sidebar"><a class="brand" href="?page=dashboard"><span class="brand-mark">c</span><span>clarity<span class="brand-sub">CUSTOMER RELATIONSHIPS</span></span></a>
<div class="nav-label">WORKSPACE</div><nav><?php foreach (self::NAVIGATION as $key => [$label, $icon]): ?>
<a class="nav-link <?= $page === $key ? 'active' : '' ?>" href="?page=<?= self::e($key) ?>"><span class="nav-icon"><?= self::e($icon) ?></span><?= self::e($label) ?><?= $key === 'tasks' && ($data['open_tasks'] ?? 0) > 0 ? '<span class="nav-count">' . (int)$data['open_tasks'] . '</span>' : ($key === 'deals' && ($data['deals'] ?? 0) > 0 ? '<span class="nav-count">' . (int)$data['deals'] . '</span>' : '') ?></a>
<?php endforeach; ?></nav><div class="sidebar-bottom"><div class="sidebar-note"><span class="note-icon">✦</span><strong>A little more clarity.</strong><p>Your customer details and tasks, together in one calm workspace.</p></div>
<div class="user-block"><span class="avatar"><?= self::e(self::initials($userName)) ?></span><span class="user-meta"><strong><?= self::e($userName) ?></strong><small><?= self::e((string)($_SESSION['user_email'] ?? '')) ?></small></span><form action="?page=logout" method="post"><input type="hidden" name="csrf" value="<?= self::e(self::csrfToken()) ?>"><button class="icon-button logout-button" title="Sign out" aria-label="Sign out">↗</button></form></div></div></aside>
<main class="main-content"><header class="topbar"><button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation" aria-controls="sidebar" aria-expanded="false">☰</button><div class="breadcrumb">Workspace <span>/</span> <strong><?= self::e($title) ?></strong></div><div class="topbar-right"><span class="today"><?= date('D, M j') ?></span><div class="notification-wrap"><button class="notification-toggle" id="notificationToggle" aria-label="Notifications" aria-expanded="false" aria-controls="notificationPanel">♧<span class="notification-count" id="notificationCount" hidden>0</span></button><section class="notification-panel" id="notificationPanel" hidden><div class="notification-heading"><div><strong>Notifications</strong><small id="notificationSummary">Your latest updates</small></div><button class="text-link mark-all-read" id="markAllRead" type="button">Mark all read</button></div><div class="notification-list" id="notificationList"><div class="notification-loading">Loading your notifications…</div></div><div class="notification-error" id="notificationError" role="status" hidden></div></section></div><a class="top-avatar" href="?page=profile" title="My profile"><?= self::e(self::initials($userName)) ?></a></div></header>
<section class="page-content"><div class="page-heading"><div><div class="eyebrow"><?= date('l, F j, Y') ?></div><h1><?= $page === 'dashboard' ? 'Good ' . self::greeting() . ', ' . self::e(explode(' ', $userName)[0]) . '.' : self::e($title) ?></h1><p><?= self::e($description) ?></p></div><?php if (in_array($page, ['customers', 'leads', 'deals', 'tasks', 'activities'], true)): ?><a class="button button-primary" href="#create-record"><span>＋</span> <?= $page === 'activities' ? 'Log activity' : 'Add ' . self::e(rtrim($page, 's')) ?></a><?php endif; ?></div>
<?php if ($flash): ?><div class="notice notice-success"><?= self::e($flash) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="notice notice-error"><?php foreach ($errors as $error): ?><div><?= self::e($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php self::renderContent($db, $page, $data); ?>
</section></main></div><script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>" defer></script></body></html><?php
    }

    private static function renderContent(PDO $db, string $page, array $data): void
    {
        if ($page === 'dashboard') {
            ?><div class="stats-grid">
<article class="stat-card"><div class="stat-top"><span>Total customers</span><span class="stat-icon purple">◉</span></div><strong data-live-stat="customers"><?= (int)$data['customers'] ?></strong><small><span data-live-stat="new_customers"><?= (int)$data['new_customers'] ?></span> new this month</small></article>
<article class="stat-card"><div class="stat-top"><span>Open deals</span><span class="stat-icon amber">◇</span></div><strong data-live-stat="open_deals"><?= (int)$data['deals'] ?></strong><small><span data-live-money="deal_pipeline"><?= self::money((float)$data['deal_pipeline']) ?></span> weighted pipeline</small></article>
<article class="stat-card"><div class="stat-top"><span>Tasks to finish</span><span class="stat-icon green">✓</span></div><strong data-live-stat="open_tasks"><?= (int)$data['open_tasks'] ?></strong><small><span data-live-stat="overdue_tasks"><?= (int)$data['overdue_tasks'] ?></span> overdue · <span data-live-stat="completed_tasks"><?= (int)$data['completed_tasks'] ?></span> completed</small></article>
</div><div class="dashboard-grid"><section class="panel"><div class="panel-heading"><div><h2>Upcoming tasks</h2><p>Your next steps, all in one place</p></div><a class="text-link" href="?page=tasks">View all <span>→</span></a></div>
<?php if (!$data['recent_tasks']): ?><div class="empty-state"><span class="empty-icon">✓</span><strong>Your task list is clear</strong><p>Add a follow-up or reminder to get started.</p><a class="text-link" href="?page=tasks">Create your first task →</a></div><?php else: ?><div class="task-list"><?php foreach ($data['recent_tasks'] as $task): ?><div class="task-row"><span class="task-check <?= $task['status'] === 'Done' ? 'checked' : '' ?>"><?= $task['status'] === 'Done' ? '✓' : '' ?></span><div class="task-info"><strong><?= self::e($task['title']) ?></strong><small><?= $task['due_date'] ? 'Due ' . self::e(date('M j', strtotime($task['due_date']))) : 'No due date' ?></small></div><span class="badge <?= self::statusClass($task['status']) ?>"><?= self::e($task['status']) ?></span></div><?php endforeach; ?></div><?php endif; ?>
</section><section class="panel"><div class="panel-heading"><div><h2>Recent leads</h2><p>Keep new conversations moving</p></div><a class="text-link" href="?page=leads">View all <span>→</span></a></div>
<?php if (!$data['recent_leads']): ?><div class="empty-state"><span class="empty-icon">◇</span><strong>No leads yet</strong><p>Capture your next opportunity and track its progress.</p><a class="text-link" href="?page=leads">Add your first lead →</a></div><?php else: ?><div class="lead-list"><?php foreach ($data['recent_leads'] as $lead): ?><div class="lead-row"><span class="avatar avatar-soft"><?= self::e(self::initials($lead['name'])) ?></span><div class="lead-info"><strong><?= self::e($lead['name']) ?></strong><small><?= self::e($lead['company'] ?: $lead['email'] ?: 'New contact') ?></small></div><div class="lead-value"><strong><?= self::money((float)$lead['value']) ?></strong><span class="badge <?= self::statusClass($lead['status']) ?>"><?= self::e($lead['status']) ?></span></div></div><?php endforeach; ?></div><?php endif; ?>
</section></div><section class="panel activity-preview"><div class="panel-heading"><div><h2>Recent activity</h2><p>Your latest customer conversations</p></div><a class="text-link" href="?page=activities">View timeline <span>→</span></a></div><?php self::renderActivityItems($data['recent_activities'], true); ?></section><section class="welcome-banner"><span class="welcome-spark">✦</span><div><strong>Good relationships start with good follow-ups.</strong><p>Make today a little easier: choose one lead or customer and take the next small step.</p></div><a class="button button-light" href="?page=tasks">Plan a follow-up <span>→</span></a></section><?php
            return;
        }

        if ($page === 'reports') {
            $totalLeads = array_sum($data['leads']);
            ?><div class="stats-grid report-stats"><article class="stat-card"><div class="stat-top"><span>Customers</span><span class="stat-icon purple">◉</span></div><strong><?= array_sum($data['customers']) ?></strong><small><?= $data['customers']['Active'] ?> active · <?= $data['customers']['Inactive'] ?> inactive</small></article><article class="stat-card"><div class="stat-top"><span>Lead pipeline</span><span class="stat-icon amber">◇</span></div><strong><?= self::money((float)$data['total_pipeline']) ?></strong><small><?= $totalLeads ?> tracked leads</small></article><article class="stat-card"><div class="stat-top"><span>Weighted deal pipeline</span><span class="stat-icon amber">▧</span></div><strong><?= self::money((float)$data['deal_pipeline']) ?></strong><small><?= array_sum($data['deals']) ?> tracked deals</small></article><article class="stat-card"><div class="stat-top"><span>Tasks completed</span><span class="stat-icon green">✓</span></div><strong><?= (int)$data['completed_tasks'] ?></strong><small>Completed tasks to date</small></article></div>
<div class="report-grid"><section class="panel report-panel"><div class="panel-heading"><div><h2>Lead status</h2><p>See how opportunities are progressing</p></div></div><div class="report-bars"><?php foreach ($data['leads'] as $status => $count): $percent = $totalLeads ? round($count / $totalLeads * 100) : 0; ?><div class="report-row"><div class="report-label"><span><?= self::e($status) ?></span><strong><?= (int)$count ?></strong></div><div class="bar-track"><span class="bar-fill <?= self::statusClass($status) ?>" style="width:<?= $percent ?>%"></span></div></div><?php endforeach; ?></div></section><section class="panel report-panel"><div class="panel-heading"><div><h2>Deal pipeline</h2><p>Weighted open opportunity value by stage</p></div><a class="text-link" href="?page=deals">Manage deals →</a></div><div class="report-bars"><?php $dealCount = array_sum($data['deals']); foreach ($data['deals'] as $stage => $count): $percent = $dealCount ? round($count / $dealCount * 100) : 0; ?><div class="report-row"><div class="report-label"><span><?= self::e($stage) ?></span><strong><?= (int)$count ?></strong></div><div class="bar-track"><span class="bar-fill <?= self::statusClass($stage) ?>" style="width:<?= $percent ?>%"></span></div></div><?php endforeach; ?></div></section></div><?php
            return;
        }

        if ($page === 'profile') {
            $user = $data['user'] ?: [];
            ?><section class="panel form-panel profile-panel"><div class="panel-heading"><div><h2>Account details</h2><p>Update how you appear in your workspace.</p></div></div><form method="post" class="record-form profile-form"><input type="hidden" name="csrf" value="<?= self::e(self::csrfToken()) ?>"><label>Full name<input name="name" required maxlength="120" value="<?= self::e($user['name'] ?? '') ?>"></label><label>Email address<input name="email" type="email" required value="<?= self::e($user['email'] ?? '') ?>"></label><label>New password <span class="optional">(leave blank to keep your current password)</span><input name="password" type="password" minlength="8" autocomplete="new-password"></label><button class="button button-primary form-submit" type="submit">Save changes</button></form></section><?php
            return;
        }

        if ($page === 'activities') {
            self::renderActivities($db, $data);
            return;
        }

        self::renderRecords($db, $page, $data);
    }

    private static function renderActivities(PDO $db, array $data): void
    {
        $selected = null;
        $editing = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
        if ($editing) {
            $query = $db->prepare('SELECT * FROM activities WHERE id = ? AND user_id = ?');
            $query->execute([$editing, (int)$_SESSION['user_id']]);
            $selected = $query->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        ?><div class="records-layout activity-layout">
<section class="panel activity-panel"><div class="panel-heading"><div><h2><?= count($data['records']) ?> <?= count($data['records']) === 1 ? 'interaction' : 'interactions' ?></h2><p><?= $data['query'] !== '' ? 'Matching “' . self::e($data['query']) . '”' : 'Notes and conversations in one place.' ?></p></div><div class="record-tools"><form class="search-form" method="get"><input type="hidden" name="page" value="activities"><input name="q" value="<?= self::e($data['query']) ?>" placeholder="Search activity…" aria-label="Search activity"><button aria-label="Search">⌕</button></form><a class="button button-secondary export-link" href="?page=activities&amp;export=csv&amp;q=<?= rawurlencode($data['query']) ?>">↓ Export CSV</a></div></div>
<?php if (!$data['records'] && $data['query'] !== ''): ?><div class="empty-state"><span class="empty-icon">◷</span><strong>No matching activity</strong><p>Try a different search or clear the search to see your full timeline.</p><a class="text-link" href="?page=activities">Clear search →</a></div><?php else: self::renderActivityItems($data['records']); endif; ?></section>
<section class="panel form-panel" id="create-record"><div class="panel-heading"><div><h2><?= $selected ? 'Edit activity' : 'Log an interaction' ?></h2><p>Capture the details while they’re fresh.</p></div><?php if ($selected): ?><a class="action-link" href="?page=activities" aria-label="Cancel editing">×</a><?php endif; ?></div>
<form method="post" class="record-form"><input type="hidden" name="csrf" value="<?= self::e(self::csrfToken()) ?>"><?php if ($selected): ?><input type="hidden" name="id" value="<?= (int)$selected['id'] ?>"><?php endif; ?>
<label>Type<select name="type" required><?php foreach (['Note', 'Call', 'Email', 'Meeting'] as $type): ?><option value="<?= self::e($type) ?>" <?= ($selected['type'] ?? 'Note') === $type ? 'selected' : '' ?>><?= self::e($type) ?></option><?php endforeach; ?></select></label>
<label>Subject<input name="subject" required maxlength="160" placeholder="What happened?" value="<?= self::e($selected['subject'] ?? '') ?>"></label>
<label>Customer or lead <span class="optional">Optional</span><select name="contact"><option value="">No linked contact</option><?php foreach (['Customers' => $data['customers'], 'Leads' => $data['leads']] as $group => $contacts): ?><optgroup label="<?= self::e($group) ?>"><?php foreach ($contacts as $contact): $contactType = $group === 'Customers' ? 'customer' : 'lead'; $contactValue = $contactType . ':' . $contact['id']; ?><option value="<?= self::e($contactValue) ?>" <?= ($selected && $selected['contact_type'] === $contactType && (int)$selected['contact_id'] === (int)$contact['id']) ? 'selected' : '' ?>><?= self::e($contact['name'] . ($contact['company'] ? ' · ' . $contact['company'] : '')) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></label>
<label>Date<input type="date" name="happened_at" required value="<?= self::e($selected['happened_at'] ?? date('Y-m-d')) ?>"></label>
<label>Details <span class="optional">Optional</span><textarea name="details" rows="4" placeholder="Add helpful context or next steps…"><?= self::e($selected['details'] ?? '') ?></textarea></label>
<button class="button button-primary form-submit" type="submit"><?= $selected ? 'Save activity' : 'Add to timeline' ?></button><?php if ($selected): ?><a class="cancel-link" href="?page=activities">Cancel</a><?php endif; ?></form></section></div><?php
    }

    private static function renderActivityItems(array $activities, bool $readOnly = false): void
    {
        if (!$activities) {
            ?><div class="empty-state"><span class="empty-icon">◷</span><strong>No activity recorded yet</strong><p>Log a call, email, meeting, or note to start building your timeline.</p><a class="text-link" href="?page=activities">Log your first interaction →</a></div><?php
            return;
        }

        ?><div class="activity-timeline"><?php foreach ($activities as $activity): $typeClass = strtolower($activity['type']); ?><article class="activity-item"><span class="activity-marker <?= self::e($typeClass) ?>"><?= match ($activity['type']) { 'Call' => '☎', 'Email' => '✉', 'Meeting' => '◷', default => '✎' } ?></span><div class="activity-body"><div class="activity-meta"><span class="activity-type <?= self::e($typeClass) ?>"><?= self::e($activity['type']) ?></span><time datetime="<?= self::e($activity['happened_at']) ?>"><?= self::e(date('M j, Y', strtotime($activity['happened_at']))) ?></time></div><h3><?= self::e($activity['subject']) ?></h3><?php if ($activity['contact_name'] !== ''): ?><a class="activity-contact" href="?page=<?= $activity['contact_type'] === 'lead' ? 'leads' : 'customers' ?>&amp;q=<?= rawurlencode($activity['contact_name']) ?>"><?= self::e($activity['contact_type'] === 'lead' ? 'Lead' : 'Customer') ?> · <?= self::e($activity['contact_name']) ?></a><?php endif; ?><?php if ($activity['details'] !== ''): ?><p class="activity-details"><?= nl2br(self::e($activity['details'])) ?></p><?php endif; ?></div><?php if (!$readOnly): ?><div class="activity-actions"><a class="action-link" href="?page=activities&amp;edit=<?= (int)$activity['id'] ?>#create-record" title="Edit activity">✎</a><form method="post" onsubmit="return confirm('Remove this activity from the timeline?');"><input type="hidden" name="csrf" value="<?= self::e(self::csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$activity['id'] ?>"><input type="hidden" name="action" value="delete"><button class="action-link delete-link" title="Remove activity" aria-label="Remove activity">×</button></form></div><?php endif; ?></article><?php endforeach; ?></div><?php
    }

    private static function renderRecords(PDO $db, string $page, array $data): void
    {
        $label = rtrim($page, 's');
        $editing = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
        $selected = null;
        if ($editing) {
            $statement = $db->prepare("SELECT * FROM $page WHERE id = ? AND user_id = ?");
            $statement->execute([$editing, (int)$_SESSION['user_id']]);
            $selected = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        ?><div class="records-layout"><section class="panel record-panel"><div class="panel-heading"><div><h2><?= count($data['records']) ?> <?= count($data['records']) === 1 ? self::e($label) : self::e($page) ?></h2><p><?= $data['query'] !== '' ? 'Matching “' . self::e($data['query']) . '”' : 'Your workspace, always up to date.' ?></p></div><div class="record-tools"><form class="search-form" method="get"><input type="hidden" name="page" value="<?= self::e($page) ?>"><input name="q" value="<?= self::e($data['query']) ?>" placeholder="Search <?= self::e($page) ?>…" aria-label="Search <?= self::e($page) ?>"><button aria-label="Search">⌕</button></form><a class="button button-secondary export-link" href="?page=<?= self::e($page) ?>&amp;export=csv&amp;q=<?= rawurlencode($data['query']) ?>">↓ Export CSV</a></div></div>
<?php if (!$data['records']): ?><div class="empty-state"><span class="empty-icon"><?= self::e(self::NAVIGATION[$page][1]) ?></span><strong><?= $data['query'] !== '' ? 'No matches found' : 'Nothing here yet' ?></strong><p><?= $data['query'] !== '' ? 'Try another search, or clear your search to see all records.' : 'Add your first ' . self::e($label) . ' to get your workspace going.' ?></p><?php if ($data['query'] !== ''): ?><a class="text-link" href="?page=<?= self::e($page) ?>">Clear search →</a><?php endif; ?></div><?php else: ?>
<div class="table-wrap"><table><thead><tr><?php if ($page === 'customers'): ?><th>Customer</th><th>Contact</th><th>Status</th><?php elseif ($page === 'leads'): ?><th>Lead</th><th>Value</th><th>Status</th><th>Source</th><?php elseif ($page === 'deals'): ?><th>Opportunity</th><th>Value</th><th>Stage</th><th>Probability</th><th>Expected close</th><?php else: ?><th>Task</th><th>Due date</th><th>Priority</th><th>Status</th><?php endif; ?><th></th></tr></thead><tbody>
<?php foreach ($data['records'] as $record): ?><tr>
<?php if ($page === 'customers'): ?><td><div class="table-person"><span class="avatar avatar-soft"><?= self::e(self::initials($record['name'])) ?></span><span><strong><?= self::e($record['name']) ?></strong><small><?= self::e($record['company'] ?: 'No company') ?></small></span></div></td><td><span class="contact-primary"><?= self::e($record['email'] ?: '—') ?></span><small class="contact-secondary"><?= self::e($record['phone'] ?: '') ?></small></td><td><span class="badge <?= self::statusClass($record['status']) ?>"><?= self::e($record['status']) ?></span></td>
<?php elseif ($page === 'leads'): ?><td><div class="table-person"><span class="avatar avatar-soft"><?= self::e(self::initials($record['name'])) ?></span><span><strong><?= self::e($record['name']) ?></strong><small><?= self::e($record['company'] ?: $record['email'] ?: 'No company') ?></small></span></div></td><td class="money-cell"><?= self::money((float)$record['value']) ?></td><td><span class="badge <?= self::statusClass($record['status']) ?>"><?= self::e($record['status']) ?></span></td><td><?= self::e($record['source'] ?: '—') ?></td>
<?php elseif ($page === 'deals'): ?><td><div class="task-title-cell"><strong><?= self::e($record['title']) ?></strong><small><?= self::e($record['customer_name'] ?: 'No linked customer') ?></small></div></td><td class="money-cell"><?= self::money((float)$record['amount']) ?></td><td><span class="badge <?= self::statusClass($record['stage']) ?>"><?= self::e($record['stage']) ?></span></td><td><span class="probability-value"><?= (int)$record['probability'] ?>%</span></td><td><?= $record['expected_close'] ? self::e(date('M j, Y', strtotime($record['expected_close']))) : '—' ?></td>
<?php else: ?><td><div class="task-title-cell"><strong><?= self::e($record['title']) ?></strong><?php if ($record['description']): ?><small><?= self::e($record['description']) ?></small><?php endif; ?></div></td><td><?= $record['due_date'] ? self::e(date('M j, Y', strtotime($record['due_date']))) : '—' ?></td><td><span class="priority <?= strtolower(self::e($record['priority'])) ?>"><?= self::e($record['priority']) ?></span></td><td><span class="badge <?= self::statusClass($record['status']) ?>"><?= self::e($record['status']) ?></span></td><?php endif; ?>
<td><div class="row-actions"><?php if ($page === 'leads' && $record['status'] === 'Won' && $record['converted_at'] === null): ?><form method="post" onsubmit="return confirm('Add this won lead to your customers?');"><input type="hidden" name="csrf" value="<?= self::e(self::csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$record['id'] ?>"><input type="hidden" name="action" value="convert"><button class="button button-secondary convert-button" type="submit">Convert</button></form><?php elseif ($page === 'leads' && $record['converted_at'] !== null): ?><span class="badge badge-green" title="This lead is already in your customers">Customer</span><?php endif; ?><a class="action-link" href="?page=<?= self::e($page) ?>&edit=<?= (int)$record['id'] ?>#create-record" title="Edit">✎</a><form method="post" onsubmit="return confirm('Remove this <?= self::e($label) ?>? This cannot be undone.');"><input type="hidden" name="csrf" value="<?= self::e(self::csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$record['id'] ?>"><input type="hidden" name="action" value="delete"><button class="action-link delete-link" title="Remove" aria-label="Remove">×</button></form></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<section class="panel form-panel" id="create-record"><div class="panel-heading"><div><h2><?= $selected ? 'Edit ' . self::e($label) : 'Add a ' . self::e($label) ?></h2><p><?= $selected ? 'Update this record’s details.' : 'A few details are all you need to get started.' ?></p></div><?php if ($selected): ?><a class="action-link" href="?page=<?= self::e($page) ?>" aria-label="Cancel editing">×</a><?php endif; ?></div>
<form method="post" class="record-form"><input type="hidden" name="csrf" value="<?= self::e(self::csrfToken()) ?>"><?php if ($selected): ?><input type="hidden" name="id" value="<?= (int)$selected['id'] ?>"><?php endif; ?>
<?php if ($page === 'customers'): ?><label>Full name<input name="name" required maxlength="160" placeholder="e.g. Jamie Parker" value="<?= self::e($selected['name'] ?? '') ?>"></label><label>Company <span class="optional">Optional</span><input name="company" maxlength="160" placeholder="Company name" value="<?= self::e($selected['company'] ?? '') ?>"></label><label>Email address <span class="optional">Optional</span><input name="email" type="email" placeholder="name@company.com" value="<?= self::e($selected['email'] ?? '') ?>"></label><label>Phone number <span class="optional">Optional</span><input name="phone" type="tel" placeholder="+1 (555) 000-0000" value="<?= self::e($selected['phone'] ?? '') ?>"></label><label>Status<select name="status"><?php foreach (['Active', 'Inactive'] as $option): ?><option <?= ($selected['status'] ?? 'Active') === $option ? 'selected' : '' ?>><?= self::e($option) ?></option><?php endforeach; ?></select></label>
<?php elseif ($page === 'leads'): ?><label>Contact name<input name="name" required maxlength="160" placeholder="e.g. Taylor Morgan" value="<?= self::e($selected['name'] ?? '') ?>"></label><label>Company <span class="optional">Optional</span><input name="company" placeholder="Company name" value="<?= self::e($selected['company'] ?? '') ?>"></label><label>Email address <span class="optional">Optional</span><input name="email" type="email" placeholder="name@company.com" value="<?= self::e($selected['email'] ?? '') ?>"></label><label>Potential value<input name="value" type="number" step="0.01" min="0" placeholder="0.00" value="<?= self::e((string)($selected['value'] ?? '0.00')) ?>"></label><label>Lead source <span class="optional">Optional</span><input name="source" placeholder="Referral, website…" value="<?= self::e($selected['source'] ?? '') ?>"></label><label>Status<select name="status"><?php foreach (['New', 'Contacted', 'Qualified', 'Won', 'Lost'] as $option): ?><option <?= ($selected['status'] ?? 'New') === $option ? 'selected' : '' ?>><?= self::e($option) ?></option><?php endforeach; ?></select></label>
<?php elseif ($page === 'deals'): ?><label>Deal name<input name="name" required maxlength="180" placeholder="e.g. Annual service renewal" value="<?= self::e($selected['title'] ?? '') ?>"></label><label>Customer <span class="optional">Optional</span><select name="customer_id"><option value="">No linked customer</option><?php foreach ($data['customers'] as $customer): ?><option value="<?= (int)$customer['id'] ?>" <?= (int)($selected['customer_id'] ?? 0) === (int)$customer['id'] ? 'selected' : '' ?>><?= self::e($customer['name'] . ($customer['company'] ? ' · ' . $customer['company'] : '')) ?></option><?php endforeach; ?></select></label><label>Deal value<input name="amount" type="number" min="0" step="0.01" required value="<?= self::e((string)($selected['amount'] ?? '0.00')) ?>"></label><label>Stage<select name="stage"><?php foreach (['New', 'Contacted', 'Qualified', 'Proposal', 'Negotiation', 'Won', 'Lost'] as $option): ?><option <?= ($selected['stage'] ?? 'New') === $option ? 'selected' : '' ?>><?= self::e($option) ?></option><?php endforeach; ?></select></label><label>Win probability (%)<input name="probability" type="number" min="0" max="100" step="1" required value="<?= self::e((string)($selected['probability'] ?? 10)) ?>"></label><label>Expected close <span class="optional">Optional</span><input name="expected_close" type="date" value="<?= self::e($selected['expected_close'] ?? '') ?>"></label>
<?php else: ?><label>Task title<input name="title" required maxlength="200" placeholder="e.g. Follow up with Jamie" value="<?= self::e($selected['title'] ?? '') ?>"></label><label>Description <span class="optional">Optional</span><textarea name="description" rows="3" placeholder="Add a little context…"><?= self::e($selected['description'] ?? '') ?></textarea></label><label>Due date <span class="optional">Optional</span><input type="date" name="due_date" value="<?= self::e($selected['due_date'] ?? '') ?>"></label><label>Priority<select name="priority"><?php foreach (['Low', 'Normal', 'High'] as $option): ?><option <?= ($selected['priority'] ?? 'Normal') === $option ? 'selected' : '' ?>><?= self::e($option) ?></option><?php endforeach; ?></select></label><label>Status<select name="status"><?php foreach (['To do', 'In progress', 'Done'] as $option): ?><option <?= ($selected['status'] ?? 'To do') === $option ? 'selected' : '' ?>><?= self::e($option) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<button class="button button-primary form-submit" type="submit"><?= $selected ? 'Save changes' : 'Add ' . self::e($label) ?></button><?php if ($selected): ?><a class="cancel-link" href="?page=<?= self::e($page) ?>">Cancel</a><?php endif; ?></form></section></div><?php
    }

    private static function renderAuth(string $page, array $errors): void
    {
        $registering = $page === 'register';
        ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $registering ? 'Create account' : 'Sign in' ?> · Clarity CRM</title><link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>"></head><body class="auth-page"><div class="auth-shell"><a class="brand auth-brand" href="index.php"><span class="brand-mark">c</span><span>clarity<span class="brand-sub">CUSTOMER RELATIONSHIPS</span></span></a><section class="auth-card"><div class="auth-intro"><span class="auth-illustration"><?= $registering ? '✦' : '✧' ?></span><div class="eyebrow"><?= $registering ? 'A calmer way to work' : 'Welcome back' ?></div><h1><?= $registering ? 'Let’s get started.' : 'Good to see you.' ?></h1><p><?= $registering ? 'Create your free account and bring your customer relationships into focus.' : 'Sign in to pick up right where you left off.' ?></p></div>
<?php if ($errors): ?><div class="notice notice-error"><?php foreach ($errors as $error): ?><div><?= self::e($error) ?></div><?php endforeach; ?></div><?php endif; ?><form method="post" class="record-form auth-form"><input type="hidden" name="csrf" value="<?= self::e(self::csrfToken()) ?>"><?php if ($registering): ?><label>Your name<input name="name" required maxlength="120" autocomplete="name" placeholder="How should we call you?"></label><?php endif; ?><label>Email address<input name="email" type="email" required autocomplete="email" placeholder="you@company.com" value="<?= self::e((string)($_POST['email'] ?? '')) ?>"></label><label>Password<input name="password" type="password" required <?= $registering ? 'minlength="8"' : '' ?> autocomplete="<?= $registering ? 'new-password' : 'current-password' ?>" placeholder="<?= $registering ? 'At least 8 characters' : 'Your password' ?>"></label><button class="button button-primary form-submit" type="submit"><?= $registering ? 'Create my account' : 'Sign in' ?><span>→</span></button></form><p class="auth-switch"><?= $registering ? 'Already have an account?' : 'New to Clarity?'; ?> <a href="?page=<?= $registering ? 'login' : 'register' ?>"><?= $registering ? 'Sign in' : 'Create a free account' ?></a></p></section><p class="auth-footnote">Simple, thoughtful tools for better customer relationships.</p></div><div class="auth-decoration decoration-one"></div><div class="auth-decoration decoration-two"></div></body></html><?php
    }

    private static function renderUnavailable(Throwable $exception): void
    {
        ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Setup needed · Clarity CRM</title><link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>"></head><body class="auth-page"><main class="setup-card"><span class="empty-icon">✦</span><div class="eyebrow">One quick setup step</div><h1>We couldn’t connect to your database.</h1><p>Start MySQL in XAMPP, create the <strong>crm_database</strong> database, then import <code>database/schema.sql</code>. You can also configure a custom connection with the <code>CRM_DSN</code>, <code>CRM_DB_USER</code>, and <code>CRM_DB_PASSWORD</code> environment variables.</p><p class="setup-detail">Connection details are hidden for your security.</p><a class="button button-primary" href="index.php">Try again</a></main></body></html><?php
    }

    private static function renderApplicationError(): void
    {
        ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Something went wrong · Clarity CRM</title><link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>"></head><body class="auth-page"><main class="setup-card"><span class="empty-icon">!</span><div class="eyebrow">Please try again</div><h1>Something didn’t go as planned.</h1><p>Your information is safe. Refresh the page and try again in a moment.</p><a class="button button-primary" href="index.php">Back to Clarity</a></main></body></html><?php
    }

    private static function logout(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && self::verifyCsrf()) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
        }
        self::redirect('login');
    }

    private static function verifyCsrf(): bool
    {
        $submitted = (string)($_POST['csrf'] ?? '');
        return isset($_SESSION['csrf']) && $submitted !== '' && hash_equals($_SESSION['csrf'], $submitted);
    }

    private static function csrfToken(): string
    {
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    private static function redirect(string $page): never
    {
        header('Location: ?page=' . rawurlencode($page));
        exit;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1);
        }
        return strtoupper($initials ?: '?');
    }

    private static function statusClass(string $status): string
    {
        return match ($status) {
            'Active', 'Won', 'Done' => 'badge-green',
            'New', 'To do' => 'badge-blue',
            'Qualified', 'In progress', 'High' => 'badge-amber',
            'Lost', 'Inactive' => 'badge-gray',
            default => 'badge-violet',
        };
    }

    private static function money(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }

    private static function greeting(): string
    {
        $hour = (int)date('G');
        return $hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening');
    }
}
