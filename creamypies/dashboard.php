<?php
require_once 'config.php';
require_once 'auth.php';
requireLogin();

/* ------------------------------------------------------------------
   CHECK THESE 3 NAMES AGAINST YOUR DATABASE
   (change them here if your columns are named differently)
------------------------------------------------------------------- */
$SALES_ID   = 'sales_id';    // primary key of the `sales` table
$SALES_DATE = 'sale_date';   // date/time column of the `sales` table

// name shown in the greeting = the logged-in user's username
$userName = '';
try {
    $u = $conn->prepare("SELECT user_Username FROM users WHERE user_ID = ? LIMIT 1");
    $uid = (int)($_SESSION['user_ID'] ?? 0);
    $u->bind_param('i', $uid);
    $u->execute();
    $userName = (string)($u->get_result()->fetch_assoc()['user_Username'] ?? '');
} catch (Throwable $e) {
    $userName = '';
}

$error = '';

// ---- Mark an order as complete (click on the "On-going Order" label) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_order') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId > 0) {
        try {
            $done = $conn->prepare("UPDATE sales SET status = 'completed' WHERE `$SALES_ID` = ? AND status = 'ongoing'");
            $done->bind_param('i', $orderId);
            $done->execute();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
    if ($error === '') {
        header('Location: dashboard.php');
        exit;
    }
}
$todaySales = 0;
$ongoing = 0;
$lowItems = 0;
$criticalItems = 0;
$bestSellers = [];
$months = [];
$feed = [];
$feedItems = [];

// months shown in the chart: Jan ... Dec (current year)
foreach (range(1, 12) as $m) { $months[$m] = 0.0; $monthOrders[$m] = 0; }

function imgSrc($blob) {
    if (empty($blob)) return '';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($blob) ?: 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode($blob);
}

function monthFull($m) {
    return date('F', mktime(0, 0, 0, (int)$m, 1));
}

function monthLabel($m) {
    return ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][(int)$m];
}

function timeAgo($mins) {
    $mins = (int)$mins;
    if ($mins < 1)    return 'Just now';
    if ($mins < 60)   return $mins . 'm ago';
    if ($mins < 1440) return floor($mins / 60) . 'h ago';
    return floor($mins / 1440) . 'd ago';
}

try {
    // ---- Top cards ----
    $r = $conn->query("SELECT COALESCE(SUM(total_Price),0) AS t FROM sales WHERE DATE(`$SALES_DATE`) = CURDATE()")->fetch_assoc();
    $todaySales = (float)$r['t'];

    $r = $conn->query("SELECT COUNT(*) AS c FROM sales WHERE status = 'ongoing'")->fetch_assoc();
    $ongoing = (int)$r['c'];

    $r = $conn->query(
        "SELECT COALESCE(SUM(stock > critical_level AND stock <= low_level),0) AS low_items,
                COALESCE(SUM(stock <= critical_level),0) AS critical_items
         FROM pantry_items"
    )->fetch_assoc();
    $lowItems = (int)$r['low_items'];
    $criticalItems = (int)$r['critical_items'];

    // ---- Best sellers (this month) ----
    $bestSellers = $conn->query(
        "SELECT p.productName, p.productPrice, p.img, SUM(si.quantity) AS sold
         FROM sale_items si
         JOIN sales s ON s.`$SALES_ID` = si.sales_id
         JOIN products p ON p.productID = si.product_id
         WHERE YEAR(s.`$SALES_DATE`) = YEAR(CURDATE()) AND MONTH(s.`$SALES_DATE`) = MONTH(CURDATE())
         GROUP BY p.productID, p.productName, p.productPrice
         ORDER BY sold DESC
         LIMIT 3"
    )->fetch_all(MYSQLI_ASSOC);

    // ---- Revenue per month (this year) ----
    $res = $conn->query(
        "SELECT MONTH(`$SALES_DATE`) AS h, SUM(total_Price) AS t, COUNT(*) AS c
         FROM sales
         WHERE YEAR(`$SALES_DATE`) = YEAR(CURDATE())
         GROUP BY MONTH(`$SALES_DATE`)"
    );
    foreach ($res as $row) {
        $h = (int)$row['h'];
        if (isset($months[$h])) { $months[$h] = (float)$row['t']; $monthOrders[$h] = (int)$row['c']; }
    }

    // ---- Live counter feed (latest 5 orders) ----
    $feed = $conn->query(
        "SELECT `$SALES_ID` AS id, total_Price, status, TIMESTAMPDIFF(MINUTE, `$SALES_DATE`, NOW()) AS mins
         FROM sales
         ORDER BY (status = 'ongoing') DESC, `$SALES_DATE` DESC, `$SALES_ID` DESC
         LIMIT 5"
    )->fetch_all(MYSQLI_ASSOC);

    if ($feed) {
        $ids = array_map(fn($f) => (int)$f['id'], $feed);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare(
            "SELECT si.sales_id, si.quantity, si.container, si.size, p.productName
             FROM sale_items si
             JOIN products p ON p.productID = si.product_id
             WHERE si.sales_id IN ($ph)
             ORDER BY si.sales_id"
        );
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $it) {
            $feedItems[(int)$it['sales_id']][] =
                (int)$it['quantity'] . '× ' . $it['productName'] . ' (' . $it['container'] . ', ' . $it['size'] . ')';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

// ---- Chart helpers ----
$maxRev = max($months) ?: 1;
$peakMonth = array_search(max($months), $months, true);

function barClass($v, $max, $isPeak) {
    if ($isPeak && $v > 0) return 'b-peak';
    $r = $v / $max;
    if ($r >= 0.9) return 'b-5';
    if ($r >= 0.7) return 'b-4';
    if ($r >= 0.5) return 'b-3';
    if ($r >= 0.25) return 'b-2';
    return 'b-1';
}

// ---- Greeting ----
$hourNow = (int)date('G');
$greeting = $hourNow < 12 ? 'Good Morning' : ($hourNow < 18 ? 'Good Afternoon' : 'Good Evening');
$dateText = str_replace('Sep ', 'Sept ', date('M j, Y'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CreamypiesCream - Dashboard</title>
    <link rel="icon" type="image/png" href="favicon/Faviconn.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css?v=<?= @filemtime(__DIR__ . '/dashboard.css') ?>">
    <link href="https://cdn.boxicons.com/3.0.8/fonts/basic/boxicons.min.css" rel="stylesheet">
</head>
<body>

    <nav class="sidebar" aria-label="Main navigation">
        <header>
            <img src="Assets/favicon/logo.png" alt="CreamypiesCream Logo" class="logo-image">
            <p class="tagline">Taste the Creamy Difference</p>
            <img src="Assets/sparepics/heart.png" alt="Heart Icon" class="heart-icon">
        </header>

        <div id="sideNavbar" class="sidenav">
            <ul class="nav-links">
                <li class="nav-item dashboardbutton">
                    <a href="dashboard.php">
                        <i class="bx bx-shapes-alt-2"></i>
                        <span class="dashboard">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item posbutton">
                    <a href="pos.php">
                        <i class="bx bx-basket"></i>
                        <span class="POS">POS</span>
                    </a>
                </li>
                <li class="nav-item orderhistorybutton">
                    <a href="orderhistory.php">
                        <i class="bx bx-file-detail"></i>
                        <span class="OrderHistory">Order History</span>
                    </a>
                </li>
                <li class="nav-item inventorybutton">
                    <a href="inventory.php">
                        <i class="bx bx-archive"></i>
                        <span class="Inventory">Inventory</span>
                    </a>
                </li>
                <li class="nav-item salesbutton">
                    <a href="sales.php">
                        <i class="bx bx-bar-chart-big"></i>
                        <span class="Sales">Sales</span>
                    </a>
                </li>
                <li class="nav-item settingsbutton">
                    <a href="settings.php">
                        <i class="bx bx-cog"></i>
                        <span class="Settings">Settings</span>
                    </a>
                </li>
            </ul>
            <div class="advertisement"></div>
            <ul class="nav-links">
                <li class="nav-item logoutbutton">
                    <a href="logout.php">
                        <i class="bx bx-arrow-out-right-square-half"></i>
                        <span class="Logout">Logout</span>
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <main class="dash-main">

        <!---------- Top bar ---------->
        <div class="dash-topbar">
            <div class="dash-greeting">
                <h1><?= $greeting ?><?= $userName !== '' ? ' ,' . htmlspecialchars($userName) : '' ?>!</h1>
                <div class="dash-meta">
                    <div>
                        <span>Today</span>
                        <span><?= htmlspecialchars($dateText) ?></span>
                    </div>
                    <i class="dash-dot"></i>
                    <div>
                        <span>Morning Shift</span>
                        <span>(06:00 - 02:00)</span>
                    </div>
                </div>
            </div>
            <div class="dash-profile"><i class="bx bx-user"></i></div>
        </div>

        <div class="dash-content">

            <?php if ($error): ?>
                <p class="dash-error">Dashboard error: <?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <!---------- Stat cards ---------->
            <section class="stat-grid">
                <div class="stat-card">
                    <p class="stat-label">Todays Sales</p>
                    <p class="stat-value">₱ <?= number_format($todaySales, 2) ?></p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">On-going Orders</p>
                    <p class="stat-value"><?= $ongoing ?></p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Low Stock</p>
                    <p class="stat-value"><?= $lowItems ?></p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Critical Shortage</p>
                    <p class="stat-value"><?= $criticalItems ?></p>
                </div>
            </section>

            <div class="dash-row">

                <div class="dash-left">

                    <!---------- Best sellers ---------->
                    <section class="panel best-panel">
                        <h2 class="best-title">Best Seller Flavors <span class="pill">Monthly Ranking</span></h2>

                        <?php if (!$bestSellers): ?>
                            <p class="empty">No sales this month yet.</p>
                        <?php endif; ?>

                        <?php foreach ($bestSellers as $i => $b): ?>
                            <div class="best-row<?= $i === 0 ? ' first' : '' ?>">
                                <span class="rank<?= $i === 0 ? ' gold' : '' ?>"><?= $i + 1 ?></span>
                                <?php if (!empty($b['img'])): ?>
                                    <img src="<?= imgSrc($b['img']) ?>" alt="" class="best-img">
                                <?php else: ?>
                                    <span class="best-img best-img-empty"></span>
                                <?php endif; ?>
                                <span class="best-name"><?= htmlspecialchars($b['productName']) ?></span>
                                <span class="best-sold">
                                    <strong><?= (int)$b['sold'] ?> sold</strong>
                                    <small>₱<?= number_format($b['productPrice'], 2) ?></small>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </section>

                    <!---------- Monthly revenue ---------->
                    <section class="panel revenue-panel">
                        <h2 class="rev-title">Monthly Revenue</h2>
                        <p class="rev-sub">Sales Velocity</p>

                        <div class="chart">
                            <div class="chart-bars">
                                <?php foreach ($months as $h => $v): ?>
                                    <?php $pct = $v > 0 ? max(6, $v / $maxRev * 92) : 4; // 92% so the tallest bar never touches the top ?>
                                    <div class="bar-col">
                                        <span class="bar <?= barClass($v, $maxRev, $h === $peakMonth) ?>" style="height: <?= round($pct, 1) ?>%">
                                            <span class="tip<?= $pct > 60 ? ' in' : '' ?>">
                                                <strong><?= monthFull($h) ?> <?= date('Y') ?></strong>
                                                <?php if ($v > 0): ?>
                                                    <span class="amt">₱ <?= number_format($v, 2) ?></span>
                                                    <span><?= (int)$monthOrders[$h] ?> order<?= $monthOrders[$h] == 1 ? '' : 's' ?></span>
                                                <?php else: ?>
                                                    <span>No sales yet</span>
                                                <?php endif; ?>
                                            </span>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="chart-labels">
                                <?php foreach ($months as $h => $v): ?>
                                    <span class="<?= ($h === $peakMonth && $v > 0) ? 'peak' : '' ?>"><?= monthLabel($h) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>
                </div>

                <!---------- Live counter feed ---------->
                <aside class="feed-panel">
                    <h2 class="feed-title">Live Counter Feed</h2>
                    <p class="feed-sub">Real-time incoming orders</p>

                    <?php if (!$feed): ?>
                        <p class="empty">No orders yet.</p>
                    <?php endif; ?>

                    <?php foreach ($feed as $i => $f): ?>
                        <?php $ago = timeAgo($f['mins']); $isOngoing = ($f['status'] === 'ongoing'); ?>
                        <div class="feed-card<?= $i === 0 ? ' first' : '' ?><?= $isOngoing ? '' : ' done' ?>">
                            <div class="feed-top">
                                <span class="feed-order">Order #<?= (int)$f['id'] ?></span>
                                <span class="feed-time<?= $ago === 'Just now' ? ' now' : '' ?>"><?= $ago ?></span>
                            </div>
                            <p class="feed-items"><?= htmlspecialchars(implode(', ', $feedItems[(int)$f['id']] ?? [])) ?></p>
                            <div class="feed-bottom">
                                <?php if ($isOngoing): ?>
                                    <form method="POST" class="status-form">
                                        <input type="hidden" name="action" value="complete_order">
                                        <input type="hidden" name="order_id" value="<?= (int)$f['id'] ?>">
                                        <button type="submit" class="status-btn" title="Click to mark as complete">
                                            <i class="status-dot"></i> On-going Order
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="status-done"><i class="bx bx-check"></i> Completed</span>
                                <?php endif; ?>
                                <strong class="feed-total">₱<?= number_format($f['total_Price'], 2) ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </aside>

            </div>
        </div>
    </main>

</body>
</html>