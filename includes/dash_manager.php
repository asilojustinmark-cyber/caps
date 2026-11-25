<?php
/* MANAGER dashboard: materials only. */
$pm = []; $scm = scope_sql('m.branch_id', $pm);
$low = qa("SELECT m.id, m.name, m.unit, m.current_quantity, m.reorder_level FROM materials m
           WHERE m.status='Active' AND m.current_quantity <= m.reorder_level $scm ORDER BY m.current_quantity ASC LIMIT 8", $pm);
$matCount = (int)q1("SELECT COUNT(*) FROM materials m WHERE m.status='Active' $scm", $pm);
$prep = qa("SELECT o.id, o.order_number, o.status, o.due_date, c.name customer
            FROM orders o JOIN customers c ON c.id = o.customer_id
            WHERE o.status IN ('Confirmed','In Production')
              AND NOT EXISTS (SELECT 1 FROM inventory_transactions t WHERE t.order_id = o.id AND t.transaction_type = 'Material Usage')
            ORDER BY o.due_date IS NULL, o.due_date ASC, o.id ASC LIMIT 8");
$moves = qa("SELECT t.transaction_type, t.quantity, t.transaction_date, m.name, m.unit, o.order_number
             FROM inventory_transactions t JOIN materials m ON m.id = t.material_id LEFT JOIN orders o ON o.id = t.order_id
             ORDER BY t.id DESC LIMIT 8");
$monthStart = date('Y-m-01');
$spent = (float)q1("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ?", [$monthStart]);
$inN   = (int)q1("SELECT COUNT(*) FROM inventory_transactions WHERE transaction_type='Stock In' AND transaction_date >= ?", [$monthStart]);
$outN  = (int)q1("SELECT COUNT(*) FROM inventory_transactions WHERE transaction_type IN ('Stock Out','Material Usage') AND transaction_date >= ?", [$monthStart]);
$fmt = fn($n) => rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
?>
<section class="role-banner role-manager">
  <div><h2>Stockroom</h2><p>Receive materials, take them out for jobs, and keep an eye on what is running low.</p></div>
  <div class="role-actions">
    <a class="btn btn-lg" href="stock_in.php"><?= icon('download') ?> Stock In</a>
    <a class="btn btn-lg btn-light" href="stock_out.php"><?= icon('upload') ?> Stock Out</a>
  </div>
</section>

<div class="kpis">
  <div class="kpi"><div class="ico red"><?= icon('box') ?></div><div class="txt"><span>Low on stock</span><strong><?= count($low) ?></strong><small>need re-ordering</small></div></div>
  <div class="kpi"><div class="ico blue"><?= icon('layers') ?></div><div class="txt"><span>Active materials</span><strong><?= $matCount ?></strong></div></div>
  <div class="kpi"><div class="ico green"><?= icon('download') ?></div><div class="txt"><span>Stock-ins this month</span><strong><?= $inN ?></strong></div></div>
  <div class="kpi"><div class="ico amber"><?= icon('upload') ?></div><div class="txt"><span>Stock-outs this month</span><strong><?= $outN ?></strong><small>Expenses: <?= e(money($spent)) ?></small></div></div>
</div>

<div class="grid-2">
  <div>
    <div class="card">
      <div class="card-head"><h2>Running low</h2><a class="link-more" href="materials.php?stock=low">See all</a></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Material</th><th class="right">On hand</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($low as $r): ?>
          <tr><td><strong><?= e($r['name']) ?></strong><div class="hint">Reorder at <?= e($fmt($r['reorder_level'])) ?></div></td>
            <td class="right"><span class="late"><?= e($fmt($r['current_quantity'])) ?> <?= e($r['unit']) ?></span></td>
            <td><a class="btn btn-sm" href="stock_in.php?material=<?= (int)$r['id'] ?>">Add stock</a></td></tr>
        <?php endforeach; if (!$low): ?><tr><td colspan="3" class="empty">Every material is above its reorder level.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
    <div class="card">
      <div class="card-head"><h2>Recent stock movement</h2></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Material</th><th>Type</th><th class="right">Qty</th></tr></thead>
        <tbody>
        <?php foreach ($moves as $r): $in = $r['transaction_type'] === 'Stock In'; ?>
          <tr><td><strong><?= e($r['name']) ?></strong><div class="hint"><?= e(date('M j, g:i A', strtotime($r['transaction_date']))) ?><?= $r['order_number'] ? ' · ' . e($r['order_number']) : '' ?></div></td>
            <td><span class="badge <?= $in ? 's-ready' : '' ?>"><?= $in ? 'In' : 'Out' ?></span></td>
            <td class="right"><?= $in ? '+' : '−' ?><?= e($fmt($r['quantity'])) ?> <?= e($r['unit']) ?></td></tr>
        <?php endforeach; if (!$moves): ?><tr><td colspan="3" class="empty">No stock movement yet.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
  <div>
    <div class="card">
      <div class="card-head"><h2>Orders needing materials</h2></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Order #</th><th>Due</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($prep as $r): ?>
          <tr><td><strong><?= e($r['order_number']) ?></strong><div class="hint"><?= e($r['customer']) ?></div></td>
            <td class="nowrap"><?= $r['due_date'] ? e(date('M j', strtotime($r['due_date']))) : '—' ?></td>
            <td><a class="btn btn-sm btn-outline" href="stock_out.php?order=<?= (int)$r['id'] ?>">Take out materials</a></td></tr>
        <?php endforeach; if (!$prep): ?><tr><td colspan="3" class="empty">All confirmed orders already have materials taken out.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>
