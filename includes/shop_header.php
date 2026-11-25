<?php
require_once __DIR__ . '/bell.php';

$shopTitle  = $shopTitle ?? 'Online Shop';
$shopActive = $shopActive ?? '';
$me         = auth();
$isCustomer = $me && $me['role'] === 'customer';
$cartCount  = 0;
foreach (($_SESSION['cart'] ?? []) as $ci) { $cartCount += (int)$ci['qty']; }
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($shopTitle) ?> | <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="sh-top">
  <div class="sh-bar">
    <a class="sh-brand" href="shop.php"><span class="brand-logo"><?= brand_mark() ?></span><strong>Own<b>style</b></strong></a>
    <nav class="sh-nav">
      <a href="shop.php" class="<?= $shopActive === 'shop' ? 'active' : '' ?>"><?= icon('bag') ?>Shop</a>
      <a href="cart.php" class="<?= $shopActive === 'cart' ? 'active' : '' ?>"><?= icon('cart') ?>Cart<?php if ($cartCount): ?> <span class="sh-count"><?= $cartCount ?></span><?php endif; ?></a>
      <?php if ($isCustomer): ?>
        <a href="account.php" class="<?= $shopActive === 'orders' ? 'active' : '' ?>"><?= icon('clipboard') ?>My Orders</a>
      <?php elseif ($me): ?>
        <a href="index.php"><?= icon('home') ?>Back to dashboard</a>
      <?php else: ?>
        <a href="login.php" class="<?= $shopActive === 'login' ? 'active' : '' ?>"><?= icon('user') ?>Log in</a>
        <a href="signup.php" class="sh-cta">Sign up</a>
      <?php endif; ?>
    </nav>
    <?php if ($isCustomer): ?>
      <div class="sh-user">
        <?= bell_html($me) ?>
        <div class="dd" data-dd>
          <button class="user-btn user-btn-light" type="button" data-dd-toggle>
            <span class="avatar"><?= e(strtoupper(substr($me['name'], 0, 1))) ?></span><span><?= e(strtok($me['name'], ' ')) ?></span>
          </button>
          <div class="dd-menu minw-210">
            <div class="dd-empty"><strong><?= e($me['name']) ?></strong><br><?= e($me['email']) ?></div>
            <a class="dd-item" href="account.php"><span>My Orders</span></a>
            <a class="dd-item" href="logout.php" data-confirm="Are you sure you want to log out?" data-confirm-title="Log out?" data-confirm-yes="Yes, log out" data-confirm-no="Stay signed in"><span>Sign out</span><?= icon('logout') ?></a>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</header>
<div class="sh-wrap">
<?php if ($flash): ?>
  <div class="flash flash-<?= e($flash[1]) ?>" data-flash><?= e($flash[0]) ?></div>
<?php endif; ?>
