<?php
require_once __DIR__ . '/config.php';
require_customer();
$me         = auth();
$shopTitle  = 'Track your order';
$shopActive = 'orders';
$cid        = customer_id_for_user($me);

$no = strtoupper(trim(g('no') ?: p('no')));
if ($no === '') { redirect('account.php'); }

// A customer can only open their own orders.
$o = qa("SELECT o.*, b.name branch, b.location blocation FROM orders o JOIN branches b ON b.id = o.branch_id
         WHERE o.order_number = ? AND o.customer_id = ?", [$no, $cid])[0] ?? null;
if (!$o) { flash('We could not find that order in your account.', 'err'); redirect('account.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (p('action') === 'cancel') {
            order_move($o, 'Cancelled');          // only allowed while the order is Pending and unpaid
            audit('status', 'order', (int)$o['id'], $o['order_number'] . ' cancelled by the customer', $me['name']);
            notify_role('staff', 'Order ' . $o['order_number'] . ' was cancelled', $me['name'] . ' cancelled this order.', 'order_view.php?id=' . $o['id']);
            flash('Your order was cancelled.');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('track.php?no=' . urlencode($no));
}

$items    = order_items_full((int)$o['id']);
$payments = qa("SELECT * FROM payments WHERE order_id=? ORDER BY payment_date, id", [$o['id']]);
$paid     = array_sum(array_column($payments, 'amount'));
$balance  = max(0, (float)$o['total_amount'] - $paid);

$steps = [
    'Pending'       => ['Order sent',     'The shop received your order.'],
    'Confirmed'     => ['Payment confirmed', 'The shop confirmed your payment.'],
    'In Production' => ['Being made',     'Your order is in production.'],
    'Ready'         => ['Ready to pick up', 'Come to the shop to get your order.'],
    'Completed'     => ['Completed',      'You received your order.'],
];
$keys = array_keys($steps);
$idx  = array_search($o['status'], $keys, true);

$next = [
    'Pending'       => 'Please pay a down payment or the full amount at the shop. The shop confirms your payment and your order moves forward.',
    'Confirmed'     => 'Your payment is confirmed. The shop will start making your order soon.',
    'In Production' => 'Your order is being made. We will tell you when it is ready.',
    'Ready'         => 'Your order is ready. Bring your tracking number and pay the balance (if any) when you pick it up.',
    'Completed'     => 'Thank you for ordering from ' . APP_NAME . '!',
    'Cancelled'     => 'This order was cancelled.',
];

require __DIR__ . '/includes/shop_header.php';
?>
<p class="crumbs"><a href="account.php">My Orders</a> &rsaquo; <?= e($o['order_number']) ?></p>

<div class="sh-box">
  <div class="row-between">
    <div><div class="muted fs-12">TRACKING NUMBER</div><strong class="big-blue"><?= e($o['order_number']) ?></strong></div>
    <span class="status-line"><?= badge($o['status']) ?><span class="status-text"><?= e(customer_status_label($o['status'])) ?></span></span>
  </div>

  <?php if ($o['status'] !== 'Cancelled'): ?>
    <div class="timeline">
      <?php foreach ($keys as $i => $k): ?>
        <div class="tl-step <?= $i <= $idx ? 'done' : '' ?> <?= $i === $idx ? 'now' : '' ?>">
          <?= e($steps[$k][0]) ?>
          <?php if ($i === $idx): ?><small><?= e($steps[$k][1]) ?></small><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="notice"><?= e($next[$o['status']]) ?></div>

  <dl class="kv mt-18">
    <dt>Ordered on</dt><dd><?= e(date('F j, Y g:i A', strtotime($o['order_date']))) ?></dd>
    <dt>Needed by</dt><dd><?= $o['due_date'] ? e(date('F j, Y', strtotime($o['due_date']))) : '&mdash;' ?></dd>
    <dt>Pick up at</dt><dd><?= e($o['branch']) ?><?= $o['blocation'] ? ' (' . e($o['blocation']) . ')' : '' ?></dd>
    <?php if ($o['notes']): ?><dt>Your notes</dt><dd><?= nl2br(e($o['notes'])) ?></dd><?php endif; ?>
  </dl>
</div>

<div class="sh-box">
  <h2>Items and players</h2>
  <?php foreach ($items as $it): ?>
    <div class="cart-item">
      <div>
        <strong><?= e($it['garment_type']) ?></strong>
        <div class="meta"><?= (int)$it['quantity'] ?> &times; <?= e(money($it['unit_price'])) ?>
          <?php if ($it['design_specifications']): ?><br><?= e($it['design_specifications']) ?><?php endif; ?></div>
        <?php if ($it['players']): ?>
          <div class="dim-chips"><?php foreach (array_count_values(array_column($it['players'], 'size')) as $sz => $n): ?><span class="chip"><strong><?= e($sz) ?></strong> &times;<?= (int)$n ?></span><?php endforeach; ?></div>
          <details class="player-list" open>
            <summary>Player list (<?= count($it['players']) ?>)</summary>
            <table class="table table-tight"><thead><tr><th>#</th><th>Player</th><th>Jersey no.</th><th>Size</th></tr></thead><tbody>
              <?php foreach ($it['players'] as $i => $pl): ?>
                <tr><td><?= $i + 1 ?></td><td><?= e($pl['name']) ?: '&mdash;' ?></td><td><?= e($pl['number']) ?: '&mdash;' ?></td><td><strong><?= e($pl['size']) ?></strong></td></tr>
              <?php endforeach; ?></tbody></table>
          </details>
        <?php endif; ?>
        <?php if ($it['dims']): ?><div class="dim-chips"><?php foreach ($it['dims'] as $d): ?><span class="chip"><?= e($d['dimension_name']) ?>: <strong><?= e(qty($d['dimension_value'])) ?> <?= e($d['unit']) ?></strong></span><?php endforeach; ?></div><?php endif; ?>
      </div>
      <div class="sub"><?= e(money($it['subtotal'])) ?></div>
    </div>
  <?php endforeach; ?>
  <div class="sum-row mt-8"><span>Order total</span><strong><?= e(money($o['total_amount'])) ?></strong></div>
  <div class="sum-row"><span>Paid so far</span><strong><?= e(money($paid)) ?></strong></div>
  <div class="sum-row big"><span>Balance to pay at the shop</span><span><?= e(money($o['status'] === 'Cancelled' ? 0 : $balance)) ?></span></div>
</div>

<div class="sh-box">
  <h2>Payments and receipts</h2>
  <?php if ($payments): ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Date</th><th>Type</th><th class="right">Amount</th><th></th></tr></thead>
      <tbody><?php foreach ($payments as $pm): ?>
        <tr><td class="nowrap"><?= e(date('M j, Y g:i A', strtotime($pm['payment_date']))) ?></td><td><span class="badge"><?= e($pm['payment_type']) ?></span></td>
          <td class="right"><strong><?= e(money($pm['amount'])) ?></strong></td>
          <td class="right"><a class="btn btn-sm btn-outline" href="receipt.php?payment=<?= (int)$pm['id'] ?>" target="_blank">View receipt</a></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php else: ?>
    <p class="muted m-0">No payment yet. Pay at the shop and the receipt will appear here.</p>
  <?php endif; ?>
</div>

<div class="toolbar">
  <a class="btn btn-outline" href="account.php">&larr; My Orders</a>
  <span class="spacer"></span>
  <?php if ($o['status'] === 'Pending' && $paid < 0.005): ?>
    <form method="post" class="inline" data-confirm="Cancel order <?= e($o['order_number']) ?>? This cannot be undone." data-confirm-title="Cancel this order?" data-confirm-yes="Yes, cancel order" data-confirm-no="Keep order">
      <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="no" value="<?= e($o['order_number']) ?>">
      <button class="btn btn-outline btn-danger" type="submit">Cancel order</button></form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/shop_footer.php'; ?>
