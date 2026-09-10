<?php
require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } else {
        $stmt = $conn->prepare('SELECT staff_id, name, password_hash, role FROM staff WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($staff = $result->fetch_assoc()) {
            if (password_verify($password, $staff['password_hash'])) {
                $_SESSION['user_type'] = 'staff';
                $_SESSION['user_id'] = $staff['staff_id'];
                $_SESSION['name'] = $staff['name'];
                header('Location: staff/dashboard.php');
                exit;
            } else {
                $error = 'Incorrect email or password.';
            }
        } else {
            $stmt = $conn->prepare('SELECT customer_id, name, password_hash FROM customers WHERE email = ?');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($customer = $result->fetch_assoc()) {
                if ($customer['password_hash'] && password_verify($password, $customer['password_hash'])) {
                    $_SESSION['user_type'] = 'customer';
                    $_SESSION['user_id'] = $customer['customer_id'];
                    $_SESSION['name'] = $customer['name'];
                    header('Location: customer/dashboard.php');
                    exit;
                } else {
                    $error = 'Incorrect email or password.';
                }
            } else {
                $error = 'Incorrect email or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>ArtsyTartsy - Welcome</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Nunito+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --cream: #fdf6ec;
            --terracotta: #d2743a;
            --terracotta-dark: #b5602c;
            --choc: #4a2c1d;
            --choc-light: #7a5a45;
            --error: #b3261e;
            --pink: #f2b8a2;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Nunito Sans', sans-serif;
            margin: 0;
            min-height: 100vh;
            display: flex;
            color: var(--choc);
        }
        .hero {
            flex: 1.1;
            background: linear-gradient(160deg, rgba(210,116,58,0.55) 0%, rgba(74,44,29,0.65) 100%), url('images/hero.jpg') center/cover no-repeat;
            color: #fff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 60px 40px;
            position: relative;
            overflow: hidden;
            min-height: 320px;
        }
        .hero::before, .hero::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
        }
        .hero::before { width: 280px; height: 280px; top: -80px; left: -80px; }
        .hero::after { width: 200px; height: 200px; bottom: -60px; right: -40px; }
        .hero h1 {
            font-family: 'Fraunces', serif;
            font-size: 2.4em;
            margin: 0 0 12px;
            text-align: center;
            z-index: 1;
        }
        .hero p {
            text-align: center;
            font-size: 1.05em;
            max-width: 340px;
            opacity: 0.95;
            z-index: 1;
            line-height: 1.5;
        }
        .form-side {
            flex: 1;
            background: var(--cream);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 24px;
        }
        .card {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 12px 32px rgba(74, 44, 29, 0.10);
            padding: 40px 36px;
            width: 100%;
            max-width: 380px;
        }
        .card h2 {
            font-family: 'Fraunces', serif;
            font-size: 1.5em;
            margin: 0 0 4px;
            color: var(--choc);
        }
        .card .sub { color: var(--choc-light); font-size: 0.9em; margin: 0 0 26px; }
        label { display: block; font-weight: 600; font-size: 0.9em; margin-bottom: 6px; }
        input {
            width: 100%;
            padding: 12px 14px;
            margin-bottom: 18px;
            border: 1.5px solid #e8d9c9;
            border-radius: 10px;
            font-size: 1em;
            font-family: inherit;
            background: #fffaf3;
            transition: border-color .15s;
        }
        input:focus { outline: none; border-color: var(--terracotta); }
        button {
            width: 100%;
            padding: 13px;
            background: var(--terracotta);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 1em;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: background .15s;
        }
        button:hover { background: var(--terracotta-dark); }
        .error {
            background: #fbeae8;
            color: var(--error);
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.9em;
            margin-bottom: 18px;
        }
        .footer-link { text-align: center; margin-top: 22px; font-size: 0.9em; color: var(--choc-light); }
        .footer-link a { color: var(--terracotta); font-weight: 700; text-decoration: none; }
        .footer-link a:hover { text-decoration: underline; }

        @media (max-width: 760px) {
            body { flex-direction: column; }
            .hero { padding: 40px 24px; min-height: 220px; }
            .hero h1 { font-size: 1.8em; }
        }
    </style>
</head>
<body>
    <div class="hero">
        <h1>Welcome to ArtsyTartsy</h1>
        <p>Homemade cakes, pastries &amp; donuts, baked fresh and delivered with love.</p>
    </div>
    <div class="form-side">
        <div class="card">
            <h2>Log In</h2>
            <p class="sub">Sign in to shop or manage your account</p>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <label>Email</label>
                <input type="email" name="email" required>
                <label>Password</label>
                <input type="password" name="password" required>
                <button type="submit">Log In</button>
            </form>
            <p class="footer-link">New customer? <a href="register.php">Create an account</a></p>
        </div>
    </div>
</body>
</html>
