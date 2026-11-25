<?php
require_once __DIR__ . '/config.php';
$shopTitle  = 'Your Cart';
$shopActive = 'cart';

const CART_MAX_LINES = 30;
const CART_UNITS     = ['in', 'cm', 'ft', 'mm'];

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) { $_SESSION['cart'] = []; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = p('action');
    $back   = 'shop.php';
    try {
        if ($action === 'add') {
            $key = (int)p('key');
            $pr  = qa("SELECT id, name, unit_price, has_sizes FROM products WHERE id=? AND status='Active'", [(int)p('product_id')])[0] ?? null;
            if (!$pr) { throw new RuntimeException('That product is not available.'); }
            $back = 'customize.php?id=' . (int)$pr['id'] . ($key ? '&edit=' . $key : '');
            if (!$key && count($_SESSION['cart']) >= CART_MAX_LINES) { throw new RuntimeException('Your cart is full. Please send this order first, then start another.'); }

            $sized = (bool)$pr['has_sizes'];
            $players = [];
            if ($sized) {
                $players = clean_players((array)($_POST['players'] ?? []));
                if (!$players) { throw new RuntimeException('Add at least one player and choose a size for each player.'); }
                $q = count($players);
            } else {
                $q = (int)p('quantity');
                if ($q < 1 || $q > 5000) { throw new RuntimeException('The quantity must be at least 1.'); }
            }
            $dims = [];
            foreach (array_slice(array_values((array)($_POST['dims'] ?? [])), 0, 12) as $d) {
                $dn = trim((string)($d['name'] ?? ''));
                $dv = $d['value'] ?? '';
                if ($dn === '' || !is_numeric($dv) || (float)$dv <= 0 || (float)$dv >= 1000) { continue; }
                $du = in_array($d['unit'] ?? '', CART_UNITS, true) ? $d['unit'] : 'in';
                $dims[] = ['name' => mb_substr($dn, 0, 100), 'value' => round((float)$dv, 2), 'unit' => $du];
            }
            $line = [
                'product_id' => (int)$pr['id'], 'name' => $pr['name'], 'sized' => $sized, 'qty' => $q,
                'specs' => mb_substr(p('design_specifications'), 0, 1000), 'players' => $players, 'dims' => $dims,
            ];
            if ($key && isset($_SESSION['cart'][$key])) {
                $_SESSION['cart'][$key] = $line;
                flash($pr['name'] . ' updated.');
            } else {
                $_SESSION['cart_seq'] = ($_SESSION['cart_seq'] ?? 0) + 1;
                $_SESSION['cart'][$_SESSION['cart_seq']] = $line;
                flash($pr['name'] . ' added to your cart.');
            }
            redirect('cart.php');

        } elseif ($action === 'update') {          // quantity of an item that has no player list
            $k = (int)p('key');
            if (isset($_SESSION['cart'][$k]) && empty($_SESSION['cart'][$k]['sized'])) {
                $q = (int)p('quantity');
                if ($q < 1) { unset($_SESSION['cart'][$k]); flash('Item removed.'); }
                else { $_SESSION['cart'][$k]['qty'] = min($q, 5000); flash('Cart updated.'); }
            }
        } elseif ($action === 'remove') {
            unset($_SESSION['cart'][(int)p('key')]);
            flash('Item removed.');
        } elseif ($action === 'clear') {
            $_SESSION['cart'] = [];
            flash('Your cart is empty.');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'err');
        redirect($back);
    }
    redirect('cart.php');
}

// Price every line from the database, never from the session.
$lines = []; $total = 0.0;
foreach ($_SESSION['cart'] as $k => $ci) {
    $pr = qa("SELECT unit_price, status FROM products WHERE id=?", [$ci['product_id']])[0] ?? null;
    $ok = $pr && $pr['status'] === 'Active';
    $price = $ok ? (float)$pr['unit_price'] : 0.0;
    $sub = round($price * $ci['qty'], 2);
    if ($ok) { $total += $sub; }
    $lines[] = $ci + ['key' => $k, 'price' => $price, 'sub' => $sub, 'ok' => $ok];
}
$canCheckout = $lines && !array_filter($lines, fn($l) => !$l['ok']);

require __DIR__ . '/includes/shop_header.php';
?>
<h1 class="title-24">Your cart</h1>

<?php if (!$lines): ?>
  <div class="sh-box"><p class="mb-14b">Your cart is empty.</p><a class="btn" href="shop.php">Browse products</a></div>
<?php else: ?>
<div class="sh-box">
  <?php foreach ($lines as $l): ?>
    <div class="cart-item">
      <div>
        <strong><?= e($l['name']) ?></strong>
        <?php if (!$l['ok']): ?> <span class="badge s-overdue">No longer available</span><?php endif; ?>
        <div class="meta">
          <?= (int)$l['qty'] ?> <?= $l['sized'] ? 'player' . ($l['qty'] > 1 ? 's' : '') : 'piece' . ($l['qty'] > 1 ? 's' : '') ?>
          &middot; <?= e(money($l['price'])) ?> each
          <?php if ($l['specs'] !== ''): ?><br>Design: <?= e($l['specs']) ?><?php endif; ?>
        </div>
        <?php if ($l['sized'] && $l['players']): ?>
          <div class="dim-chips"><?php foreach (array_count_values(array_column($l['players'], 'size')) as $sz => $n): ?>
            <span class="chip"><strong><?= e($sz) ?></strong> &times;<?= (int)$n ?></span><?php endforeach; ?></div>
          <details class="player-list">
            <summary>See the player list</summary>
            <table class="table table-tight"><thead><tr><th>#</th><th>Player</th><th>Jersey no.</th><th>Size</th></tr></thead><tbody>
              <?php foreach ($l['players'] as $i => $pl): ?>
                <tr><td><?= $i + 1 ?></td><td><?= e($pl['name']) ?: '&mdash;' ?></td><td><?= e($pl['number']) ?: '&mdash;' ?></td><td><strong><?= e($pl['size']) ?></strong></td></tr>
              <?php endforeach; ?></tbody></table>
          </details>
        <?php endif; ?>
        <?php if ($l['dims']): ?><div class="dim-chips">
          <?php foreach ($l['dims'] as $d): ?><span class="chip"><?= e($d['name']) ?>: <strong><?= e(qty($d['value'])) ?> <?= e($d['unit']) ?></strong></span><?php endforeach; ?></div><?php endif; ?>
      </div>
      <div class="text-right">
        <div class="sub title-17"><?= e(money($l['sub'])) ?></div>
        <div class="cart-actions jc-end">
          <?php if ($l['sized']): ?>
            <a class="btn btn-sm btn-outline" href="customize.php?id=<?= (int)$l['product_id'] ?>&amp;edit=<?= (int)$l['key'] ?>">Edit players</a>
          <?php else: ?>
            <form method="post" class="cart-actions">
              <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="key" value="<?= (int)$l['key'] ?>">
              <input type="number" name="quantity" min="0" max="5000" value="<?= (int)$l['qty'] ?>" aria-label="Quantity">
              <button class="btn btn-sm btn-outline" type="submit">Update</button>
            </form>
          <?php endif; ?>
          <form method="post" class="inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="key" value="<?= (int)$l['key'] ?>">
            <button class="btn btn-sm btn-outline" type="submit">Remove</button>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <div class="sh-sum"><span>Estimated total</span><span><?= e(money($total)) ?></span></div>
</div>

<div class="notice mb-18">
  <strong>No online payment.</strong> After you send your order, the shop confirms it when you pay a down payment or the full amount at the shop.
  You will see the update, and your receipt, in My Orders.
</div>

<div class="toolbar">
  <a class="btn btn-outline" href="shop.php">&larr; Continue shopping</a>
  <form method="post" class="inline" data-confirm="Empty your cart?">
    <?= csrf_field() ?><input type="hidden" name="action" value="clear"><button class="btn btn-outline" type="submit">Empty cart</button></form>
  <span class="spacer"></span>
  <?php if (!$canCheckout): ?><span class="muted">Remove unavailable items to continue.</span>
  <?php elseif (auth() && auth()['role'] === 'customer'): ?><a class="btn" href="checkout.php">Continue to checkout &rarr;</a>
  <?php else: ?><a class="btn" href="login.php?next=checkout.php">Log in to check out &rarr;</a>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/shop_footer.php'; ?>
