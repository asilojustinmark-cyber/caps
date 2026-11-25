<?php
require_once __DIR__ . '/config.php';
$pageScripts = ['roster', 'customize'];
$shopTitle   = 'Customize your order';
$shopActive  = 'shop';

$pr = qa("SELECT * FROM products WHERE id=? AND status='Active'", [(int)g('id')])[0] ?? null;
if (!$pr) { flash('That product is not available.', 'err'); redirect('shop.php'); }

// Editing a line that is already in the cart pre-fills the form.
$key  = (int)g('edit');
$line = $key && isset($_SESSION['cart'][$key]) && (int)$_SESSION['cart'][$key]['product_id'] === (int)$pr['id'] ? $_SESSION['cart'][$key] : null;
if (!$line) { $key = 0; }

$sized   = (bool)$pr['has_sizes'];
$players = $line['players'] ?? [];
$specs   = $line['specs'] ?? '';
$qty     = $line['qty'] ?? 1;
$dims    = $line['dims'] ?? [];

require __DIR__ . '/includes/shop_header.php';
?>
<p class="crumbs"><a href="shop.php">Shop</a> &rsaquo; <?= e($pr['name']) ?></p>
<h1 class="title-24">Customize: <?= e($pr['name']) ?></h1>

<form method="post" action="cart.php" class="customize" id="customizeForm">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add">
  <input type="hidden" name="product_id" value="<?= (int)$pr['id'] ?>">
  <input type="hidden" name="key" value="<?= (int)$key ?>">

  <div class="customize-main">
    <?php if ($sized): ?>
    <div class="sh-box">
      <h2>1. Players and sizes</h2>
      <p class="hint mb-14">One row for each player. Every player picks their own size: <?= e(implode(', ', SIZES)) ?>. Jersey numbers must not repeat.</p>
      <div class="roster" id="roster">
        <div class="roster-head"><span>#</span><span>Player name</span><span>Jersey no.</span><span>Size</span><span></span></div>
        <div class="roster-rows"></div>
        <div class="roster-foot">
          <button type="button" class="btn btn-sm btn-outline" data-add-player>+ Add player</button>
          <button type="button" class="btn btn-sm btn-outline" data-add-5>+ Add 5 players</button>
          <strong class="roster-count"></strong>
        </div>
        <div class="roster-sizes" aria-live="polite"></div>
      </div>
    </div>
    <?php else: ?>
    <div class="sh-box">
      <h2>1. Quantity</h2>
      <div class="field-narrow"><label for="qty">How many?</label>
        <input id="qty" type="number" name="quantity" min="1" max="5000" value="<?= (int)$qty ?>" required data-qty></div>
    </div>
    <?php endif; ?>

    <div class="sh-box">
      <h2>2. Design details</h2>
      <label for="specs">Team name, colors, logo position, notes</label>
      <textarea id="specs" name="design_specifications" maxlength="1000" placeholder="Example: Navy and gold, team logo on the chest, name on the back."><?= e($specs) ?></textarea>
    </div>
  </div>

  <aside class="customize-side">
    <div class="sh-box sticky">
      <h2>Your order</h2>
      <div class="sum-row"><span>Item</span><strong><?= e($pr['name']) ?></strong></div>
      <div class="sum-row"><span>Price each</span><strong><?= e(money($pr['unit_price'])) ?></strong></div>
      <div class="sum-row"><span>Pieces</span><strong id="liveQty">0 pieces</strong></div>
      <div class="sh-sum"><span>Total</span><span id="liveTotal"><?= e(money(0)) ?></span></div>
      <button class="btn btn-block btn-lg" type="submit"><?= $key ? 'Save changes' : 'Add to cart' ?></button>
      <a class="btn btn-outline btn-block mt-10" href="<?= $key ? 'cart.php' : 'shop.php' ?>">Cancel</a>
      <p class="hint mt-10">You can review everything in your cart before sending the order.</p>
    </div>
  </aside>
</form>

<datalist id="dimNames"><option>Print Width</option><option>Print Height</option><option>Logo Size</option><option>Chest Width</option><option>Body Length</option><option>Sleeve Length</option></datalist>
<script type="application/json" id="customizeData"><?= json_encode([
    'price' => (float)$pr['unit_price'], 'sized' => $sized, 'currency' => CURRENCY,
    'players' => $players, 'dims' => $dims,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php require __DIR__ . '/includes/shop_footer.php'; ?>
