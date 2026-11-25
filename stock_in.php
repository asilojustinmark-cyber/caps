<?php
require_once __DIR__ . '/config.php';
$pageScript = 'stock';
require_login(['manager']);
$pageTitle = 'Stock In';
$active    = 'stockin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (!can_stock()) { deny_write('Adding stock'); }
        $date = date('Y-m-d H:i:s');   // always the real time
        $m = apply_stock_movement((int)p('material_id'), 'Stock In', (float)p('quantity'), null, p('reference'), p('notes'), $date);
        flash('Added ' . qty(p('quantity')) . ' ' . $m['unit'] . ' of ' . $m['name'] . ' to stock.');
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'err');
    } catch (PDOException $ex) {
        flash(db_error_message($ex), 'err');
    }
    redirect('stock_in.php');
}

$pm = []; $scm = scope_sql('branch_id', $pm);
$materials = qa("SELECT id, name, unit, current_quantity, reorder_level FROM materials WHERE status='Active' $scm ORDER BY name", $pm);
$pick = (int)g('material');

$fMat = (int)g('material_id');
$pp = []; $w = "t.transaction_type = 'Stock In'" . scope_sql('t.branch_id', $pp);
if ($fMat) { $w .= ' AND t.material_id = ?'; $pp[] = $fMat; }
$rows = qa("SELECT t.*, m.name material, m.unit, u.name user
            FROM inventory_transactions t
            JOIN materials m ON m.id = t.material_id
            LEFT JOIN users u ON u.id = t.user_id
            WHERE $w ORDER BY t.transaction_date DESC, t.id DESC LIMIT 100", $pp);

require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="card-head"><h2>Receive materials</h2><span class="muted">Add what was delivered to your stock</span></div>
  <div class="card-body">
    <form method="post" class="form-grid">
      <?= csrf_field() ?>
      <div><label for="material_id">Material *</label>
        <select id="material_id" name="material_id" required><option value="">Choose a material</option>
          <?php foreach ($materials as $m): $low = (float)$m['current_quantity'] <= (float)$m['reorder_level']; ?>
            <option value="<?= (int)$m['id'] ?>" data-unit="<?= e($m['unit']) ?>" data-qty="<?= e(qty($m['current_quantity'])) ?>" <?= $pick === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?><?= $low ? ' (low)' : '' ?></option>
          <?php endforeach; ?></select>
        <div class="hint" id="onHand"></div></div>
      <div><label for="quantity">Quantity received *</label>
        <div class="input-unit"><input id="quantity" type="number" step="0.01" min="0.01" name="quantity" required><span id="unitLabel"></span></div></div>
      <div><label for="reference">Supplier / delivery receipt no.</label><input id="reference" name="reference" maxlength="100" placeholder="Optional"></div>
      <div><label>Date &amp; time received</label><input value="<?= e(date('M j, Y g:i A')) ?>" readonly disabled><div class="hint">Set automatically.</div></div>
      <div class="full"><label for="notes">Notes</label><input id="notes" name="notes" maxlength="255"></div>
      <div class="full"><button class="btn btn-lg" type="submit"><?= icon('download') ?> Add to stock</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h2>Stock In history</h2>
    <form method="get" class="filters">
      <select name="material_id" aria-label="Filter by material"><option value="">All materials</option>
        <?php foreach ($materials as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $fMat === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select>
      <button class="btn btn-outline" type="submit">Show</button>
    </form>
  </div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Material</th><th class="right">Quantity</th><th>Supplier / reference</th><th>Added by</th><th>Notes</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $t): ?>
      <tr>
        <td class="nowrap"><?= e(date('M j, Y g:i A', strtotime($t['transaction_date']))) ?></td>
        <td><strong><?= e($t['material']) ?></strong></td>
        <td class="right nowrap">+<?= e(qty($t['quantity'])) ?> <?= e($t['unit']) ?></td>
        <td><?= e($t['reference']) ?></td>
        <td><?= e($t['user']) ?></td>
        <td><?= e($t['notes']) ?></td>
      </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="empty">No stock received yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>