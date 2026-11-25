<?php
require_once __DIR__ . '/config.php';
require_login(['owner']);

$tabs = [
    'sales'    => 'Sales',
    'balances' => 'Unpaid Balances',
    'stock'    => 'Material Summary',
    'usage'    => 'Usage by Order',
    'products' => 'Best Sellers',
    'expenses' => 'Expenses',
    'profit'   => 'Income vs Expenses',
];
$r = array_key_exists(g('r'), $tabs) ? g('r') : 'sales';

$from  = preg_match('/^\d{4}-\d{2}-\d{2}$/', g('from')) ? g('from') : date('Y-m-01');
$to    = preg_match('/^\d{4}-\d{2}-\d{2}$/', g('to'))   ? g('to')   : date('Y-m-d');
$group = g('group') === 'month' ? 'month' : 'day';
$fBranch = (int)g('branch');
$branches = branches_all();

/** Adds " AND col = ?" for a branch-limited user, or for the owner's branch filter. */
function branch_filter(string $col, array &$pp, int $fBranch): string
{
    $b = scope_branch() ?? ($fBranch ?: null);
    if ($b === null) { return ''; }
    $pp[] = $b;
    return " AND $col = ?";
}

$head = []; $types = []; $rows = []; $foot = null; $note = '';

if ($r === 'sales') {
    $fmt = $group === 'month' ? '%Y-%m' : '%Y-%m-%d';
    $p1 = [$from, $to]; $b1 = branch_filter('o.branch_id', $p1, $fBranch);
    $pay = qa("SELECT DATE_FORMAT(pay.payment_date, '$fmt') k, SUM(pay.amount) amt
               FROM payments pay JOIN orders o ON o.id = pay.order_id
               WHERE DATE(pay.payment_date) BETWEEN ? AND ? $b1 GROUP BY k", $p1);
    $p2 = [$from, $to]; $b2 = branch_filter('o.branch_id', $p2, $fBranch);
    $ord = qa("SELECT DATE_FORMAT(o.order_date, '$fmt') k, COUNT(*) n, SUM(o.total_amount) val
               FROM orders o WHERE DATE(o.order_date) BETWEEN ? AND ? AND o.status <> 'Cancelled' $b2 GROUP BY k", $p2);
    $m = [];
    foreach ($ord as $x) { $m[$x['k']] = ['n' => (int)$x['n'], 'val' => (float)$x['val'], 'amt' => 0.0]; }
    foreach ($pay as $x) { $m[$x['k']] = ($m[$x['k']] ?? ['n' => 0, 'val' => 0.0]) + []; $m[$x['k']]['amt'] = (float)$x['amt']; }
    ksort($m);
    $head = [$group === 'month' ? 'Month' : 'Date', 'Orders placed', 'Order value', 'Payments collected'];
    $types = ['text', 'int', 'money', 'money'];
    $tn = 0; $tv = 0; $ta = 0;
    foreach ($m as $k => $x) { $rows[] = [$k, $x['n'], $x['val'], $x['amt'] ?? 0]; $tn += $x['n']; $tv += $x['val']; $ta += $x['amt'] ?? 0; }
    $foot = ['Total', $tn, $tv, $ta];
    $note = 'Payments collected is cash actually received in the period. Order value counts non-cancelled orders placed in the period.';

} elseif ($r === 'balances') {
    $pp = []; $bf = branch_filter('o.branch_id', $pp, $fBranch);
    $data = qa("SELECT o.order_number, c.name customer, o.order_date, o.due_date, o.status, o.total_amount,
                       COALESCE((SELECT SUM(amount) FROM payments WHERE order_id = o.id), 0) paid
                FROM orders o JOIN customers c ON c.id = o.customer_id
                WHERE o.status <> 'Cancelled' $bf ORDER BY o.due_date IS NULL, o.due_date, o.id", $pp);
    $head = ['Order #', 'Customer', 'Order date', 'Due date', 'Status', 'Total', 'Paid', 'Balance'];
    $types = ['text', 'text', 'date', 'date', 'text', 'money', 'money', 'money'];
    $tt = $tp = $tb = 0;
    foreach ($data as $x) {
        $bal = (float)$x['total_amount'] - (float)$x['paid'];
        if ($bal <= 0.005) { continue; }
        $rows[] = [$x['order_number'], $x['customer'], $x['order_date'], $x['due_date'], $x['status'], (float)$x['total_amount'], (float)$x['paid'], $bal];
        $tt += $x['total_amount']; $tp += $x['paid']; $tb += $bal;
    }
    $foot = ['Total', '', '', '', '', $tt, $tp, $tb];
    $note = 'All orders that still have an unpaid balance (cancelled orders excluded). The date range does not apply here.';

} elseif ($r === 'stock') {
    $pp = [$from, $to]; $bf = branch_filter('m.branch_id', $pp, $fBranch);
    $data = qa("SELECT m.name, m.unit, b.name branch, m.current_quantity, m.reorder_level,
                  COALESCE(SUM(CASE WHEN t.transaction_type='Stock In' THEN t.quantity END),0) q_in,
                  COALESCE(SUM(CASE WHEN t.transaction_type='Material Usage' THEN t.quantity END),0) q_use,
                  COALESCE(SUM(CASE WHEN t.transaction_type='Stock Out' THEN t.quantity END),0) q_out,
                  COALESCE(SUM(CASE WHEN t.transaction_type='Adjustment' THEN t.quantity END),0) q_adj
                FROM materials m
                JOIN branches b ON b.id = m.branch_id
                LEFT JOIN inventory_transactions t ON t.material_id = m.id AND DATE(t.transaction_date) BETWEEN ? AND ?
                WHERE m.status = 'Active' $bf
                GROUP BY m.id, m.name, m.unit, b.name, m.current_quantity, m.reorder_level
                ORDER BY m.name", $pp);
    $head = ['Material', 'Unit', 'Branch', 'Stock in', 'Used', 'Stock out', 'Adjustments', 'On hand now', 'Reorder level', 'Status'];
    $types = ['text', 'text', 'text', 'qty', 'qty', 'qty', 'qty', 'qty', 'qty', 'text'];
    foreach ($data as $x) {
        $low = (float)$x['current_quantity'] <= (float)$x['reorder_level'];
        $rows[] = [$x['name'], $x['unit'], $x['branch'], $x['q_in'], $x['q_use'], $x['q_out'], $x['q_adj'], $x['current_quantity'], $x['reorder_level'], $low ? 'REORDER' : 'OK'];
    }
    $note = 'Movements are counted inside the chosen date range. "On hand now" is the current stock.';

} elseif ($r === 'usage') {
    $pp = [$from, $to]; $bf = branch_filter('o.branch_id', $pp, $fBranch);
    $data = qa("SELECT t.transaction_date, o.order_number, c.name customer, m.name material, m.unit, t.quantity, u.name user
                FROM inventory_transactions t
                JOIN orders o ON o.id = t.order_id
                JOIN customers c ON c.id = o.customer_id
                JOIN materials m ON m.id = t.material_id
                LEFT JOIN users u ON u.id = t.user_id
                WHERE t.transaction_type = 'Material Usage' AND DATE(t.transaction_date) BETWEEN ? AND ? $bf
                ORDER BY o.order_number, t.transaction_date", $pp);
    $head = ['Order #', 'Customer', 'Material', 'Quantity', 'Unit', 'Date', 'Recorded by'];
    $types = ['text', 'text', 'text', 'qty', 'text', 'date', 'text'];
    foreach ($data as $x) { $rows[] = [$x['order_number'], $x['customer'], $x['material'], $x['quantity'], $x['unit'], $x['transaction_date'], $x['user']]; }
    $note = 'Materials consumed per order, to help you work out the real cost of each job.';

} elseif ($r === 'expenses') {
    $pp = [$from, $to]; $bf = branch_filter('e.branch_id', $pp, $fBranch);
    $data = qa("SELECT e.expense_date, e.category, e.description, b.name branch, e.reference, u.name user, e.amount
                FROM expenses e JOIN branches b ON b.id = e.branch_id LEFT JOIN users u ON u.id = e.user_id
                WHERE e.expense_date BETWEEN ? AND ? $bf ORDER BY e.expense_date, e.id", $pp);
    $head = ['Date', 'Category', 'Description', 'Branch', 'Reference', 'Recorded by', 'Amount'];
    $types = ['date', 'text', 'text', 'text', 'text', 'text', 'money'];
    $tt = 0;
    foreach ($data as $x) { $rows[] = [$x['expense_date'], $x['category'], $x['description'], $x['branch'], $x['reference'], $x['user'], (float)$x['amount']]; $tt += $x['amount']; }
    $foot = ['Total', '', '', '', '', '', $tt];
    $note = 'Every expense recorded in the period. Managers record expenses; the owner views them here and on the Expenses page.';

} elseif ($r === 'profit') {
    $fmt = $group === 'month' ? '%Y-%m' : '%Y-%m-%d';
    $p1 = [$from, $to]; $b1 = branch_filter('o.branch_id', $p1, $fBranch);
    $inc = qa("SELECT DATE_FORMAT(pay.payment_date, '$fmt') k, SUM(pay.amount) amt
               FROM payments pay JOIN orders o ON o.id = pay.order_id
               WHERE DATE(pay.payment_date) BETWEEN ? AND ? $b1 GROUP BY k", $p1);
    $p2 = [$from, $to]; $b2 = branch_filter('e.branch_id', $p2, $fBranch);
    $exp = qa("SELECT DATE_FORMAT(e.expense_date, '$fmt') k, SUM(e.amount) amt
               FROM expenses e WHERE e.expense_date BETWEEN ? AND ? $b2 GROUP BY k", $p2);
    $m = [];
    foreach ($inc as $x) { $m[$x['k']]['in'] = (float)$x['amt']; }
    foreach ($exp as $x) { $m[$x['k']]['out'] = (float)$x['amt']; }
    ksort($m);
    $head = [$group === 'month' ? 'Month' : 'Date', 'Payments collected', 'Expenses', 'Net'];
    $types = ['text', 'money', 'money', 'money'];
    $ti = $to_ = 0;
    foreach ($m as $k => $x) {
        $i = $x['in'] ?? 0.0; $o = $x['out'] ?? 0.0;
        $rows[] = [$k, $i, $o, $i - $o]; $ti += $i; $to_ += $o;
    }
    $foot = ['Total', $ti, $to_, $ti - $to_];
    $note = 'Net = payments actually collected minus expenses recorded in the same period. It is a cash view, not an accounting profit.';

} else { // products
    $pp = [$from, $to]; $bf = branch_filter('o.branch_id', $pp, $fBranch);
    $data = qa("SELECT i.garment_type, SUM(i.quantity) qty_sold, SUM(i.subtotal) revenue, COUNT(DISTINCT o.id) orders_n
                FROM order_items i JOIN orders o ON o.id = i.order_id
                WHERE o.status <> 'Cancelled' AND DATE(o.order_date) BETWEEN ? AND ? $bf
                GROUP BY i.garment_type ORDER BY revenue DESC", $pp);
    $head = ['Product / Garment', 'Pieces sold', 'Orders', 'Revenue'];
    $types = ['text', 'int', 'int', 'money'];
    $tq = $tr = 0;
    foreach ($data as $x) { $rows[] = [$x['garment_type'], (int)$x['qty_sold'], (int)$x['orders_n'], (float)$x['revenue']]; $tq += $x['qty_sold']; $tr += $x['revenue']; }
    $foot = ['Total', $tq, '', $tr];
}

/** Format a cell for HTML. */
function cell($v, string $type): string
{
    if ($v === null || $v === '') { return '&mdash;'; }
    return match ($type) {
        'money' => e(money($v)),
        'qty'   => e(qty($v)),
        'int'   => e((string)(int)$v),
        'date'  => e(date('M j, Y', strtotime((string)$v))),
        default => e($v),
    };
}

if (g('export') === 'csv') {
    audit('export', 'report', null, 'Exported report: ' . $tabs[$r] . " ($from to $to)");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ownstyle-' . $r . '-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $head);
    $line = function (array $row) use ($types, $out) {
        $o = [];
        foreach ($row as $i => $v) {
            $t = $types[$i] ?? 'text';
            if ($t === 'text' && is_string($v) && $v !== '' && strpbrk($v[0], "=+-@") !== false) { $v = "'" . $v; }
            if ($t === 'date' && $v) { $v = date('Y-m-d', strtotime((string)$v)); }
            $o[] = $v;
        }
        fputcsv($out, $o);
    };
    foreach ($rows as $row) { $line($row); }
    if ($foot) { $line($foot); }
    fclose($out);
    exit;
}

$pageTitle = 'Reports';
$active    = 'reports';
$qs = fn(array $over = []) => http_build_query(array_merge(['r' => $r, 'from' => $from, 'to' => $to, 'group' => $group, 'branch' => $fBranch ?: ''], $over));
require __DIR__ . '/includes/header.php';
?>
<div class="tabs no-print">
  <?php foreach ($tabs as $k => $label): ?>
    <a class="tab <?= $r === $k ? 'active' : '' ?>" href="reports.php?<?= e($qs(['r' => $k])) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-head">
    <h2><?= e($tabs[$r]) ?><?php if (!in_array($r, ['balances'], true)): ?> <span class="muted fw-400-13"><?= e(date('M j, Y', strtotime($from))) ?> &ndash; <?= e(date('M j, Y', strtotime($to))) ?></span><?php endif; ?></h2>
    
  </div>
  <div class="card-body no-print pb-0">
    <form method="get" class="filters">
      <input type="hidden" name="r" value="<?= e($r) ?>">
      <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
      <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
      <?php if (in_array($r, ['sales', 'profit'], true)): ?>
      <div><label>Group by</label><select name="group"><option value="day" <?= $group === 'day' ? 'selected' : '' ?>>Day</option><option value="month" <?= $group === 'month' ? 'selected' : '' ?>>Month</option></select></div>
      <?php endif; ?>
      <?php if (scope_branch() === null): ?>
      <div><label>Branch</label><select name="branch"><option value="">All branches</option>
        <?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $fBranch === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <button class="btn" type="submit">Apply</button>
    </form>
  </div>
  <?php if ($note): ?><div class="card-body pt-10-pb-0"><span class="hint"><?= e($note) ?></span></div><?php endif; ?>
  <div class="table-wrap mt-14"><table class="table">
    <thead><tr><?php foreach ($head as $i => $h): ?><th class="<?= in_array($types[$i], ['money', 'qty', 'int'], true) ? 'right' : '' ?>"><?= e($h) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr><?php foreach ($row as $i => $v): ?><td class="<?= in_array($types[$i], ['money', 'qty', 'int'], true) ? 'right' : '' ?>"><?= $head[$i] === 'Status' && $v !== '' ? badge((string)$v) : cell($v, $types[$i]) ?></td><?php endforeach; ?></tr>
    <?php endforeach; if (!$rows): ?>
      <tr><td colspan="<?= count($head) ?>" class="empty">No data for this report and date range.</td></tr>
    <?php endif; ?>
    </tbody>
    <?php if ($foot && $rows): ?>
    <tfoot><tr class="tfoot-row"><?php foreach ($foot as $i => $v): ?><td class="<?= in_array($types[$i], ['money', 'qty', 'int'], true) ? 'right' : '' ?>"><?= $v === '' ? '' : cell($v, $types[$i]) ?></td><?php endforeach; ?></tr></tfoot>
    <?php endif; ?>
  </table></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
