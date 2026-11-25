
<?php
require_once __DIR__ . '/config.php';

$next = safe_next(g('next') ?: p('next'));

if (auth()) {
    redirect($next ?: home_for(auth()['role']));
}

$error = '';
$notice = $_SESSION['flash'][0] ?? '';

unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    try {
        $u = qa(
            "SELECT *
             FROM users
             WHERE email = ?
             AND status IN ('Active', 'Pending')
             LIMIT 1",
            [p('email')]
        )[0] ?? null;

    } catch (PDOException $ex) {
        $u = null;

        $error = 'The database is out of date. Re-import database.sql in phpMyAdmin, then try again.';
    }

    $ok = $u && password_verify(
        (string) ($_POST['password'] ?? ''),
        $u['password_hash']
    );

    if ($ok && $u['status'] === 'Pending') {
        $error = 'Your account is waiting for the owner to approve it. Please try again later.';

    } elseif ($ok) {
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id'        => (int) $u['id'],
            'name'      => $u['name'],
            'email'     => $u['email'],
            'role'      => $u['role'],
            'branch_id' => $u['branch_id'] ? (int) $u['branch_id'] : null,
        ];

        // Customers return to their previous activity.
        // Shop team members go to their dashboard.
        redirect(
            $u['role'] === 'customer'
                ? ($next ?: 'account.php')
                : 'index.php'
        );

    } elseif ($error === '') {
        $error = 'Invalid email or password.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Sign in | <?= e(APP_NAME) ?></title>

    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

    <div class="login-wrap">

        <!-- Login Hero -->
        <section class="login-hero">
            <h1>
                Dimension-Based Sublimation Management And Inventory System
            </h1>
        </section>

        <!-- Login Panel -->
        <section class="login-panel">

            <form class="login-box" method="post" autocomplete="on">

                <h2>Welcome back</h2>

                <p class="muted lead-22">
                    Sign in to your account
                </p>

                <!-- Success Message -->
                <?php if ($notice): ?>
                    <div class="flash flash-ok">
                        <?= e($notice) ?>
                    </div>
                <?php endif; ?>

                <!-- Error Message -->
                <?php if ($error): ?>
                    <div class="flash flash-err">
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <?= csrf_field() ?>

                <input
                    type="hidden"
                    name="next"
                    value="<?= e($next) ?>"
                >

                <!-- Email -->
                <div class="field">

                    <label for="email">Email</label>

                    <input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autofocus
                        value="<?= e(p('email')) ?>"
                    >

                </div>

                <!-- Password -->
                <div class="field">

                    <label for="password">Password</label>

                    <input
                        id="password"
                        type="password"
                        name="password"
                        required
                    >

                </div>

                <!-- Login Button -->
                <button class="btn" type="submit">
                    Login
                </button>

                <!-- Customer Registration -->
                <p class="auth-note">
                    New customer?
                </p>

                <a
                    class="btn btn-outline btn-block"
                    href="signup.php<?= $next ? '?next=' . e(urlencode($next)) : '' ?>"
                >
                    Create a customer account
                </a>

                <!-- Staff / Manager Registration -->
                <a
                    class="btn btn-outline btn-block"
                    href="register.php"
                >
                    Register as Staff or Manager
                </a>

            </form>

        </section>

    </div>

</body>
</html>
