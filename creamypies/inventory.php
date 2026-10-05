<?php
require_once 'config.php';
require_once 'auth.php';
requireLogin();

$message = '';
$openModal = false;
$categoryOptions = ['Containers', 'Ingredients', 'Fruits', 'Utensils', 'Kitchenware'];
$unitOptions = ['kg', 'pcs', 'L'];
$filter = (($_GET['filter'] ?? '') === 'low') ? 'low' : 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_resource') {
    $name     = trim($_POST['item_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $unit     = trim($_POST['unit'] ?? '');
    if (!in_array($unit, $unitOptions, true)) $unit = 'pcs';
    $qty      = (float)($_POST['quantity'] ?? 0);
    $low      = (float)($_POST['low_level'] ?? 20);
    $critical = (float)($_POST['critical_level'] ?? 5);

    if ($name === '' || $category === '' || $qty <= 0) {
        $message = '<p class="inv-error">Please enter an item name, choose a category and enter a quantity above 0.</p>';
        $openModal = true;
    } else {
        try {
            $find = $conn->prepare("SELECT item_id FROM pantry_items WHERE item_name = ? LIMIT 1");
            $find->bind_param('s', $name);
            $find->execute();
            $existing = $find->get_result()->fetch_assoc();

            if ($existing) {
                $id = (int)$existing['item_id'];
                $upd = $conn->prepare("UPDATE pantry_items SET stock = stock + ? WHERE item_id = ?");
                $upd->bind_param('di', $qty, $id);
                $upd->execute();
            } else {
                $ins = $conn->prepare("INSERT INTO pantry_items (item_name, category, stock, unit, low_level, critical_level) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->bind_param('ssdsdd', $name, $category, $qty, $unit, $low, $critical);
                $ins->execute();
            }
            header('Location: inventory.php' . ($filter === 'low' ? '?filter=low' : ''));
            exit;
        } catch (Throwable $e) {
            $message = '<p class="inv-error">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            $openModal = true;
        }
    }
}

$stats = $conn->query(
    "SELECT COUNT(*) AS total_items,
            COALESCE(SUM(stock), 0) AS total_stock,
            COALESCE(SUM(stock > critical_level AND stock <= low_level), 0) AS low_items,
            COALESCE(SUM(stock <= critical_level), 0) AS critical_items
     FROM pantry_items"
)->fetch_assoc();

$sql = "SELECT item_id, item_name, category, stock, unit, low_level, critical_level FROM pantry_items";
if ($filter === 'low') $sql .= " WHERE stock <= low_level";
$sql .= " ORDER BY (stock <= critical_level) DESC, (stock <= low_level) DESC, item_name";
$items = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

$known = [];
foreach ($conn->query("SELECT item_name, category, unit FROM pantry_items ORDER BY item_name") as $r) {
    $known[mb_strtolower($r['item_name'])] = ['category' => $r['category'], 'unit' => $r['unit']];
}
$names = array_map(fn($r) => $r['item_name'], $items);

function fmtQty($n) {
    return rtrim(rtrim(number_format((float)$n, 2, '.', ','), '0'), '.');
}
function stockStatus($row) {
    if ($row['stock'] <= $row['critical_level']) return ['Critical', 'critical'];
    if ($row['stock'] <= $row['low_level'])      return ['Low Stock', 'low'];
    return ['In Stock', 'ok'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CreamypiesCream - Inventory</title>
    <link rel="icon" type="image/png" href="favicon/Faviconn.png">
    <link rel="stylesheet" href="inventory.css">
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

    <main class="inv-main">

        <div class="inv-topbar">
            <h1 class="inv-title">Pantry Management</h1>
            <div class="inv-profile"><i class="bx bx-user"></i></div>
        </div>

        <div class="inv-content">
            <?= $message ?>

            <h3 class="inv-heading">Inventory</h3>

            <!---------- Stat cards ---------->
            <div class="stat-grid">
                <div class="stat-card">
                    <span class="stat-label">Total Stocks</span>
                    <span class="stat-value"><?= fmtQty($stats['total_stock']) ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Total Items</span>
                    <span class="stat-value"><?= (int)$stats['total_items'] ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Low Stock Items</span>
                    <span class="stat-value"><?= (int)$stats['low_items'] ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Critical Shortage</span>
                    <span class="stat-value"><?= (int)$stats['critical_items'] ?></span>
                </div>
            </div>

            <!---------- Buttons ---------->
            <div class="inv-actions">
                <?php if ($filter === 'low'): ?>
                    <a class="inv-btn" href="inventory.php">View All Stocks</a>
                <?php else: ?>
                    <a class="inv-btn" href="inventory.php?filter=low">View Low Stocks....</a>
                <?php endif; ?>
                <button type="button" class="inv-btn" id="openAdd">
                    <i class="bx bx-plus"></i> Add Resources
                </button>
            </div>

            <!---------- Table ---------->
            <div class="inv-table-wrap">
                <table class="inv-table">
                    <thead>
                        <tr>
                            <th class="col-item">Items</th>
                            <th class="col-cat">Categories</th>
                            <th class="col-status">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$items): ?>
                            <tr><td colspan="3" class="inv-empty">
                                <?= $filter === 'low' ? 'No low stock items.' : 'No items yet. Click "Add Resources" to add one.' ?>
                            </td></tr>
                        <?php endif; ?>

                        <?php foreach ($items as $row): [$label, $cls] = stockStatus($row); ?>
                            <tr>
                                <td><?= htmlspecialchars($row['item_name']) ?></td>
                                <td><?= htmlspecialchars($row['category']) ?></td>
                                <td>
                                    <span class="status-pill <?= $cls ?>"><?= $label ?></span>
                                    <span class="stock-amount"><?= fmtQty($row['stock']) ?> <?= htmlspecialchars($row['unit']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!---------- Add Resources popup ---------->
    <div class="modal-overlay<?= $openModal ? ' open' : '' ?>" id="addModal"
         data-known="<?= htmlspecialchars(json_encode($known, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
        <form method="POST" class="modal-box" action="inventory.php<?= $filter === 'low' ? '?filter=low' : '' ?>">
            <input type="hidden" name="action" value="add_resource">

            <button type="button" class="modal-close" id="closeAdd" aria-label="Close">
                <i class="bx bx-x"></i>
            </button>

            <h3 class="modal-title">Add Resources</h3>
            <p class="modal-hint">Type an existing item to add more stock to it, or a new name to create it.</p>

            <label class="field">
                <span>Item name</span>
                <input type="text" name="item_name" id="fItem" list="itemNames" required
                       value="<?= htmlspecialchars($_POST['item_name'] ?? '') ?>">
                <datalist id="itemNames">
                    <?php foreach ($names as $n): ?>
                        <option value="<?= htmlspecialchars($n) ?>">
                    <?php endforeach; ?>
                </datalist>
            </label>

            <div class="field-row">
                <label class="field">
                    <span>Category</span>
                    <select name="category" id="fCategory" required>
                        <option value="" disabled <?= empty($_POST['category']) ? 'selected' : '' ?>>Select category</option>
                        <?php foreach ($categoryOptions as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>"
                                <?= (($_POST['category'] ?? '') === $c) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span>Unit</span>
                    <select name="unit" id="fUnit" required>
                        <option value="" disabled <?= empty($_POST['unit']) ? 'selected' : '' ?>>Select unit</option>
                        <?php foreach ($unitOptions as $u): ?>
                            <option value="<?= htmlspecialchars($u) ?>"
                                <?= (($_POST['unit'] ?? '') === $u) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="field-row">
                <label class="field">
                    <span>Quantity to add</span>
                    <input type="number" name="quantity" min="0.01" step="any" required
                           value="<?= htmlspecialchars($_POST['quantity'] ?? '') ?>">
                </label>
                <label class="field">
                    <span>Low at or below</span>
                    <input type="number" name="low_level" min="0" step="any" value="20">
                </label>
                <label class="field">
                    <span>Critical at or below</span>
                    <input type="number" name="critical_level" min="0" step="any" value="5">
                </label>
            </div>

            <button type="submit" class="modal-add"><i class="bx bx-plus"></i> Add</button>
        </form>
    </div>

    <script src="inventory.js"></script>
</body>
</html>