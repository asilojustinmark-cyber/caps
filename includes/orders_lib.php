<?php
/**
 * Order rules shared by the staff pages and the customer shop:
 * sizes and player lists, the one-way order status flow, payments, and notifications.
 */

/* ---------- Sizes and player lists ---------- */
const SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];

/**
 * Cleans a player list from a form. Empty rows are skipped.
 * Every player needs a name or a number, and a size from SIZES.
 *
 * @return array<int, array{name: string, number: string, size: string}>
 */
function clean_players(array $raw): array
{
    $out  = [];
    $seen = [];
    foreach (array_values($raw) as $i => $r) {
        $name   = mb_substr(trim((string)($r['name'] ?? '')), 0, 60);
        $number = trim((string)($r['number'] ?? ''));
        $size   = trim((string)($r['size'] ?? ''));
        if ($name === '' && $number === '' && $size === '') { continue; }

        $row = $i + 1;
        if ($name === '' && $number === '') { throw new RuntimeException('Player ' . $row . ': enter a name or a jersey number.'); }
        if ($number !== '' && !preg_match('/^\d{1,2}$/', $number)) { throw new RuntimeException('Player ' . $row . ': the jersey number must be 0 to 99.'); }
        if (!in_array($size, SIZES, true)) { throw new RuntimeException('Player ' . $row . ': choose a size (' . implode(', ', SIZES) . ').'); }
        if ($number !== '') {
            $key = (string)(int)$number;
            if (isset($seen[$key])) { throw new RuntimeException('Jersey number ' . $number . ' is used by two players.'); }
            $seen[$key] = true;
        }
        $out[] = ['name' => $name, 'number' => $number, 'size' => $size];
        if (count($out) > 60) { throw new RuntimeException('A single item can have at most 60 players.'); }
    }
    return $out;
}

/** "XS x1 | S x3 | M x5": how many of each size, in size order. */
function size_summary(array $players): string
{
    $count = array_fill_keys(SIZES, 0);
    foreach ($players as $p) { $count[$p['size']] = ($count[$p['size']] ?? 0) + 1; }
    $parts = [];
    foreach ($count as $size => $n) { if ($n > 0) { $parts[] = $size . ' ×' . $n; } }
    return implode('  |  ', $parts);
}

/** Order items with their measurements and players loaded. */
function order_items_full(int $orderId): array
{
    $items = qa("SELECT * FROM order_items WHERE order_id=? ORDER BY id", [$orderId]);
    foreach ($items as &$it) {
        $it['dims'] = qa("SELECT * FROM order_dimensions WHERE order_item_id=? ORDER BY id", [$it['id']]);
        $it['players'] = qa("SELECT player_name AS name, jersey_number AS number, size
                             FROM order_players WHERE order_item_id=? ORDER BY sort_order, id", [$it['id']]);
    }
    unset($it);
    return $items;
}

/**
 * Cleans the items of an order form (staff) into ready-to-save lines.
 * A sized item takes its quantity from the player list.
 */
function clean_order_lines(array $rawItems): array
{
    $lines = [];
    foreach (array_values($rawItems) as $n => $it) {
        $name  = mb_substr(trim((string)($it['garment_type'] ?? '')), 0, 100);
        $price = round((float)($it['unit_price'] ?? 0), 2);
        $sized = !empty($it['sized']);
        if ($name === '') { continue; }
        if ($price < 0) { throw new RuntimeException('Item ' . ($n + 1) . ': the price cannot be negative.'); }

        $players = [];
        if ($sized) {
            $players = clean_players((array)($it['players'] ?? []));
            if (!$players) { throw new RuntimeException('Item ' . ($n + 1) . ' (' . $name . '): add at least one player with a size.'); }
            $qty = count($players);
        } else {
            $qty = (int)($it['quantity'] ?? 0);
            if ($qty < 1 || $qty > 5000) { throw new RuntimeException('Item ' . ($n + 1) . ' (' . $name . '): the quantity must be at least 1.'); }
        }

        $dims = [];
        foreach (array_values((array)($it['dims'] ?? [])) as $d) {
            $dn = mb_substr(trim((string)($d['name'] ?? '')), 0, 100);
            if ($dn === '' || !is_numeric($d['value'] ?? null) || (float)$d['value'] <= 0) { continue; }
            $dims[] = ['name' => $dn, 'value' => round((float)$d['value'], 2), 'unit' => mb_substr(trim((string)($d['unit'] ?? '')), 0, 20) ?: 'in'];
        }
        $lines[] = [
            'name' => $name, 'price' => $price, 'qty' => $qty, 'sub' => round($qty * $price, 2),
            'spec' => mb_substr(trim((string)($it['design_specifications'] ?? '')), 0, 1000),
            'sized' => $sized, 'players' => $players, 'dims' => $dims,
        ];
    }
    if (!$lines) { throw new RuntimeException('Add at least one item.'); }
    return $lines;
}

/** Saves the item lines of an order. Returns the order total. Call inside a transaction. */
function insert_order_items(int $orderId, array $lines): float
{
    global $pdo;
    $total = 0.0;
    foreach ($lines as $l) {
        run("INSERT INTO order_items (order_id, garment_type, size, design_specifications, quantity, unit_price, subtotal)
             VALUES (?,?,?,?,?,?,?)",
            [$orderId, $l['name'], null, $l['spec'] !== '' ? $l['spec'] : null, $l['qty'], $l['price'], $l['sub']]);
        $iid = (int)$pdo->lastInsertId();
        foreach ($l['players'] as $k => $pl) {
            run("INSERT INTO order_players (order_item_id, player_name, jersey_number, size, sort_order) VALUES (?,?,?,?,?)",
                [$iid, $pl['name'] !== '' ? $pl['name'] : null, $pl['number'] !== '' ? $pl['number'] : null, $pl['size'], $k]);
        }
        foreach ($l['dims'] as $d) {
            run("INSERT INTO order_dimensions (order_item_id, dimension_name, dimension_value, unit) VALUES (?,?,?,?)",
                [$iid, $d['name'], $d['value'], $d['unit']]);
        }
        $total += $l['sub'];
    }
    return round($total, 2);
}

/** Creates a new Pending order with its items. Returns [id, order number, total]. */
function create_order(int $customerId, ?int $createdBy, string $source, ?string $due, string $notes, array $lines, ?string $date = null): array
{
    global $pdo;
    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }
    try {
        run("INSERT INTO orders (branch_id, customer_id, created_by, order_number, order_date, due_date, source, status, total_amount, notes)
             VALUES (?,?,?,?,?,?,?,'Pending',0,?)",
            [main_branch_id(), $customerId, $createdBy, 'TMP-' . bin2hex(random_bytes(6)), $date ?: date('Y-m-d H:i:s'),
             $due ?: null, $source, mb_substr($notes, 0, 1000) ?: null]);
        $id    = (int)$pdo->lastInsertId();
        $no    = order_number_for($id);
        $total = insert_order_items($id, $lines);
        run("UPDATE orders SET order_number=?, total_amount=? WHERE id=?", [$no, $total, $id]);
        if ($own) { $pdo->commit(); }
        return [$id, $no, $total];
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $ex;
    }
}

/** Number of pieces in a list of lines (players count one each). */
function lines_pieces(array $lines): int { return (int)array_sum(array_column($lines, 'qty')); }

/* ---------- Order status: one direction only ---------- */
const ORDER_FLOW = ['Pending', 'Confirmed', 'In Production', 'Ready', 'Completed'];
// What each status may move to. Nothing moves backwards.
// Pending becomes Confirmed only when the first payment is recorded.
const ORDER_NEXT = [
    'Pending'       => ['Confirmed', 'Cancelled'],
    'Confirmed'     => ['In Production'],
    'In Production' => ['Ready'],
    'Ready'         => ['Completed'],
    'Completed'     => [],
    'Cancelled'     => [],
];
const STATUS_ACTIONS = [      // button text for moving to a status
    'In Production' => 'Start production',
    'Ready'         => 'Mark as ready for pick-up',
    'Completed'     => 'Release to customer',
    'Cancelled'     => 'Cancel order',
];

/** Staff can edit the details and players until production starts. */
function order_can_edit(string $status): bool { return in_array($status, ['Pending', 'Confirmed'], true); }

/** Wording customers see. */
function customer_status_label(string $status): string
{
    return [
        'Pending'       => 'Waiting for confirmation',
        'Confirmed'     => 'Confirmed',
        'In Production' => 'Being made',
        'Ready'         => 'Ready for pick-up',
        'Completed'     => 'Completed',
        'Cancelled'     => 'Cancelled',
    ][$status] ?? $status;
}

function order_paid(int $orderId): float
{
    return (float)q1("SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id=?", [$orderId]);
}

/**
 * Moves an order to its next status. Throws RuntimeException if the move is not allowed.
 * Confirming is done by recording a payment (see record_payment).
 */
function order_move(array $order, string $to): void
{
    $from = $order['status'];
    if ($to === 'Confirmed') { throw new RuntimeException('Record the down payment or full payment to confirm this order.'); }
    if (!in_array($to, ORDER_NEXT[$from] ?? [], true)) {
        throw new RuntimeException('This order is ' . $from . '. It cannot move to ' . $to . '.');
    }
    if ($to === 'Completed' && order_paid((int)$order['id']) + 0.005 < (float)$order['total_amount']) {
        throw new RuntimeException('The balance must be fully paid before the order is released.');
    }
    if ($to === 'Cancelled' && order_paid((int)$order['id']) > 0.005) {
        throw new RuntimeException('This order already has a payment, so it cannot be cancelled.');
    }
    run("UPDATE orders SET status=? WHERE id=? AND status=?", [$to, $order['id'], $from]);
    audit('status', 'order', (int)$order['id'], $order['order_number'] . ': ' . $from . ' -> ' . $to);
    notify_customer_of_order($order, 'Order ' . $order['order_number'] . ': ' . customer_status_label($to),
        $to === 'Ready' ? 'Your order is ready. Please pick it up at the shop.' : null);
}

/* ---------- Payments ---------- */
/**
 * Records one payment and returns its id. Rules:
 *  - first payment: Down Payment (less than the total) or Full Payment (the exact total)
 *  - later payments: Balance Payment (up to what is still owed)
 *  - the first payment confirms a Pending order.
 */
function record_payment(array $order, string $type, float $amt, string $date, string $ref = '', string $notes = ''): int
{
    global $pdo;
    $id      = (int)$order['id'];
    $paid    = order_paid($id);
    $total   = (float)$order['total_amount'];
    $balance = round($total - $paid, 2);
    $amt     = round($amt, 2);

    if (in_array($order['status'], ['Cancelled', 'Completed'], true)) { throw new RuntimeException('This order is ' . $order['status'] . '. No more payments can be recorded.'); }
    if ($balance <= 0.005) { throw new RuntimeException('This order is already fully paid.'); }
    if ($amt <= 0) { throw new RuntimeException('The amount must be greater than zero.'); }
    if ($amt > $balance + 0.005) { throw new RuntimeException('The amount is more than the balance of ' . money($balance) . '.'); }

    if ($paid <= 0.005) {
        if (!in_array($type, ['Down Payment', 'Full Payment'], true)) { throw new RuntimeException('The first payment is a Down Payment or a Full Payment.'); }
        if ($type === 'Full Payment' && abs($amt - $balance) > 0.005) { throw new RuntimeException('A Full Payment must be the whole order total of ' . money($balance) . '.'); }
        if ($type === 'Down Payment' && $amt >= $balance - 0.005) { throw new RuntimeException('A Down Payment is less than the total. Use Full Payment to pay it all.'); }
    } elseif ($type !== 'Balance Payment') {
        throw new RuntimeException('This order already has a payment. Record the rest as a Balance Payment.');
    }

    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }
    try {
        run("INSERT INTO payments (order_id, recorded_by, amount, payment_type, payment_date, reference_number, notes)
             VALUES (?,?,?,?,?,?,?)", [$id, auth()['id'], $amt, $type, $date, $ref ?: null, $notes ?: null]);
        $pid = (int)$pdo->lastInsertId();
        audit('payment', 'payment', $pid, $type . ' ' . number_format($amt, 2, '.', '') . ' for ' . $order['order_number']);

        $confirmed = false;
        if ($order['status'] === 'Pending') {
            run("UPDATE orders SET status='Confirmed' WHERE id=? AND status='Pending'", [$id]);
            audit('status', 'order', $id, $order['order_number'] . ': Pending -> Confirmed (' . $type . ' received)');
            $confirmed = true;
        }
        if ($own) { $pdo->commit(); }
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $ex;
    }

    $left = round($balance - $amt, 2);
    notify_customer_of_order($order, 'Payment received: ' . money($amt),
        $type . ' recorded for ' . $order['order_number'] . '. ' . ($left > 0.005 ? 'Balance left: ' . money($left) . '.' : 'Fully paid.') . ' Your receipt is ready.');
    if ($confirmed) {
        notify_customer_of_order($order, 'Order ' . $order['order_number'] . ' is confirmed', 'The shop will start preparing your order.');
        notify_role('manager', 'Order ' . $order['order_number'] . ' confirmed', 'Prepare the materials for this order.', 'order_view.php?id=' . $id);
    }
    return $pid;
}

/* ---------- Customers (shop accounts) ---------- */
/** The customers row that belongs to a customer login. Created if it is missing. */
function customer_id_for_user(array $user): int
{
    $id = q1("SELECT id FROM customers WHERE user_id=?", [$user['id']]);
    if ($id !== false) { return (int)$id; }
    global $pdo;
    run("INSERT INTO customers (branch_id, user_id, name, email) VALUES (?,?,?,?)", [main_branch_id(), $user['id'], $user['name'], $user['email']]);
    return (int)$pdo->lastInsertId();
}

/* ---------- Notifications ---------- */
function notify_role(string $role, string $title, string $message = '', string $link = ''): void
{
    try {
        run("INSERT INTO notifications (user_id, role, title, message, link) VALUES (NULL,?,?,?,?)",
            [$role, mb_substr($title, 0, 120), mb_substr($message, 0, 255) ?: null, $link ?: null]);
    } catch (Throwable $ex) { /* a failed notification must never block the real work */ }
}

function notify_user(int $userId, string $title, string $message = '', string $link = ''): void
{
    try {
        run("INSERT INTO notifications (user_id, role, title, message, link) VALUES (?,NULL,?,?,?)",
            [$userId, mb_substr($title, 0, 120), mb_substr($message, 0, 255) ?: null, $link ?: null]);
    } catch (Throwable $ex) { }
}

/** Tells the customer who owns this order (if they have an account). */
function notify_customer_of_order(array $order, string $title, ?string $message = null): void
{
    $uid = q1("SELECT user_id FROM customers WHERE id=?", [$order['customer_id']]);
    if ($uid) { notify_user((int)$uid, $title, (string)$message, 'track.php?no=' . urlencode($order['order_number'])); }
}

function notif_where(array $user, array &$params): string
{
    $params[] = $user['id'];
    $params[] = $user['role'];
    $params[] = date('Y-m-d H:i:s', strtotime('-30 days'));
    return "(n.user_id = ? OR (n.user_id IS NULL AND n.role = ?)) AND n.created_at >= ?";
}

/** Notifications this person has not opened yet. */
function notif_unread(array $user, int $limit = 8): array
{
    $pp = [];
    $w  = notif_where($user, $pp);
    $pp[] = $user['id'];
    return qa("SELECT n.* FROM notifications n
               WHERE $w AND NOT EXISTS (SELECT 1 FROM notification_reads r WHERE r.notification_id = n.id AND r.user_id = ?)
               ORDER BY n.id DESC LIMIT " . (int)$limit, $pp);
}

function notif_unread_count(array $user): int
{
    $pp = [];
    $w  = notif_where($user, $pp);
    $pp[] = $user['id'];
    return (int)q1("SELECT COUNT(*) FROM notifications n
                    WHERE $w AND NOT EXISTS (SELECT 1 FROM notification_reads r WHERE r.notification_id = n.id AND r.user_id = ?)", $pp);
}

/** Everything from the last 30 days, with a read flag. */
function notif_all(array $user, int $limit = 100): array
{
    $pp = [$user['id']];
    $w  = notif_where($user, $pp);
    return qa("SELECT n.*, (SELECT COUNT(*) FROM notification_reads r WHERE r.notification_id = n.id AND r.user_id = ?) AS is_read
               FROM notifications n WHERE $w ORDER BY n.id DESC LIMIT " . (int)$limit, $pp);
}

function notif_mark_read(int $notifId, int $userId): void
{
    try {
        if (!q1("SELECT 1 FROM notification_reads WHERE notification_id=? AND user_id=?", [$notifId, $userId])) {
            run("INSERT INTO notification_reads (notification_id, user_id) VALUES (?,?)", [$notifId, $userId]);
        }
    } catch (PDOException $ex) { /* already marked */ }
}

function notif_mark_all(array $user): void
{
    foreach (notif_unread($user, 200) as $n) { notif_mark_read((int)$n['id'], (int)$user['id']); }
}

/** Short "5 min ago" text. */
function time_ago(string $ts): string
{
    $d = time() - strtotime($ts);
    if ($d < 60)    { return 'just now'; }
    if ($d < 3600)  { return floor($d / 60) . ' min ago'; }
    if ($d < 86400) { return floor($d / 3600) . ' h ago'; }
    return date('M j', strtotime($ts));
}
