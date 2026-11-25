<?php
/**
 * Ownstyle Graphic Design - Sublimation Management and Inventory System
 * Core configuration + helpers.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
}
date_default_timezone_set('Asia/Manila');

const DB_HOST  = '127.0.0.1';
const DB_NAME  = 'caps';
const DB_USER  = 'root';
const DB_PASS  = '';
const APP_NAME = 'Ownstyle Graphic Design';
const CURRENCY = '₱';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $ex) {
    http_response_code(500);
    exit('Database connection failed. Check config.php and import database.sql first.');
}

/* ---------- Output / input helpers ---------- */
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function p(string $k, string $d = ''): string { return trim((string)($_POST[$k] ?? $d)); }
function g(string $k, string $d = ''): string { return trim((string)($_GET[$k] ?? $d)); }
function redirect(string $to): void { header('Location: ' . $to); exit; }
function money($n): string { return CURRENCY . number_format((float)$n, 2); }

/* ---------- Flash messages ---------- */
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'] = [$msg, $type]; }

/* ---------- Auth ---------- */
function auth(): ?array { return $_SESSION['user'] ?? null; }

/** Where the current request is, so login can send the person back after signing in. */
function current_page(): string
{
    $uri = basename((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
    $qs  = (string)($_SERVER['QUERY_STRING'] ?? '');
    return $uri . ($qs !== '' ? '?' . $qs : '');
}

/** Only allow a same-site page name as a "next" target (blocks open redirects). */
function safe_next(string $next, string $default = ''): string
{
    return preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $next) ? $next : $default;
}

/** Pages for the shop team (owner, manager, staff). Customers are sent to their own account page. */
function require_login(array $roles = []): void
{
    $u = auth();
    if (!$u) { redirect('login.php'); }
    $roles = $roles ?: ['owner', 'manager', 'staff'];
    if (!in_array($u['role'], $roles, true)) {
        if ($u['role'] === 'customer') { redirect('account.php'); }
        http_response_code(403);
        exit('Access denied. You do not have permission to view this page.');
    }
}

/** Pages for signed-in shop customers. Visitors go to login and come back here afterwards. */
function require_customer(): void
{
    $u = auth();
    if (!$u) {
        flash('Please log in to continue.');
        redirect('login.php?next=' . urlencode(current_page()));
    }
    if ($u['role'] !== 'customer') { redirect('index.php'); }
}

/** Dashboard or account page for a role. */
function home_for(string $role): string { return $role === 'customer' ? 'account.php' : 'index.php'; }

// Role model:
//   customer: signs in to order, then follows the order and prints receipts
//   staff:    TRANSACTIONS (customers, orders, confirm payment, receipts, status)
//   manager:  STOCK IN, STOCK OUT, materials, and records expenses
//   owner:    VIEWS orders, payments, expenses and reports; edits prices; approves users
function role_is(string ...$roles): bool { return in_array(auth()['role'] ?? '', $roles, true); }
function can_transact(): bool { return role_is('staff'); }
function can_stock(): bool    { return role_is('manager'); }
function can_expense(): bool  { return role_is('manager'); }
function can_delete(): bool   { return role_is('manager'); }           // undo a stock-out entry
function can_price(): bool    { return role_is('owner'); }             // price list
function deny_write(string $what = 'This action'): void
{
    throw new RuntimeException($what . ' is not available for your role.');
}

/** Branch the signed-in user is limited to (null = can see every branch). */
/** This system runs a single shop, so there is exactly one branch. */
function main_branch_id(): int
{
    static $id = null;
    if ($id === null) { $id = (int)q1("SELECT id FROM branches ORDER BY (status = 'Active') DESC, id LIMIT 1"); }
    return $id;
}

/** Everyone works inside the one branch (kept as a function so the queries stay branch-aware). */
function scope_branch(): ?int
{
    return auth() ? main_branch_id() : null;
}

/** Appends " AND col = ?" for branch-limited users and pushes the param. */
function scope_sql(string $col, array &$params): string
{
    $b = scope_branch();
    if ($b === null) { return ''; }
    $params[] = $b;
    return " AND $col = ?";
}

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Session expired. Please go back, refresh the page and try again.');
    }
}

/* ---------- Database shortcuts ---------- */
function qa(string $sql, array $params = []): array
{
    global $pdo;
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchAll();
}
function q1(string $sql, array $params = [])
{
    global $pdo;
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchColumn();
}
function run(string $sql, array $params = []): int
{
    global $pdo;
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->rowCount();
}
function branches_all(): array { return qa("SELECT id, name FROM branches WHERE status = 'Active' ORDER BY name"); }

/* ---------- Shared constants / small helpers ---------- */
const ORDER_STATUSES = ['Pending', 'Confirmed', 'In Production', 'Ready', 'Completed', 'Cancelled'];
const PAYMENT_TYPES  = ['Down Payment', 'Full Payment', 'Balance Payment'];
const MOVE_TYPES     = ['Stock In', 'Stock Out', 'Material Usage', 'Adjustment'];
// Self-registered accounts wait for the owner's approval before they can sign in.
const REGISTRATION_NEEDS_APPROVAL = true;
const EXPENSE_CATEGORIES = ['Materials & Supplies', 'Utilities', 'Rent', 'Salaries', 'Equipment & Repairs', 'Transportation', 'Other'];

/** 12.50 -> "12.5", 120.00 -> "120" */
function qty($n): string { return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'); }

/** "OS-2026-00042" for the given order id. */
function order_number_for(int $id): string { return 'OS-' . date('Y') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT); }

function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

/** True for an order that is still active and past its due date. */
function is_overdue(?string $due, string $status): bool
{
    return $due && $due < date('Y-m-d') && !in_array($status, ['Completed', 'Cancelled'], true);
}

/**
 * Write one line to the audit log. Never throws: logging must not break a transaction.
 * $actor overrides the signed-in user (used for online customers).
 */
function audit(string $action, string $entity, ?int $entityId, string $details = '', ?string $actor = null): void
{
    try {
        $u = auth();
        run("INSERT INTO audit_logs (user_id, user_name, action, entity, entity_id, details, ip_address) VALUES (?,?,?,?,?,?,?)",
            [$u['id'] ?? null, $actor ?? ($u['name'] ?? 'System'), $action, $entity, $entityId, mb_substr($details, 0, 500), client_ip()]);
    } catch (Throwable $ex) { /* ignore */ }
}

/**
 * Apply one stock movement and update the material's quantity in ONE transaction.
 * Material Usage can be tied to an order. Throws RuntimeException for user errors.
 * Returns the material row (before the change).
 */
function apply_stock_movement(int $materialId, string $type, float $qty, ?int $orderId, string $reference, string $notes, string $date): array
{
    global $pdo;
    if (!in_array($type, MOVE_TYPES, true)) { throw new RuntimeException('Choose a valid transaction type.'); }
    $qty = round($qty, 2);
    if ($type === 'Adjustment') {
        if ($qty == 0) { throw new RuntimeException('Adjustment cannot be zero. Use a negative number to decrease.'); }
        $delta = $qty;
    } else {
        if ($qty <= 0) { throw new RuntimeException('Quantity must be greater than zero.'); }
        $delta = $type === 'Stock In' ? $qty : -$qty;
    }
    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }
    try {
        $pp = [$materialId]; $sc = scope_sql('branch_id', $pp);
        $st = $pdo->prepare("SELECT * FROM materials WHERE id=? $sc FOR UPDATE");
        $st->execute($pp);
        $m = $st->fetch();
        if (!$m) { throw new RuntimeException('Material not found.'); }
        if ((float)$m['current_quantity'] + $delta < 0) {
            throw new RuntimeException('Not enough stock. Only ' . qty($m['current_quantity']) . ' ' . $m['unit'] . ' on hand.');
        }
        if ($orderId !== null) {
            $o = qa("SELECT branch_id FROM orders WHERE id=?", [$orderId])[0] ?? null;
            if (!$o || (int)$o['branch_id'] !== (int)$m['branch_id']) {
                throw new RuntimeException('That order belongs to a different branch than this material.');
            }
        }
        run("UPDATE materials SET current_quantity = current_quantity + ? WHERE id=?", [$delta, $materialId]);
        run("INSERT INTO inventory_transactions (material_id, branch_id, user_id, order_id, transaction_type, quantity, reference, notes, transaction_date)
             VALUES (?,?,?,?,?,?,?,?,?)",
            [$materialId, $m['branch_id'], auth()['id'], $orderId, $type, $qty, $reference ?: null, $notes ?: null, $date]);
        $id = (int)$pdo->lastInsertId();
        audit('stock', 'material', $materialId, $type . ' ' . qty($qty) . ' ' . $m['unit'] . ' of ' . $m['name'] . ($reference ? ' (' . $reference . ')' : ''));
        if ($own) { $pdo->commit(); }
        $m['txn_id'] = $id;
        return $m;
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $ex;
    }
}

/** Friendly message for a failed query. */
function db_error_message(PDOException $ex): string
{
    return $ex->getCode() === '23000'
        ? 'Cannot complete this action: the value already exists or the record is used by other data.'
        : 'Database error. Please try again.';
}

/* ---------- UI helpers ---------- */
function badge(string $s): string
{
    $cls = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $s));
    return '<span class="badge s-' . e($cls) . '">' . e($s) . '</span>';
}

function delete_form(int $id): string
{
    return '<form method="post" class="inline" data-confirm="Delete this record? This cannot be undone.">'
        . csrf_field()
        . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $id . '">'
        . '<button class="btn btn-sm btn-outline" type="submit">Delete</button></form>';
}

function icon(string $name): string
{
    static $i = [
        'home'      => 'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z M9 22V12h6v10',
        'users'     => 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M23 21v-2a4 4 0 0 0-3-3.87 M16 3.13a4 4 0 0 1 0 7.75',
        'user'      => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2 M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
        'pin'       => 'M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z M12 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
        'clipboard' => 'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2 M9 2h6v4H9z',
        'box'       => 'M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z M3.27 6.96L12 12.01l8.73-5.05 M12 22.08V12',
        'layers'    => 'M12 2L2 7l10 5 10-5-10-5z M2 17l10 5 10-5 M2 12l10 5 10-5',
        'card'      => 'M1 4h22v16H1z M1 10h22',
        'logout'    => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4 M16 17l5-5-5-5 M21 12H9',
        'menu'      => 'M3 12h18 M3 6h18 M3 18h18',
        'tag'       => 'M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z M7 7h.01',
        'chart'     => 'M18 20V10 M12 20V4 M6 20v-6',
        'shield'    => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
        'bag'       => 'M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z M3 6h18 M16 10a4 4 0 0 1-8 0',
        'cart'      => 'M9 22a1 1 0 1 0 0-2 1 1 0 0 0 0 2z M20 22a1 1 0 1 0 0-2 1 1 0 0 0 0 2z M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6',
        'search'    => 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16z M21 21l-4.35-4.35',
        'shirt'     => 'M8 3l-5 3 2 5 3-1v11h8V10l3 1 2-5-5-3a4 4 0 0 1-8 0z',
        'bell'      => 'M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9z M13.73 21a2 2 0 0 1-3.46 0',
        'chevron'   => 'M6 9l6 6 6-6',
        'arrow'     => 'M5 12h14 M13 6l6 6-6 6',
        'plus'      => 'M12 5v14 M5 12h14',
        'truck'     => 'M1 3h15v13H1z M16 8h4l3 3v5h-7z M5.5 21a2 2 0 1 0 0-4 2 2 0 0 0 0 4z M18.5 21a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
        'clock'     => 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z M12 6v6l4 2',
        'lock'      => 'M5 11h14v10H5z M8 11V7a4 4 0 0 1 8 0v4',
        'check'     => 'M20 6L9 17l-5-5',
        'download'  => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4 M7 10l5 5 5-5 M12 15V3',
        'upload'    => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4 M17 8l-5-5-5 5 M12 3v12',
        'receipt'   => 'M4 2v20l3-2 2 2 3-2 3 2 2-2 3 2V2z M8 8h8 M8 12h8',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . ($i[$name] ?? '') . '"/></svg>';
}

/** Brand mark: a basketball, drawn here so the project has no outside image files. */
function brand_mark(): string
{
    return '<svg viewBox="0 0 48 48" aria-hidden="true"><defs><radialGradient id="bm" cx="38%" cy="32%" r="75%"><stop offset="0" stop-color="#9cc0ff"/><stop offset="1" stop-color="#1d4ed8"/></radialGradient></defs>'
        . '<circle cx="24" cy="24" r="22" fill="url(#bm)"/>'
        . '<g fill="none" stroke="#0b2a6f" stroke-width="2" stroke-linecap="round"><path d="M24 2v44M2 24h44"/><path d="M7 8c8 6 8 26 0 32M41 8c-8 6-8 26 0 32"/></g></svg>';
}

require_once __DIR__ . '/includes/orders_lib.php';