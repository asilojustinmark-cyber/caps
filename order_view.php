<?php
require_once __DIR__ . '/config.php';
$pageScript = 'order-view';
require_login(['staff', 'owner', 'manager']);
$user = auth();
$id   = (int)g('id');

function load_order(int $id): ?array
{
    $pp = [$id]; $sc = scope_sql('o.branch_id', $pp);
    return qa("SELECT o.*, c.name customer, c.contact_number, c.email cemail, c.address, c.user_id customer_user,
                      u.name creator
               FROM orders o
               JOIN customers c ON c.id = o.customer_id
               LEFT JOIN users u ON u.id = o.created_by
               WHERE o.id = ? $sc", $pp)[0] ?? null;
}

$order = load_order($id);
if (!$order) { flash('Order not found.', 'err'); redirect(role_is('manager') ? 'index.php' : 'orders.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $action = p('action');

        if ($action === 'status') {
            if (!can_transact()) { deny_write('Changing the order status'); }
            order_move($order, p('status'));
            flash('Order is now ' . p('status') . '.');

        } elseif ($action === 'payment') {
            if (!can_transact()) { deny_write('Confirming payments'); }
            $date = date('Y-m-d H:i:s');   // always the real time; staff cannot back-date a payment
            $pid  = record_payment($order, p('payment_type'), (float)p('amount'), $date, p('reference_number'), p('notes'));
            flash('Payment confirmed. Here is the receipt.');
            redirect('receipt.php?payment=' . $pid);

        } elseif ($action === 'delete_payment') {
            if (!role_is('owner')) { deny_write('Voiding a payment'); }
            if (in_array($order['status'], ['Completed', 'Cancelled'], true)) { throw new RuntimeException('Payments of a closed order cannot be voided.'); }
            $pid = (int)p('payment_id');
            $n = run("DELETE FROM payments WHERE id=? AND order_id=?", [$pid, $id]);
            if ($n) { audit('delete', 'payment', $pid, 'Voided a payment on ' . $order['order_number']); }
            flash('Payment voided.');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'err');
    } catch (PDOException $ex) {
        flash(db_error_message($ex), 'err');
    }
    redirect('order_view.php?id=' . $id);
}

$items    = order_items_full($id);
$payments = qa("SELECT pay.*, u.name recorder FROM payments pay LEFT JOIN users u ON u.id = pay.recorded_by
                WHERE pay.order_id=? ORDER BY pay.payment_date, pay.id", [$id]);
$usage    = qa("SELECT t.*, m.name material, m.unit, u.name user
                FROM inventory_transactions t
                JOIN materials m ON m.id = t.material_id
                LEFT JOIN users u ON u.id = t.user_id
                WHERE t.order_id=? AND t.transaction_type='Material Usage' ORDER BY t.transaction_date, t.id", [$id]);
$history  = qa("SELECT * FROM audit_logs WHERE (entity='order' AND entity_id=?) ORDER BY id DESC LIMIT 8", [$id]);
$paid     = array_sum(array_column($payments, 'amount'));
$balance  = max(0, (float)$order['total_amount'] - $paid);
$late     = is_overdue($order['due_date'], $order['status']);
$status   = $order['status'];
$pieces   = array_sum(array_column($items, 'quantity'));

$idx   = array_search($status, ORDER_FLOW, true);          // false for Cancelled
$nextS = ORDER_NEXT[$status][0] ?? null;                   // the one allowed forward move (not Cancelled)
if ($nextS === 'Confirmed') { $nextS = null; }             // Pending is confirmed by a payment

$pageTitle = 'Order ' . $order['order_number'];
$active    = role_is('manager') ? 'dashboard' : 'orders';
require __DIR__ . '/includes/header.php';
?>
<div class="toolbar no-print mb-18">
  <a class="btn btn-outline" href="<?= role_is('manager') ? 'index.php' : 'orders.php' ?>">&larr; <?= role_is('manager') ? 'Dashboard' : 'All orders' ?></a>
  <span class="spacer"></span>
  <?php if (!role_is('manager')): ?><a class="btn btn-outline" href="receipt.php?id=<?= (int)$id ?>" target="_blank">Print statement</a><?php endif; ?>
  <?php if (can_transact()): ?>
    <?php if (order_can_edit($status)): ?>
      <a class="btn" href="order_form.php?id=<?= (int)$id ?>">Edit order</a>
    <?php else: ?>
      <span class="btn is-disabled" title="Orders cannot be edited once production starts or the order is closed." aria-disabled="true"><?= icon('lock') ?> Edit order</span>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="grid-2">
  <div>
    <div class="card">
      <div class="card-head"><h2><?= e($order['order_number']) ?> <?php if ($order['source'] === 'Online'): ?><span class="badge s-online">Online order</span><?php endif; ?></h2><?= badge($status) ?></div>
      <div class="card-body">
        <dl class="kv">
          <dt>Customer</dt><dd><strong><?= e($order['customer']) ?></strong> <?= $order['customer_user'] ? '<span class="badge">Has account</span>' : '<span class="badge">Walk-in</span>' ?></dd>
          <dt>Contact</dt><dd><?= e($order['contact_number']) ?: '&mdash;' ?><?= $order['cemail'] ? ' &middot; ' . e($order['cemail']) : '' ?></dd>
          <dt>Address</dt><dd><?= e($order['address']) ?: '&mdash;' ?></dd>
          <dt>Ordered</dt><dd><?= e(date('F j, Y g:i A', strtotime($order['order_date']))) ?></dd>
          <dt>Due / pick-up</dt><dd><?php if ($order['due_date']): ?><strong class="<?= $late ? 'late' : '' ?>"><?= e(date('F j, Y', strtotime($order['due_date']))) ?></strong> <?= $late ? '<span class="badge s-overdue">Overdue</span>' : '' ?><?php else: ?>&mdash;<?php endif; ?></dd>
          <dt>Created by</dt><dd><?= e($order['creator'] ?? 'Customer (online shop)') ?></dd>
          <dt>Notes</dt><dd><?= nl2br(e($order['notes'])) ?: '&mdash;' ?></dd>
        </dl>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Items &amp; Players</h2><span class="muted"><?= (int)$pieces ?> pieces</span></div>
      <div class="card-body">
        <?php foreach ($items as $it): ?>
          <div class="item-block">
            <div class="row-between">
              <div><strong><?= e($it['garment_type']) ?></strong> <span class="muted">&times; <?= (int)$it['quantity'] ?> at <?= e(money($it['unit_price'])) ?></span></div>
              <strong><?= e(money($it['subtotal'])) ?></strong>
            </div>
            <?php if ($it['design_specifications']): ?><div class="hint"><?= e($it['design_specifications']) ?></div><?php endif; ?>
            <?php if ($it['players']): ?>
              <div class="dim-chips"><?php foreach (array_count_values(array_column($it['players'], 'size')) as $sz => $n): ?><span class="chip"><strong><?= e($sz) ?></strong> &times;<?= (int)$n ?></span><?php endforeach; ?></div>
              <table class="table table-tight mt-8"><thead><tr><th>#</th><th>Player</th><th>Jersey no.</th><th>Size</th></tr></thead><tbody>
                <?php foreach ($it['players'] as $i => $pl): ?>
                  <tr><td><?= $i + 1 ?></td><td><?= e($pl['name']) ?: '&mdash;' ?></td><td><?= e($pl['number']) ?: '&mdash;' ?></td><td><strong><?= e($pl['size']) ?></strong></td></tr>
                <?php endforeach; ?></tbody></table>
            <?php endif; ?>
            <?php if ($it['dims']): ?><div class="dim-chips"><?php foreach ($it['dims'] as $d): ?><span class="chip"><?= e($d['dimension_name']) ?>: <strong><?= e(qty($d['dimension_value'])) ?> <?= e($d['unit']) ?></strong></span><?php endforeach; ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div class="sum-row big"><span>Order total</span><span><?= e(money($order['total_amount'])) ?></span></div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Materials Used</h2><span class="muted">recorded by the manager</span></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Date</th><th>Material</th><th class="right">Quantity</th><th>By</th><th>Notes</th></tr></thead>
        <tbody>
        <?php foreach ($usage as $t): ?>
          <tr>
            <td class="nowrap"><?= e(date('M j, Y g:i A', strtotime($t['transaction_date']))) ?></td>
            <td><strong><?= e($t['material']) ?></strong></td>
            <td class="right nowrap"><?= e(qty($t['quantity'])) ?> <?= e($t['unit']) ?></td>
            <td><?= e($t['user']) ?></td>
            <td><?= e($t['notes']) ?></td>
          </tr>
        <?php endforeach; if (!$usage): ?><tr><td colspan="5" class="empty">No materials recorded for this order yet.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <div>
    <div class="card no-print">
      <div class="card-head"><h2>Order Progress</h2><?= badge($status) ?></div>
      <div class="card-body">
        <?php if ($status === 'Cancelled'): ?>
          <div class="notice">This order was cancelled. It is locked.</div>
        <?php else: ?>
          <ol class="stepper">
            <?php foreach (ORDER_FLOW as $i => $s): $cls = $i < $idx ? 'done' : ($i === $idx ? 'now' : ''); ?>
              <li class="<?= $cls ?>"><span class="dot"><?= $i < $idx ? icon('check') : $i + 1 ?></span><span class="lbl"><?= e($s) ?></span></li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>

        <?php if (can_transact()): ?>
          <div class="progress-actions">
          <?php if ($status === 'Pending'): ?>
            <p class="hint">Waiting for payment. Confirm the down payment or full payment (below) to confirm this order.</p>
            <form method="post" data-confirm="Cancel this order? This cannot be undone.">
              <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="status" value="Cancelled">
              <button class="btn btn-outline btn-danger" type="submit">Cancel order</button></form>
          <?php elseif ($nextS): ?>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="status" value="<?= e($nextS) ?>">
              <?php $blocked = $nextS === 'Completed' && $balance > 0.005; ?>
              <button class="btn <?= $blocked ? 'is-disabled' : '' ?>" type="submit" <?= $blocked ? 'disabled' : '' ?>><?= e(STATUS_ACTIONS[$nextS]) ?></button>
              <?php if ($blocked): ?><p class="hint mt-10">Collect the balance of <strong><?= e(money($balance)) ?></strong> first, then release the order.</p><?php endif; ?>
            </form>
            <p class="hint"><?= icon('lock') ?> An order only moves forward. Earlier steps are locked.</p>
          <?php else: ?>
            <p class="hint"><?= icon('lock') ?> This order is completed and locked.</p>
          <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card" id="payment">
      <div class="card-head"><h2>Payment</h2></div>
      <div class="card-body">
        <div class="sum-row"><span>Order total</span><strong><?= e(money($order['total_amount'])) ?></strong></div>
        <div class="sum-row"><span>Paid</span><strong><?= e(money($paid)) ?></strong></div>
        <div class="sum-row big"><span>Balance due</span><span><?= e(money($status === 'Cancelled' ? 0 : $balance)) ?></span></div>

        <?php if (can_transact() && !in_array($status, ['Cancelled', 'Completed'], true) && $balance > 0.005): ?>
        <form method="post" class="form-grid cols-1 pay-form no-print" data-pay data-total="<?= e(number_format((float)$order['total_amount'], 2, '.', '')) ?>" data-balance="<?= e(number_format($balance, 2, '.', '')) ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="payment">
          <?php if ($paid <= 0.005): ?>
            <h3 class="pay-title">Confirm payment</h3>
            <div class="choice-row">
              <label class="choice"><input type="radio" name="payment_type" value="Down Payment" checked data-pay-type><span><b>Down payment</b><small>Part of the total now, the rest later</small></span></label>
              <label class="choice"><input type="radio" name="payment_type" value="Full Payment" data-pay-type><span><b>Full payment</b><small>The whole total, nothing left</small></span></label>
            </div>
          <?php else: ?>
            <h3 class="pay-title">Collect the balance</h3>
            <input type="hidden" name="payment_type" value="Balance Payment">
          <?php endif; ?>
          <div><label>Amount received (<?= e(CURRENCY) ?>) *</label>
            <input type="number" step="0.01" min="0.01" max="<?= e(number_format($balance, 2, '.', '')) ?>" name="amount" required data-amount
                   value="<?= $paid > 0.005 ? e(number_format($balance, 2, '.', '')) : '' ?>" placeholder="<?= $paid > 0.005 ? '' : 'e.g. ' . e(number_format($balance / 2, 2, '.', '')) ?>">
            <div class="hint" data-amount-hint></div></div>
          <div><label>Date &amp; time</label><input value="<?= e(date('M j, Y g:i A')) ?>" readonly disabled><div class="hint">Set automatically.</div></div>
          <div><label>Receipt / reference no.</label><input name="reference_number" maxlength="100" placeholder="Optional"></div>
          <div class="full"><label>Notes</label><input name="notes" maxlength="255"></div>
          <div class="full"><button class="btn btn-lg btn-block" type="submit"><?= $paid > 0.005 ? 'Record payment & print receipt' : 'Confirm payment & print receipt' ?></button></div>
        </form>
        <?php elseif ($balance <= 0.005 && $status !== 'Cancelled'): ?>
          <p class="paid-ok"><?= icon('check') ?> Fully paid</p>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($history): ?>
    <div class="card no-print">
      <div class="card-head"><h2>Recent Activity</h2></div>
      <div class="card-body">
        <?php foreach ($history as $h): ?>
          <div class="sum-row d-block"><strong><?= e($h['user_name']) ?></strong> &middot; <span class="muted"><?= e(date('M j, g:i A', strtotime($h['created_at']))) ?></span><br><?= e($h['details']) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>Payment History</h2></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Type</th><th class="right">Amount</th><th>Reference</th><th>Recorded by</th><th class="no-print"></th></tr></thead>
    <tbody>
    <?php foreach ($payments as $pm): ?>
      <tr>
        <td class="nowrap"><?= e(date('M j, Y g:i A', strtotime($pm['payment_date']))) ?></td>
        <td><span class="badge"><?= e($pm['payment_type']) ?></span></td>
        <td class="right"><strong><?= e(money($pm['amount'])) ?></strong></td>
        <td><?= e($pm['reference_number']) ?></td>
        <td><?= e($pm['recorder']) ?></td>
        <td class="no-print"><div class="actions">
          <?php if (!role_is('manager')): ?><a class="btn btn-sm btn-outline" href="receipt.php?payment=<?= (int)$pm['id'] ?>" target="_blank">Receipt</a><?php endif; ?>
          <?php if (role_is('owner') && !in_array($status, ['Completed', 'Cancelled'], true)): ?>
          <form method="post" class="inline" data-confirm="Void this payment? Use this only to fix a wrong entry.">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete_payment"><input type="hidden" name="payment_id" value="<?= (int)$pm['id'] ?>">
            <button class="btn btn-sm btn-outline" type="submit">Void</button></form><?php endif; ?></div></td>
      </tr>
    <?php endforeach; if (!$payments): ?><tr><td colspan="6" class="empty">No payments yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>