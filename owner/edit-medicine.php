<?php

require_once __DIR__ . '/../includes/auth.php';
requireRole('owner');

require_once __DIR__ . '/../config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');

// Generate CSRF token if needed.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    exit('Invalid medicine ID.');
}

// Fetch medicine.
$stmt = $pdo->prepare(
    "SELECT * FROM inventory WHERE id = :id"
);
$stmt->execute([':id' => $id]);

$medicine = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$medicine) {
    http_response_code(404);
    exit('Medicine not found.');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $errors[] = 'Invalid security token. Refresh the page and try again.';
    }

    $medicineName = trim($_POST['medicine_name'] ?? '');
    $batchNumber = trim($_POST['batch_number'] ?? '');
    $quantityInput = $_POST['quantity'] ?? '';
    $minimumStockInput = $_POST['minimum_stock'] ?? '';
    $expiryDate = $_POST['expiry_date'] ?? '';
    $supplierName = trim($_POST['supplier_name'] ?? '');
    $purchasePriceInput = $_POST['purchase_price'] ?? '';
    $sellingPriceInput = $_POST['selling_price'] ?? '';

    if ($medicineName === '' || mb_strlen($medicineName) > 200) {
        $errors[] = 'Enter a medicine name of up to 200 characters.';
    }

    if (mb_strlen($batchNumber) > 100) {
        $errors[] = 'Batch number cannot exceed 100 characters.';
    }

    if (!filter_var(
        $quantityInput,
        FILTER_VALIDATE_INT
    ) || (int) $quantityInput < 0) {
        $errors[] = 'Quantity must be a non-negative whole number.';
    }

    if (!filter_var(
        $minimumStockInput,
        FILTER_VALIDATE_INT
    ) || (int) $minimumStockInput < 0) {
        $errors[] = 'Minimum stock must be a non-negative whole number.';
    }

    if ($supplierName !== '' && mb_strlen($supplierName) > 200) {
        $errors[] = 'Supplier name cannot exceed 200 characters.';
    }

    if (
        $expiryDate !== '' &&
        (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate) ||
            !checkdate(
                (int) substr($expiryDate, 5, 2),
                (int) substr($expiryDate, 8, 2),
                (int) substr($expiryDate, 0, 4)
            )
        )
    ) {
        $errors[] = 'Enter a valid expiry date.';
    }

    foreach ([
        'Purchase price' => $purchasePriceInput,
        'Selling price' => $sellingPriceInput
    ] as $label => $value) {

        if (
            !is_numeric($value) ||
            (float) $value < 0 ||
            (float) $value > 99999999.99 ||
            !preg_match('/^\d{1,8}(\.\d{1,2})?$/', (string) $value)
        ) {
            $errors[] = $label . ' must be a valid non-negative amount with up to two decimal places.';
        }
    }

    if (empty($errors)) {

        $update = $pdo->prepare(
            "UPDATE inventory
             SET medicine_name = :medicine_name,
                 batch_number = :batch_number,
                 quantity = :quantity,
                 minimum_stock = :minimum_stock,
                 expiry_date = :expiry_date,
                 supplier_name = :supplier_name,
                 purchase_price = :purchase_price,
                 selling_price = :selling_price
             WHERE id = :id"
        );

        $update->execute([
            ':medicine_name' => $medicineName,
            ':batch_number' => $batchNumber !== '' ? $batchNumber : null,
            ':quantity' => (int) $quantityInput,
            ':minimum_stock' => (int) $minimumStockInput,
            ':expiry_date' => $expiryDate !== '' ? $expiryDate : null,
            ':supplier_name' => $supplierName !== '' ? $supplierName : null,
            ':purchase_price' => $purchasePriceInput,
            ':selling_price' => $sellingPriceInput,
            ':id' => $id
        ]);

        header('Location: inventory.php?updated=1');
        exit;
    }

    // Retain entered values if validation fails.
    $medicine = array_merge($medicine, [
        'medicine_name' => $medicineName,
        'batch_number' => $batchNumber,
        'quantity' => $quantityInput,
        'minimum_stock' => $minimumStockInput,
        'expiry_date' => $expiryDate,
        'supplier_name' => $supplierName,
        'purchase_price' => $purchasePriceInput,
        'selling_price' => $sellingPriceInput
    ]);
}

function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Medicine | Mahalaxmi Medical Store</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        body {
            background: #fff8f0;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
        }

        .edit-container {
            max-width: 800px;
            margin: 25px auto;
            padding: 28px;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 5px 20px rgba(0,0,0,.08);
        }

        .edit-container h1 {
            color: #a6192e;
            margin-top: 0;
        }

        .edit-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        .form-group input {
            box-sizing: border-box;
            width: 100%;
            padding: 11px;
            border: 1px solid #ddd;
            border-radius: 7px;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 25px;
        }

        .form-actions button,
        .form-actions a {
            padding: 11px 17px;
            border: none;
            border-radius: 7px;
            text-decoration: none;
            cursor: pointer;
            font-weight: 600;
        }

        .save-button {
            background: #a6192e;
            color: #fff;
        }

        .cancel-button {
            background: #eee;
            color: #333;
        }

        .error-box {
            padding: 12px 16px;
            margin-bottom: 20px;
            background: #f8d7da;
            color: #842029;
            border-radius: 7px;
        }

        @media (max-width: 600px) {
            .edit-grid {
                grid-template-columns: 1fr;
            }

            .edit-container {
                padding: 18px;
            }
        }
    </style>
</head>

<body>

<div class="edit-container">

    <h1>Edit Medicine</h1>
    <p>Update the medicine details below.</p>

    <?php if ($errors): ?>
        <div class="error-box">
            <strong>Please correct the following:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($_SESSION['csrf_token']) ?>"
        >

        <div class="edit-grid">

            <div class="form-group">
                <label>Medicine Name *</label>
                <input
                    type="text"
                    name="medicine_name"
                    maxlength="200"
                    required
                    value="<?= e($medicine['medicine_name']) ?>"
                >
            </div>

            <div class="form-group">
                <label>Batch Number</label>
                <input
                    type="text"
                    name="batch_number"
                    maxlength="100"
                    value="<?= e($medicine['batch_number']) ?>"
                >
            </div>

            <div class="form-group">
                <label>Quantity *</label>
                <input
                    type="number"
                    name="quantity"
                    min="0"
                    required
                    value="<?= e($medicine['quantity']) ?>"
                >
            </div>

            <div class="form-group">
                <label>Minimum Stock *</label>
                <input
                    type="number"
                    name="minimum_stock"
                    min="0"
                    required
                    value="<?= e($medicine['minimum_stock']) ?>"
                >
            </div>

            <div class="form-group">
                <label>Expiry Date</label>
                <input
                    type="date"
                    name="expiry_date"
                    value="<?= e($medicine['expiry_date']) ?>"
                >
            </div>

            <div class="form-group">
                <label>Supplier Name</label>
                <input
                    type="text"
                    name="supplier_name"
                    maxlength="200"
                    value="<?= e($medicine['supplier_name']) ?>"
                >
            </div>

            <div class="form-group">
                <label>Purchase Price (₹) *</label>
                <input
                    type="number"
                    name="purchase_price"
                    min="0"
                    max="99999999.99"
                    step="0.01"
                    required
                    value="<?= e($medicine['purchase_price']) ?>"
                >
            </div>

            <div class="form-group">
                <label>Selling Price (₹) *</label>
                <input
                    type="number"
                    name="selling_price"
                    min="0"
                    max="99999999.99"
                    step="0.01"
                    required
                    value="<?= e($medicine['selling_price']) ?>"
                >
            </div>

        </div>

        <div class="form-actions">
            <button type="submit" class="save-button">
                Save Changes
            </button>

            <a href="inventory.php" class="cancel-button">
                Cancel
            </a>
        </div>

    </form>

</div>

</body>
</html>