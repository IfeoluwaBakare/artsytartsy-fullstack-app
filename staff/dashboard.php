<?php
require_once '../auth_check.php';
require_role('staff');
require_once '../config.php';

// --- Handle "Add Staff" form submission (moved in from add_staff.php) ---
$addStaffError = '';
$addStaffSuccess = false;
$addStaffName = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_staff') {
    $addStaffName = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'staff';
    $allowedRoles = ['staff', 'manager'];
    if (!in_array($role, $allowedRoles, true)) {
        $role = 'staff';
    }
    if ($addStaffName === '' || $email === '' || $password === '') {
        $addStaffError = 'Name, email, and password are required.';
    } elseif (strlen($password) < 8) {
        $addStaffError = 'Password must be at least 8 characters.';
    } else {
        $stmt = $conn->prepare('SELECT staff_id FROM staff WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $addStaffError = 'A staff account with that email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                'INSERT INTO staff (name, email, password_hash, role) VALUES (?, ?, ?, ?)'
            );
            $stmt->bind_param('ssss', $addStaffName, $email, $hash, $role);
            $stmt->execute();
            $addStaffSuccess = true;
        }
    }
}

// --- Analytics: today's snapshot ---
$todayOrders = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE order_date = CURDATE()")->fetch_assoc()['cnt'];
$todayRevenueRow = $conn->query(
    "SELECT COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS rev
     FROM orders o JOIN order_items oi ON o.order_id = oi.order_id
     WHERE o.order_date = CURDATE()"
)->fetch_assoc();
$todayRevenue = $todayRevenueRow['rev'];

// --- Analytics: 7-day trend (fill in missing days with 0) ---
$trendRaw = [];
$result = $conn->query(
    "SELECT o.order_date, COUNT(DISTINCT o.order_id) AS orders, COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS revenue
     FROM orders o LEFT JOIN order_items oi ON o.order_id = oi.order_id
     WHERE o.order_date >= CURDATE() - INTERVAL 6 DAY
     GROUP BY o.order_date
     ORDER BY o.order_date"
);
while ($row = $result->fetch_assoc()) {
    $trendRaw[$row['order_date']] = $row;
}
$trend = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i day"));
    $trend[] = [
        'date' => $date,
        'label' => date('D', strtotime($date)),
        'orders' => $trendRaw[$date]['orders'] ?? 0,
        'revenue' => $trendRaw[$date]['revenue'] ?? 0,
    ];
}
$maxRevenue = max(array_column($trend, 'revenue')) ?: 1;
$maxOrders = max(array_column($trend, 'orders')) ?: 1;

// --- Top products (last 30 days) ---
$topProducts = $conn->query(
    "SELECT p.product_name, SUM(oi.quantity) AS total_qty
     FROM order_items oi
     JOIN products p ON oi.product_id = p.product_id
     JOIN orders o ON oi.order_id = o.order_id
     WHERE o.order_date >= CURDATE() - INTERVAL 30 DAY
     GROUP BY p.product_id
     ORDER BY total_qty DESC
     LIMIT 5"
);

// --- Orders + inventory tables ---
$orders = $conn->query(
    'SELECT o.order_id, c.name AS customer_name, o.order_date, o.order_status
     FROM orders o JOIN customers c ON o.customer_id = c.customer_id
     ORDER BY o.order_date DESC LIMIT 20'
);

$products = $conn->query(
    'SELECT product_id, product_name, stock_quantity, price FROM products ORDER BY stock_quantity ASC'
);

$statuses = ['Pending', 'Confirmed', 'Shipped', 'Delivered', 'Cancelled'];

// --- Analytics tab: selectable date range (3, 6, or 12 months) ---
$allowedRanges = [3, 6, 12];
$analyticsRange = (int)($_GET['range'] ?? 12);
if (!in_array($analyticsRange, $allowedRanges, true)) {
    $analyticsRange = 12;
}

// --- Analytics tab: monthly revenue + orders ---
$monthlyRaw = [];
$monthlyStmt = $conn->prepare(
    "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS ym,
            COUNT(DISTINCT o.order_id) AS orders,
            COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS revenue
     FROM orders o LEFT JOIN order_items oi ON o.order_id = oi.order_id
     WHERE o.order_date >= CURDATE() - INTERVAL ? MONTH
     GROUP BY ym
     ORDER BY ym"
);
$monthlyStmt->bind_param('i', $analyticsRange);
$monthlyStmt->execute();
$monthlyResult = $monthlyStmt->get_result();
while ($row = $monthlyResult->fetch_assoc()) {
    $monthlyRaw[$row['ym']] = $row;
}

// --- Analytics tab: new customers per month (proxy = month of each customer's first order) ---
$newCustRaw = [];
$newCustStmt = $conn->prepare(
    "SELECT DATE_FORMAT(first_order, '%Y-%m') AS ym, COUNT(*) AS new_customers
     FROM (
         SELECT customer_id, MIN(order_date) AS first_order
         FROM orders
         GROUP BY customer_id
     ) AS first_orders
     WHERE first_order >= CURDATE() - INTERVAL ? MONTH
     GROUP BY ym
     ORDER BY ym"
);
$newCustStmt->bind_param('i', $analyticsRange);
$newCustStmt->execute();
$newCustResult = $newCustStmt->get_result();
while ($row = $newCustResult->fetch_assoc()) {
    $newCustRaw[$row['ym']] = $row['new_customers'];
}

// Build a full series (fill gaps with 0) for all three metrics, sized to the selected range
$monthlySeries = [];
for ($i = $analyticsRange - 1; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i month"));
    $monthlySeries[] = [
        'ym' => $ym,
        'label' => date('M \'y', strtotime($ym . '-01')),
        'revenue' => (float)($monthlyRaw[$ym]['revenue'] ?? 0),
        'orders' => (int)($monthlyRaw[$ym]['orders'] ?? 0),
        'new_customers' => (int)($newCustRaw[$ym] ?? 0),
    ];
}
$monthlyLabels = array_column($monthlySeries, 'label');
$monthlyRevenue = array_column($monthlySeries, 'revenue');
$monthlyOrders = array_column($monthlySeries, 'orders');
$monthlyNewCustomers = array_column($monthlySeries, 'new_customers');

// Summary totals/averages for the selected range
$totalRevenue = array_sum($monthlyRevenue);
$totalOrders = array_sum($monthlyOrders);
$totalNewCustomers = array_sum($monthlyNewCustomers);
$avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

// Which tab should be active on load?
$activeTab = $_GET['tab'] ?? 'overview';
if ($addStaffSuccess || $addStaffError) {
    $activeTab = 'add-staff';
}
$validTabs = ['overview', 'inventory', 'orders', 'analytics', 'add-staff'];
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'overview';
}

$staffName = $_SESSION['name'] ?? 'Staff';
$initial = strtoupper(substr($staffName, 0, 1));
?>
<!DOCTYPE html>
<html>
<head>
    <title>Staff Dashboard - ArtsyTartsy</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --ivory: #FBF7F2;
            --ivory-soft: #F3ECE3;
            --burgundy: #6E1E2B;
            --burgundy-dark: #4E1520;
            --burgundy-light: #8C3242;
            --ink: #2A2320;
            --muted: #7A6F68;
            --border: #E4D9CC;
            --gold: #B98B3E;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            margin: 0; display: flex; min-height: 100vh;
            background: var(--ivory); color: var(--ink);
        }
        h1, h2 { font-family: 'Playfair Display', serif; font-weight: 600; }

        /* --- Sidebar --- */
        #sidebar {
            width: 240px;
            background: var(--burgundy-dark);
            color: var(--ivory);
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
        .brand-text { font-family: 'Playfair Display', serif; font-weight: 600; font-size: 1.25em; white-space: nowrap; letter-spacing: 0.02em; }
        #toggle-btn { background: none; border: none; color: var(--ivory); font-size: 1.2em; cursor: pointer; padding: 4px 8px; opacity: 0.8; }
        #toggle-btn:hover { opacity: 1; }

        .badge-row { display: flex; align-items: center; gap: 10px; padding: 0 18px 20px; border-bottom: 1px solid rgba(255,255,255,0.12); margin-bottom: 12px; }
        .monogram {
            width: 34px; height: 34px; border-radius: 50%;
            background: var(--gold); color: var(--burgundy-dark);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Playfair Display', serif; font-weight: 700; font-size: 1em;
            flex-shrink: 0;
        }
        .badge-name { font-size: 0.9em; opacity: 0.9; white-space: nowrap; }

        .nav-item {
            display: flex; align-items: center; gap: 13px;
            padding: 12px 18px; cursor: pointer; color: rgba(255,255,255,0.75); text-decoration: none;
            white-space: nowrap; border-left: 3px solid transparent;
            font-size: 0.95em;
        }
        .nav-item:hover { background: rgba(255,255,255,0.06); color: #fff; }
        .nav-item.active { background: rgba(255,255,255,0.09); color: #fff; border-left: 3px solid var(--gold); }
        .nav-icon { font-size: 1.15em; width: 20px; text-align: center; flex-shrink: 0; }
        .nav-spacer { flex: 1; }

        /* --- Main content --- */
        #main { flex: 1; padding: 40px 48px; max-width: 1080px; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
        h1 { font-size: 1.6em; margin: 0 0 28px; color: var(--burgundy-dark); }
        h2 { font-size: 1.2em; margin: 0 0 16px; color: var(--burgundy-dark); }

        table { width: 100%; border-collapse: collapse; margin-bottom: 36px; background: #fff; }
        th, td { text-align: left; padding: 12px 10px; border-bottom: 1px solid var(--border); font-size: 0.93em; }
        th { color: var(--muted); font-weight: 600; text-transform: uppercase; font-size: 0.75em; letter-spacing: 0.04em; }
        .low { color: var(--burgundy); font-weight: 600; }

        select, input[type=number], input[type=text], input[type=email], input[type=password] {
            padding: 7px 10px; border: 1px solid var(--border); border-radius: 6px;
            font-family: 'Inter', sans-serif; background: #fff; color: var(--ink);
        }
        button {
            padding: 7px 16px; cursor: pointer; border: none; border-radius: 6px;
            background: var(--burgundy); color: #fff; font-family: 'Inter', sans-serif;
            font-weight: 500; font-size: 0.9em; transition: background 0.15s ease;
        }
        button:hover { background: var(--burgundy-light); }

        .stat-row { display: flex; gap: 20px; margin-bottom: 36px; }
        .stat-card {
            flex: 1; border: 1px solid var(--border); border-radius: 10px;
            padding: 22px; text-align: center; background: #fff;
        }
        .stat-card .num { font-family: 'Playfair Display', serif; font-size: 2em; font-weight: 700; color: var(--burgundy); }
        .stat-card div:last-child { color: var(--muted); font-size: 0.85em; margin-top: 4px; }

        .chart { display: flex; align-items: flex-end; gap: 10px; height: 140px; margin-bottom: 8px; background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 16px; }
        .bar-col { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; }
        .bar { width: 100%; background: var(--burgundy); border-radius: 3px 3px 0 0; }
        .bar.revenue { background: var(--gold); }
        .bar-label { font-size: 0.75em; margin-top: 6px; color: var(--muted); }
        .bar-value { font-size: 0.72em; color: var(--ink); font-weight: 500; }

        /* --- Analytics tab (Chart.js) --- */
        .range-filter { display: flex; gap: 8px; margin-bottom: 20px; }
        .range-btn {
            padding: 7px 16px; border-radius: 6px; border: 1px solid var(--border);
            background: #fff; color: var(--ink); text-decoration: none; font-size: 0.88em; font-weight: 500;
        }
        .range-btn:hover { background: var(--ivory-soft); }
        .range-btn.active { background: var(--burgundy); color: #fff; border-color: var(--burgundy); }
        .chart-card {
            background: #fff; border: 1px solid var(--border); border-radius: 10px;
            padding: 24px; margin-bottom: 28px;
        }
        .chart-card h2 { margin-bottom: 4px; }
        .chart-card .subtitle { color: var(--muted); font-size: 0.85em; margin-bottom: 18px; }
        .chart-canvas-wrap { position: relative; height: 260px; }

        /* --- Add staff form --- */
        .form-card {
            max-width: 400px; background: #fff; border: 1px solid var(--border);
            border-radius: 10px; padding: 28px;
        }
        .form-card label { display: block; margin-top: 14px; font-size: 0.85em; color: var(--muted); font-weight: 500; }
        .form-card input, .form-card select { width: 100%; margin-top: 6px; }
        .form-card button { width: 100%; margin-top: 22px; padding: 11px; font-size: 0.95em; }
        .error { color: var(--burgundy); margin-bottom: 12px; font-size: 0.9em; }
        .success { color: #2E5E3A; margin-bottom: 12px; font-size: 0.9em; }
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
            <div class="badge-name"><?= htmlspecialchars($staffName) ?></div>
        </div>
        <a class="nav-item" data-tab="overview" onclick="showTab('overview')">
            <span class="nav-icon">&#128202;</span><span class="label">Overview</span>
        </a>
        <a class="nav-item" data-tab="inventory" onclick="showTab('inventory')">
            <span class="nav-icon">&#128230;</span><span class="label">Inventory</span>
        </a>
        <a class="nav-item" data-tab="orders" onclick="showTab('orders')">
            <span class="nav-icon">&#129534;</span><span class="label">Recent Orders</span>
        </a>
        <a class="nav-item" data-tab="analytics" onclick="showTab('analytics')">
            <span class="nav-icon">&#128200;</span><span class="label">Analytics</span>
        </a>
        <a class="nav-item" data-tab="add-staff" onclick="showTab('add-staff')">
            <span class="nav-icon">&#128100;</span><span class="label">Add Staff</span>
        </a>
        <div class="nav-spacer"></div>
        <a class="nav-item" href="../logout.php">
            <span class="nav-icon">&#128682;</span><span class="label">Log out</span>
        </a>
    </div>

    <div id="main">
        <h1>Welcome, <?= htmlspecialchars($staffName) ?></h1>

        <!-- OVERVIEW TAB -->
        <div class="tab-panel" id="tab-overview">
            <div class="stat-row">
                <div class="stat-card">
                    <div class="num"><?= $todayOrders ?></div>
                    <div>Orders Today</div>
                </div>
                <div class="stat-card">
                    <div class="num">$<?= number_format($todayRevenue, 2) ?></div>
                    <div>Revenue Today</div>
                </div>
            </div>

            <h2>Orders — Last 7 Days</h2>
            <div class="chart">
                <?php foreach ($trend as $day): ?>
                <div class="bar-col">
                    <div class="bar-value"><?= $day['orders'] ?></div>
                    <div class="bar" style="height: <?= max(4, ($day['orders'] / $maxOrders) * 100) ?>px;"></div>
                    <div class="bar-label"><?= $day['label'] ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <h2 style="margin-top: 32px;">Revenue — Last 7 Days</h2>
            <div class="chart">
                <?php foreach ($trend as $day): ?>
                <div class="bar-col">
                    <div class="bar-value">$<?= number_format($day['revenue'], 0) ?></div>
                    <div class="bar revenue" style="height: <?= max(4, ($day['revenue'] / $maxRevenue) * 100) ?>px;"></div>
                    <div class="bar-label"><?= $day['label'] ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <h2 style="margin-top: 32px;">Top Products (30 days)</h2>
            <table>
                <tr><th>Product</th><th>Units Sold</th></tr>
                <?php while ($row = $topProducts->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['product_name']) ?></td>
                    <td><?= $row['total_qty'] ?></td>
                </tr>
                <?php endwhile; ?>
            </table>
        </div>

        <!-- INVENTORY TAB -->
        <div class="tab-panel" id="tab-inventory">
            <h2>Inventory</h2>
            <table>
                <tr><th>Product</th><th>Stock</th><th>Price</th><th></th></tr>
                <?php $products->data_seek(0); while ($row = $products->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['product_name']) ?></td>
                    <td class="<?= $row['stock_quantity'] < 10 ? 'low' : '' ?>"><?= $row['stock_quantity'] ?></td>
                    <td>$<?= number_format($row['price'], 2) ?></td>
                    <td>
                        <form method="post" action="order_action.php" style="display:flex; gap:8px; align-items:center;">
                            <input type="hidden" name="action" value="update_stock">
                            <input type="hidden" name="product_id" value="<?= $row['product_id'] ?>">
                            <input type="number" name="stock_quantity" value="<?= $row['stock_quantity'] ?>" min="0" style="width:75px;">
                            <button type="submit">Update</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            </table>
        </div>

        <!-- ORDERS TAB -->
        <div class="tab-panel" id="tab-orders">
            <h2>Recent Orders</h2>
            <table>
                <tr><th>Order #</th><th>Customer</th><th>Date</th><th>Status</th><th></th></tr>
                <?php $orders->data_seek(0); while ($row = $orders->fetch_assoc()): ?>
                <tr>
                    <td>#<?= $row['order_id'] ?></td>
                    <td><?= htmlspecialchars($row['customer_name']) ?></td>
                    <td><?= $row['order_date'] ?></td>
                    <td colspan="2">
                        <form method="post" action="order_action.php" style="display:flex; gap:8px; align-items:center;">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="order_id" value="<?= $row['order_id'] ?>">
                            <select name="status">
                                <?php foreach ($statuses as $s): ?>
                                <option value="<?= $s ?>" <?= $s === $row['order_status'] ? 'selected' : '' ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit">Update</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            </table>
        </div>

        <!-- ANALYTICS TAB -->
        <div class="tab-panel" id="tab-analytics">
            <div class="stat-row">
                <div class="stat-card">
                    <div class="num">$<?= number_format($totalRevenue, 2) ?></div>
                    <div>Revenue (<?= $analyticsRange ?>mo)</div>
                </div>
                <div class="stat-card">
                    <div class="num"><?= $totalOrders ?></div>
                    <div>Orders (<?= $analyticsRange ?>mo)</div>
                </div>
                <div class="stat-card">
                    <div class="num"><?= $totalNewCustomers ?></div>
                    <div>New Customers (<?= $analyticsRange ?>mo)</div>
                </div>
                <div class="stat-card">
                    <div class="num">$<?= number_format($avgOrderValue, 2) ?></div>
                    <div>Avg Order Value</div>
                </div>
            </div>
            <div class="range-filter">
                <a href="?tab=analytics&range=3" class="range-btn <?= $analyticsRange === 3 ? 'active' : '' ?>">3 Months</a>
                <a href="?tab=analytics&range=6" class="range-btn <?= $analyticsRange === 6 ? 'active' : '' ?>">6 Months</a>
                <a href="?tab=analytics&range=12" class="range-btn <?= $analyticsRange === 12 ? 'active' : '' ?>">12 Months</a>
            </div>
            <div class="chart-card">
                <h2>Revenue per Month</h2>
                <div class="subtitle">Last <?= $analyticsRange ?> months</div>
                <div class="chart-canvas-wrap"><canvas id="revenueChart"></canvas></div>
            </div>
            <div class="chart-card">
                <h2>Orders per Month</h2>
                <div class="subtitle">Last <?= $analyticsRange ?> months</div>
                <div class="chart-canvas-wrap"><canvas id="ordersChart"></canvas></div>
            </div>
            <div class="chart-card">
                <h2>New Customers per Month</h2>
                <div class="subtitle">Based on each customer's first order date — last <?= $analyticsRange ?> months</div>
                <div class="chart-canvas-wrap"><canvas id="customersChart"></canvas></div>
            </div>
        </div>

        <!-- ADD STAFF TAB -->
        <div class="tab-panel" id="tab-add-staff">
            <h2>Add Staff Account</h2>
            <div class="form-card">
                <?php if ($addStaffSuccess): ?>
                    <div class="success">Staff account created for <?= htmlspecialchars($addStaffName) ?>.</div>
                <?php else: ?>
                    <?php if ($addStaffError): ?>
                        <div class="error"><?= htmlspecialchars($addStaffError) ?></div>
                    <?php endif; ?>
                    <form method="post" action="dashboard.php">
                        <input type="hidden" name="action" value="add_staff">
                        <label>Full Name</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($addStaffName) ?>" required>
                        <label>Email</label>
                        <input type="email" name="email" required>
                        <label>Password (min 8 characters)</label>
                        <input type="password" name="password" required minlength="8">
                        <label>Role</label>
                        <select name="role">
                            <option value="staff">Staff</option>
                            <option value="manager">Manager</option>
                        </select>
                        <button type="submit">Create Staff Account</button>
                    </form>
                <?php endif; ?>
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
        }
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        }
        showTab('<?= $activeTab ?>');

        // --- Analytics charts ---
        const monthLabels = <?= json_encode($monthlyLabels) ?>;
        const monthlyRevenue = <?= json_encode($monthlyRevenue) ?>;
        const monthlyOrders = <?= json_encode($monthlyOrders) ?>;
        const monthlyNewCustomers = <?= json_encode($monthlyNewCustomers) ?>;

        const burgundy = '#6E1E2B';
        const gold = '#B98B3E';

        new Chart(document.getElementById('revenueChart'), {
            type: 'line',
            data: {
                labels: monthLabels,
                datasets: [{
                    label: 'Revenue ($)',
                    data: monthlyRevenue,
                    borderColor: burgundy,
                    backgroundColor: 'rgba(110,30,43,0.08)',
                    fill: true,
                    tension: 0.25,
                    pointRadius: 3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: v => '$' + v.toLocaleString() } }
                }
            }
        });

        new Chart(document.getElementById('ordersChart'), {
            type: 'bar',
            data: {
                labels: monthLabels,
                datasets: [{
                    label: 'Orders',
                    data: monthlyOrders,
                    backgroundColor: burgundy,
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });

        new Chart(document.getElementById('customersChart'), {
            type: 'bar',
            data: {
                labels: monthLabels,
                datasets: [{
                    label: 'New Customers',
                    data: monthlyNewCustomers,
                    backgroundColor: gold,
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    </script>
</body>
</html>
