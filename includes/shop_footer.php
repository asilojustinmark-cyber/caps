</div>
<footer class="sh-foot">
  <?= e(APP_NAME) ?> &middot; No online payment: the shop confirms your payment when you pay at the shop.
  <?php if (!auth()): ?><br><a href="login.php">Shop team? Sign in here</a><?php endif; ?>
</footer>
<script src="assets/js/app.js"></script>
<?php foreach ((array)($pageScripts ?? $pageScript ?? []) as $s): ?><script src="assets/js/<?= e($s) ?>.js"></script><?php endforeach; ?>
</body>
</html>
