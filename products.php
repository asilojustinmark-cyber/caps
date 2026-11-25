<?php
require_once __DIR__ . '/config.php';
require_login(['owner']);
$pageTitle = 'Price List';
$active    = 'products';
$canEdit   = can_price();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) { http_response_code(403); exit('Only the owner can change the price list.'); }
    try {
        $action = p('action');
        if ($action === 'save') {
            $id     = (int)p('id');
            $name   = p('name');
            $price  = round((float)p('unit_price'), 2);
            $status = in_array(p('status'), ['Active', 'Inactive'], true) ? p('status') : 'Active';
            $sized  = p('has_sizes') === '1' ? 1 : 0;
            if ($name === '') { throw new RuntimeException('Product name is required.'); }
            if ($price < 0)   { throw new RuntimeException('Price cannot be negative.'); }
            if ($id) {
                $old = q1("SELECT unit_price FROM products WHERE id=?", [$id]);
                run("UPDATE products SET name=?, category=?, description=?, unit_price=?, has_sizes=?, status=? WHERE id=?",
                    [$name, p('category') ?: null, p('description') ?: null, $price, $sized, $status, $id]);
                audit('update', 'product', $id, 'Updated product ' . $name . ' (price ' . number_format((float)$old, 2, '.', '') . ' -> ' . number_format($price, 2, '.', '') . ')');
                flash('Product updated. Existing orders keep their original price.');
            } else {
                run("INSERT INTO products (name, category, description, unit_price, has_sizes, status) VALUES (?,?,?,?,?,?)",
                    [$name, p('category') ?: null, p('description') ?: null, $price, $sized, $status]);
                audit('create', 'product', (int)$pdo->lastInsertId(), 'Added product ' . $name);
                flash('Product added.');
            }
        } elseif ($action === 'delete') {
            $pn = q1("SELECT name FROM products WHERE id=?", [(int)p('id')]);
            run("DELETE FROM products WHERE id=?", [(int)p('id')]);
            audit('delete', 'product', (int)p('id'), 'Deleted product ' . $pn);
            flash('Product deleted.');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'err');
    } catch (PDOException $ex) {
        flash(db_error_message($ex), 'err');
    }
    redirect('products.php');
}

$rows = qa("SELECT * FROM products ORDER BY status, category, name");

require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="card-head">
    <h2>Products &amp; Prices</h2>
    <div class="toolbar">
      <input class="w-220" type="search" placeholder="Search products..." data-search="prodTable">
      <?php if ($canEdit): ?><button class="btn" data-open="formModal" data-title="Add Product">+ Add Product</button><?php endif; ?>
    </div>
  </div>
  <div class="table-wrap"><table class="table" id="prodTable">
    <thead><tr><th>Product</th><th>Category</th><th class="right">Unit price</th><th>Ordering</th><th>Status</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><?= e($r['name']) ?></strong><div class="hint"><?= e($r['description']) ?></div></td>
        <td><?= e($r['category']) ?></td>
        <td class="right"><strong><?= e(money($r['unit_price'])) ?></strong></td>
        <td><?= $r['has_sizes'] ? '<span class="badge s-active">Player list + sizes</span>' : '<span class="badge">Quantity only</span>' ?></td>
        <td><?= badge($r['status']) ?></td>
        <?php if ($canEdit): ?><td><div class="actions">
          <button class="btn btn-sm btn-outline" data-open="formModal" data-title="Edit Product"
                  data-row='<?= e(json_encode(['id' => $r['id'], 'name' => $r['name'], 'category' => $r['category'], 'description' => $r['description'], 'unit_price' => $r['unit_price'], 'has_sizes' => (int)$r['has_sizes'], 'status' => $r['status']])) ?>'>Edit price</button>
          <?= delete_form((int)$r['id']) ?>
        </div></td><?php endif; ?>
      </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="empty">No products yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<p class="hint">Active products appear in the online shop and in the staff order form. A new price applies to new orders only. Existing orders keep their price.</p>

<?php if ($canEdit): ?>
<div class="modal" id="formModal"><div class="modal-card">
  <div class="modal-head"><h3 class="modal-title">Product</h3><button class="x" data-close type="button">&times;</button></div>
  <div class="modal-body">
    <form method="post" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="">
      <div class="full"><label>Product name *</label><input name="name" required maxlength="150"></div>
      <div><label>Category</label><input name="category" maxlength="80" placeholder="Sportswear, Uniforms..."></div>
      <div><label>Unit price (<?= e(CURRENCY) ?>) *</label><input type="number" step="0.01" min="0" name="unit_price" required></div>
      <div class="full"><label>Description</label><textarea name="description" placeholder="Shown to customers in the online shop"></textarea></div>
      <div><label>Ordering</label><select name="has_sizes"><option value="0">Quantity only</option><option value="1">Player list with a size for each player (XS to XXL)</option></select></div>
      <div><label>Status</label><select name="status"><option>Active</option><option>Inactive</option></select></div>
      <div class="full modal-foot">
        <button type="button" class="btn btn-outline" data-close>Cancel</button>
        <button class="btn" type="submit">Save</button>
      </div>
    </form>
  </div>
</div></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
