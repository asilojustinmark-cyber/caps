<?php
/* OWNER dashboard: the business at a glance. Read-only except prices. */
$months = [];
for ($i = 5; $i >= 0; $i--) { $k = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); $months[$k] = ['label' => date('M', strtotime($k . '-01')), 'in' => 0.0, 'out' => 0.0]; }
$since = date('Y-m-01', strtotime('-5 month'));
foreach (qa("SELECT payment_date d, amount FROM payments WHERE payment_date >= ?", [$since]) as $r) { $k = substr($r['d'], 0, 7); if (isset($months[$k])) { $months[$k]['in'] += (float)$r['amount']; } }
foreach (qa("SELECT expense_date d, amount FROM expenses WHERE expense_date >= ?", [$since]) as $r) { $k = substr($r['d'], 0, 7); if (isset($months[$k])) { $months[$k]['out'] += (float)$r['amount']; } }
$cur = $months[date('Y-m')];
$net = $cur['in'] - $cur['out'];
$maxM = max(1, ...array_map(fn($m) => max($m['in'], $m['out']), array_values($months)));

$topProducts = qa("SELECT i.garment_type name, SUM(i.quantity) pieces, SUM(i.subtotal) sales
                   FROM order_items i JOIN orders o ON o.id = i.order_id WHERE o.status <> 'Cancelled'
                   GROUP BY i.garment_type ORDER BY sales DESC LIMIT 5");
$cats = qa("SELECT category, SUM(amount) total FROM expenses WHERE expense_date >= ? GROUP BY category ORDER BY total DESC LIMIT 5", [date('Y-m-01')]);
$maxCat = max(1, ...array_map(fn($c) => (float)$c['total'], $cats ?: [['total' => 1]]));
$maxTop = max(1, ...array_map(fn($c) => (float)$c['sales'], $topProducts ?: [['sales' => 1]]));
$orders  = (int)q1("SELECT COUNT(*) FROM orders WHERE status <> 'Cancelled'");
$open    = (int)q1("SELECT COUNT(*) FROM orders WHERE status IN ('Pending','Confirmed','In Production','Ready')");
$owing   = (float)q1("SELECT COALESCE(SUM(o.total_amount - COALESCE((SELECT SUM(amount) FROM payments WHERE order_id=o.id),0)),0) FROM orders o WHERE o.status <> 'Cancelled'");
$pendingUsers = (int)q1("SELECT COUNT(*) FROM users WHERE status='Pending'");
?>
<section class="role-banner role-owner">
  <div><h2>Business Overview</h2><p>Sales, expenses and prices. You can look at everything and change the price list.</p></div>
  <div class="role-actions">
    <a class="btn btn-lg" href="reports.php">Open reports</a>
    <a class="btn btn-lg btn-light" href="products.php">Edit prices</a>
  </div>
</section>

<?php if ($pendingUsers): ?>
  <div class="flash"><strong><?= $pendingUsers ?> team registration<?= $pendingUsers > 1 ? 's' : '' ?></strong> waiting for your approval. <a href="users.php">Review</a></div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><div class="ico green"><?= icon('card') ?></div><div class="txt"><span>Income this month</span><strong><?= e(money($cur['in'])) ?></strong></div></div>
  <div class="kpi"><div class="ico red"><?= icon('tag') ?></div><div class="txt"><span>Expenses this month</span><strong><?= e(money($cur['out'])) ?></strong></div></div>
  <div class="kpi"><div class="ico blue"><?= icon('chart') ?></div><div class="txt"><span>Net this month</span><strong><?= e(money($net)) ?></strong><small class="<?= $net < 0 ? 'warn' : '' ?>"><?= $net < 0 ? 'spending more than earning' : 'in the green' ?></small></div></div>
  <div class="kpi"><div class="ico amber"><?= icon('receipt') ?></div><div class="txt"><span>Unpaid by customers</span><strong><?= e(money($owing)) ?></strong><small><?= $open ?> open of <?= $orders ?> orders</small></div></div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card-head"><h2>Income vs expenses (6 months)</h2><a class="link-more" href="reports.php">Reports</a></div>
    <div class="card-body">
      <div class="chart-legend"><span class="lg lg-in">Income</span><span class="lg lg-out">Expenses</span></div>
      <div class="month-chart">
        <?php foreach ($months as $m): ?>
          <div class="mc-col">
            <div class="mc-bars">
              <i class="mc-in" data-h="<?= round($m['in'] / $maxM * 100) ?>" title="Income <?= e(money($m['in'])) ?>"></i>
              <i class="mc-out" data-h="<?= round($m['out'] / $maxM * 100) ?>" title="Expenses <?= e(money($m['out'])) ?>"></i>
            </div>
            <span><?= e($m['label']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div>
    <div class="card">
      <div class="card-head"><h2>Best sellers</h2><a class="link-more" href="products.php">Price list</a></div>
      <div class="card-body">
        <?php foreach ($topProducts as $t): ?>
          <div class="bar-row"><span><?= e($t['name']) ?> <small><?= (int)$t['pieces'] ?> pcs</small></span>
            <div class="bar-track"><div class="bar-fill" data-w="<?= round((float)$t['sales'] / $maxTop * 100) ?>"></div></div><b><?= e(money($t['sales'])) ?></b></div>
        <?php endforeach; if (!$topProducts): ?><div class="empty">No sales yet.</div><?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h2>Where the money went (this month)</h2><a class="link-more" href="expenses.php">Expenses</a></div>
      <div class="card-body">
        <?php foreach ($cats as $c): ?>
          <div class="bar-row"><span><?= e($c['category']) ?></span>
            <div class="bar-track"><div class="bar-fill bar-red" data-w="<?= round((float)$c['total'] / $maxCat * 100) ?>"></div></div><b><?= e(money($c['total'])) ?></b></div>
        <?php endforeach; if (!$cats): ?><div class="empty">No expenses this month.</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>
