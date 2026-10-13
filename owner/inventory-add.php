<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole('owner');

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Asia/Kolkata');

$pageTitle = 'Add Inventory | Mahalaxmi Medical Store';

$errors = [];
$success = '';

$form = [
    'medicine_name' => '',
    'batch_number' => '',
    'quantity' => '0',
    'minimum_stock' => '10',
    'expiry_date' => '',
    'supplier_name' => '',
    'purchase_price' => '0.00',
    'selling_price' => '0.00',
];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($_SESSION['csrf_token'], $postedToken)
    ) {
        $errors[] = 'Invalid security token. Refresh the page and try again.';
    }

    foreach ($form as $key => $default) {
        $value = $_POST[$key] ?? $default;

        $form[$key] = is_string($value)
            ? trim($value)
            : $default;
    }

    // Medicine name
    if ($form['medicine_name'] === '') {
        $errors[] = 'Medicine name is required.';
    } elseif (mb_strlen($form['medicine_name']) > 200) {
        $errors[] = 'Medicine name cannot exceed 200 characters.';
    }

    // Batch number
    if (mb_strlen($form['batch_number']) > 100) {
        $errors[] = 'Batch number cannot exceed 100 characters.';
    }

    // Supplier
    if (mb_strlen($form['supplier_name']) > 200) {
        $errors[] = 'Supplier name cannot exceed 200 characters.';
    }

    // Quantity
    $quantity = filter_var(
        $form['quantity'],
        FILTER_VALIDATE_INT
    );

    if ($quantity === false || $quantity < 0) {
        $errors[] = 'Quantity must be a non-negative whole number.';
    }

    // Minimum stock
    $minimumStock = filter_var(
        $form['minimum_stock'],
        FILTER_VALIDATE_INT
    );

    if ($minimumStock === false || $minimumStock < 0) {
        $errors[] = 'Minimum stock must be a non-negative whole number.';
    }

    // Prices
    foreach ([
        'purchase_price' => 'Purchase price',
        'selling_price' => 'Selling price',
    ] as $field => $label) {

        if (
            $form[$field] === '' ||
            !is_numeric($form[$field]) ||
            !is_finite((float) $form[$field]) ||
            (float) $form[$field] < 0 ||
            (float) $form[$field] > 99999999.99
        ) {
            $errors[] = $label . ' must be a valid non-negative amount.';
        }
    }

    // Expiry date is optional
    $expiryDate = null;

    if ($form['expiry_date'] !== '') {

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $form['expiry_date']
        );

        if (
            !$date ||
            $date->format('Y-m-d') !== $form['expiry_date']
        ) {
            $errors[] = 'Please enter a valid expiry date.';
        } else {
            $expiryDate = $date->format('Y-m-d');
        }
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO inventory (
                    medicine_name,
                    batch_number,
                    quantity,
                    minimum_stock,
                    expiry_date,
                    supplier_name,
                    purchase_price,
                    selling_price
                ) VALUES (
                    :medicine_name,
                    :batch_number,
                    :quantity,
                    :minimum_stock,
                    :expiry_date,
                    :supplier_name,
                    :purchase_price,
                    :selling_price
                )
            ");

            $stmt->execute([
                'medicine_name' => $form['medicine_name'],
                'batch_number' => $form['batch_number'] !== ''
                    ? $form['batch_number']
                    : null,
                'quantity' => $quantity,
                'minimum_stock' => $minimumStock,
                'expiry_date' => $expiryDate,
                'supplier_name' => $form['supplier_name'] !== ''
                    ? $form['supplier_name']
                    : null,
                'purchase_price' => $form['purchase_price'],
                'selling_price' => $form['selling_price'],
            ]);

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header('Location: inventory-add.php?success=1');
            exit;

        } catch (PDOException $e) {

            error_log('Inventory insertion failed: ' . $e->getMessage());

            $errors[] = 'Unable to save the inventory item. Please check the database configuration and try again.';
        }
    }
}

if (isset($_GET['success']) && $_GET['success'] === '1') {
    $success = 'Medicine added to inventory successfully.';
}

function inventoryEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= inventoryEscape($pageTitle) ?></title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #fff9f1;
            color: #292321;
            font-family: Arial, Helvetica, sans-serif;
        }

        .topbar {
            background: #ad2024;
            color: #fff;
            padding: 17px 6%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .brand {
            font-size: 19px;
            font-weight: 700;
        }

        .topbar a {
            color: #fff;
            text-decoration: none;
            font-size: 14px;
        }

        .container {
            width: min(900px, 94%);
            margin: 32px auto;
        }

        .heading {
            margin-bottom: 22px;
        }

        .heading h1 {
            margin: 0 0 8px;
            font-size: 28px;
        }

        .heading p {
            color: #766761;
            margin: 0;
            line-height: 1.6;
        }

        .card {
            background: #fff;
            border: 1px solid #eadbcf;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(65, 36, 20, 0.04);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .field {
            margin-bottom: 3px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .required {
            color: #ad2024;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #dfd0c4;
            border-radius: 7px;
            background: #fff;
            font-size: 14px;
            color: #292321;
        }

        input:focus {
            outline: 2px solid rgba(173, 32, 36, 0.15);
            border-color: #ad2024;
        }

        .hint {
            display: block;
            color: #82746d;
            font-size: 12px;
            margin-top: 6px;
        }

        .notice {
            padding: 13px 15px;
            margin-bottom: 20px;
            border-radius: 7px;
            line-height: 1.6;
            font-size: 14px;
        }

        .notice.success {
            color: #176534;
            background: #e9f8ee;
            border: 1px solid #b8e6c6;
        }

        .notice.error {
            color: #8b191d;
            background: #fff0f0;
            border: 1px solid #f0c4c4;
        }

        .notice ul {
            margin: 8px 0 0;
            padding-left: 20px;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 26px;
        }

        .btn {
            display: inline-block;
            border: 0;
            border-radius: 7px;
            padding: 12px 18px;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-primary {
            color: #fff;
            background: #ad2024;
        }

        .btn-primary:hover {
            background: #8d171b;
        }

        .btn-secondary {
            color: #463832;
            background: #f3e9df;
        }

        .footer {
            text-align: center;
            padding: 25px 10px;
            font-size: 12px;
            color: #8b7a70;
        }

        @media (max-width: 600px) {
            .container {
                margin: 22px auto;
            }

            .card {
                padding: 18px;
            }

            .grid {
                grid-template-columns: 1fr;
                gap: 17px;
            }

            .heading h1 {
                font-size: 23px;
            }

            .field.full {
                grid-column: auto;
            }

            .actions .btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <div class="brand">▣ Mahalaxmi Medical Store</div>

    <a href="dashboard.php">← Back to Owner Dashboard</a>
</header>

<main class="container">

    <div class="heading">
        <h1>Add Inventory</h1>
        <p>
            Register a medicine batch, enter its quantity and prices,
            and record its expiry date for inventory monitoring.
        </p>
    </div>

    <?php if ($success !== ''): ?>
        <div class="notice success" role="status">
            <?= inventoryEscape($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="notice error" role="alert">
            <strong>Please correct the following:</strong>

            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= inventoryEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="card">

        <form method="POST" action="inventory-add.php" autocomplete="off">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= inventoryEscape($_SESSION['csrf_token']) ?>"
            >

            <div class="grid">

                <div class="field full">
                    <label for="medicine_name">
                        Medicine Name <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="medicine_name"
                        name="medicine_name"
                        maxlength="200"
                        value="<?= inventoryEscape($form['medicine_name']) ?>"
                        placeholder="e.g. Paracetamol 500 mg"
                        required
                    >
                </div>

                <div class="field">
                    <label for="batch_number">Batch Number</label>

                    <input
                        type="text"
                        id="batch_number"
                        name="batch_number"
                        maxlength="100"
                        value="<?= inventoryEscape($form['batch_number']) ?>"
                        placeholder="e.g. BATCH123"
                    >
                </div>

                <div class="field">
                    <label for="supplier_name">Supplier Name</label>

                    <input
                        type="text"
                        id="supplier_name"
                        name="supplier_name"
                        maxlength="200"
                        value="<?= inventoryEscape($form['supplier_name']) ?>"
                        placeholder="Enter supplier name"
                    >
                </div>

                <div class="field">
                    <label for="quantity">
                        Available Quantity <span class="required">*</span>
                    </label>

                    <input
                        type="number"
                        id="quantity"
                        name="quantity"
                        min="0"
                        step="1"
                        value="<?= inventoryEscape($form['quantity']) ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="minimum_stock">
                        Minimum Stock Level <span class="required">*</span>
                    </label>

                    <input
                        type="number"
                        id="minimum_stock"
                        name="minimum_stock"
                        min="0"
                        step="1"
                        value="<?= inventoryEscape($form['minimum_stock']) ?>"
                        required
                    >

                    <span class="hint">
                        An alert will appear when quantity reaches or falls
                        below this level.
                    </span>
                </div>

                <div class="field">
                    <label for="expiry_date">Expiry Date</label>

                    <input
                        type="date"
                        id="expiry_date"
                        name="expiry_date"
                        value="<?= inventoryEscape($form['expiry_date']) ?>"
                    >

                    <span class="hint">
                        Leave blank if the expiry date is unavailable.
                    </span>
                </div>

                <div class="field">
                    <label for="purchase_price">
                        Purchase Price (₹) <span class="required">*</span>
                    </label>

                    <input
                        type="number"
                        id="purchase_price"
                        name="purchase_price"
                        min="0"
                        max="99999999.99"
                        step="0.01"
                        value="<?= inventoryEscape($form['purchase_price']) ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="selling_price">
                        Selling Price (₹) <span class="required">*</span>
                    </label>

                    <input
                        type="number"
                        id="selling_price"
                        name="selling_price"
                        min="0"
                        max="99999999.99"
                        step="0.01"
                        value="<?= inventoryEscape($form['selling_price']) ?>"
                        required
                    >
                </div>

            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary">
                    Save Inventory
                </button>

                <a href="dashboard.php" class="btn btn-secondary">
                    Cancel
                </a>
            </div>

        </form>

    </section>

</main>

<footer class="footer">
    © <?= date('Y') ?> Mahalaxmi Medical Store Management System.
    All rights reserved.
</footer>

</body>
</html>