<?php
require_once __DIR__ . '/config.php';
$me = auth();
if (!$me) { redirect('login.php'); }
$isCustomer = $me['role'] === 'customer';
if ($me['role'] === 'manager') { http_response_code(403); exit('Access denied. You do not have permission to view this page.'); }

$payId = (int)g('payment');
$id    = (int)g('id');
$pay   = null;

if ($payId) {
    $pay = qa("SELECT pay.*, u.name recorder FROM payments pay LEFT JOIN users u ON u.id = pay.recorded_by WHERE pay.id=?", [$payId])[0] ?? null;
    if (!$pay) { http_response_code(404); exit('Receipt not found.'); }
    $id = (int)$pay['order_id'];
}

$pp = [$id]; $sc = scope_sql('o.branch_id', $pp);
if ($isCustomer) { $sc .= ' AND o.customer_id = ?'; $pp[] = customer_id_for_user($me); }   // customers only see their own
$o = qa("SELECT o.*, c.name customer, c.contact_number, c.email cemail, c.address,
                b.name branch, b.location blocation
         FROM orders o JOIN customers c ON c.id = o.customer_id JOIN branches b ON b.id = o.branch_id
         WHERE o.id=? $sc", $pp)[0] ?? null;
if (!$o) { http_response_code(404); exit('Order not found.'); }

$items = order_items_full($id);
$payments = qa("SELECT * FROM payments WHERE order_id=? ORDER BY payment_date, id", [$id]);

$paidAll = array_sum(array_column($payments, 'amount'));
$paidUpTo = 0.0;                       // paid up to and including this receipt's payment
if ($pay) {
    foreach ($payments as $x) { $paidUpTo += (float)$x['amount']; if ((int)$x['id'] === $payId) { break; } }
}
$title = $pay ? 'OFFICIAL RECEIPT' : 'ORDER STATEMENT';
$docNo = $pay ? 'RCPT-' . str_pad((string)$payId, 5, '0', STR_PAD_LEFT) : $o['order_number'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> <?= e($docNo) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="plain">
<div class="rc-bar"><?php if (!$isCustomer): ?><button class="btn" type="button" data-print>Print</button>
  <?php endif; ?>  <a class="btn btn-outline" href="<?= $isCustomer ? 'track.php?no=' . e(urlencode($o['order_number'])) : 'order_view.php?id=' . (int)$id ?>">Back to order</a></div>
<div class="rc">
  <div class="rc-head">
    <div>
      <h1><?= e(APP_NAME) ?></h1>
      <div class="muted"><?= e($o['branch']) ?><?= $o['blocation'] ? ' &middot; ' . e($o['blocation']) : '' ?></div>
    </div>
    <div class="text-right">
      <div class="rc-title"><?= e($title) ?></div>
      <div>No. <strong><?= e($docNo) ?></strong></div>
      <div class="muted"><?= e(date('F j, Y', strtotime($pay['payment_date'] ?? 'now'))) ?></div>
    </div>
  </div>

  <table>
    <tr><td class="cell-half"><h3 class="mt-0">Customer</h3>
        <strong><?= e($o['customer']) ?></strong><br>
        <?= e($o['contact_number']) ?><?= $o['cemail'] ? '<br>' . e($o['cemail']) : '' ?><?= $o['address'] ? '<br>' . e($o['address']) : '' ?></td>
      <td class="cell-plain"><h3 class="mt-0">Order</h3>
        Order No.: <strong><?= e($o['order_number']) ?></strong><br>
        Order date: <?= e(date('M j, Y', strtotime($o['order_date']))) ?><br>
        <?php if ($o['due_date']): ?>Due date: <?= e(date('M j, Y', strtotime($o['due_date']))) ?><br><?php endif; ?>
        Status: <?= e($o['status']) ?></td></tr>
  </table>

  <?php if ($pay): ?>
    <h3>Order summary</h3>
    <table>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr><td><strong><?= e($it['garment_type']) ?></strong> &times; <?= (int)$it['quantity'] ?>
            <?php if ($it['players']): ?><div class="fs-12-mt3"><?= e(size_summary($it['players'])) ?></div><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <h3>Payment received</h3>
    <table>
      <thead><tr><th>Type</th><th>Reference</th><th>Recorded by</th><th class="r">Amount</th></tr></thead>
      <tbody><tr><td><?= e($pay['payment_type']) ?></td><td><?= e($pay['reference_number']) ?: '&mdash;' ?></td><td><?= e($pay['recorder']) ?></td>
        <td class="r"><strong><?= e(money($pay['amount'])) ?></strong></td></tr></tbody>
    </table>
    <div class="rc-total"><table>
      <tr><td>Order total</td><td class="r"><?= e(money($o['total_amount'])) ?></td></tr>
      <tr><td>Paid to date (incl. this payment)</td><td class="r"><?= e(money($paidUpTo)) ?></td></tr>
      <tr><td><strong>Balance after this payment</strong></td><td class="r"><strong><?= e(money((float)$o['total_amount'] - $paidUpTo)) ?></strong></td></tr>
    </table></div>
    <?php if ((float)$o['total_amount'] - $paidUpTo <= 0.005): ?><p class="mt-14"><span class="rc-stamp">PAID IN FULL</span></p><?php endif; ?>
  <?php else: ?>
    <h3>Items</h3>
    <table>
      <thead><tr><th>Description</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr><td><strong><?= e($it['garment_type']) ?></strong>
            <?php if ($it['design_specifications']): ?><div class="muted fs-12"><?= e($it['design_specifications']) ?></div><?php endif; ?>
            <?php if ($it['players']): ?><div class="fs-12-mt3"><?= e(size_summary($it['players'])) ?></div><?php endif; ?>
            <?php if ($it['dims']): ?><div class="fs-12-mt3"><?php
                echo e(implode('; ', array_map(fn($d) => $d['dimension_name'] . ' ' . qty($d['dimension_value']) . ' ' . $d['unit'], $it['dims']))); ?></div><?php endif; ?></td>
          <td class="r"><?= (int)$it['quantity'] ?></td>
          <td class="r"><?= e(money($it['unit_price'])) ?></td><td class="r"><?= e(money($it['subtotal'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($payments): ?>
    <h3>Payments</h3>
    <table>
      <thead><tr><th>Date</th><th>Type</th><th>Reference</th><th class="r">Amount</th></tr></thead>
      <tbody><?php foreach ($payments as $x): ?>
        <tr><td><?= e(date('M j, Y', strtotime($x['payment_date']))) ?></td><td><?= e($x['payment_type']) ?></td><td><?= e($x['reference_number']) ?></td><td class="r"><?= e(money($x['amount'])) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table>
    <?php endif; ?>
    <div class="rc-total"><table>
      <tr><td>Order total</td><td class="r"><?= e(money($o['total_amount'])) ?></td></tr>
      <tr><td>Total paid</td><td class="r"><?= e(money($paidAll)) ?></td></tr>
      <tr><td><strong>Balance due</strong></td><td class="r"><strong><?= e(money((float)$o['total_amount'] - $paidAll)) ?></strong></td></tr>
    </table></div>
  <?php endif; ?>

  <div class="rc-sign"><div>Received by (<?= e($pay['recorder'] ?? APP_NAME) ?>)</div><div>Customer signature</div></div>
  <p class="muted foot-note">Thank you for choosing <?= e(APP_NAME) ?>. Please keep this document for your records.</p>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
