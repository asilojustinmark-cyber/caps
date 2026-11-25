<?php
/* STAFF dashboard: what needs doing right now on the order desk. */
$toConfirm = qa("SELECT o.id, o.order_number, o.total_amount, o.order_date, c.name customer
                 FROM orders o JOIN customers c ON c.id = o.customer_id
                 WHERE o.status = 'Pending' ORDER BY o.id ASC LIMIT 8");
$toRelease = qa("SELECT o.id, o.order_number, c.name customer,
                        o.total_amount - COALESCE((SELECT SUM(amount) FROM payments WHERE order_id = o.id), 0) balance
                 FROM orders o JOIN customers c ON c.id = o.customer_id
                 WHERE o.status = 'Ready' ORDER BY o.id ASC LIMIT 8");
$late = qa("SELECT o.id, o.order_number, o.due_date, o.status, c.name customer
            FROM orders o JOIN customers c ON c.id = o.customer_id
            WHERE o.due_date IS NOT NULL AND o.due_date <= ? AND o.status NOT IN ('Completed','Cancelled')
            ORDER BY o.due_date ASC LIMIT 8", [date('Y-m-d', strtotime('+3 days'))]);
$today = date('Y-m-d');
$todayPaid  = (float)q1("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date >= ? AND payment_date < ?", [$today, date('Y-m-d', strtotime('+1 day'))]);
$todayCount = (int)q1("SELECT COUNT(*) FROM payments WHERE payment_date >= ? AND payment_date < ?", [$today, date('Y-m-d', strtotime('+1 day'))]);
$inProd     = (int)q1("SELECT COUNT(*) FROM orders WHERE status IN ('Confirmed','In Production')");
$owing      = (float)q1("SELECT COALESCE(SUM(o.total_amount - COALESCE((SELECT SUM(amount) FROM payments WHERE order_id=o.id),0)),0)
                         FROM orders o WHERE o.status NOT IN ('Cancelled')");
?>
<section class="role-banner role-staff">
  <div><h2>Order Desk</h2><p>Confirm payments, issue receipts and move orders forward.</p></div>
  <div class="role-actions">
    <a class="btn btn-lg" href="order_form.php">+ New walk-in order</a>
    <a class="btn btn-lg btn-light" href="orders.php?status=Pending">Orders to confirm (<?= count($toConfirm) ?>)</a>
  </div>
</section>

<div class="kpis">
  <div class="kpi"><div class="ico amber"><?= icon('clock') ?></div><div class="txt"><span>Waiting for payment</span><strong><?= count($toConfirm) ?></strong><small>Pending orders</small></div></div>
  <div class="kpi"><div class="ico blue"><?= icon('shirt') ?></div><div class="txt"><span>Being prepared</span><strong><?= $inProd ?></strong><small>Confirmed or in production</small></div></div>
  <div class="kpi"><div class="ico green"><?= icon('card') ?></div><div class="txt"><span>Collected today</span><strong><?= e(money($todayPaid)) ?></strong><small><?= $todayCount ?> payment<?= $todayCount === 1 ? '' : 's' ?></small></div></div>
  <div class="kpi"><div class="ico red"><?= icon('receipt') ?></div><div class="txt"><span>Still to collect</span><strong><?= e(money($owing)) ?></strong><small>unpaid balances</small></div></div>
</div>

<div class="grid-2">
  <div>
    <div class="card">
      <div class="card-head"><h2>1. Confirm payment</h2><a class="link-more" href="orders.php?status=Pending">See all</a></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Order #</th><th>Customer</th><th class="right">Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($toConfirm as $r): ?>
          <tr><td><strong><?= e($r['order_number']) ?></strong><div class="hint"><?= e(time_ago($r['order_date'])) ?></div></td>
            <td><?= e($r['customer']) ?></td><td class="right"><?= e(money($r['total_amount'])) ?></td>
            <td><a class="btn btn-sm" href="order_view.php?id=<?= (int)$r['id'] ?>#payment">Confirm payment</a></td></tr>
        <?php endforeach; if (!$toConfirm): ?><tr><td colspan="4" class="empty">No orders waiting for payment.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
    <div class="card">
      <div class="card-head"><h2>2. Ready for release</h2><a class="link-more" href="orders.php?status=Ready">See all</a></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Order #</th><th>Customer</th><th class="right">Balance</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($toRelease as $r): $owe = (float)$r['balance'] > 0.004; ?>
          <tr><td><strong><?= e($r['order_number']) ?></strong></td><td><?= e($r['customer']) ?></td>
            <td class="right"><?= $owe ? e(money($r['balance'])) : '<span class="badge s-ready">Paid</span>' ?></td>
            <td><a class="btn btn-sm <?= $owe ? 'btn-outline' : '' ?>" href="order_view.php?id=<?= (int)$r['id'] ?>#<?= $owe ? 'payment' : 'progress' ?>"><?= $owe ? 'Collect balance' : 'Release order' ?></a></td></tr>
        <?php endforeach; if (!$toRelease): ?><tr><td colspan="4" class="empty">Nothing is waiting for pick-up.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
  <div>
    <div class="card">
      <div class="card-head"><h2>Due within 3 days</h2><a class="link-more" href="orders.php?due=week">See all</a></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Order #</th><th>Customer</th><th>Due</th></tr></thead>
        <tbody>
        <?php foreach ($late as $r): $isLate = is_overdue($r['due_date'], $r['status']); ?>
          <tr><td><a href="order_view.php?id=<?= (int)$r['id'] ?>"><strong><?= e($r['order_number']) ?></strong></a><div class="hint"><?= badge($r['status']) ?></div></td>
            <td><?= e($r['customer']) ?></td>
            <td class="nowrap"><span class="<?= $isLate ? 'late' : '' ?>"><?= e(date('M j', strtotime($r['due_date']))) ?></span>
              <?= $isLate ? '<span class="badge s-overdue">Overdue</span>' : '' ?></td></tr>
        <?php endforeach; if (!$late): ?><tr><td colspan="3" class="empty">No deadlines coming up.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>
