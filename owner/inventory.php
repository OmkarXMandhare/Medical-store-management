<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole('owner');

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Asia/Kolkata');

$pageTitle = 'Inventory Management';

$search = trim((string) ($_GET['search'] ?? ''));
$stockFilter = (string) ($_GET['stock'] ?? 'all');

$allowedFilters = ['all', 'low', 'out', 'expired', 'expiring'];

if (!in_array($stockFilter, $allowedFilters, true)) {
    $stockFilter = 'all';
}

$today = date('Y-m-d');
$next30Days = date('Y-m-d', strtotime('+30 days'));

$inventory = [];
$stats = [
    'total' => 0,
    'low' => 0,
    'out' => 0,
    'expired' => 0,
    'expiring' => 0,
];

$error = '';

function inventoryPageEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

try {
    /*
     * Summary cards.
     */
    $summaryQuery = $pdo->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(
                CASE
                    WHEN quantity > 0
                     AND quantity <= minimum_stock
                    THEN 1 ELSE 0
                END
            ), 0) AS low,
            COALESCE(SUM(
                CASE
                    WHEN quantity = 0
                    THEN 1 ELSE 0
                END
            ), 0) AS out_of_stock,
            COALESCE(SUM(
                CASE
                    WHEN expiry_date < CURDATE()
                    THEN 1 ELSE 0
                END
            ), 0) AS expired,
            COALESCE(SUM(
                CASE
                    WHEN expiry_date >= CURDATE()
                     AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                    THEN 1 ELSE 0
                END
            ), 0) AS expiring
        FROM inventory
    ");

    $summary = $summaryQuery->fetch(PDO::FETCH_ASSOC);

    if ($summary) {
        $stats['total'] = (int) $summary['total'];
        $stats['low'] = (int) $summary['low'];
        $stats['out'] = (int) $summary['out_of_stock'];
        $stats['expired'] = (int) $summary['expired'];
        $stats['expiring'] = (int) $summary['expiring'];
    }

    /*
     * Inventory list and filters.
     */
    $sql = "
        SELECT
            id,
            medicine_name,
            batch_number,
            quantity,
            minimum_stock,
            expiry_date,
            supplier_name,
            purchase_price,
            selling_price,
            created_at
        FROM inventory
        WHERE 1 = 1
    ";

    $params = [];

    if ($search !== '') {
        $sql .= "
            AND (
                medicine_name LIKE :medicine_name
                OR batch_number LIKE :batch_number
                OR supplier_name LIKE :supplier_name
            )
        ";

        $term = '%' . $search . '%';

        $params['medicine_name'] = $term;
        $params['batch_number'] = $term;
        $params['supplier_name'] = $term;
    }

    switch ($stockFilter) {
        case 'low':
            $sql .= "
                AND quantity > 0
                AND quantity <= minimum_stock
            ";
            break;

        case 'out':
            $sql .= " AND quantity = 0 ";
            break;

        case 'expired':
            $sql .= "
                AND expiry_date IS NOT NULL
                AND expiry_date < CURDATE()
            ";
            break;

        case 'expiring':
            $sql .= "
                AND expiry_date >= CURDATE()
                AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ";
            break;
    }

    $sql .= " ORDER BY medicine_name ASC, expiry_date ASC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Inventory page error: ' . $e->getMessage());
    $error = 'Unable to load inventory. Please check the inventory table and database connection.';
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= inventoryPageEscape($pageTitle) ?> | Mahalaxmi Medical Store</title>

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
            color: white;
            padding: 16px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .brand {
            font-weight: 700;
            font-size: 18px;
        }

        .topbar a {
            color: white;
            text-decoration: none;
            font-size: 14px;
        }

        .container {
            width: min(1250px, 95%);
            margin: 28px auto;
        }

        .heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        h1 {
            font-size: 27px;
            margin: 0 0 8px;
        }

        .subtitle {
            color: #786961;
            font-size: 14px;
            line-height: 1.6;
        }

        .btn {
            display: inline-block;
            padding: 11px 15px;
            border: 0;
            border-radius: 7px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-primary {
            background: #ad2024;
            color: white;
        }

        .btn-secondary {
            background: #f1e5d9;
            color: #44352e;
        }

        .btn-edit {
            background: #fff0d6;
            color: #805400;
        }

        .btn-delete {
            background: #ffebeb;
            color: #a71920;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 13px;
            margin-bottom: 22px;
        }

        .stat {
            background: white;
            border: 1px solid #eadbcf;
            border-radius: 10px;
            padding: 17px;
            box-shadow: 0 3px 12px rgba(65, 36, 20, .03);
        }

        .stat-label {
            font-size: 12px;
            color: #766761;
            margin-bottom: 9px;
        }

        .stat-number {
            font-size: 27px;
            font-weight: 700;
        }

        .stat-danger .stat-number {
            color: #b51f25;
        }

        .stat-warning .stat-number {
            color: #b77700;
        }

        .card {
            background: white;
            border: 1px solid #eadbcf;
            border-radius: 10px;
            margin-bottom: 22px;
            overflow: hidden;
            box-shadow: 0 3px 12px rgba(65, 36, 20, .03);
        }

        .card-header {
            padding: 17px 20px;
            border-bottom: 1px solid #eee0d4;
            font-weight: 700;
        }

        .filters {
            padding: 18px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filters input,
        .filters select {
            padding: 11px;
            border: 1px solid #dfd0c4;
            border-radius: 6px;
            font-size: 13px;
            background: white;
        }

        .filters input {
            flex: 1;
            min-width: 200px;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        th {
            background: #fbf2e8;
            text-align: left;
            padding: 13px 14px;
            font-size: 12px;
            white-space: nowrap;
        }

        td {
            padding: 14px;
            border-top: 1px solid #f0e6de;
            font-size: 12px;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: #fffdf9;
        }

        .medicine-name {
            font-weight: 700;
            font-size: 13px;
        }

        .muted {
            color: #86776f;
            font-size: 11px;
            margin-top: 5px;
        }

        .badge {
            display: inline-block;
            border-radius: 20px;
            padding: 5px 9px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .badge-good {
            background: #e6f7ec;
            color: #176534;
        }

        .badge-low,
        .badge-expired {
            background: #ffebeb;
            color: #a71920;
        }

        .badge-warning {
            background: #fff0d6;
            color: #805400;
        }

        .badge-neutral {
            background: #eee9e4;
            color: #5e514a;
        }

        .actions {
            display: flex;
            gap: 7px;
        }

        .empty {
            text-align: center;
            padding: 45px 20px;
            color: #82746d;
            font-size: 13px;
        }

        .notice {
            padding: 14px;
            border-radius: 7px;
            margin-bottom: 18px;
            background: #fff0f0;
            color: #8b191d;
            border: 1px solid #f0c4c4;
        }

        .footer {
            padding: 24px;
            text-align: center;
            color: #8b7a70;
            font-size: 12px;
        }

        @media (max-width: 900px) {
            .stats {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 550px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            h1 {
                font-size: 23px;
            }

            .heading .btn {
                width: 100%;
                text-align: center;
            }

            .filters input,
            .filters select,
            .filters button {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <div class="brand">▣ Mahalaxmi Medical Store</div>

    <a href="dashboard.php">← Owner Dashboard</a>
</header>

<main class="container">

    <div class="heading">
        <div>
            <h1>Inventory Management</h1>

            <div class="subtitle">
                Manage medicine batches, stock levels, prices and expiry dates.
            </div>
        </div>

        <a href="inventory-add.php" class="btn btn-primary">
            + Add Medicine
        </a>
    </div>

    <?php if ($error !== ''): ?>
        <div class="notice">
            <?= inventoryPageEscape($error) ?>
        </div>
    <?php endif; ?>

    <section class="stats">

        <div class="stat">
            <div class="stat-label">Total Batches</div>
            <div class="stat-number">
                <?= $stats['total'] ?>
            </div>
        </div>

        <div class="stat stat-danger">
            <div class="stat-label">Low Stock</div>
            <div class="stat-number">
                <?= $stats['low'] ?>
            </div>
        </div>

        <div class="stat stat-danger">
            <div class="stat-label">Out of Stock</div>
            <div class="stat-number">
                <?= $stats['out'] ?>
            </div>
        </div>

        <div class="stat stat-danger">
            <div class="stat-label">Expired Batches</div>
            <div class="stat-number">
                <?= $stats['expired'] ?>
            </div>
        </div>

        <div class="stat stat-warning">
            <div class="stat-label">Expiring Within 30 Days</div>
            <div class="stat-number">
                <?= $stats['expiring'] ?>
            </div>
        </div>

    </section>

    <section class="card">

        <div class="card-header">
            🔍 Search and Filter Inventory
        </div>

        <form method="GET" class="filters">

            <input
                type="search"
                name="search"
                placeholder="Medicine, batch number or supplier..."
                value="<?= inventoryPageEscape($search) ?>"
            >

            <select name="stock">
                <option value="all"
                    <?= $stockFilter === 'all' ? 'selected' : '' ?>>
                    All Inventory
                </option>

                <option value="low"
                    <?= $stockFilter === 'low' ? 'selected' : '' ?>>
                    Low Stock
                </option>

                <option value="out"
                    <?= $stockFilter === 'out' ? 'selected' : '' ?>>
                    Out of Stock
                </option>

                <option value="expired"
                    <?= $stockFilter === 'expired' ? 'selected' : '' ?>>
                    Expired
                </option>

                <option value="expiring"
                    <?= $stockFilter === 'expiring' ? 'selected' : '' ?>>
                    Expiring Within 30 Days
                </option>
            </select>

            <button type="submit" class="btn btn-primary">
                Search
            </button>

            <a href="inventory.php" class="btn btn-secondary">
                Reset
            </a>

        </form>

        <div class="table-wrapper">

            <table>
                <thead>
                    <tr>
                        <th>Medicine</th>
                        <th>Batch</th>
                        <th>Quantity</th>
                        <th>Stock Status</th>
                        <th>Expiry Date</th>
                        <th>Supplier</th>
                        <th>Purchase Price</th>
                        <th>Selling Price</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$inventory): ?>

                    <tr>
                        <td colspan="9" class="empty">
                            No inventory records found.
                            Add a medicine or change your search filters.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($inventory as $item): ?>

                        <?php
                        $quantity = (int) $item['quantity'];
                        $minimumStock = (int) $item['minimum_stock'];

                        $expiry = $item['expiry_date'] ?? null;

                        $isExpired = $expiry !== null
                            && $expiry !== ''
                            && $expiry < $today;

                        $isExpiring = $expiry !== null
                            && $expiry !== ''
                            && $expiry >= $today
                            && $expiry <= $next30Days;

                        if ($isExpired) {
                            $expiryLabel = 'Expired';
                            $expiryClass = 'badge-expired';
                        } elseif ($isExpiring) {
                            $expiryLabel = 'Expiring Soon';
                            $expiryClass = 'badge-warning';
                        } elseif ($expiry === null || $expiry === '') {
                            $expiryLabel = 'Not Entered';
                            $expiryClass = 'badge-neutral';
                        } else {
                            $expiryLabel = 'Valid';
                            $expiryClass = 'badge-good';
                        }

                        if ($quantity === 0) {
                            $stockLabel = 'Out of Stock';
                            $stockClass = 'badge-expired';
                        } elseif ($quantity <= $minimumStock) {
                            $stockLabel = 'Low Stock';
                            $stockClass = 'badge-low';
                        } else {
                            $stockLabel = 'In Stock';
                            $stockClass = 'badge-good';
                        }
                        ?>

                        <tr>
                            <td>
                                <div class="medicine-name">
                                    <?= inventoryPageEscape($item['medicine_name']) ?>
                                </div>

                                <div class="muted">
                                    ID: <?= (int) $item['id'] ?>
                                </div>
                            </td>

                            <td>
                                <?= inventoryPageEscape(
                                    $item['batch_number'] ?: '—'
                                ) ?>
                            </td>

                            <td>
                                <strong><?= $quantity ?></strong>
                            </td>

                            <td>
                                <span class="badge <?= $stockClass ?>">
                                    <?= $stockLabel ?>
                                </span>
                            </td>

                            <td>
                                <?php if ($expiry): ?>
                                    <div>
                                        <?= inventoryPageEscape(
                                            date('d M Y', strtotime($expiry))
                                        ) ?>
                                    </div>

                                    <div style="margin-top:6px">
                                        <span class="badge <?= $expiryClass ?>">
                                            <?= $expiryLabel ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span class="badge badge-neutral">
                                        Not Entered
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= inventoryPageEscape(
                                    $item['supplier_name'] ?: '—'
                                ) ?>
                            </td>

                            <td>
                                ₹<?= number_format(
                                    (float) $item['purchase_price'],
                                    2
                                ) ?>
                            </td>

                            <td>
                                ₹<?= number_format(
                                    (float) $item['selling_price'],
                                    2
                                ) ?>
                            </td>

                            <td>
                                <div class="actions">

                                    <a
                                        class="btn btn-edit"
                                        href="inventory-edit.php?id=<?= (int) $item['id'] ?>"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        action="inventory-delete.php"
                                        onsubmit="return confirm('Are you sure you want to delete this inventory record?');"
                                    >
                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int) $item['id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= inventoryPageEscape(
                                                $_SESSION['csrf_token'] ?? ''
                                            ) ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-delete"
                                        >
                                            Delete
                                        </button>
                                    </form>

                                </div>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>

        </div>

    </section>

</main>

<footer class="footer">
    © <?= date('Y') ?> Mahalaxmi Medical Store Management System.
</footer>

</body>
</html>