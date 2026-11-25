<?php
require_once __DIR__ . '/config.php';
require_customer();
$me         = auth();
$shopTitle  = 'My Orders';
$shopActive = 'orders';
$cid        = customer_id_for_user($me);

// Tabs group the statuses the way a customer thinks about them.
$tabs = [
    ''          => ['All',             null],
    'waiting'   => ['Waiting',         ['Pending']],
    'preparing' => ['Being prepared',  ['Confirmed', 'In Production']],
    'ready'     => ['Ready to pick up', ['Ready']],
    'done'      => ['Completed',       ['Completed']],
    'cancelled' => ['Cancelled',       ['Cancelled']],
];
$tab = array_key_exists(g('tab'), $tabs) ? g('tab') : '';
$q   = g('q');

$orders = qa("SELECT o.*, COALESCE((SELECT SUM(amount) FROM payments WHERE order_id = o.id), 0) paid,
                     (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) n_items
              FROM orders o WHERE o.customer_id = ? ORDER BY o.id DESC", [$cid]);

$counts = array_fill_keys(array_keys($tabs), 0);
foreach ($orders as $o) {
    $counts['']++;
    foreach ($tabs as $k => [$_, $sts]) { if ($sts && in_array($o['status'], $sts, true)) { $counts[$k]++; } }
}
$shown = array_values(array_filter($orders, function ($o) use ($tabs, $tab, $q) {
    if ($tabs[$tab][1] && !in_array($o['status'], $tabs[$tab][1], true)) { return false; }
    return $q === '' || stripos($o['order_number'], $q) !== false;
}));

// For each order shown: its items and a short player summary.
foreach ($shown as &$o) {
    $o['items'] = order_items_full((int)$o['id']);
    $o['last_payment'] = (int)(q1("SELECT MAX(id) FROM payments WHERE order_id=?", [$o['id']]) ?: 0);
}
unset($o);

$balanceDue = 0.0; $active = 0;
foreach ($orders as $o) {
    if (!in_array($o['status'], ['Cancelled'], true)) { $balanceDue += max(0, (float)$o['total_amount'] - (float)$o['paid']); }
    if (!in_array($o['status'], ['Completed', 'Cancelled'], true)) { $active++; }
}
$updates = array_slice(notif_all($me, 5), 0, 5);
$cust = qa("SELECT contact_number, address FROM customers WHERE id=?", [$cid])[0] ?? ['contact_number' => '', 'address' => ''];

require __DIR__ . '/includes/shop_header.php';
?>
<div class="acct-top">
  <div class="sh-box acct-card">
    <div class="avatar avatar-lg"><?= e(strtoupper(substr($me['name'], 0, 1))) ?></div>
    <div>
      <h1 class="title-24 m-0"><?= e($me['name']) ?></h1>
      <div class="muted"><?= e($me['email']) ?><?= $cust['contact_number'] ? ' &middot; ' . e($cust['contact_number']) : '' ?></div>
    </div>
    <a class="btn ml-auto" href="shop.php">+ New order</a>
  </div>
  <div class="acct-stats">
    <div class="stat"><span>Orders in progress</span><strong><?= (int)$active ?></strong></div>
    <div class="stat"><span>Balance to pay at the shop</span><strong><?= e(money($balanceDue)) ?></strong></div>
    <div class="stat"><span>Completed orders</span><strong><?= (int)$counts['done'] ?></strong></div>
  </div>
</div>

<div class="grid-2">
  <div>
    <div class="tabs">
      <?php foreach ($tabs as $k => [$label]): ?>
        <a class="tab <?= $tab === $k ? 'active' : '' ?>" href="account.php<?= $k !== '' ? '?tab=' . e($k) : '' ?>"><?= e($label) ?> <span class="tab-n"><?= (int)$counts[$k] ?></span></a>
      <?php endforeach; ?>
    </div>
    <form method="get" class="filters mb-14">
      <?php if ($tab !== ''): ?><input type="hidden" name="tab" value="<?= e($tab) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Find by tracking number (OS-...)">
      <button class="btn btn-outline" type="submit">Find</button>
    </form>

    <?php foreach ($shown as $o): $bal = max(0, (float)$o['total_amount'] - (float)$o['paid']); ?>
      <article class="order-card">
        <header>
          <div><span class="muted fs-12">TRACKING NUMBER</span><br><a class="track-no" href="track.php?no=<?= e(urlencode($o['order_number'])) ?>"><?= e($o['order_number']) ?></a></div>
          <span class="status-line"><?= badge($o['status']) ?><span class="status-text"><?= e(customer_status_label($o['status'])) ?></span></span>
        </header>
        <div class="order-lines">
          <?php foreach ($o['items'] as $it): ?>
            <div class="order-line">
              <div><strong><?= e($it['garment_type']) ?></strong> <span class="muted">&times; <?= (int)$it['quantity'] ?></span>
                <?php if ($it['players']): ?><div class="dim-chips"><?php foreach (array_count_values(array_column($it['players'], 'size')) as $sz => $n): ?><span class="chip"><strong><?= e($sz) ?></strong> &times;<?= (int)$n ?></span><?php endforeach; ?></div><?php endif; ?></div>
              <span><?= e(money($it['subtotal'])) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <footer>
          <div class="order-money">
            <span>Total <strong><?= e(money($o['total_amount'])) ?></strong></span>
            <span>Paid <strong><?= e(money($o['paid'])) ?></strong></span>
            <span class="<?= $bal > 0 && $o['status'] !== 'Cancelled' ? 'owe' : '' ?>">Balance <strong><?= e(money($o['status'] === 'Cancelled' ? 0 : $bal)) ?></strong></span>
          </div>
          <div class="cart-actions">
            <?php if ($o['last_payment']): ?><a class="btn btn-sm btn-outline" href="receipt.php?payment=<?= (int)$o['last_payment'] ?>" target="_blank">Latest receipt</a><?php endif; ?>
            <?php if ($o['status'] === 'Pending' && (float)$o['paid'] < 0.005): ?>
              <form method="post" class="inline" action="track.php" data-confirm="Cancel order <?= e($o['order_number']) ?>? This cannot be undone." data-confirm-title="Cancel this order?" data-confirm-yes="Yes, cancel order" data-confirm-no="Keep order">
                <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="no" value="<?= e($o['order_number']) ?>">
                <button class="btn btn-sm btn-outline btn-danger" type="submit">Cancel order</button></form>
            <?php endif; ?>
            <a class="btn btn-sm" href="track.php?no=<?= e(urlencode($o['order_number'])) ?>">Track order</a>
          </div>
        </footer>
      </article>
    <?php endforeach; if (!$shown): ?>
      <div class="sh-box"><p class="mb-14b"><?= $orders ? 'No orders in this tab.' : 'You have not placed an order yet.' ?></p><a class="btn" href="shop.php">Start shopping</a></div>
    <?php endif; ?>
  </div>

  <div>
    <div class="sh-box">
      <h2>Latest updates</h2>
      <?php foreach ($updates as $n): ?>
        <a class="update-item <?= !empty($n['is_read']) ? '' : 'is-new' ?>" href="notifications.php?go=<?= (int)$n['id'] ?>">
          <b><?= e($n['title']) ?></b><?php if ($n['message']): ?><span><?= e($n['message']) ?></span><?php endif; ?><small><?= e(time_ago($n['created_at'])) ?></small>
        </a>
      <?php endforeach; if (!$updates): ?><p class="muted m-0">Updates about your orders show up here.</p><?php endif; ?>
      <?php if ($updates): ?><a class="link-more" href="notifications.php">See all updates</a><?php endif; ?>
    </div>
    <div class="sh-box">
      <h2>How it works</h2>
      <ol class="how">
        <li>Send your order. You get a tracking number.</li>
        <li>Pay a down payment or the full amount at the shop.</li>
        <li>The shop confirms your payment and gives you a receipt.</li>
        <li>Follow the progress here until it is ready to pick up.</li>
      </ol>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/shop_footer.php'; ?>
