
<?php
require_once __DIR__ . '/config.php';

$shopTitle  = 'Online Shop';
$shopActive = 'shop';

$cat = g('cat');

$all = qa("SELECT * FROM products WHERE status='Active' ORDER BY category, name");

$cats = array_values(array_unique(
    array_filter(array_column($all, 'category'))
));

$products = array_values(array_filter(
    $all,
    fn($p) => $cat === '' || $p['category'] === $cat
));

$loggedIn = (bool) auth();

/* FIXED PRODUCT IMAGES - NO DATABASE CHANGE */

$productImages = [
    'ID Lace' => 'id-lace.png',
    'T-Shirt (Full Print)' => 'tshirt.png',
    'Sublimated Mug' => 'mug.png',
    'Basketball Jersey' => 'basketball-jersey.png',
    'Polo Shirt' => 'polo-shirt.png',
    'Zip Jacket' => 'hoodie.png',
    'Basketball Shorts' =>'uniform.png',
];

require __DIR__ . '/includes/shop_header.php';
?>

<!-- SHOP HERO -->
<section class="sh-hero">
    <div class="flex-360">

        <h1>Order your team's jerseys. Every player, every size.</h1>

        <p>
            Log in, choose a product, list your players with their own sizes
            (XS to XXL), and send your order. You get a tracking number.
            The shop confirms your payment and you follow every step from My Orders.
        </p>

        <div class="steps">
            <span class="sh-step">1 &middot; Log in</span>
            <span class="sh-step">2 &middot; Add players and sizes</span>
            <span class="sh-step">3 &middot; Send your order</span>
            <span class="sh-step">4 &middot; Pay at the shop</span>
            <span class="sh-step">5 &middot; Track and pick up</span>
        </div>

        <?php if (!$loggedIn): ?>
            <div class="sh-hero-cta">
                <a class="btn btn-lg btn-light" href="signup.php">
                    Create an account
                </a>

                <a class="btn btn-lg btn-ghost" href="login.php">
                    Log in
                </a>
            </div>
        <?php endif; ?>

    </div>
</section>


<!-- CATEGORY TABS -->
<?php if ($cats): ?>

<div class="tabs">

    <a class="tab <?= $cat === '' ? 'active' : '' ?>"
       href="shop.php">
        All
    </a>

    <?php foreach ($cats as $c): ?>

        <a class="tab <?= $cat === $c ? 'active' : '' ?>"
           href="shop.php?cat=<?= e(urlencode($c)) ?>">
            <?= e($c) ?>
        </a>

    <?php endforeach; ?>

</div>

<?php endif; ?>


<!-- PRODUCT GRID -->
<div class="product-grid">

<?php foreach ($products as $pr): ?>

    <?php
        $productName = $pr['name'];

        $imageFile = $productImages[$productName] ?? '';

        $imagePath = 'assets/products/' . $imageFile;
    ?>

    <div class="product-card">

        <!-- PRODUCT IMAGE -->
        <div class="product-thumb">

            <?php if ($imageFile !== ''): ?>

                <img
                    src="<?= e($imagePath) ?>"
                    alt="<?= e($productName) ?>"
                    class="product-image"
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                >

                <div class="product-placeholder">
                    <?= icon('shirt') ?>
                </div>

            <?php else: ?>

                <div class="product-placeholder">
                    <?= icon('shirt') ?>
                </div>

            <?php endif; ?>

        </div>


        <!-- PRODUCT INFORMATION -->
        <div class="product-body">

            <span class="cat">
                <?= e($pr['category']) ?>
            </span>

            <h3>
                <?= e($pr['name']) ?>
            </h3>

            <p>
                <?= e($pr['description']) ?>
            </p>


            <?php if ($pr['has_sizes']): ?>

                <span class="chip chip-sizes">
                    Sizes <?= e(SIZES[0]) ?> to
                    <?= e(SIZES[count(SIZES) - 1]) ?>, per player
                </span>

            <?php endif; ?>


            <!-- PRICE -->
            <div class="product-price">

                <?= e(money($pr['unit_price'])) ?>

                <span class="muted fs-12-fw400">
                    each
                </span>

            </div>


            <!-- ORDER BUTTON -->
            <a class="btn"
               href="customize.php?id=<?= (int)$pr['id'] ?>">

                <?= $pr['has_sizes']
                    ? 'Choose players &amp; sizes'
                    : 'Order now' ?>

            </a>

        </div>

    </div>

<?php endforeach; ?>


<?php if (!$products): ?>

    <div class="sh-box col-full">
        <p class="muted m-0">
            No products are available right now.
            Please check back soon.
        </p>
    </div>

<?php endif; ?>

</div>


<?php require __DIR__ . '/includes/shop_footer.php'; ?>
