<?php
require_once __DIR__ . '/config.php';

$next = safe_next(g('next') ?: p('next'));
if (auth()) { redirect(home_for(auth()['role'])); }

$error = '';
$old   = ['name' => '', 'contact_number' => '', 'email' => '', 'address' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($old as $k => $_) { $old[$k] = p($k); }
    $pw  = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');

    // Slow down bots: 5 sign-ups per 10 minutes per session.
    $tries = array_filter($_SESSION['signup_try'] ?? [], fn($t) => $t > time() - 600);
    try {
        if (p('website') !== '') { throw new RuntimeException('Sign-up failed.'); }                      // honeypot
        if (count($tries) >= 5)  { throw new RuntimeException('Too many attempts. Please wait a few minutes and try again.'); }
        if ($old['name'] === '' || mb_strlen($old['name']) > 100) { throw new RuntimeException('Please enter your full name.'); }
        $digits = preg_replace('/\D/', '', $old['contact_number']);
        if (strlen($digits) < 7 || strlen($digits) > 15) { throw new RuntimeException('Enter a contact number the shop can reach (7 to 15 digits).'); }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('Enter a valid email address. You will use it to sign in.'); }
        if (strlen($pw) < 8)  { throw new RuntimeException('Password must be at least 8 characters.'); }
        if ($pw !== $pw2)     { throw new RuntimeException('The two passwords do not match.'); }
        if (q1("SELECT id FROM users WHERE email = ?", [$old['email']])) { throw new RuntimeException('That email is already registered. Try signing in.'); }

        $pdo->beginTransaction();
        run("INSERT INTO users (branch_id, name, email, password_hash, role, status) VALUES (?,?,?,?, 'customer', 'Active')",
            [main_branch_id(), $old['name'], $old['email'], password_hash($pw, PASSWORD_DEFAULT)]);
        $uid = (int)$pdo->lastInsertId();
        run("INSERT INTO customers (branch_id, user_id, name, contact_number, email, address) VALUES (?,?,?,?,?,?)",
            [main_branch_id(), $uid, $old['name'], mb_substr($old['contact_number'], 0, 30), $old['email'], mb_substr($old['address'], 0, 255) ?: null]);
        $pdo->commit();
        $_SESSION['signup_try'] = array_merge(array_values($tries), [time()]);
        audit('register', 'user', $uid, 'Customer account created', $old['name']);

        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $uid, 'name' => $old['name'], 'email' => $old['email'], 'role' => 'customer', 'branch_id' => main_branch_id()];
        flash('Welcome, ' . $old['name'] . '! Your account is ready.');
        redirect($next ?: 'shop.php');
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = 'Could not create the account. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create account | <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrap">
  <section class="login-hero">
    <h1>Order your team's jerseys online</h1>
    
  </section>
  <section class="login-panel">
    <form class="login-box" method="post" autocomplete="on">
      <h2>Create your account</h2>
      <p class="muted lead-22">For customers. It takes a minute.</p>
      <?php if ($error): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="honey" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
      <div class="field"><label for="name">Full name or team name</label>
        <input id="name" name="name" required maxlength="100" value="<?= e($old['name']) ?>"></div>
      <div class="field"><label for="contact_number">Contact number</label>
        <input id="contact_number" name="contact_number" required maxlength="30" inputmode="tel" placeholder="0917 000 0000" value="<?= e($old['contact_number']) ?>"></div>
      <div class="field"><label for="email">Email</label>
        <input id="email" type="email" name="email" required maxlength="150" value="<?= e($old['email']) ?>"></div>
      <div class="field"><label for="address">Address (optional)</label>
        <input id="address" name="address" maxlength="255" value="<?= e($old['address']) ?>"></div>
      <div class="field"><label for="password">Password</label>
        <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password"></div>
      <div class="field"><label for="password2">Confirm password</label>
        <input id="password2" type="password" name="password2" required minlength="8" autocomplete="new-password"></div>
      <button class="btn" type="submit">Create account</button>
      <p class="auth-link">Already have an account? <a href="login.php<?= $next ? '?next=' . e(urlencode($next)) : '' ?>">Login</a></p>
    </form>
  </section>
</div>
</body>
</html>
