<?php
require_once __DIR__ . '/config.php';
if (auth()) {
  redirect('index.php');
}

$error = '';
$old = ['role' => 'staff', 'name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $old = ['role' => p('role'), 'name' => p('name'), 'email' => p('email')];
  $pw = (string) ($_POST['password'] ?? '');
  $pw2 = (string) ($_POST['password2'] ?? '');

  // Slow down bots: 5 sign-ups per 10 minutes per session.
  $tries = array_filter($_SESSION['reg_try'] ?? [], fn($t) => $t > time() - 600);
  try {
    if (p('website') !== '') {
      throw new RuntimeException('Registration failed.');
    }   // honeypot
    if (count($tries) >= 5) {
      throw new RuntimeException('Too many attempts. Please wait a few minutes and try again.');
    }
    if (!in_array($old['role'], ['staff', 'manager'], true)) {
      throw new RuntimeException('Choose Staff or Manager.');
    }
    if ($old['name'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
      throw new RuntimeException('Enter your full name and a valid email.');
    }
    if (strlen($pw) < 8) {
      throw new RuntimeException('Password must be at least 8 characters.');
    }
    if ($pw !== $pw2) {
      throw new RuntimeException('The two passwords do not match.');
    }
    if (q1("SELECT id FROM users WHERE email = ?", [$old['email']])) {
      throw new RuntimeException('That email is already registered.');
    }

    $status = REGISTRATION_NEEDS_APPROVAL ? 'Pending' : 'Active';
    run(
      "INSERT INTO users (branch_id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)",
      [main_branch_id(), $old['name'], $old['email'], password_hash($pw, PASSWORD_DEFAULT), $old['role'], $status]
    );
    $uid = (int) $pdo->lastInsertId();
    $_SESSION['reg_try'] = array_merge(array_values($tries), [time()]);

    audit('register', 'user', $uid, 'Registered as ' . $old['role'] . ' (' . $status . ')', $old['name']);
    flash(REGISTRATION_NEEDS_APPROVAL
      ? 'Registration sent. You can sign in after the owner approves your account.'
      : 'Account created. You can sign in now.');
    redirect('login.php');
  } catch (RuntimeException $ex) {
    $error = $ex->getMessage();
  } catch (PDOException $ex) {
    $error = 'Could not create the account. Please try again.';
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Register | <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
  <div class="login-wrap">
    <section class="login-hero">
      <h1>Join the Ownstyle team</h1>
    </section>
    <section class="login-panel">
      <form class="login-box" method="post" autocomplete="off">
        <h2>Create your account</h2>
        <p class="muted lead-22">Register as Staff or Manager</p>
        <?php if ($error): ?>
          <div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
        <?= csrf_field() ?>
        <div class="honey" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
        <div class="field"><label for="role">Register as</label>
          <select id="role" name="role" required>
            <option value="staff" <?= $old['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
            <option value="manager" <?= $old['role'] === 'manager' ? 'selected' : '' ?>>Manager</option>
          </select>
        </div>
        <div class="field"><label for="name">Full name</label>
          <input id="name" name="name" required maxlength="100" value="<?= e($old['name']) ?>">
        </div>
        <div class="field"><label for="email">Email</label>
          <input id="email" type="email" name="email" required maxlength="150" value="<?= e($old['email']) ?>">
        </div>
        <div class="field"><label for="password">Password</label>
          <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
        </div>
        <div class="field"><label for="password2">Confirm password</label>
          <input id="password2" type="password" name="password2" required minlength="8" autocomplete="new-password">
        </div>
        <button class="btn" type="submit">Register</button>
        <p class="auth-link"><a href="login.php">&larr; Back to sign in</a></p>
      </form>
    </section>
  </div>
</body>

</html>