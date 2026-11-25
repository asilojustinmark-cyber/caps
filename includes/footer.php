    </main>
  </div>
</div>
<script src="assets/js/app.js"></script>
<?php foreach ((array)($pageScripts ?? $pageScript ?? []) as $s): ?><script src="assets/js/<?= e($s) ?>.js"></script><?php endforeach; ?>
</body>
</html>
