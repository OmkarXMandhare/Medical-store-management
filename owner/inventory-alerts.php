<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole('owner');

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Asia/Kolkata');

$today = date('Y-m-d');
$in30Days = date('Y-m-d', strtotime('+30 days'));
$in90Days = date('Y-m-d', strtotime('+90 days'));

$search = trim((string) ($_GET['search'] ?? ''));
$filter = (string) ($_GET['filter'] ?? 'all');

$allowedFilters = [
    'all',
    'low_stock',
    'expired',
    'expiring_30',
    'expiring_90'
];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$stats = [
    'total' => 0,
    'low_stock' => 0,
    'expired' => 0,
    'expiring_30' => 0,
    'expiring_90' => 0
];

$items = [];
$error = '';

try {
    // Inventory summary
    $stats['total'] = (int) $pdo->query("
        SELECT COUNT(*) FROM inventory
    ")->fetchColumn();

    $stats['low_stock'] = (int) $pdo->query("
        SELECT COUNT(*)
        FROM inventory
        WHERE quantity <= minimum_stock
    ")->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM inventory
        WHERE expiry_date IS NOT NULL
          AND expiry_date < :today
    ");
    $stmt->execute(['today' => $today]);
    $stats['expired'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM inventory
        WHERE expiry_date BETWEEN :today AND :end_date
    ");
    $stmt->execute([
        'today' => $today,
        'end_date' => $in30Days
    ]);
    $stats['expiring_30'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM inventory
        WHERE expiry_date > :end_date
          AND expiry_date <= :end_date_90
    ");
    $stmt->execute([
        'end_date' => $in30Days,
        'end_date_90' => $in90Days
    ]);
    $stats['expiring_90'] = (int) $stmt->fetchColumn();

    // Search and filter
    $sql = "
        SELECT *
        FROM inventory
        WHERE 1 = 1
    ";

    $params = [];

    if ($search !== '') {
        $sql .= "
            AND (
                medicine_name LIKE :name
                OR batch_number LIKE :batch
                OR supplier_name LIKE :supplier
            )
        ";

        $searchTerm = '%' . $search . '%';

        $params['name'] = $searchTerm;
        $params['batch'] = $searchTerm;
        $params['supplier'] = $searchTerm;
    }

    if ($filter === 'low_stock') {
        $sql .= " AND quantity <= minimum_stock";
    } elseif ($filter === 'expired') {
        $sql .= "
            AND expiry_date IS NOT NULL
            AND expiry_date < :filter_today
        ";
        $params['filter_today'] = $today;
    } elseif ($filter === 'expiring_30') {
        $sql .= "
            AND expiry_date BETWEEN :filter_today AND :filter_30
        ";
        $params['filter_today'] = $today;
        $params['filter_30'] = $in30Days;
    } elseif ($filter === 'expiring_90') {
        $sql .= "
            AND expiry_date > :filter_30
            AND expiry_date <= :filter_90
        ";
        $params['filter_30'] = $in30Days;
        $params['filter_90'] = $in90Days;
    }

    $sql .= "
        ORDER BY
            CASE
                WHEN expiry_date IS NOT NULL
                     AND expiry_date < :sort_today THEN 0
                WHEN quantity <= minimum_stock THEN 1
                ELSE 2
            END,
            expiry_date ASC,
            medicine_name ASC
        LIMIT 500
    ";

    $params['sort_today'] = $today;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('Inventory alerts error: ' . $e->getMessage());
    $error = 'Unable to load inventory. Please check the database connection.';
}

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function formatDate(?string $date): string
{
    if (!$date) {
        return 'Not provided';
    }

    return date('d M Y', strtotime($date));
}

function stockLabel(int $quantity, int $minimum): array
{
    if ($quantity <= 0) {
        return ['Out of stock', 'danger'];
    }

    if ($quantity <= $minimum) {
        return ['Low stock', 'warning'];
    }

    return ['In stock', 'success'];
}

function expiryLabel(?string $expiryDate): array
{
    if (!$expiryDate) {
        return ['No expiry date', 'muted'];
    }

    $today = date('Y-m-d');
    $in30Days = date('Y-m-d', strtotime('+30 days'));
    $in90Days = date('Y-m-d', strtotime('+90 days'));

    if ($expiryDate < $today) {
        return ['Expired', 'danger'];
    }

    if ($expiryDate <= $in30Days) {
        return ['Expires within 30 days', 'warning'];
    }

    if ($expiryDate <= $in90Days) {
        return ['Expires within 90 days', 'notice'];
    }

    return ['Valid', 'success'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Inventory Alerts | Mahalaxmi Medical Store</title>

    <style>
        :root {
            --red: #b51f24;
            --red-dark: #92191d;
            --cream: #fff9f1;
            --white: #ffffff;
            --border: #eadccd;
            --text: #292323;
            --muted: #82736a;
            --green: #16804a;
            --orange: #b96a00;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--cream);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }

        .topbar {
            background: var(--red);
            color: white;
            padding: 16px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .brand {
            font-size: 18px;
            font-weight: bold;
        }

        .topbar a {
            color: white;
            text-decoration: none;
            font-size: 13px;
            margin-left: 15px;
        }

        .container {
            width: min(1200px, 94%);
            margin: 30px auto;
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
            margin: 0 0 8px;
            font-size: 27px;
        }

        h2 {
            font-size: 18px;
        }

        .muted {
            color: var(--muted);
        }

        .button {
            display: inline-block;
            background: var(--red);
            color: white;
            padding: 11px 15px;
            border: none;
            border-radius: 7px;
            text-decoration: none;
            cursor: pointer;
            font-size: 13px;
        }

        .button:hover {
            background: var(--red-dark);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 11px;
            padding: 18px;
            box-shadow: 0 3px 10px #39201408;
        }

        .stat-label {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 12px;
        }

        .stat-number {
            font-size: 29px;
            font-weight: bold;
        }

        .red {
            color: var(--red);
        }

        .orange {
            color: var(--orange);
        }

        .green {
            color: var(--green);
        }

        .filter-form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        input,
        select {
            padding: 11px;
            border: 1px solid var(--border);
            border-radius: 7px;
            background: white;
            font-size: 13px;
        }

        input[name="search"] {
            flex: 1;
            min-width: 200px;
        }

        .table-card {
            padding: 0;
            overflow: hidden;
        }

        .table-heading {
            padding: 18px;
            border-bottom: 1px solid var(--border);
        }

        .table-heading h2 {
            margin: 0;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            min-width: 950px;
        }

        th,
        td {
            padding: 13px 15px;
            border-bottom: 1px solid #f0e7df;
            text-align: left;
            font-size: 13px;
        }

        th {
            background: #fbf1e6;
            color: #6e5547;
            font-size: 11px;
            text-transform: uppercase;
        }

        tbody tr:hover {
            background: #fffaf5;
        }

        .badge {
            display: inline-block;
            border-radius: 20px;
            padding: 6px 9px;
            font-size: 11px;
            font-weight: bold;
            white-space: nowrap;
        }

        .badge-danger {
            color: #a01820;
            background: #fde8e8;
        }

        .badge-warning {
            color: #935500;
            background: #fff0d3;
        }

        .badge-notice {
            color: #7858a6;
            background: #f0e8ff;
        }

        .badge-success {
            color: #176b3c;
            background: #e3f7e9;
        }

        .badge-muted {
            color: #746b65;
            background: #eeeae7;
        }

        .alert {
            padding: 14px;
            margin-bottom: 18px;
            border-radius: 8px;
            font-size: 13px;
        }

        .alert-error {
            background: #fde8e8;
            color: #92191d;
        }

        .empty {
            padding: 40px 20px;
            text-align: center;
            color: var(--muted);
        }

        footer {
            text-align: center;
            color: var(--muted);
            font-size: 12px;
            padding: 30px 10px;
        }

        @media (max-width: 900px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 480px) {
            .stats {
                grid-template-columns: 1fr 1fr;
                gap: 9px;
            }

            .card {
                padding: 13px;
            }

            h1 {
                font-size: 23px;
            }

            .filter-form > * {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <div class="brand">▣ Mahalaxmi Medical Store</div>

    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="tasks.php">Tasks</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<main class="container">

    <div class="heading">
        <div>
            <h1>Inventory Alerts</h1>
            <div class="muted">
                Monitor medicine stock levels and expiry dates.
            </div>
        </div>

        <a class="button" href="inventory-add.php">
            + Add Inventory
        </a>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <section class="stats">

        <div class="card">
            <div class="stat-label">Total Inventory Records</div>
            <div class="stat-number">
                <?= number_format($stats['total']) ?>
            </div>
        </div>

        <div class="card">
            <div class="stat-label">Low Stock</div>
            <div class="stat-number red">
                <?= number_format($stats['low_stock']) ?>
            </div>
        </div>

        <div class="card">
            <div class="stat-label">Expired</div>
            <div class="stat-number red">
                <?= number_format($stats['expired']) ?>
            </div>
        </div>

        <div class="card">
            <div class="stat-label">Expiring Within 30 Days</div>
            <div class="stat-number orange">
                <?= number_format($stats['expiring_30']) ?>
            </div>
        </div>

        <div class="card">
            <div class="stat-label">Expiring Within 90 Days</div>
            <div class="stat-number">
                <?= number_format($stats['expiring_90']) ?>
            </div>
        </div>

    </section>

    <section class="card">

        <h2>Search and Filter Inventory</h2>

        <form method="GET" class="filter-form">

            <input
                type="search"
                name="search"
                placeholder="Medicine, batch or supplier..."
                value="<?= e($search) ?>"
            >

            <select name="filter">
                <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>
                    All Inventory
                </option>

                <option value="low_stock" <?= $filter === 'low_stock' ? 'selected' : '' ?>>
                    Low Stock
                </option>

                <option value="expired" <?= $filter === 'expired' ? 'selected' : '' ?>>
                    Expired
                </option>

                <option value="expiring_30" <?= $filter === 'expiring_30' ? 'selected' : '' ?>>
                    Expiring Within 30 Days
                </option>

                <option value="expiring_90" <?= $filter === 'expiring_90' ? 'selected' : '' ?>>
                    Expiring Within 90 Days
                </option>
            </select>

            <button class="button" type="submit">
                Search
            </button>

            <a class="button" href="inventory-alerts.php">
                Reset
            </a>

        </form>

    </section>

    <br>

    <section class="card table-card">

        <div class="table-heading">
            <h2>Inventory Records</h2>
            <p class="muted">
                Showing <?= count($items) ?> records, with a maximum of 500.
            </p>
        </div>

        <div class="table-wrap">

            <table>
                <thead>
                    <tr>
                        <th>Medicine</th>
                        <th>Batch</th>
                        <th>Quantity</th>
                        <th>Stock Status</th>
                        <th>Expiry Date</th>
                        <th>Expiry Status</th>
                        <th>Supplier</th>
                        <th>Selling Price</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$items): ?>

                    <tr>
                        <td colspan="8" class="empty">
                            No inventory records found.
                            Click "Add Inventory" to add your first record.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($items as $item): ?>

                        <?php
                        [$stockText, $stockClass] = stockLabel(
                            (int) $item['quantity'],
                            (int) $item['minimum_stock']
                        );

                        [$expiryText, $expiryClass] = expiryLabel(
                            $item['expiry_date']
                        );
                        ?>

                        <tr>
                            <td>
                                <strong><?= e($item['medicine_name']) ?></strong>
                            </td>

                            <td><?= e($item['batch_number'] ?: '—') ?></td>

                            <td>
                                <?= number_format((int) $item['quantity']) ?>
                                <small class="muted">
                                    / minimum <?= number_format((int) $item['minimum_stock']) ?>
                                </small>
                            </td>

                            <td>
                                <span class="badge badge-<?= e($stockClass) ?>">
                                    <?= e($stockText) ?>
                                </span>
                            </td>

                            <td><?= e(formatDate($item['expiry_date'])) ?></td>

                            <td>
                                <span class="badge badge-<?= e($expiryClass) ?>">
                                    <?= e($expiryText) ?>
                                </span>
                            </td>

                            <td><?= e($item['supplier_name'] ?: '—') ?></td>

                            <td>
                                ₹<?= number_format((float) $item['selling_price'], 2) ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>

        </div>
    </section>

</main>

<footer>
    © <?= date('Y') ?> Mahalaxmi Medical Store Management System.
    All rights reserved.
</footer>

</body>
</html>