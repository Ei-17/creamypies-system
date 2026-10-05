<?php
require_once 'config.php';
require_once 'auth.php';
requireLogin();

$perPage = 8;
$page = max(1, (int)($_GET['page'] ?? 1));

// ---- Mark an order as completed (from the popup) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete') {
    $sid = (int)($_POST['sales_id'] ?? 0);
    if ($sid > 0) {
        $upd = $conn->prepare("UPDATE sales SET status = 'Completed' WHERE sales_id = ?");
        $upd->bind_param('i', $sid);
        $upd->execute();
    }
    header('Location: orderhistory.php?page=' . $page);
    exit;
}

// ---- Paging ----
$totalOrders = (int)$conn->query("SELECT COUNT(*) AS c FROM sales")->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($totalOrders / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// ---- This page's orders (newest first) ----
$rows = $conn->query(
    "SELECT sales_id, total_Price, sale_date, status
     FROM sales
     ORDER BY sales_id DESC
     LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
)->fetch_all(MYSQLI_ASSOC);

// ---- Items of those orders ----
$itemsBySale = [];
if ($rows) {
    $ids = implode(',', array_map(fn($r) => (int)$r['sales_id'], $rows));
    $res = $conn->query(
        "SELECT si.sales_id, si.container, si.quantity, si.unit_price, si.line_total, p.productName
         FROM sale_items si
         LEFT JOIN products p ON p.productID = si.product_id
         WHERE si.sales_id IN ($ids)
         ORDER BY si.item_id"
    );
    foreach ($res->fetch_all(MYSQLI_ASSOC) as $it) {
        $itemsBySale[(int)$it['sales_id']][] = $it;
    }
}

// ---- Shape the data for the page + popup ----
$orders = [];
foreach ($rows as $r) {
    $id = (int)$r['sales_id'];
    $items = $itemsBySale[$id] ?? [];

    // "Vanilla Mist (2x), Cookie Cloud (1x) ...."  (first two items only)
    $parts = [];
    foreach (array_slice($items, 0, 2) as $it) {
        $parts[] = ($it['productName'] ?? 'Deleted product') . ' (' . (int)$it['quantity'] . 'x)';
    }
    $summary = implode(', ', $parts) . (count($items) > 2 ? ' ....' : '');

    $orders[] = [
        'id'      => $id,
        'number'  => '00000-' . str_pad((string)$id, 2, '0', STR_PAD_LEFT),
        'date'    => date('m/d/Y', strtotime($r['sale_date'])),
        'status'  => $r['status'],
        'total'   => number_format((float)$r['total_Price'], 2),
        'summary' => $summary,
        'items'   => array_map(fn($it) => [
            'name'      => $it['productName'] ?? 'Deleted product',
            'container' => $it['container'],
            'qty'       => (int)$it['quantity'],
            'price'     => number_format((float)$it['unit_price'], 2),
            'line'      => number_format((float)$it['line_total'], 2),
        ], $items),
    ];
}

// page numbers to show (current page +/- 2)
$from = max(1, $page - 2);
$to   = min($totalPages, $page + 2);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CreamypiesCream - Order History</title>
    <link rel="icon" type="image/png" href="favicon/Faviconn.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@400..700&family=Jersey+25&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="orderhistory.css">
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

    <main class="oh-main">

        <div class="oh-topbar">
            <div class="oh-profile"><i class="bx bx-user"></i></div>
        </div>

        <div class="oh-content">
            <h3 class="oh-heading">Order History</h3>

            <?php if (!$orders): ?>
                <div class="order-card oh-empty">No orders yet.</div>
            <?php endif; ?>

            <?php foreach ($orders as $o): ?>
                <div class="order-card">
                    <div class="oc-info">
                        <p>Order Number: <?= htmlspecialchars($o['number']) ?></p>
                        <p>Date Ordered: &nbsp;<?= htmlspecialchars($o['date']) ?></p>
                    </div>

                    <div class="oc-items"><?= htmlspecialchars($o['summary']) ?></div>

                    <div class="oc-status">
                        <p><?= htmlspecialchars($o['status']) ?></p>
                        <p>Total Amount: <?= htmlspecialchars($o['total']) ?></p>
                    </div>

                    <button type="button" class="oc-view"
                            data-order="<?= htmlspecialchars(json_encode($o, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
                        View Order
                    </button>
                </div>
            <?php endforeach; ?>

            <!---------- Paging ---------->
            <div class="oh-paging">
                <span>Showing <?= count($orders) ?> of <?= $totalOrders ?></span>
                <div class="oh-pages">
                    <?php for ($p = $from; $p <= $to; $p++): ?>
                        <a href="orderhistory.php?page=<?= $p ?>" class="<?= $p === $page ? 'current' : '' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="orderhistory.php?page=<?= $page + 1 ?>">Next</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!---------- Whole order popup ---------->
    <div class="modal-overlay" id="orderModal" aria-hidden="true">
        <div class="modal-box">
            <button type="button" class="modal-close" id="closeOrder" aria-label="Close">
                <i class="bx bx-x"></i>
            </button>

            <h3 class="modal-title" id="mNumber"></h3>
            <p class="modal-meta">
                <span id="mDate"></span> &middot; <span id="mStatus"></span>
            </p>

            <div class="modal-table-wrap">
                <table class="modal-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Container</th>
                            <th class="num">Qty</th>
                            <th class="num">Price</th>
                            <th class="num">Total</th>
                        </tr>
                    </thead>
                    <tbody id="mItems"></tbody>
                </table>
            </div>

            <div class="modal-total">
                <span>Total</span>
                <strong id="mTotal"></strong>
            </div>

            <form method="POST" action="orderhistory.php?page=<?= $page ?>" id="completeForm">
                <input type="hidden" name="action" value="complete">
                <input type="hidden" name="sales_id" id="mSalesId" value="">
                <button type="submit" class="modal-complete" id="mComplete">
                    <i class="bx bx-check"></i> Mark as Completed
                </button>
            </form>
        </div>
    </div>

<script src="orderhistory.js"></script>
</body>
</html>