<?php
require_once 'config.php';
require_once 'auth.php';
requireLogin();

// Size surcharge added to the product's base price (S = base price)
const SIZE_PRICES = ['S' => 0, 'M' => 25, 'L' => 50, 'XL' => 100];

// Convert a BLOB from the database into an image the browser can display
function imgSrc($blob) {
    if (empty($blob)) return '';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($blob) ?: 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode($blob);
}

// Link that keeps the current category + search
function pageUrl($category, $search) {
    $q = ['category' => $category];
    if ($search !== '') $q['search'] = $search;
    return 'pos.php?' . http_build_query($q);
}

// Load the cart's products from the database (prices always come from the DB)
// Returns [items, subtotal]
function loadCart($conn, array $cart) {
    $items = [];
    $subtotal = 0; // base prices only
    $addons = 0;   // size add-ons
    if (!$cart) return [$items, $subtotal, $addons];

    // cart keys look like "12_Cups_M" -> product 12 in a medium cup
    $ids = [];
    foreach (array_keys($cart) as $key) {
        $ids[(int)explode('_', (string)$key)[0]] = true;
    }
    $ids = array_keys($ids);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT productID, productName, productPrice, img FROM products WHERE productID IN ($ph)");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();

    $rows = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $rows[(int)$r['productID']] = $r;
    }

    // keep the order the items were added in
    foreach ($cart as $key => $qty) {
        [$pid, $container, $size] = array_pad(explode('_', (string)$key, 3), 3, '');
        $pid = (int)$pid;
        if ($container === '') $container = 'Cups';
        if (!isset(SIZE_PRICES[$size])) $size = 'S';
        if (!isset($rows[$pid])) continue; // product was deleted
        $row = $rows[$pid];
        $row['key'] = (string)$key;
        $row['container'] = $container;
        $row['size'] = $size;
        $row['qty'] = $qty;
        // base price + size surcharge
        $row['addon'] = SIZE_PRICES[$size];
        $row['unitPrice'] = $row['productPrice'] + $row['addon'];
        $row['line'] = $row['unitPrice'] * $qty;
        $subtotal += $row['productPrice'] * $qty;
        $addons += $row['addon'] * $qty;
        $items[] = $row;
    }
    return [$items, $subtotal, $addons];
}

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$message = '';
$containers = ['Cups', 'Cones'];
$sizes = array_keys(SIZE_PRICES);
$search = trim($_GET['search'] ?? $_POST['search'] ?? '');
$selected_category = $_GET['category'] ?? $_POST['category'] ?? 'All';

// ---- Cart actions (add / update qty / remove / checkout) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update';
    $cart = $_SESSION['cart'];

    if ($action === 'select') {
        // the popup sends the ice cream + chosen container + chosen size
        // same flavor + container + size = +1, anything different = separate row
        $pid = (int)($_POST['productID'] ?? 0);
        $container = $_POST['container'] ?? '';
        $size = $_POST['size'] ?? '';
        if ($pid > 0 && in_array($container, $containers, true) && in_array($size, $sizes, true)) {
            $key = $pid . '_' . $container . '_' . $size;
            $cart[$key] = ($cart[$key] ?? 0) + 1;
        }
    } else {
        // apply any quantity changes from the order form
        foreach ((array)($_POST['qty'] ?? []) as $key => $q) {
            $key = (string)$key;
            if (isset($cart[$key])) {
                $cart[$key] = max(1, (int)$q);
            }
        }
        // remove button
        if (strpos($action, 'remove:') === 0) {
            unset($cart[substr($action, 7)]);
        }
    }
    $_SESSION['cart'] = $cart;

    if ($action === 'record') {
        // ---- Checkout ----
        $served_by = (int)$_SESSION['user_ID'];
        [$items, $orderSub, $orderAddons] = loadCart($conn, $cart);
        $orderTotal = $orderSub + $orderAddons;

        if (!$items) {
            $message = '<p class="error">Please select a product first.</p>';
        } else {
            try {
                $conn->begin_transaction();

                // 1) one row in `sales` for the whole order
                $head = $conn->prepare("INSERT INTO sales (total_Price, served_by) VALUES (?, ?)");
                $orderTotal = (float)$orderTotal;
                $head->bind_param('di', $orderTotal, $served_by);
                $head->execute();
                $sales_id = $conn->insert_id;

                // 2) one row in `sale_items` per product + container + size in that order
                $ins = $conn->prepare("INSERT INTO sale_items (sales_id, product_id, container, size, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($items as $it) {
                    $pid = (int)$it['productID'];
                    $container = $it['container'];
                    $size = $it['size'];
                    $qty = (int)$it['qty'];
                    $unit = (float)$it['unitPrice'];
                    $line = (float)$it['line'];
                    $ins->bind_param('iissidd', $sales_id, $pid, $container, $size, $qty, $unit, $line);
                    $ins->execute();
                }
                $conn->commit();
                $_SESSION['cart'] = [];
                header('Location: ' . pageUrl($selected_category, $search));
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                $message = '<p class="error">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
        }
    } else {
        // Post/Redirect/Get so a refresh doesn't add the item again
        header('Location: ' . pageUrl($selected_category, $search));
        exit;
    }
}

// ---- Product list ($display) ----
$sql = "SELECT p.productID, p.productName, p.productPrice, p.img
        FROM products p
        WHERE 1=1";
$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND p.productName LIKE ?";
    $params[] = "%$search%";
    $types .= 's';
}
if ($selected_category !== 'All') {
    $sql .= " AND p.categoryID = (SELECT categoryID FROM category WHERE categoryName = ? LIMIT 1)";
    $params[] = $selected_category;
    $types .= 's';
}
$sql .= " ORDER BY p.productName";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$display = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ---- Current order ----
[$cartItems, $subtotal, $addons] = loadCart($conn, $_SESSION['cart']);
$total = $subtotal + $addons;

$categories = $conn->query("SELECT * FROM category ORDER BY categoryName");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CreamypiesCream</title>
    <link rel="icon" type="image/png" href="favicon/Faviconn.png">
    <link rel="stylesheet" href="pos.css">
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

    <!---------- Search ---------->

    <div class="top-shit">
        <form class="search-container" method="GET">
            <input type="hidden" name="category" value="<?= htmlspecialchars($selected_category) ?>">
            <div class="search">
                <i class="bx bx-search-alt"></i>
                <input type="search" name="search" placeholder="Search Products..." value="<?= htmlspecialchars($search) ?>">
            </div>
        </form>
        <div class="user-profile">
            <i class="bx bx-user"></i>
        </div>
    </div>

    <?php if ($message): ?>
        <?= $message ?>
    <?php endif; ?>

    <!---------- Categories ---------->

    <div class="categories">
        <h3 class="category-heading">Categories</h3>
        <div class="category-grid">
            <div class="category">
                <a class="category-name<?= $selected_category === 'All' ? ' active' : '' ?>"
                   href="<?= htmlspecialchars(pageUrl('All', $search)) ?>">All</a>
            </div>

            <?php while ($category = $categories->fetch_assoc()): ?>
                <?php if ($category['categoryName'] === 'All') continue; ?>
                <div class="category">
                    <a class="category-name<?= $selected_category === $category['categoryName'] ? ' active' : '' ?>"
                       href="<?= htmlspecialchars(pageUrl($category['categoryName'], $search)) ?>">
                        <?= htmlspecialchars($category['categoryName']) ?>
                    </a>
                </div>
            <?php endwhile; ?>
        </div>
    </div>

    <!---------- Products ---------->

    <div class="products">
        <h3 class="product-heading">Products</h3>
        <div class="product-grid">

            <?php if (empty($display)): ?>
                <p class="no-results">No products found.</p>
            <?php endif; ?>

            <?php foreach ($display as $product): ?>
                <div class="product-form">
                    <button type="button" class="product"
                            data-id="<?= (int)$product['productID'] ?>"
                            data-name="<?= htmlspecialchars($product['productName']) ?>">
                        <img src="<?= imgSrc($product['img']) ?>" alt="<?= htmlspecialchars($product['productName']) ?>" class="product-image">
                        <p class="product-name"><?= htmlspecialchars($product['productName']) ?></p>
                        <p class="product-price">₱ <?= number_format($product['productPrice'], 2) ?></p>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!---------- Orders ---------->

    <div class="order">
        <div class="current-order-header">
            <div class="current-order">
                <p class="currentorder">Current Order</p>
            </div>
        </div>

        <form method="POST" id="orderForm">
            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
            <input type="hidden" name="category" value="<?= htmlspecialchars($selected_category) ?>">

            <div class="order-section">
                <div class="order-box">
                    <?php if ($cartItems): ?>
                        <?php foreach ($cartItems as $item): ?>
                            <div class="order-item">
                                <img src="<?= imgSrc($item['img']) ?>" alt="" class="product-image">
                                <div class="order-info">
                                    <p class="product-name"><?= htmlspecialchars($item['productName']) ?></p>
                                    <p class="product-price">₱ <?= number_format($item['unitPrice'], 2) ?></p>

                                    <div class="qty-control">
                                        <button type="button" class="qty-btn" data-step="-1">−</button>
                                        <input type="number" class="qty-input" name="qty[<?= htmlspecialchars($item['key']) ?>]"
                                               min="1" value="<?= (int)$item['qty'] ?>"
                                               data-price="<?= htmlspecialchars($item['unitPrice']) ?>"
                                               data-addon="<?= (int)$item['addon'] ?>">
                                        <button type="button" class="qty-btn" data-step="1">+</button>
                                    </div>
                                </div>

                                <span class="order-container" data-container="<?= htmlspecialchars($item['container']) ?>">
                                    <?= htmlspecialchars($item['container']) ?>
                                    <small class="order-size"><?= htmlspecialchars($item['size']) ?></small>
                                </span>
                                <span class="line-total">₱ <?= number_format($item['line'], 2) ?></span>

                                <button type="submit" name="action" value="remove:<?= htmlspecialchars($item['key']) ?>"
                                        class="remove-item" title="Remove">
                                    <i class="bx bx-x"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="no-results">No product selected.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="order-summary">
                <div class="summary-row">
                    <p class="subtotal">Subtotal</p>
                    <p class="subtotal-amount" id="subtotalAmount">₱ <?= number_format($subtotal, 2) ?></p>
                </div>
                <div class="summary-row">
                    <p class="addon">Size Add-ons</p>
                    <p class="addon-amount" id="addonAmount">₱ <?= number_format($addons, 2) ?></p>
                </div>
                <div class="summary-row">
                    <p class="tax">Tax</p>
                    <p class="tax-amount">₱ 0.00</p>
                </div>
                <div class="summary-row">
                    <p class="discount">Discount</p>
                    <p class="discount-amount">₱ 0.00</p>
                </div>
                <div class="total-row">
                    <p class="total">Total</p>
                    <p class="total-amount" id="totalAmount">₱ <?= number_format($total, 2) ?></p>
                </div>
                <div class="checkout-wrap">
                    <button class="checkout-button" type="button" id="checkoutBtn"
                            <?= $cartItems ? '' : 'disabled' ?>>
                        <i class="bx bx-currency-notes"></i> Checkout
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!---------- Choose Container + Size popup ---------->

    <div class="modal-overlay" id="containerModal" aria-hidden="true">
        <form method="POST" class="modal-box" id="containerForm">
            <input type="hidden" name="productID" id="modalProductID" value="">
            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
            <input type="hidden" name="category" value="<?= htmlspecialchars($selected_category) ?>">

            <button type="button" class="modal-close" id="modalClose" aria-label="Close">
                <i class="bx bx-x"></i>
            </button>

            <h3 class="modal-title" id="modalTitle"></h3>
            <p class="modal-subtitle">Choose Container</p>

            <div class="container-options">
                <label class="container-option">
                    <input type="radio" name="container" value="Cups" required>
                    <span class="container-card">
                        <img src="Assets/containers/Cups.png" alt="Cups">
                        <span>Cups</span>
                    </span>
                </label>
                <label class="container-option">
                    <input type="radio" name="container" value="Cones" required>
                    <span class="container-card">
                        <img src="Assets/containers/Cone.png" alt="Cones">
                        <span>Cones</span>
                    </span>
                </label>
            </div>

            <div class="size-section" id="sizeSection">
                <p class="modal-subtitle">Choose Size</p>
                <div class="size-options">
                    <?php foreach ($sizes as $s): ?>
                        <label class="size-option">
                            <input type="radio" name="size" value="<?= $s ?>">
<span class="size-pill"><?= $s ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" name="action" value="select" class="modal-add" id="modalAdd" disabled>
                <i class="bx bx-cart"></i> Add
            </button>
        </form>
    </div>

    <!---------- Order Summary popup ---------->

    <div class="modal-overlay" id="checkoutModal" aria-hidden="true">
        <div class="modal-box summary-box">
            <button type="button" class="modal-close" id="checkoutClose" aria-label="Close">
                <i class="bx bx-x"></i>
            </button>

            <h3 class="modal-title">Order Summary</h3>
            <p class="modal-subtitle">Containers needed</p>

            <div class="container-options">
                <div class="count-card">
                    <span class="count-number" id="cupCount">0</span>
                    <span>Cups</span>
                </div>
                <div class="count-card">
                    <span class="count-number" id="coneCount">0</span>
                    <span>Cones</span>
                </div>
            </div>

            <div class="summary-total">
                <span>Total</span>
                <strong id="checkoutTotal">₱ 0.00</strong>
            </div>

            <button type="submit" form="orderForm" name="action" value="record" class="modal-add">
                <i class="bx bx-currency-notes"></i> Confirm Checkout
            </button>
        </div>
    </div>

    <script src="pos.js"></script>

</body>
</html>