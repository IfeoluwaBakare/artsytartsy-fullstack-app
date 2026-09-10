<?php
require_once '../auth_check.php';
require_role('customer');
require_once '../config.php';

$customer_id = $_SESSION['user_id'];

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$products = $conn->query('SELECT product_id, product_name, price, stock_quantity FROM products WHERE stock_quantity > 0');

$stmt = $conn->prepare(
    'SELECT o.order_id, o.order_date, o.order_status, SUM(oi.quantity * oi.unit_price) AS total
     FROM orders o JOIN order_items oi ON o.order_id = oi.order_id
     WHERE o.customer_id = ?
     GROUP BY o.order_id
     ORDER BY o.order_date DESC'
);
$stmt->bind_param('i', $customer_id);
$stmt->execute();
$orders = $stmt->get_result();

$cartItems = [];
$cartTotal = 0;
if (!empty($_SESSION['cart'])) {
    $ids = implode(',', array_map('intval', array_keys($_SESSION['cart'])));
    $cartProducts = $conn->query("SELECT product_id, product_name, price FROM products WHERE product_id IN ($ids)");
    $productLookup = [];
    while ($row = $cartProducts->fetch_assoc()) {
        $productLookup[$row['product_id']] = $row;
    }
    foreach ($_SESSION['cart'] as $pid => $qty) {
        if (isset($productLookup[$pid])) {
            $lineTotal = $productLookup[$pid]['price'] * $qty;
            $cartTotal += $lineTotal;
            $cartItems[] = [
                'product_id' => $pid,
                'name' => $productLookup[$pid]['product_name'],
                'price' => $productLookup[$pid]['price'],
                'qty' => $qty,
                'line_total' => $lineTotal,
            ];
        }
    }
}

$message = '';
if (isset($_GET['success']) && $_GET['success'] === 'order_placed') {
    $message = '<div class="flash success">Order placed. Check your order history below.</div>';
} elseif (isset($_GET['error'])) {
    $errors = [
        'empty_cart' => 'Your cart is empty.',
        'out_of_stock' => 'One or more items no longer have enough stock. Please adjust your cart.',
        'order_failed' => 'Something went wrong placing your order. Please try again.',
    ];
    $msg = $errors[$_GET['error']] ?? 'Something went wrong.';
    $message = '<div class="flash error">' . htmlspecialchars($msg) . '</div>';
}

// Which tab should be active on load?
// If a cart action just happened (success/error present), land on Shop so the message is visible.
$activeTab = $_GET['tab'] ?? (($message !== '') ? 'shop' : 'home');
$validTabs = ['home', 'shop', 'orders'];
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'home';
}

$customerName = $_SESSION['name'] ?? 'there';
$initial = strtoupper(substr($customerName, 0, 1));
?>
<!DOCTYPE html>
<html>
<head>
    <title>ArtsyTartsy</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f5f3ef;
            --ink: #2b211c;
            --ink-light: #6b5d52;
            --accent: #7a2e2e;
            --accent-dark: #5e2323;
            --border: #e4ddd3;
            --error: #b3261e;
            --success: #2e6b3e;
            --gold: #b98b3e;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--ink);
            margin: 0;
            display: flex;
            min-height: 100vh;
        }
        h1, h2 { font-family: 'Playfair Display', serif; }

        /* --- Sidebar --- */
        #sidebar {
            width: 240px;
            background: var(--accent-dark);
            color: #f5efe8;
            display: flex;
            flex-direction: column;
            padding: 20px 0;
            flex-shrink: 0;
            transition: width 0.2s ease;
        }
        #sidebar.collapsed { width: 64px; }
        #sidebar.collapsed .label { display: none; }
        #sidebar.collapsed .brand-text { display: none; }
        #sidebar.collapsed .badge-name { display: none; }
        .sidebar-header { display: flex; align-items: center; justify-content: space-between; padding: 0 18px 20px; }
        .brand-text { font-family: 'Playfair Display', serif; font-weight: 700; font-size: 1.25em; white-space: nowrap; letter-spacing: 0.02em; }
        #toggle-btn { background: none; border: none; color: #f5efe8; font-size: 1.2em; cursor: pointer; padding: 4px 8px; opacity: 0.8; }
        #toggle-btn:hover { opacity: 1; }

        .badge-row { display: flex; align-items: center; gap: 10px; padding: 0 18px 20px; border-bottom: 1px solid rgba(255,255,255,0.12); margin-bottom: 12px; }
        .monogram {
            width: 34px; height: 34px; border-radius: 50%;
            background: var(--gold); color: var(--accent-dark);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Playfair Display', serif; font-weight: 700; font-size: 1em;
            flex-shrink: 0;
        }
        .badge-name { font-size: 0.9em; opacity: 0.9; white-space: nowrap; }

        .nav-item {
            display: flex; align-items: center; gap: 13px;
            padding: 12px 18px; cursor: pointer; color: rgba(245,239,232,0.75); text-decoration: none;
            white-space: nowrap; border-left: 3px solid transparent;
            font-size: 0.95em;
        }
        .nav-item:hover { background: rgba(255,255,255,0.06); color: #fff; }
        .nav-item.active { background: rgba(255,255,255,0.09); color: #fff; border-left: 3px solid var(--gold); }
        .nav-icon { font-size: 1.15em; width: 20px; text-align: center; flex-shrink: 0; }
        .nav-spacer { flex: 1; }

        /* --- Main content --- */
        #main { flex: 1; overflow-x: hidden; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        /* --- Home / landing tab --- */
        .hero {
            position: relative;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 40px;
            overflow: hidden;
        }
        .hero-bg {
            position: absolute; inset: 0;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
        }
        .hero-bg img {
            width: 100%; height: 100%; object-fit: cover;
            filter: saturate(0.9);
        }
        .hero-overlay {
            position: absolute; inset: 0;
            background: linear-gradient(180deg, rgba(43,33,28,0.55) 0%, rgba(43,33,28,0.72) 60%, rgba(43,33,28,0.85) 100%);
        }
        .hero-content { position: relative; z-index: 1; color: #fff; max-width: 640px; }
        .hero-content h1 {
            font-size: 2.6em; font-weight: 700; margin: 0 0 18px; line-height: 1.2;
            text-shadow: 0 2px 12px rgba(0,0,0,0.3);
        }
        .hero-content p { font-size: 1.1em; color: #f0e8de; margin: 0 0 32px; }
        .cta-btn {
            display: inline-block;
            background: var(--accent);
            color: #fff;
            border: none;
            padding: 16px 40px;
            font-family: 'Inter', sans-serif;
            font-weight: 600;
            font-size: 1em;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .cta-btn:hover { background: var(--gold); }

        /* --- Shop / orders tabs (content area) --- */
        .content-wrap { max-width: 1020px; margin: 0 auto; padding: 40px 32px; }
        .flash { padding: 12px 16px; border-radius: 4px; margin-bottom: 24px; font-size: 0.92em; }
        .flash.success { background: #e8f3ea; color: var(--success); }
        .flash.error { background: #fbeae8; color: var(--error); }
        h2 { font-weight: 700; font-size: 1.3em; margin: 0 0 18px; }
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 18px;
            margin-bottom: 44px;
        }
        .product-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 22px;
            display: flex;
            flex-direction: column;
        }
        .product-badge {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--accent);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            font-size: 1.1em;
            margin-bottom: 14px;
        }
        .product-name { font-weight: 600; margin-bottom: 6px; min-height: 44px; line-height: 1.35; }
        .product-price { color: var(--accent); font-weight: 700; font-size: 1.15em; margin-bottom: 2px; }
        .product-stock { color: var(--ink-light); font-size: 0.82em; margin-bottom: 16px; }
        .qty-row { display: flex; gap: 8px; margin-top: auto; }
        .qty-row input {
            width: 55px;
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-family: inherit;
            text-align: center;
        }
        .qty-row button {
            flex: 1;
            background: var(--ink);
            color: #fff;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            font-family: inherit;
            font-size: 0.85em;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            cursor: pointer;
        }
        .qty-row button:hover { background: var(--accent); }

        .cart-box {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 26px;
            margin-bottom: 44px;
        }
        .cart-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .cart-item:last-of-type { border-bottom: none; }
        .cart-item .info { flex: 1; }
        .cart-item .name { font-weight: 600; }
        .cart-item .qty { color: var(--ink-light); font-size: 0.88em; }
        .cart-item .price { font-weight: 700; color: var(--accent); margin-right: 14px; }
        .remove-btn { background: none; border: none; color: var(--error); cursor: pointer; font-size: 0.82em; text-decoration: underline; }
        .cart-total-row { display: flex; justify-content: space-between; font-weight: 700; font-size: 1.15em; padding-top: 16px; margin-top: 8px; border-top: 2px solid var(--ink); }
        .cart-actions { display: flex; gap: 10px; margin-top: 18px; }
        .cart-actions form { flex: 1; }
        .cart-actions button {
            width: 100%; padding: 13px; border: none; border-radius: 4px;
            font-weight: 600; font-family: inherit; font-size: 0.88em;
            letter-spacing: 0.03em; text-transform: uppercase; cursor: pointer;
        }
        .place-order-btn { background: var(--accent); color: #fff; }
        .place-order-btn:hover { background: var(--accent-dark); }
        .clear-btn { background: #fff; color: var(--ink); border: 1px solid var(--border) !important; }
        .clear-btn:hover { background: #f4f0ea; }

        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 12px 8px; border-bottom: 1px solid var(--border); }
        th { color: var(--ink-light); font-size: 0.78em; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
        .status-pill { padding: 4px 12px; border-radius: 3px; font-size: 0.78em; font-weight: 600; letter-spacing: 0.02em; text-transform: uppercase; background: #f4f0ea; color: var(--ink-light); }
    </style>
</head>
<body>

    <div id="sidebar">
        <div class="sidebar-header">
            <span class="brand-text">ArtsyTartsy</span>
            <button id="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
        </div>
        <div class="badge-row">
            <div class="monogram"><?= htmlspecialchars($initial) ?></div>
            <div class="badge-name"><?= htmlspecialchars($customerName) ?></div>
        </div>
        <a class="nav-item" data-tab="home" onclick="showTab('home')">
            <span class="nav-icon">&#127968;</span><span class="label">Home</span>
        </a>
        <a class="nav-item" data-tab="shop" onclick="showTab('shop')">
            <span class="nav-icon">&#128717;</span><span class="label">Shop</span>
        </a>
        <a class="nav-item" data-tab="orders" onclick="showTab('orders')">
            <span class="nav-icon">&#128220;</span><span class="label">Order History</span>
        </a>
        <div class="nav-spacer"></div>
        <a class="nav-item" href="../logout.php">
            <span class="nav-icon">&#128682;</span><span class="label">Log out</span>
        </a>
    </div>

    <div id="main">

        <!-- HOME / LANDING TAB -->
        <div class="tab-panel" id="tab-home">
            <div class="hero">
                <div class="hero-bg">
                    <img src="../images/product1.jpg" alt="">
                    <img src="../images/product2.jpg" alt="">
                    <img src="../images/product3.jpg" alt="">
                    <img src="../images/product4.jpg" alt="">
                </div>
                <div class="hero-overlay"></div>
                <div class="hero-content">
                    <h1>What Sweet Treat Are You Up to Today, <?= htmlspecialchars($customerName) ?>?</h1>
                    <p>Freshly made cakes, pastries, and savory bakes — order online and we'll have it ready for you.</p>
                    <button class="cta-btn" onclick="showTab('shop')">Click to Order</button>
                </div>
            </div>
        </div>

        <!-- SHOP TAB -->
        <div class="tab-panel" id="tab-shop">
            <div class="content-wrap">
                <?= $message ?>

                <h2>Shop</h2>
                <div class="product-grid">
                    <?php $products->data_seek(0); while ($row = $products->fetch_assoc()): ?>
                    <div class="product-card">
                        <div class="product-badge"><?= strtoupper(substr($row['product_name'], 0, 1)) ?></div>
                        <div class="product-name"><?= htmlspecialchars($row['product_name']) ?></div>
                        <div class="product-price">$<?= number_format($row['price'], 2) ?></div>
                        <div class="product-stock"><?= $row['stock_quantity'] ?> in stock</div>
                        <form method="post" action="cart_action.php" class="qty-row">
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="product_id" value="<?= $row['product_id'] ?>">
                            <input type="number" name="quantity" value="1" min="1" max="<?= $row['stock_quantity'] ?>">
                            <button type="submit">Add</button>
                        </form>
                    </div>
                    <?php endwhile; ?>
                </div>

                <h2>Your Cart</h2>
                <div class="cart-box">
                    <?php if (empty($cartItems)): ?>
                        <p style="color: var(--ink-light); margin: 0;">Your cart is empty.</p>
                    <?php else: ?>
                        <?php foreach ($cartItems as $item): ?>
                        <div class="cart-item">
                            <div class="info">
                                <div class="name"><?= htmlspecialchars($item['name']) ?></div>
                                <div class="qty">Qty: <?= $item['qty'] ?></div>
                            </div>
                            <div class="price">$<?= number_format($item['line_total'], 2) ?></div>
                            <form method="post" action="cart_action.php">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                                <button type="submit" class="remove-btn">Remove</button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                        <div class="cart-total-row">
                            <span>Total</span>
                            <span>$<?= number_format($cartTotal, 2) ?></span>
                        </div>
                        <div class="cart-actions">
                            <form method="post" action="cart_action.php">
                                <input type="hidden" name="action" value="place_order">
                                <button type="submit" class="place-order-btn">Place Order</button>
                            </form>
                            <form method="post" action="cart_action.php">
                                <input type="hidden" name="action" value="clear">
                                <button type="submit" class="clear-btn">Clear Cart</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ORDER HISTORY TAB -->
        <div class="tab-panel" id="tab-orders">
            <div class="content-wrap">
                <h2>My Order History</h2>
                <table>
                    <tr><th>Order</th><th>Date</th><th>Status</th><th>Total</th></tr>
                    <?php $orders->data_seek(0); while ($row = $orders->fetch_assoc()): ?>
                    <tr>
                        <td>#<?= $row['order_id'] ?></td>
                        <td><?= $row['order_date'] ?></td>
                        <td><span class="status-pill"><?= htmlspecialchars($row['order_status']) ?></span></td>
                        <td>$<?= number_format($row['total'], 2) ?></td>
                    </tr>
                    <?php endwhile; ?>
                </table>
            </div>
        </div>

    </div>

    <script>
        function showTab(tab) {
            document.querySelectorAll('.tab-panel').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.nav-item[data-tab]').forEach(el => el.classList.remove('active'));
            document.getElementById('tab-' + tab).classList.add('active');
            const navEl = document.querySelector('.nav-item[data-tab="' + tab + '"]');
            if (navEl) navEl.classList.add('active');
            sessionStorage.setItem('artsytartsy_active_tab', tab);
        }
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        }
        // Prefer the tab remembered from before a form submit/reload;
        // otherwise fall back to what the server decided (e.g. 'shop' after a flash message).
        const rememberedTab = sessionStorage.getItem('artsytartsy_active_tab');
        const serverTab = '<?= $activeTab ?>';
        showTab(rememberedTab || serverTab);
    </script>
</body>
</html>
