<?php

require_once __DIR__ . '/../includes/auth.php';
requireRole('employee');

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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Report Activity | Mahalaxmi Medical Store</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/employee.css">
</head>
<body>

<header class="employee-header">
    <h1>Mahalaxmi Medical Store</h1>
    <p>Activity Reporting</p>
</header>

<main class="employee-main">

    <h2>Hello, <?= $name ?></h2>
    <p>Report what's happening in the store.</p>

    <section class="employee-card">

        <h3>Submit Store Activity</h3>

        <form id="activityForm">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= $csrfToken ?>"
            >

            <label for="activity_type">Activity</label>

            <select id="activity_type" name="activity_type" required>
                <option value="">Select an activity</option>
                <option>Store opened</option>
                <option>Stock received</option>
                <option>Medicine out of stock</option>
                <option>Purchase bills completed</option>
                <option>Cleaning completed</option>
                <option>Refrigerator checked</option>
                <option>Store closing</option>
                <option>Other</option>
            </select>

            <label for="description">Description</label>

            <textarea
                id="description"
                name="description"
                rows="4"
                maxlength="3000"
                placeholder="Add details about what's happening..."
            ></textarea>

            <label for="priority">Priority</label>

            <select id="priority" name="priority">
                <option value="normal">Normal</option>
                <option value="low">Low</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
            </select>

            <button type="submit" class="activity-submit">
                Submit Activity
            </button>

            <p id="activityMessage" role="status" aria-live="polite"></p>

        </form>

    </section>

    <section class="employee-card">

        <h3>My Recent Activities</h3>

        <div id="myActivities">
            Loading activities...
        </div>

    </section>

    <a href="dashboard.php" class="logout-link">Back to Dashboard</a>

</main>

<script>
const activityForm = document.getElementById('activityForm');
const activityMessage = document.getElementById('activityMessage');
const myActivities = document.getElementById('myActivities');

function escapeHTML(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    })[char]);
}

async function loadMyActivities() {
    try {
        const response = await fetch('../api/activities.php', {
            credentials: 'same-origin'
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Unable to load activities.');
        }

        if (!data.activities.length) {
            myActivities.textContent = 'No activities submitted yet.';
            return;
        }

        myActivities.innerHTML = data.activities.map(activity => `
            <article class="activity-item">
                <h4>${escapeHTML(activity.activity_type)}</h4>
                <p>${escapeHTML(activity.description || 'No additional details.')}</p>
                <small>
                    ${escapeHTML(activity.created_at)}
                    · ${escapeHTML(activity.priority)}
                    · ${escapeHTML(activity.status)}
                </small>
            </article>
        `).join('');

    } catch (error) {
        myActivities.textContent = error.message;
    }
}

activityForm.addEventListener('submit', async event => {
    event.preventDefault();

    const formData = new FormData(activityForm);
    const payload = Object.fromEntries(formData.entries());
    payload.action = 'create';

    activityMessage.textContent = 'Submitting...';

    try {
        const response = await fetch('../api/activities.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Submission failed.');
        }

        activityMessage.textContent = data.message;
        activityForm.reset();

        await loadMyActivities();

    } catch (error) {
        activityMessage.textContent = error.message;
    }
});

loadMyActivities();
</script>

</body>
</html>