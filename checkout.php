<?php
require_once __DIR__ . '/config.php';
require_customer();
$me         = auth();
$shopTitle  = 'Place your order';
$shopActive = 'cart';

$cart = $_SESSION['cart'] ?? [];
if (!$cart) { flash('Your cart is empty.', 'err'); redirect('shop.php'); }

$cid  = customer_id_for_user($me);
$cust = qa("SELECT * FROM customers WHERE id=?", [$cid])[0];
$f    = ['contact_number' => (string)$cust['contact_number'], 'address' => (string)$cust['address'], 'due_date' => '', 'notes' => ''];
$error = '';

// Price every line from the database.
$lines = []; $total = 0.0;
foreach ($cart as $k => $ci) {
    $pr = qa("SELECT id, name, unit_price, status FROM products WHERE id=?", [$ci['product_id']])[0] ?? null;
    $ok = $pr && $pr['status'] === 'Active';
    $price = $ok ? (float)$pr['unit_price'] : 0.0;
    $sub = round($price * $ci['qty'], 2);
    if ($ok) { $total += $sub; }
    $lines[] = $ci + ['key' => $k, 'ok' => $ok, 'price' => $price, 'sub' => $sub, 'pname' => $pr['name'] ?? $ci['name']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($f as $k => $_) { $f[$k] = p($k); }
    try {
        if (p('website') !== '') { throw new RuntimeException('Could not send your order.'); }                           // honeypot
        if (time() - (int)($_SESSION['last_order_at'] ?? 0) < 20) { throw new RuntimeException('Please wait a moment before sending another order.'); }
        if (array_filter($lines, fn($l) => !$l['ok'])) { throw new RuntimeException('Some items are no longer available. Please review your cart.'); }
        $digits = preg_replace('/\D/', '', $f['contact_number']);
        if (strlen($digits) < 7 || strlen($digits) > 15) { throw new RuntimeException('Please enter a valid contact number so the shop can reach you.'); }
        $due = $f['due_date'];
        if ($due !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || $due < date('Y-m-d'))) { throw new RuntimeException('The date you need it cannot be in the past.'); }

        $orderLines = array_map(fn($l) => [
            'name' => $l['pname'], 'price' => $l['price'], 'qty' => (int)$l['qty'], 'sub' => $l['sub'],
            'spec' => (string)$l['specs'], 'sized' => !empty($l['sized']), 'players' => $l['players'] ?? [], 'dims' => $l['dims'] ?? [],
        ], $lines);

        $pdo->beginTransaction();
        run("UPDATE customers SET contact_number=?, address=? WHERE id=?",
            [mb_substr($f['contact_number'], 0, 30), mb_substr($f['address'], 0, 255) ?: null, $cid]);
        [$oid, $no, $orderTotal] = create_order($cid, null, 'Online', $due ?: null, $f['notes'], $orderLines);
        audit('create', 'order', $oid, 'Online order ' . $no . ' (' . number_format($orderTotal, 2, '.', '') . ')', 'Customer: ' . $me['name']);
        $pdo->commit();

        notify_role('staff', 'New order ' . $no, $me['name'] . ' · ' . lines_pieces($orderLines) . ' pcs · ' . money($orderTotal), 'order_view.php?id=' . $oid);

        $_SESSION['cart'] = [];
        $_SESSION['last_order_at'] = time();
        flash('Order sent! Your tracking number is ' . $no . '. The shop will confirm it once your payment is received.');
        redirect('track.php?no=' . urlencode($no));
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = $ex->getMessage();
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = 'Sorry, we could not save your order. Please try again.';
    }
}

require __DIR__ . '/includes/shop_header.php';
?>
<h1 class="title-24">Place your order</h1>
<?php if ($error): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>

<div class="grid-2">
  <form method="post" class="sh-box" autocomplete="on">
    <h2>Your details</h2>
    <?= csrf_field() ?>
    <div class="honey" aria-hidden="true"><label>Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <div class="form-grid">
      <div class="full"><label>Name</label><input value="<?= e($me['name']) ?>" readonly></div>
      <div><label>Contact number *</label><input name="contact_number" required maxlength="30" inputmode="tel" value="<?= e($f['contact_number']) ?>"></div>
      <div><label>Email</label><input value="<?= e($me['email']) ?>" readonly></div>
      <div class="full"><label>Address</label><input name="address" maxlength="255" value="<?= e($f['address']) ?>"></div>
      <div><label>Date you need it</label><input type="date" name="due_date" min="<?= e(date('Y-m-d')) ?>" value="<?= e($f['due_date']) ?>">
        <div class="hint">The shop will tell you if this date is possible.</div></div>
      <div class="full"><label>Notes</label><textarea name="notes" maxlength="1000" placeholder="Anything else the shop should know?"><?= e($f['notes']) ?></textarea></div>
    </div>
    <div class="toolbar mt-18">
      <a class="btn btn-outline" href="cart.php">&larr; Back to cart</a>
      <span class="spacer"></span>
      <button class="btn btn-lg" type="submit">Place order</button>
    </div>
  </form>

  <div class="sh-box">
    <h2>Order summary</h2>
    <?php foreach ($lines as $l): ?>
      <div class="sum-row">
        <span><?= e($l['pname']) ?> &times; <?= (int)$l['qty'] ?><?= !empty($l['sized']) ? '<br><small class="muted">' . e(size_summary($l['players'])) . '</small>' : '' ?></span>
        <strong><?= e(money($l['ok'] ? $l['sub'] : 0)) ?></strong>
      </div>
    <?php endforeach; ?>
    <div class="sh-sum"><span>Estimated total</span><span><?= e(money($total)) ?></span></div>
    <div class="notice mt-14"><strong>No online payment.</strong> Pay a down payment or the full amount at the shop. The shop confirms your payment and your order moves forward.</div>
  </div>
</div>
<?php require __DIR__ . '/includes/shop_footer.php'; ?>
