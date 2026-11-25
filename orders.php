<?php
require_once __DIR__ . '/config.php';
require_login(['staff', 'owner']);
$pageTitle = 'Orders';
$active    = 'orders';

$fStatus = g('status'); $fDue = g('due'); $q = g('q');
$pp = []; $w = '1=1' . scope_sql('o.branch_id', $pp);
if (in_array($fStatus, ORDER_STATUSES, true)) { $w .= ' AND o.status = ?'; $pp[] = $fStatus; }
if ($fDue === 'overdue') { $w .= " AND o.due_date < CURDATE() AND o.status NOT IN ('Completed','Cancelled')"; }
if ($fDue === 'week')    { $w .= " AND o.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND o.status NOT IN ('Completed','Cancelled')"; }
if ($q !== '') { $w .= ' AND (o.order_number LIKE ? OR c.name LIKE ?)'; $pp[] = "%$q%"; $pp[] = "%$q%"; }

$rows = qa("SELECT o.*, c.name customer,
              COALESCE((SELECT SUM(amount) FROM payments WHERE order_id = o.id), 0) paid,
              COALESCE((SELECT SUM(quantity) FROM order_items WHERE order_id = o.id), 0) pieces
            FROM orders o
            JOIN customers c ON c.id = o.customer_id
            WHERE $w ORDER BY (o.status = 'Pending') DESC, o.id DESC LIMIT 200", $pp);

// Counts for the status tabs.
$pc = []; $wc = '1=1' . scope_sql('o.branch_id', $pc);
$counts = [];
foreach (qa("SELECT o.status, COUNT(*) n FROM orders o WHERE $wc GROUP BY o.status", $pc) as $r) { $counts[$r['status']] = (int)$r['n']; }
$totalAll = array_sum($counts);
$qs = fn(array $extra) => '?' . http_build_query(array_filter(array_merge(['q' => $q, 'due' => $fDue], $extra), fn($v) => $v !== ''));

require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="card-head">
    <h2><?= can_transact() ? 'All Orders' : 'Orders (view only)' ?></h2>
    <?php if (can_transact()): ?><a class="btn" href="order_form.php">+ New walk-in order</a><?php endif; ?>
  </div>

  <div class="card-body pb-0">
    <div class="tabs">
      <a class="tab <?= $fStatus === '' ? 'active' : '' ?>" href="orders.php<?= e($qs(['status' => ''])) ?>">All <span class="tab-n"><?= $totalAll ?></span></a>
      <?php foreach (ORDER_STATUSES as $s): ?>
        <a class="tab <?= $fStatus === $s ? 'active' : '' ?>" href="orders.php<?= e($qs(['status' => $s])) ?>"><?= e($s) ?> <span class="tab-n"><?= (int)($counts[$s] ?? 0) ?></span></a>
      <?php endforeach; ?>
    </div>
    <form method="get" class="filters">
      <?php if ($fStatus !== ''): ?><input type="hidden" name="status" value="<?= e($fStatus) ?>"><?php endif; ?>
      <div><label>Search</label><input type="search" name="q" value="<?= e($q) ?>" placeholder="Order # or customer"></div>
      <div><label>Due date</label><select name="due"><option value="">Any</option>
        <option value="overdue" <?= $fDue === 'overdue' ? 'selected' : '' ?>>Overdue</option>
        <option value="week" <?= $fDue === 'week' ? 'selected' : '' ?>>Due in 7 days</option></select></div>
      <button class="btn btn-outline" type="submit">Search</button>
      <a class="btn btn-outline" href="orders.php">Clear</a>
    </form>
  </div>

  <div class="table-wrap mt-14"><table class="table">
    <thead><tr><th>Order #</th><th>Customer</th><th class="right">Pieces</th><th>Ordered</th><th>Due</th><th>Status</th><th class="right">Total</th><th class="right">Balance</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $bal = (float)$r['total_amount'] - (float)$r['paid']; $late = is_overdue($r['due_date'], $r['status']); ?>
      <tr>
        <td class="nowrap"><a href="order_view.php?id=<?= (int)$r['id'] ?>"><strong><?= e($r['order_number']) ?></strong></a>
          <?php if ($r['source'] === 'Online'): ?> <span class="badge s-online">Online</span><?php endif; ?></td>
        <td><?= e($r['customer']) ?></td>
        <td class="right"><?= (int)$r['pieces'] ?></td>
        <td class="nowrap"><?= e(date('M j, Y', strtotime($r['order_date']))) ?></td>
        <td class="nowrap"><?php if ($r['due_date']): ?><span class="<?= $late ? 'late' : '' ?>"><?= e(date('M j, Y', strtotime($r['due_date']))) ?></span>
          <?= $late ? '<span class="badge s-overdue">Overdue</span>' : '' ?><?php else: ?><span class="muted">&mdash;</span><?php endif; ?></td>
        <td><?= badge($r['status']) ?></td>
        <td class="right"><?= e(money($r['total_amount'])) ?></td>
        <td class="right"><strong><?= e(money($r['status'] === 'Cancelled' ? 0 : $bal)) ?></strong></td>
        <td><div class="actions">
          <?php if (can_transact() && $r['status'] === 'Pending'): ?>
            <a class="btn btn-sm" href="order_view.php?id=<?= (int)$r['id'] ?>#payment">Confirm payment</a>
          <?php else: ?>
            <a class="btn btn-sm btn-outline" href="order_view.php?id=<?= (int)$r['id'] ?>">View</a>
          <?php endif; ?>
          <?php if (can_transact()): ?>
            <?php if (order_can_edit($r['status'])): ?>
              <a class="btn btn-sm btn-outline" href="order_form.php?id=<?= (int)$r['id'] ?>">Edit</a>
            <?php else: ?>
              <span class="btn btn-sm btn-outline is-disabled" title="Orders cannot be edited once production starts or the order is closed." aria-disabled="true">Edit</span>
            <?php endif; ?>
          <?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="empty">No orders found.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
