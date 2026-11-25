<?php
require_once __DIR__ . '/config.php';
require_login(['staff', 'owner']);
$pageTitle = can_transact() ? 'Payments & Receipts' : 'Payments';
$active    = 'payments';
$ptypes = ['Down Payment', 'Full Payment', 'Balance Payment'];

$fType = g('type'); $from = g('from'); $to = g('to'); $q = g('q');
$pp = []; $w = '1=1' . scope_sql('o.branch_id', $pp);
if (in_array($fType, $ptypes, true)) { $w .= ' AND pay.payment_type = ?'; $pp[] = $fType; }
if ($from !== '') { $w .= ' AND DATE(pay.payment_date) >= ?'; $pp[] = $from; }
if ($to !== '')   { $w .= ' AND DATE(pay.payment_date) <= ?'; $pp[] = $to; }
if ($q !== '')    { $w .= ' AND (o.order_number LIKE ? OR c.name LIKE ? OR pay.reference_number LIKE ?)'; array_push($pp, "%$q%", "%$q%", "%$q%"); }

$rows = qa("SELECT pay.*, o.order_number, o.id order_id, c.name customer, u.name recorder
            FROM payments pay
            JOIN orders o ON o.id = pay.order_id
            JOIN customers c ON c.id = o.customer_id
            LEFT JOIN users u ON u.id = pay.recorded_by
            WHERE $w ORDER BY pay.payment_date DESC, pay.id DESC LIMIT 300", $pp);
$total = array_sum(array_column($rows, 'amount'));

require __DIR__ . '/includes/header.php';
?>
<div class="kpis">
  <div class="kpi"><span>Payments shown</span><strong><?= count($rows) ?></strong></div>
  <div class="kpi"><span>Total collected</span><strong><?= e(money($total)) ?></strong></div>
</div>

<div class="card">
  <div class="card-head"><h2><?= can_transact() ? 'Payments &amp; Receipts' : 'Payment Records' ?></h2></div>
  <div class="card-body pb-0">
    <form method="get" class="filters">
      <div><label>Search</label><input type="search" name="q" value="<?= e($q) ?>" placeholder="Order #, customer, reference"></div>
      <div><label>Type</label><select name="type"><option value="">All types</option>
        <?php foreach ($ptypes as $t): ?><option <?= $fType === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
      <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
      <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="btn btn-outline" type="submit">Filter</button>
      <a class="btn btn-outline" href="payments.php">Reset</a>
    </form>
  </div>
  <div class="table-wrap mt-14"><table class="table">
    <thead><tr><th>Date</th><th>Order #</th><th>Customer</th><th>Type</th><th class="right">Amount</th><th>Reference</th><th>Recorded by</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap"><?= e(date('M j, Y g:i A', strtotime($r['payment_date']))) ?></td>
        <td><a href="order_view.php?id=<?= (int)$r['order_id'] ?>"><strong><?= e($r['order_number']) ?></strong></a></td>
        <td><?= e($r['customer']) ?></td>
        <td><span class="badge"><?= e($r['payment_type']) ?></span></td>
        <td class="right"><strong><?= e(money($r['amount'])) ?></strong></td>
        <td><?= e($r['reference_number']) ?></td>
        <td><?= e($r['recorder']) ?></td>
        <td><a class="btn btn-sm btn-outline" href="receipt.php?payment=<?= (int)$r['id'] ?>" target="_blank">Receipt</a></td>
      </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="empty">No payments found.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
