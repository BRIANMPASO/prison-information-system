<?php
// helpers.php — shared functions used across all pages
// Include this file at the top of any page that needs it:
//   require_once 'helpers.php';

// ── FORMAT SENTENCE LENGTH ────────────────────────────────────────────────────
// Converts total months into a human-readable string
// Examples: 6 → "6 months", 12 → "1 year", 18 → "1 year 6 months"
function format_sentence($months) {
    if ($months < 12) return "$months month" . ($months > 1 ? 's' : '');
    $years  = intdiv($months, 12);
    $remain = $months % 12;
    $label  = "$years year" . ($years > 1 ? 's' : '');
    if ($remain > 0) $label .= " $remain month" . ($remain > 1 ? 's' : '');
    return $label;
}

// ── WRITE TO AUDIT LOG ────────────────────────────────────────────────────────
// Call this whenever something important happens, e.g.:
//   log_action($connection, $_SESSION['username'], "Added prisoner John Phiri");
function log_action($connection, $done_by, $action) {
    $stmt = mysqli_prepare($connection,
        "INSERT INTO logs (done_by, action) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, "ss", $done_by, $action);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// ── PAGINATION HELPER ─────────────────────────────────────────────────────────
// Returns an array with: offset, current page, total pages
// Usage:
//   $pager = paginate($connection, "SELECT COUNT(*) AS total FROM prisoners", 10);
//   Then use $pager['offset'] in your LIMIT query
//   Then call render_pagination($pager, 'prisoners.php') to show the buttons
function paginate($connection, $count_sql, $per_page = 20) {
    $total_rows  = mysqli_fetch_assoc(mysqli_query($connection, $count_sql))['total'];
    $total_pages = max(1, ceil($total_rows / $per_page));
    $current     = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $offset      = ($current - 1) * $per_page;

    return [
        'total'       => $total_rows,
        'total_pages' => $total_pages,
        'current'     => $current,
        'offset'      => $offset,
        'per_page'    => $per_page,
    ];
}

// ── RENDER PAGINATION BUTTONS ─────────────────────────────────────────────────
// Prints Previous / page numbers / Next buttons
// Pass extra query params you want to keep, e.g. ['search' => 'john', 'status' => 'active']
function render_pagination($pager, $base_url, $extra_params = []) {
    if ($pager['total_pages'] <= 1) return; // nothing to show

    $current = $pager['current'];
    $total   = $pager['total_pages'];

    // Build base query string from extra params
    $qs = '';
    if (!empty($extra_params)) {
        $parts = [];
        foreach ($extra_params as $k => $v) {
            if ($v !== '') $parts[] = urlencode($k) . '=' . urlencode($v);
        }
        if ($parts) $qs = '&' . implode('&', $parts);
    }

    echo '<div class="pagination">';

    // Previous button
    if ($current > 1) {
        echo '<a href="' . $base_url . '?page=' . ($current - 1) . $qs . '" class="page-btn">← Prev</a>';
    } else {
        echo '<span class="page-btn disabled">← Prev</span>';
    }

    // Page number buttons (show max 5 around current)
    $start = max(1, $current - 2);
    $end   = min($total, $current + 2);

    if ($start > 1) echo '<span class="page-btn disabled">…</span>';

    for ($i = $start; $i <= $end; $i++) {
        $active = ($i === $current) ? ' active' : '';
        echo '<a href="' . $base_url . '?page=' . $i . $qs . '" class="page-btn' . $active . '">' . $i . '</a>';
    }

    if ($end < $total) echo '<span class="page-btn disabled">…</span>';

    // Next button
    if ($current < $total) {
        echo '<a href="' . $base_url . '?page=' . ($current + 1) . $qs . '" class="page-btn">Next →</a>';
    } else {
        echo '<span class="page-btn disabled">Next →</span>';
    }

    echo '</div>';
}
?>
