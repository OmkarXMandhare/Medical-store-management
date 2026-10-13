<?php

require_once __DIR__ . '/../includes/auth.php';
requireRole('employee');

require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = htmlspecialchars(
    $_SESSION['csrf_token'],
    ENT_QUOTES,
    'UTF-8'
);

$name = htmlspecialchars(
    $_SESSION['name'] ?? 'Employee',
    ENT_QUOTES,
    'UTF-8'
);

$today = date('Y-m-d');

$stmt = $pdo->prepare(
    "SELECT
        ci.id,
        ci.item_name,
        ci.category,
        COALESCE(cl.completed, 0) AS completed,
        cl.notes,
        cl.completed_at
     FROM checklist_items ci
     LEFT JOIN checklist_logs cl
        ON cl.item_id = ci.id
        AND cl.user_id = :user_id
        AND cl.checklist_date = :checklist_date
     WHERE ci.is_active = 1
     ORDER BY
        FIELD(
            ci.category,
            'Opening',
            'Stock',
            'Cleaning',
            'Safety',
            'Closing'
        ),
        ci.id"
);

$stmt->execute([
    'user_id' => $_SESSION['user_id'],
    'checklist_date' => $today
]);

$items = $stmt->fetchAll();

$completedCount = 0;

foreach ($items as $item) {
    if ((int) $item['completed'] === 1) {
        $completedCount++;
    }
}

$totalCount = count($items);

function checklistEscape($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <meta name="csrf-token"
          content="<?= $csrfToken ?>">

    <title>Daily Checklist | Mahalaxmi Medical Store</title>

    <link rel="stylesheet"
          href="../assets/css/style.css">

    <link rel="stylesheet"
          href="../assets/css/employee.css">
</head>

<body>

<header class="employee-header">
    <h1>Mahalaxmi Medical Store</h1>
    <p>Daily Store Checklist</p>
</header>

<main class="employee-main">

    <h2>Hello, <?= $name ?>!</h2>

    <p>Complete your assigned daily store checks.</p>

    <section class="employee-card">

        <div class="checklist-summary">
            <h3>Today's Progress</h3>

            <p id="progressText">
                <?= $completedCount ?> of <?= $totalCount ?>
                tasks completed
            </p>

            <progress
                id="progressBar"
                value="<?= $completedCount ?>"
                max="<?= max($totalCount, 1) ?>">
            </progress>
        </div>

        <p id="checklistMessage"
           role="status"
           aria-live="polite"></p>

        <?php if (!$items): ?>

            <p>No checklist items have been configured.</p>

        <?php else: ?>

            <?php
            $currentCategory = null;

            foreach ($items as $item):
            ?>

                <?php if ($currentCategory !== $item['category']): ?>

                    <?php
                    $currentCategory = $item['category'];
                    ?>

                    <h3 class="checklist-category">
                        <?= checklistEscape($currentCategory) ?>
                    </h3>

                <?php endif; ?>

                <article class="checklist-item">

                    <label class="checklist-label">

                        <input
                            type="checkbox"
                            class="checklist-checkbox"
                            data-id="<?= (int) $item['id'] ?>"
                            <?= (int) $item['completed'] === 1
                                ? 'checked'
                                : '' ?>
                        >

                        <span>
                            <?= checklistEscape($item['item_name']) ?>
                        </span>

                    </label>

                    <textarea
                        class="checklist-notes"
                        data-notes-id="<?= (int) $item['id'] ?>"
                        maxlength="500"
                        rows="2"
                        placeholder="Optional notes..."
                    ><?= checklistEscape($item['notes'] ?? '') ?></textarea>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

    <a href="dashboard.php"
       class="logout-link">
        Back to Dashboard
    </a>

    <p>
        <a href="../logout.php">Log out</a>
    </p>

</main>

<script>
const csrfToken =
    document.querySelector('meta[name="csrf-token"]').content;

const checklistMessage =
    document.getElementById('checklistMessage');

const progressText =
    document.getElementById('progressText');

const progressBar =
    document.getElementById('progressBar');

const checkboxes =
    document.querySelectorAll('.checklist-checkbox');

async function saveChecklistItem(checkbox) {
    checkbox.disabled = true;

    checklistMessage.textContent = 'Saving checklist update...';

    const itemId = Number(checkbox.dataset.id);

    const notesField = document.querySelector(
        `[data-notes-id="${itemId}"]`
    );

    try {
        const response = await fetch(
            '../api/checklist.php',
            {
                method: 'POST',
                credentials: 'same-origin',

                headers: {
                    'Content-Type': 'application/json'
                },

                body: JSON.stringify({
                    item_id: itemId,
                    completed: checkbox.checked ? 1 : 0,
                    notes: notesField.value,
                    csrf_token: csrfToken
                })
            }
        );

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(
                data.message || 'Unable to save checklist.'
            );
        }

        checklistMessage.textContent = data.message;

        updateProgress();

    } catch (error) {
        checkbox.checked = !checkbox.checked;

        checklistMessage.textContent = error.message;

    } finally {
        checkbox.disabled = false;
    }
}

function updateProgress() {
    const total = checkboxes.length;

    const completed = Array.from(checkboxes)
        .filter(checkbox => checkbox.checked)
        .length;

    progressText.textContent =
        `${completed} of ${total} tasks completed`;

    progressBar.max = Math.max(total, 1);
    progressBar.value = completed;
}

checkboxes.forEach(checkbox => {
    checkbox.addEventListener('change', () => {
        saveChecklistItem(checkbox);
    });
});

document.querySelectorAll('.checklist-notes')
    .forEach(textarea => {

        let timer;

        textarea.addEventListener('input', () => {
            clearTimeout(timer);

            timer = setTimeout(async () => {
                const itemId = Number(
                    textarea.dataset.notesId
                );

                const checkbox = document.querySelector(
                    `.checklist-checkbox[data-id="${itemId}"]`
                );

                if (checkbox) {
                    await saveChecklistItem(checkbox);
                }
            }, 700);
        });
    });

updateProgress();
</script>

</body>
</html>