<?php
require_once 'config.php';
require_once 'auth.php';
requireLogin();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$userId = (int)($_SESSION['user_ID'] ?? 0);

/* ------------------------------------------------------------------
   Find the logged-in user's row (and remember which table / id column
   worked, so we can UPDATE the same row later).
------------------------------------------------------------------- */
$tableNames = ['users', 'user', 'accounts'];
$idColumns  = ['user_ID', 'user_id', 'id'];

$user = null;
$userTable = $userIdCol = '';
foreach ($tableNames as $table) {
    foreach ($idColumns as $idCol) {
        try {
            $stmt = $conn->prepare("SELECT * FROM `$table` WHERE `$idCol` = ? LIMIT 1");
            if (!$stmt) continue;
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) { $user = $row; $userTable = $table; $userIdCol = $idCol; break 2; }
        } catch (Throwable $e) {
            // wrong table/column name, try the next one
        }
    }
}

// get the actual column name that matches one of the names (even if empty)
function findCol($row, array $names) {
    foreach ($names as $n) {
        foreach ($row as $col => $val) {
            if (strcasecmp($col, $n) === 0) return $col;
        }
    }
    return '';
}

// get the first filled-in column that matches one of the names
function pick($row, array $names) {
    foreach ($names as $n) {
        foreach ($row as $col => $val) {
            if (strcasecmp($col, $n) === 0 && trim((string)$val) !== '') return trim((string)$val);
        }
    }
    return '';
}

$nameList    = ['user_Username', 'fullname', 'full_name', 'name', 'user_name', 'username'];
$phoneList   = ['user_Phone', 'phone', 'phone_number', 'phonenumber', 'contact', 'contact_number', 'contact_no', 'mobile', 'mobile_number'];
$emailList   = ['user_Email', 'email', 'email_address', 'emailaddress'];
$addressList = ['user_Address', 'address', 'user_address', 'home_address'];

/* ------------------------------------------------------------------
   Profile picture: stored as a file, so no database column is needed.
   uploads/profiles/user_<id>.<ext>
------------------------------------------------------------------- */
$uploadDirFs  = __DIR__ . '/uploads/profiles/';
$uploadDirUrl = 'uploads/profiles/';

function findAvatar($dirFs, $dirUrl, $userId) {
    $files = glob($dirFs . 'user_' . $userId . '.*');
    if ($files) {
        $f = $files[0];
        return $dirUrl . basename($f) . '?v=' . @filemtime($f);
    }
    return '';
}

/* ------------------------------------------------------------------
   Handle the "Edit Profile" form
------------------------------------------------------------------- */
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $errors = [];

    if (!$user) {
        $errors[] = "Couldn't find your account.";
    }
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Invalid request. Please refresh the page and try again.';
    }

    $newName    = trim($_POST['name'] ?? '');
    $newPhone   = trim($_POST['phone'] ?? '');
    $newEmail   = trim($_POST['email'] ?? '');
    $newAddress = trim($_POST['address'] ?? '');

    if ($newName === '') $errors[] = 'Name is required.';
    if ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($newPhone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $newPhone)) $errors[] = 'Please enter a valid phone number.';

    // --- profile picture (optional) ---
    $newImageTmp = null;
    $newImageExt = '';
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['avatar'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The picture could not be uploaded.';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Profile picture must be 2 MB or smaller.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
                $errors[] = 'Profile picture must be a JPG, PNG or WEBP image.';
            } else {
                $newImageTmp = $file['tmp_name'];
                $newImageExt = $allowed[$mime];
            }
        }
    }

    if (!$errors) {
        // build the UPDATE using the columns that really exist in your table
        $sets = [];
        $vals = [];
        $map = [
            [$nameList,    $newName],
            [$phoneList,   $newPhone],
            [$emailList,   $newEmail],
            [$addressList, $newAddress],
        ];
        foreach ($map as [$list, $value]) {
            $col = findCol($user, $list);
            if ($col !== '') {
                $sets[] = "`$col` = ?";
                $vals[] = $value;
            }
        }

        if ($sets) {
            $sql  = "UPDATE `$userTable` SET " . implode(', ', $sets) . " WHERE `$userIdCol` = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $types  = str_repeat('s', count($vals)) . 'i';
                $params = array_merge($vals, [$userId]);
                $stmt->bind_param($types, ...$params);
                if (!$stmt->execute()) {
                    $errors[] = 'Could not save your changes. Please try again.';
                }
            } else {
                $errors[] = 'Could not save your changes. Please try again.';
            }
        }
    }

    if (!$errors && $newImageTmp !== null) {
        if (!is_dir($uploadDirFs)) @mkdir($uploadDirFs, 0755, true);
        foreach (glob($uploadDirFs . 'user_' . $userId . '.*') ?: [] as $old) @unlink($old);
        if (!move_uploaded_file($newImageTmp, $uploadDirFs . 'user_' . $userId . '.' . $newImageExt)) {
            $errors[] = 'Your info was saved, but the picture could not be stored.';
        }
    }

    // remove current picture (optional checkbox)
    if (!$errors && !empty($_POST['remove_avatar']) && $newImageTmp === null) {
        foreach (glob($uploadDirFs . 'user_' . $userId . '.*') ?: [] as $old) @unlink($old);
    }

    $_SESSION['flash'] = $errors
        ? ['type' => 'error',   'msg' => implode(' ', $errors)]
        : ['type' => 'success', 'msg' => 'Your profile has been updated.'];

    header('Location: settings.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ------------------------------------------------------------------
   Values to display
------------------------------------------------------------------- */
$name = $phone = $email = $address = '';
if ($user) {
    $name = pick($user, $nameList);
    if ($name === '') {
        $name = trim(pick($user, ['first_name', 'firstname', 'fname']) . ' ' . pick($user, ['last_name', 'lastname', 'lname']));
    }
    $phone   = pick($user, $phoneList);
    $email   = pick($user, $emailList);
    $address = pick($user, $addressList);
}

$initial   = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '';
$avatarUrl = findAvatar($uploadDirFs, $uploadDirUrl, $userId);

$fields = [
    ['icon' => 'bx-user',     'label' => 'Name',          'value' => $name],
    ['icon' => 'bx-phone',    'label' => 'Phone Number',  'value' => $phone],
    ['icon' => 'bx-envelope', 'label' => 'Email Address', 'value' => $email],
    ['icon' => 'bx-map',      'label' => 'Address',       'value' => $address],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CreamypiesCream - Settings</title>
    <link rel="icon" type="image/png" href="favicon/Faviconn.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="settings.css?v=<?= @filemtime(__DIR__ . '/settings.css') ?>">
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

    <main class="set-main">

        <div class="set-topbar">
            <h1>Settings</h1>
            <div class="set-profile">
                <?php if ($avatarUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Profile picture">
                <?php else: ?>
                    <i class="bx bx-user"></i>
                <?php endif; ?>
            </div>
        </div>

        <div class="set-content">

            <?php if ($flash): ?>
                <p class="set-flash <?= $flash['type'] === 'success' ? 'ok' : 'err' ?>">
                    <?= htmlspecialchars($flash['msg']) ?>
                </p>
            <?php endif; ?>

            <?php if (!$user): ?>
                <p class="set-error">Couldn't find your account details in the database.</p>
            <?php endif; ?>

            <section class="account-card">
                <div class="account-head">
                    <div class="avatar">
                        <?php if ($avatarUrl !== ''): ?>
                            <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Profile picture">
                        <?php elseif ($initial !== ''): ?>
                            <?= htmlspecialchars($initial) ?>
                        <?php else: ?>
                            <i class="bx bx-user"></i>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h2><?= $name !== '' ? htmlspecialchars($name) : 'Account' ?></h2>
                        <p>Account information</p>
                    </div>
                </div>

                <div class="info-list">
                    <?php foreach ($fields as $f): ?>
                        <div class="info-row">
                            <span class="info-icon"><i class="bx <?= $f['icon'] ?>"></i></span>
                            <div class="info-text">
                                <span class="info-label"><?= $f['label'] ?></span>
                                <span class="info-value<?= $f['value'] === '' ? ' empty' : '' ?>">
                                    <?= $f['value'] !== '' ? htmlspecialchars($f['value']) : 'Not set' ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if ($user): ?>
                <button type="button" class="edit-btn" id="openEdit">
                    <i class="bx bx-edit"></i>
                    <span>Edit Profile</span>
                </button>
            <?php endif; ?>

        </div>
    </main>

    <!-- ====== EDIT PROFILE MODAL ====== -->
    <?php if ($user): ?>
    <div class="modal-backdrop" id="editModal" hidden>
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="editTitle">
            <div class="modal-head">
                <h2 id="editTitle">Edit Profile</h2>
                <button type="button" class="modal-close" id="closeEdit" aria-label="Close">&times;</button>
            </div>

            <form method="post" enctype="multipart/form-data" class="modal-body">
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">

                <div class="pic-row">
                    <div class="avatar avatar-lg" id="picPreview">
                        <?php if ($avatarUrl !== ''): ?>
                            <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Profile picture">
                        <?php elseif ($initial !== ''): ?>
                            <?= htmlspecialchars($initial) ?>
                        <?php else: ?>
                            <i class="bx bx-user"></i>
                        <?php endif; ?>
                    </div>
                    <div class="pic-actions">
                        <label class="pic-btn" for="avatarInput"><i class="bx bx-image-add"></i> Change picture</label>
                        <input type="file" id="avatarInput" name="avatar" accept="image/png,image/jpeg,image/webp" hidden>
                        <small>JPG, PNG or WEBP, up to 2 MB</small>
                        <?php if ($avatarUrl !== ''): ?>
                            <label class="remove-pic">
                                <input type="checkbox" name="remove_avatar" value="1"> Remove current picture
                            </label>
                        <?php endif; ?>
                    </div>
                </div>

                <label class="field">
                    <span>Name</span>
                    <input type="text" name="name" value="<?= htmlspecialchars($name) ?>" maxlength="100" required>
                </label>
                <label class="field">
                    <span>Phone Number</span>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($phone) ?>" maxlength="20">
                </label>
                <label class="field">
                    <span>Email Address</span>
                    <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" maxlength="100">
                </label>
                <label class="field">
                    <span>Address</span>
                    <input type="text" name="address" value="<?= htmlspecialchars($address) ?>" maxlength="255">
                </label>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" id="cancelEdit">Cancel</button>
                    <button type="submit" class="btn-save">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function () {
        var modal   = document.getElementById('editModal');
        var openBtn = document.getElementById('openEdit');
        var close1  = document.getElementById('closeEdit');
        var close2  = document.getElementById('cancelEdit');
        var input   = document.getElementById('avatarInput');
        var preview = document.getElementById('picPreview');

        function open()  { modal.hidden = false; document.body.style.overflow = 'hidden'; }
        function close() { modal.hidden = true;  document.body.style.overflow = ''; }

        openBtn.addEventListener('click', open);
        close1.addEventListener('click', close);
        close2.addEventListener('click', close);
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });

        // live preview of the chosen picture
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;
            if (file.size > 2 * 1024 * 1024) {
                alert('Profile picture must be 2 MB or smaller.');
                input.value = '';
                return;
            }
            var reader = new FileReader();
            reader.onload = function (e) {
                preview.innerHTML = '';
                var img = document.createElement('img');
                img.src = e.target.result;
                img.alt = 'Preview';
                preview.appendChild(img);
            };
            reader.readAsDataURL(file);
        });
    })();
    </script>
    <?php endif; ?>

</body>
</html>