<?php
session_start();
require_once 'helpers.php';

if (!isset($_SESSION['username'])) { header("Location: index.php"); exit(); }
if ($_SESSION['role'] !== 'admin') { header("Location: dashboard.php"); exit(); }

$username = $_SESSION['username'];
$role     = $_SESSION['role'];

$connection = mysqli_connect('localhost', 'root', '', 'prison_db');
if (!$connection) die("Database connection failed: " . mysqli_connect_error());

$active_page = 'pending_approvals';

// ── CAPACITY CHECK ────────────────────────────────────────────────────────────
$capacity_info = mysqli_fetch_assoc(mysqli_query($connection,
    "SELECT
        (SELECT COALESCE(SUM(capacity),0) FROM cells) AS total_capacity,
        (SELECT COUNT(*) FROM prisoners WHERE status = 'active') AS total_active"
));
$total_capacity = intval($capacity_info['total_capacity']);
$total_active   = intval($capacity_info['total_active']);
$prison_full    = ($total_capacity > 0 && $total_active >= $total_capacity);

// ── HANDLE: Approve ───────────────────────────────────────────────────────────
if (isset($_GET['approve_id'])) {
    $id = intval($_GET['approve_id']);

    if ($prison_full) {
        $_SESSION['flash_error'] = "Cannot approve — prison is at full capacity ($total_active/$total_capacity beds).";
        header("Location: pending_approvals.php"); exit();
    }

    // Fetch pending prisoner
    $stmt = mysqli_prepare($connection,
        "SELECT * FROM pending_prisoners WHERE id = ? AND status = 'pending'");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);

    if ($pending = mysqli_fetch_assoc($result)) {
        $cell_id = intval($pending['cell_id']);

        // Verify the cell is not full
        $cell_check = mysqli_fetch_assoc(mysqli_query($connection,
            "SELECT c.capacity, COUNT(p.id) AS occupants, c.cell_number
             FROM cells c
             LEFT JOIN prisoners p ON c.id = p.cell_id AND p.status = 'active'
             WHERE c.id = $cell_id GROUP BY c.id"
        ));

        if (!$cell_check) {
            $_SESSION['flash_error'] = "The requested cell no longer exists. Please reject and resubmit.";
            header("Location: pending_approvals.php"); exit();
        }

        if ($cell_check['occupants'] >= $cell_check['capacity']) {
            $_SESSION['flash_error'] = "Cell {$cell_check['cell_number']} is now full. Please reject and ask receptionist to resubmit with a different cell.";
            header("Location: pending_approvals.php"); exit();
        }

        // Calculate release date using months
        $release_date = date('Y-m-d',
            strtotime($pending['date_entered'] . ' +' . $pending['sentence_months'] . ' months')
        );

        // Insert into prisoners
        $stmt2 = mysqli_prepare($connection,
            "INSERT INTO prisoners
                (full_name, crime, sentence_months, cell_id, date_entered, release_date, status)
             VALUES (?, ?, ?, ?, ?, ?, 'active')"
        );
        mysqli_stmt_bind_param($stmt2, "ssiiss",
            $pending['full_name'],
            $pending['crime'],
            $pending['sentence_months'],
            $cell_id,
            $pending['date_entered'],
            $release_date
        );
        mysqli_stmt_execute($stmt2);
        mysqli_stmt_close($stmt2);

        // Mark pending as approved
        $now = date('Y-m-d H:i:s');
        $stmt3 = mysqli_prepare($connection,
            "UPDATE pending_prisoners SET status='approved', reviewed_by=?, reviewed_at=? WHERE id=?"
        );
        mysqli_stmt_bind_param($stmt3, "ssi", $username, $now, $id);
        mysqli_stmt_execute($stmt3);
        mysqli_stmt_close($stmt3);

        $sentence_label = format_sentence($pending['sentence_months']);
        log_action($connection, $username,
            "Approved prisoner: {$pending['full_name']} | $sentence_label | Cell {$cell_check['cell_number']} | Release: $release_date"
        );
        $_SESSION['flash_success'] =
            "✅ {$pending['full_name']} approved. Sentence: $sentence_label. Release date: $release_date. Cell: {$cell_check['cell_number']}.";
    }

    header("Location: pending_approvals.php"); exit();
}

// ── HANDLE: Reject ────────────────────────────────────────────────────────────
if (isset($_GET['reject_id'])) {
    $id = intval($_GET['reject_id']);

    $stmt = mysqli_prepare($connection, "SELECT full_name FROM pending_prisoners WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $rr = mysqli_stmt_get_result($stmt);
    $pname = ($row = mysqli_fetch_assoc($rr)) ? $row['full_name'] : "Unknown";
    mysqli_stmt_close($stmt);

    $now = date('Y-m-d H:i:s');
    $stmt2 = mysqli_prepare($connection,
        "UPDATE pending_prisoners SET status='rejected', reviewed_by=?, reviewed_at=? WHERE id=? AND status='pending'"
    );
    mysqli_stmt_bind_param($stmt2, "ssi", $username, $now, $id);
    mysqli_stmt_execute($stmt2);
    mysqli_stmt_close($stmt2);

    log_action($connection, $username, "Rejected prisoner submission: $pname (ID: $id)");
    $_SESSION['flash_error'] = "Submission for '$pname' has been rejected.";

    header("Location: pending_approvals.php"); exit();
}

// ── FLASH MESSAGES ────────────────────────────────────────────────────────────
$success_message = $_SESSION['flash_success'] ?? '';
$error_message   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ── LOAD PENDING ──────────────────────────────────────────────────────────────
$pending_result = mysqli_query($connection,
    "SELECT pp.*, c.cell_number, c.block,
            c.capacity,
            (SELECT COUNT(*) FROM prisoners p WHERE p.cell_id = c.id AND p.status='active') AS cell_occupants
     FROM pending_prisoners pp
     LEFT JOIN cells c ON pp.cell_id = c.id
     WHERE pp.status = 'pending'
     ORDER BY pp.submitted_at ASC"
);

// ── LOAD HISTORY ──────────────────────────────────────────────────────────────
$history_result = mysqli_query($connection,
    "SELECT pp.*, c.cell_number
     FROM pending_prisoners pp
     LEFT JOIN cells c ON pp.cell_id = c.id
     WHERE pp.status IN ('approved','rejected')
     ORDER BY pp.reviewed_at DESC LIMIT 20"
);

$pending_count = mysqli_num_rows($pending_result);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <title>Pending Approvals – Maula Prison</title>
</head>
<body class="dashboard-body">
<?php require_once 'nav.php'; ?>
<div class="dashboard-layout">
<main class="main-content">

    <h1>✅ Pending Prisoner Approvals</h1>
    <p class="page-subtitle">
        <?= $pending_count > 0
            ? "<strong style='color:#c0392b;'>$pending_count submission(s) waiting for your review.</strong>"
            : "No pending submissions right now." ?>
        &nbsp; Prison: <strong><?= $total_active ?>/<?= $total_capacity ?></strong> beds occupied.
    </p>

    <?php if ($prison_full): ?>
        <div class="alert alert-error">
            ⛔ <strong>Prison is at full capacity</strong> — you cannot approve new prisoners until someone is released.
        </div>
    <?php endif; ?>

    <?php if ($success_message): ?>
        <div class="alert alert-success" id="flash-msg"><?= htmlspecialchars($success_message) ?></div>
        <script>setTimeout(function(){var e=document.getElementById('flash-msg');e.style.opacity='0';setTimeout(function(){e.style.display='none';},1000);},3000);</script>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="alert alert-error" id="flash-msg"><?= htmlspecialchars($error_message) ?></div>
        <script>setTimeout(function(){var e=document.getElementById('flash-msg');e.style.opacity='0';setTimeout(function(){e.style.display='none';},1000);},3000);</script>
    <?php endif; ?>

    <!-- PENDING CARDS -->
    <?php if ($pending_count === 0): ?>
        <div class="alert alert-success">🎉 No pending submissions — all caught up!</div>
    <?php else: ?>
        <div class="approval-cards">
            <?php while ($p = mysqli_fetch_assoc($pending_result)): ?>
            <?php
                $cell_full    = ($p['cell_occupants'] >= $p['capacity']);
                $can_approve  = !$prison_full && !$cell_full && $p['cell_number'];
            ?>
            <div class="approval-card" style="<?= $cell_full ? 'border-left-color:#c0392b;' : '' ?>">
                <h3>🔒 <?= htmlspecialchars($p['full_name']) ?></h3>
                <p><strong>Crime:</strong> <?= htmlspecialchars($p['crime']) ?></p>
                <p><strong>Sentence:</strong> <?= format_sentence($p['sentence_months']) ?></p>
                <p><strong>Date Entered:</strong> <?= htmlspecialchars($p['date_entered']) ?></p>
                <p><strong>Cell Requested:</strong>
                    <?php if ($p['cell_number']): ?>
                        Cell <?= htmlspecialchars($p['cell_number']) ?>
                        (<?= htmlspecialchars($p['block']) ?>) —
                        <?= $p['cell_occupants'] ?>/<?= $p['capacity'] ?> occupied
                        <?php if ($cell_full): ?>
                            <span style="color:#c0392b; font-weight:bold;"> ⚠️ FULL</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:#c0392b;">⚠️ Cell no longer exists</span>
                    <?php endif; ?>
                </p>
                <p><strong>Release Date (if approved):</strong>
                    <span style="color:#1e8449; font-weight:bold;">
                        <?= date('d M Y', strtotime($p['date_entered'] . ' +' . $p['sentence_months'] . ' months')) ?>
                    </span>
                </p>
                <p><strong>Submitted By:</strong> <?= htmlspecialchars($p['submitted_by']) ?></p>
                <p><strong>Submitted At:</strong>
                    <span style="color:#888;font-size:0.82rem;">
                        <?= date('d M Y, H:i', strtotime($p['submitted_at'])) ?>
                    </span>
                </p>
                <div class="card-actions">
                    <?php if ($can_approve): ?>
                        <a href="pending_approvals.php?approve_id=<?= $p['id'] ?>"
                           class="btn-approve"
                           onclick="return confirm('Approve <?= htmlspecialchars($p['full_name'], ENT_QUOTES) ?>?')">
                            ✅ Approve
                        </a>
                    <?php else: ?>
                        <span style="color:#aaa;font-size:0.85rem;padding:7px 0;">
                            <?= $prison_full ? '⛔ Prison full' : '⚠️ Cell full — cannot approve' ?>
                        </span>
                    <?php endif; ?>
                    <a href="pending_approvals.php?reject_id=<?= $p['id'] ?>"
                       class="btn-reject"
                       onclick="return confirm('Reject submission for <?= htmlspecialchars($p['full_name'], ENT_QUOTES) ?>?')">
                        ❌ Reject
                    </a>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>

    <!-- HISTORY TABLE -->
    <h2 style="font-size:1.1rem;margin-bottom:14px;color:#333;">📜 Approval History (Last 20)</h2>
    <div class="table-card">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th><th>Full Name</th><th>Crime</th><th>Sentence</th>
                    <th>Cell</th><th>Submitted By</th><th>Reviewed By</th>
                    <th>Decision</th><th>Reviewed At</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($history_result) === 0): ?>
                    <tr><td colspan="9" style="text-align:center;color:#888;">No reviewed submissions yet.</td></tr>
                <?php else: ?>
                    <?php while ($h = mysqli_fetch_assoc($history_result)): ?>
                    <tr>
                        <td><?= $h['id'] ?></td>
                        <td><?= htmlspecialchars($h['full_name']) ?></td>
                        <td><?= htmlspecialchars($h['crime']) ?></td>
                        <td><?= format_sentence($h['sentence_months']) ?></td>
                        <td><?= $h['cell_number'] ? 'Cell '.htmlspecialchars($h['cell_number']) : '—' ?></td>
                        <td><?= htmlspecialchars($h['submitted_by']) ?></td>
                        <td><?= htmlspecialchars($h['reviewed_by'] ?? '—') ?></td>
                        <td>
                            <span class="badge <?= $h['status'] === 'approved' ? 'badge-approved' : 'badge-rejected' ?>">
                                <?= ucfirst($h['status']) ?>
                            </span>
                        </td>
                        <td style="font-size:0.82rem;color:#888;">
                            <?= $h['reviewed_at'] ? date('d M Y, H:i', strtotime($h['reviewed_at'])) : '—' ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</main>
</div>
</body>
</html>
<?php mysqli_close($connection); ?>
