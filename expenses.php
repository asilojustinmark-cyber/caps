<?php
require_once __DIR__ . '/config.php';
require_login(['owner', 'manager']);
$pageTitle = 'Expenses';
$active    = 'expenses';
$me = auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (!can_expense()) { deny_write('Recording expenses'); }
        $action = p('action');
        $branch = main_branch_id();
        if ($action === 'save') {
            $id   = (int)p('id');
            $date = p('expense_date');
            if ($date > date('Y-m-d') || $date < date('Y-m-d', strtotime('-30 days'))) { throw new RuntimeException('The expense date must be within the last 30 days and not in the future.'); }
            $cat  = in_array(p('category'), EXPENSE_CATEGORIES, true) ? p('category') : 'Other';
            $amt  = round((float)p('amount'), 2);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { throw new RuntimeException('Choose a valid date.'); }
            if ($date > date('Y-m-d')) { throw new RuntimeException('The expense date cannot be in the future.'); }
            if ($amt <= 0 || $amt > 99999999) { throw new RuntimeException('Enter an amount greater than zero.'); }
            if ($id) {
                $n = run("UPDATE expenses SET expense_date=?, category=?, description=?, amount=?, reference=? WHERE id=? AND branch_id=?",
                         [$date, $cat, p('description'), $amt, p('reference'), $id, $branch]);
                audit('update', 'expense', $id, 'Updated expense ' . $cat . ' ' . number_format($amt, 2));
                flash('Expense updated.');
            } else {
                run("INSERT INTO expenses (branch_id, user_id, expense_date, category, description, amount, reference) VALUES (?,?,?,?,?,?,?)",
                    [$branch, $me['id'], $date, $cat, p('description'), $amt, p('reference')]);
                audit('create', 'expense', (int)$pdo->lastInsertId(), 'Recorded expense ' . $cat . ' ' . number_format($amt, 2));
                flash('Expense recorded.');
            }
        } elseif ($action === 'delete') {
            $id = (int)p('id');
            $n = run("DELETE FROM expenses WHERE id=? AND branch_id=?", [$id, $branch]);
            if ($n) { audit('delete', 'expense', $id, 'Deleted an expense'); }
            flash('Expense deleted.');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('expenses.php');
}

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', g('from')) ? g('from') : date('Y-m-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', g('to'))   ? g('to')   : date('Y-m-d');
$cat  = in_array(g('category'), EXPENSE_CATEGORIES, true) ? g('category') : '';

$pp = [$from, $to]; $w = '';
$w .= ' AND e.branch_id = ?'; $pp[] = main_branch_id();
if ($cat !== '')   { $w .= ' AND e.category = ?';  $pp[] = $cat; }
$rows = qa("SELECT e.*, u.name recorder FROM expenses e
            LEFT JOIN users u ON u.id = e.user_id
            WHERE e.expense_date BETWEEN ? AND ? $w ORDER BY e.expense_date DESC, e.id DESC", $pp);
$total = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));
$byCat = [];
foreach ($rows as $r) { $byCat[$r['category']] = ($byCat[$r['category']] ?? 0) + (float)$r['amount']; }
arsort($byCat);

require __DIR__ . '/includes/header.php';
?>
<div class="kpis">
  <div class="kpi"><span>Total expenses</span><strong><?= e(money($total)) ?></strong></div>
  <div class="kpi"><span>Records</span><strong><?= count($rows) ?></strong></div>
  <div class="kpi"><span>Biggest category</span><strong class="fs-18"><?= $byCat ? e(array_key_first($byCat)) : '&mdash;' ?></strong></div>
</div>

<div class="card">
  <div class="card-head">
    <h2>Expenses</h2>
    <?php if (can_expense()): ?><button class="btn" data-open="formModal" data-title="Record Expense">+ Record Expense</button>
    <?php else: ?><span class="muted">View only. The manager records expenses.</span><?php endif; ?>
  </div>
  <div class="card-body pb-0">
    <form method="get" class="filters">
      <input type="date" name="from" value="<?= e($from) ?>"><input type="date" name="to" value="<?= e($to) ?>">
      <select name="category"><option value="">All categories</option>
        <?php foreach (EXPENSE_CATEGORIES as $c): ?><option <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select>
      <button class="btn btn-outline" type="submit">Filter</button>
      <a class="btn btn-outline" href="expenses.php">Reset</a>
    </form>
  </div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Reference</th><th>Recorded by</th><th class="right">Amount</th><?php if (can_expense()): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap"><?= e(date('M j, Y', strtotime($r['expense_date']))) ?></td>
        <td><?= e($r['category']) ?></td>
        <td><?= e($r['description']) ?></td>
        <td><?= e($r['reference']) ?></td>
        <td><?= e($r['recorder']) ?></td>
        <td class="right"><strong><?= e(money($r['amount'])) ?></strong></td>
        <?php if (can_expense()): ?><td><div class="actions">
          <button class="btn btn-sm btn-outline" data-open="formModal" data-title="Edit Expense"
                  data-row='<?= e(json_encode(['id' => $r['id'], 'expense_date' => $r['expense_date'], 'category' => $r['category'], 'description' => $r['description'], 'amount' => $r['amount'], 'reference' => $r['reference']])) ?>'>Edit</button>
          <?= delete_form((int)$r['id']) ?></div></td><?php endif; ?>
      </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty">No expenses in this period.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($rows): ?><tfoot><tr class="tfoot-row"><td colspan="5">Total</td><td class="right"><strong><?= e(money($total)) ?></strong></td><?php if (can_expense()): ?><td></td><?php endif; ?></tr></tfoot><?php endif; ?>
  </table></div>
</div>

<?php if ($byCat): ?>
<div class="card mt-18">
  <div class="card-head"><h2>By category</h2></div>
  <div class="card-body">
    <?php foreach ($byCat as $c => $amt): $pct = $total > 0 ? round($amt / $total * 100) : 0; ?>
      <div class="bar-row cols-170"><span><?= e($c) ?></span>
        <div class="bar-track"><div class="bar-fill" data-w="<?= (int)$pct ?>"></div></div>
        <strong><?= e(money($amt)) ?> <span class="muted fw-400">(<?= (int)$pct ?>%)</span></strong></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (can_expense()): ?>
<div class="modal" id="formModal"><div class="modal-card">
  <div class="modal-head"><h3 class="modal-title">Expense</h3><button class="x" data-close type="button">&times;</button></div>
  <div class="modal-body">
    <form method="post" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="">
      <div><label>Date *</label><input type="date" name="expense_date" value="<?= e(date('Y-m-d')) ?>" min="<?= e(date('Y-m-d', strtotime('-30 days'))) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
      <div><label>Category *</label><select name="category"><?php foreach (EXPENSE_CATEGORIES as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></div>
      <div><label>Amount *</label><input type="number" step="0.01" min="0.01" name="amount" required></div>
      <div><label>Reference #</label><input name="reference" maxlength="100" placeholder="Receipt / invoice"></div>
      <div class="full"><label>Description</label><input name="description" maxlength="255" placeholder="e.g. 2 liters cyan ink"></div>
      <div class="full modal-foot">
        <button type="button" class="btn btn-outline" data-close>Cancel</button>
        <button class="btn" type="submit">Save</button>
      </div>
    </form>
  </div>
</div></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>