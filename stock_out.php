<?php
require_once __DIR__ . '/config.php';
$pageScript = 'stock';
require_login(['manager']);
$pageTitle = 'Stock Out';
$active    = 'stockout';

const OUT_REASONS = ['order' => 'Used for an order', 'damaged' => 'Damaged or wasted', 'other' => 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (!can_stock()) { deny_write('Removing stock'); }

        if (p('action') === 'undo') {
            // Put the quantity back and remove the entry (a wrong entry, not a normal step).
            $tid = (int)p('id');
            $pdo->beginTransaction();
            $pp = [$tid]; $sc = scope_sql('t.branch_id', $pp);
            $t = qa("SELECT t.* FROM inventory_transactions t WHERE t.id=? AND t.transaction_type IN ('Stock Out','Material Usage') $sc", $pp)[0] ?? null;
            if (!$t) { throw new RuntimeException('That entry was not found.'); }
            run("UPDATE materials SET current_quantity = current_quantity + ? WHERE id=?", [$t['quantity'], $t['material_id']]);
            run("DELETE FROM inventory_transactions WHERE id=?", [$tid]);
            audit('delete', 'material', (int)$t['material_id'], 'Undid a stock-out of ' . qty($t['quantity']) . ' (returned to stock)');
            $pdo->commit();
            flash('Entry undone. The quantity went back to stock.');
        } else {
            $reason  = array_key_exists(p('reason'), OUT_REASONS) ? p('reason') : 'other';
            $orderId = null; $type = 'Stock Out'; $ref = OUT_REASONS[$reason];
            if ($reason === 'order') {
                $orderId = (int)p('order_id');
                $pp = [$orderId]; $sc = scope_sql('branch_id', $pp);
                $o = qa("SELECT order_number, status FROM orders WHERE id=? $sc", $pp)[0] ?? null;
                if (!$o) { throw new RuntimeException('Choose the order these materials are for.'); }
                if (!in_array($o['status'], ['Confirmed', 'In Production', 'Ready'], true)) {
                    throw new RuntimeException('Materials can only be used for a confirmed order that is not yet released.');
                }
                $type = 'Material Usage'; $ref = $o['order_number'];
            }
            $date = date('Y-m-d H:i:s');   // always the real time
            $m = apply_stock_movement((int)p('material_id'), $type, (float)p('quantity'), $orderId, $ref, p('notes'), $date);
            flash('Removed ' . qty(p('quantity')) . ' ' . $m['unit'] . ' of ' . $m['name'] . ' from stock.');
        }
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        flash($ex->getMessage(), 'err');
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        flash(db_error_message($ex), 'err');
    }
    redirect('stock_out.php');
}

$pm = []; $scm = scope_sql('branch_id', $pm);
$materials = qa("SELECT id, name, unit, current_quantity FROM materials WHERE status='Active' $scm ORDER BY name", $pm);
$po = []; $sco = scope_sql('o.branch_id', $po);
$openOrders = qa("SELECT o.id, o.order_number, c.name customer FROM orders o JOIN customers c ON c.id = o.customer_id
                  WHERE o.status IN ('Confirmed','In Production','Ready') $sco ORDER BY o.id DESC LIMIT 100", $po);
$pickOrder = (int)g('order');
$pickMat   = (int)g('material');

$fMat = (int)g('material_id');
$pp = []; $w = "t.transaction_type IN ('Stock Out','Material Usage')" . scope_sql('t.branch_id', $pp);
if ($fMat) { $w .= ' AND t.material_id = ?'; $pp[] = $fMat; }
$rows = qa("SELECT t.*, m.name material, m.unit, u.name user, o.order_number
            FROM inventory_transactions t
            JOIN materials m ON m.id = t.material_id
            LEFT JOIN users u ON u.id = t.user_id
            LEFT JOIN orders o ON o.id = t.order_id
            WHERE $w ORDER BY t.transaction_date DESC, t.id DESC LIMIT 100", $pp);

require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="card-head"><h2>Take materials out of stock</h2><span class="muted">For an order, or when something is damaged</span></div>
  <div class="card-body">
    <form method="post" class="form-grid">
      <?= csrf_field() ?>
      <div><label for="material_id">Material *</label>
        <select id="material_id" name="material_id" required><option value="">Choose a material</option>
          <?php foreach ($materials as $m): ?>
            <option value="<?= (int)$m['id'] ?>" data-unit="<?= e($m['unit']) ?>" data-qty="<?= e(qty($m['current_quantity'])) ?>" <?= $pickMat === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
          <?php endforeach; ?></select>
        <div class="hint" id="onHand"></div></div>
      <div><label for="quantity">Quantity to remove *</label>
        <div class="input-unit"><input id="quantity" type="number" step="0.01" min="0.01" name="quantity" required><span id="unitLabel"></span></div></div>
      <div><label for="reason">Reason *</label>
        <select id="reason" name="reason" required>
          <?php foreach (OUT_REASONS as $k => $label): ?><option value="<?= e($k) ?>" <?= $k === 'order' && ($pickOrder || !g('reason')) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div id="orderRow"><label for="order_id">Which order? *</label>
        <select id="order_id" name="order_id"><option value="">Choose an order</option>
          <?php foreach ($openOrders as $o): ?><option value="<?= (int)$o['id'] ?>" <?= $pickOrder === (int)$o['id'] ? 'selected' : '' ?>><?= e($o['order_number']) ?> &middot; <?= e($o['customer']) ?></option><?php endforeach; ?></select>
        <?php if (!$openOrders): ?><div class="hint">No confirmed orders to prepare right now.</div><?php endif; ?></div>
      <div><label>Date &amp; time</label><input value="<?= e(date('M j, Y g:i A')) ?>" readonly disabled><div class="hint">Set automatically.</div></div>
      <div><label for="notes">Notes</label><input id="notes" name="notes" maxlength="255"></div>
      <div class="full"><button class="btn btn-lg" type="submit"><?= icon('upload') ?> Remove from stock</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h2>Stock Out history</h2>
    <form method="get" class="filters">
      <select name="material_id" aria-label="Filter by material"><option value="">All materials</option>
        <?php foreach ($materials as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $fMat === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select>
      <button class="btn btn-outline" type="submit">Show</button>
    </form>
  </div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Material</th><th class="right">Quantity</th><th>Reason</th><th>Removed by</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $t): ?>
      <tr>
        <td class="nowrap"><?= e(date('M j, Y g:i A', strtotime($t['transaction_date']))) ?></td>
        <td><strong><?= e($t['material']) ?></strong></td>
        <td class="right nowrap">&minus;<?= e(qty($t['quantity'])) ?> <?= e($t['unit']) ?></td>
        <td><?php if ($t['order_number']): ?>For order <a href="order_view.php?id=<?= (int)$t['order_id'] ?>"><?= e($t['order_number']) ?></a><?php else: ?><?= e($t['reference']) ?><?php endif; ?>
          <?php if ($t['notes']): ?><div class="hint"><?= e($t['notes']) ?></div><?php endif; ?></td>
        <td><?= e($t['user']) ?></td>
        <td><form method="post" class="inline" data-confirm="Undo this entry? The quantity goes back to stock.">
          <?= csrf_field() ?><input type="hidden" name="action" value="undo"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button class="btn btn-sm btn-outline" type="submit">Undo</button></form></td>
      </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="empty">No stock taken out yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>