<?php
/**
 * Diagnostic script to check for missing editorial decisions
 * on the latest review round of submissions.
 *
 * Connects directly via PDO using environment variables.
 * Does NOT modify any data — read-only queries only.
 */

$host = getenv('PKP_DB_HOST');
$name = getenv('PKP_DB_NAME');
$user = getenv('PKP_DB_USER');
$pass = getenv('PKP_DB_PASSWORD');

if (!$host || !$name || !$user || !$pass) {
    fwrite(STDERR, "ERROR: Missing required DB env vars (PKP_DB_HOST, PKP_DB_NAME, PKP_DB_USER, PKP_DB_PASSWORD)\n");
    exit(1);
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Decision constant reference (from PKP\decision\Decision):
$decisionLabels = [
    1  => 'INTERNAL_REVIEW',
    2  => 'ACCEPT',
    3  => 'EXTERNAL_REVIEW',
    4  => 'PENDING_REVISIONS',
    5  => 'RESUBMIT',
    6  => 'DECLINE',
    7  => 'SEND_TO_PRODUCTION',
    8  => 'INITIAL_DECLINE',
    9  => 'RECOMMEND_ACCEPT',
    10 => 'RECOMMEND_PENDING_REVISIONS',
    11 => 'RECOMMEND_RESUBMIT',
    12 => 'RECOMMEND_DECLINE',
    13 => 'RECOMMEND_EXTERNAL_REVIEW',
    14 => 'NEW_EXTERNAL_ROUND',
    15 => 'REVERT_DECLINE',
    16 => 'REVERT_INITIAL_DECLINE',
    17 => 'SKIP_EXTERNAL_REVIEW',
    18 => 'SKIP_INTERNAL_REVIEW',
    19 => 'ACCEPT_INTERNAL',
    20 => 'PENDING_REVISIONS_INTERNAL',
    21 => 'RESUBMIT_INTERNAL',
    22 => 'DECLINE_INTERNAL',
    23 => 'RECOMMEND_ACCEPT_INTERNAL',
    24 => 'RECOMMEND_PENDING_REVISIONS_INTERNAL',
    25 => 'RECOMMEND_RESUBMIT_INTERNAL',
    26 => 'RECOMMEND_DECLINE_INTERNAL',
    27 => 'REVERT_INTERNAL_DECLINE',
    28 => 'NEW_INTERNAL_ROUND',
    29 => 'BACK_FROM_PRODUCTION',
    30 => 'BACK_FROM_COPYEDITING',
    31 => 'CANCEL_REVIEW_ROUND',
    32 => 'CANCEL_INTERNAL_REVIEW_ROUND',
];

// Qualifying decisions that allow authors to upload revisions
// (external review: 2=ACCEPT, 4=PENDING_REVISIONS, 5=RESUBMIT, 14=NEW_EXTERNAL_ROUND)
$qualifyingDecisions = [2, 4, 5, 14];

// Specific submission IDs to focus on (if any)
$focusIds = [7, 28, 95];

echo "========================================================================\n";
echo "DIAGNOSTIC: Missing Editorial Decisions on Latest Review Round\n";
echo "========================================================================\n\n";

// -----------------------------------------------------------------------
// Query 1: edit_decisions for focus submissions
// -----------------------------------------------------------------------
echo "--- Query 1: All edit_decisions for submissions " . implode(', ', $focusIds) . " ---\n\n";

$placeholders = implode(',', array_fill(0, count($focusIds), '?'));
$stmt = $pdo->prepare("
    SELECT ed.edit_decision_id, ed.submission_id, ed.review_round_id,
           ed.stage_id, ed.round, ed.decision, ed.date_decided
    FROM edit_decisions ed
    WHERE ed.submission_id IN ($placeholders)
    ORDER BY ed.submission_id, ed.date_decided
");
$stmt->execute($focusIds);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "  (no rows)\n";
} else {
    printf("  %-6s %-6s %-10s %-6s %-6s %-6s  %-40s %s\n",
        'sub_id', 'ed_id', 'rr_id', 'stage', 'round', 'dec', 'decision_label', 'date_decided');
    printf("  %s\n", str_repeat('-', 110));
    foreach ($rows as $r) {
        $label = $decisionLabels[(int)$r['decision']] ?? '???';
        printf("  %-6s %-6s %-10s %-6s %-6s %-6s  %-40s %s\n",
            $r['submission_id'], $r['edit_decision_id'],
            $r['review_round_id'] ?? 'NULL',
            $r['stage_id'], $r['round'] ?? 'NULL',
            $r['decision'], $label, $r['date_decided']);
    }
}
echo "\n";

// -----------------------------------------------------------------------
// Query 2: review_rounds for focus submissions
// -----------------------------------------------------------------------
echo "--- Query 2: All review_rounds for submissions " . implode(', ', $focusIds) . " ---\n\n";

$stmt = $pdo->prepare("
    SELECT rr.review_round_id, rr.submission_id, rr.stage_id, rr.round, rr.status
    FROM review_rounds rr
    WHERE rr.submission_id IN ($placeholders)
    ORDER BY rr.submission_id, rr.stage_id, rr.round
");
$stmt->execute($focusIds);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "  (no rows)\n";
} else {
    printf("  %-6s %-12s %-6s %-6s %s\n", 'sub_id', 'rr_id', 'stage', 'round', 'status');
    printf("  %s\n", str_repeat('-', 50));
    foreach ($rows as $r) {
        printf("  %-6s %-12s %-6s %-6s %s\n",
            $r['submission_id'], $r['review_round_id'],
            $r['stage_id'], $r['round'], $r['status']);
    }
}
echo "\n";

// -----------------------------------------------------------------------
// Query 3: ALL submissions whose latest external review round has NO
//          qualifying decision (the suspected broken ones)
// -----------------------------------------------------------------------
echo "--- Query 3: Submissions whose latest review round has NO qualifying decision ---\n";
echo "             (qualifying = ACCEPT/PENDING_REVISIONS/RESUBMIT/NEW_EXTERNAL_ROUND)\n\n";

$qualPlaceholders = implode(',', $qualifyingDecisions);

$stmt = $pdo->query("
    SELECT
        rr.submission_id,
        rr.review_round_id,
        rr.round AS current_round,
        rr.stage_id,
        rr.status AS round_status,
        s.stage_id AS submission_stage_id,
        (SELECT COUNT(*) FROM edit_decisions ed
         WHERE ed.review_round_id = rr.review_round_id
         AND ed.decision IN ($qualPlaceholders)
        ) AS qualifying_decisions
    FROM review_rounds rr
    INNER JOIN submissions s ON s.submission_id = rr.submission_id
    INNER JOIN (
        SELECT submission_id, stage_id, MAX(round) AS max_round
        FROM review_rounds
        WHERE stage_id = 3
        GROUP BY submission_id, stage_id
    ) latest ON rr.submission_id = latest.submission_id
             AND rr.stage_id = latest.stage_id
             AND rr.round = latest.max_round
    WHERE rr.stage_id = 3
    AND s.status = 1
    HAVING qualifying_decisions = 0
    ORDER BY rr.submission_id
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "  None found — all latest review rounds have a qualifying decision.\n";
} else {
    echo "  Found " . count($rows) . " submission(s) with missing decisions:\n\n";
    printf("  %-6s %-12s %-6s %-6s %-14s %-12s\n",
        'sub_id', 'rr_id', 'stage', 'round', 'round_status', 'sub_stage_id');
    printf("  %s\n", str_repeat('-', 65));
    foreach ($rows as $r) {
        printf("  %-6s %-12s %-6s %-6s %-14s %-12s\n",
            $r['submission_id'], $r['review_round_id'],
            $r['stage_id'], $r['current_round'],
            $r['round_status'], $r['submission_stage_id']);
    }
}
echo "\n";

// -----------------------------------------------------------------------
// Query 4: For each affected submission's latest round, show what
//          decisions DO exist (if any, even non-qualifying ones)
// -----------------------------------------------------------------------
if (!empty($rows)) {
    $affectedRrIds = array_column($rows, 'review_round_id');
    $rrPlaceholders = implode(',', array_fill(0, count($affectedRrIds), '?'));

    echo "--- Query 4: Existing decisions for the affected review rounds ---\n\n";

    $stmt = $pdo->prepare("
        SELECT ed.edit_decision_id, ed.submission_id, ed.review_round_id,
               ed.stage_id, ed.round, ed.decision, ed.date_decided
        FROM edit_decisions ed
        WHERE ed.review_round_id IN ($rrPlaceholders)
        ORDER BY ed.submission_id, ed.date_decided
    ");
    $stmt->execute($affectedRrIds);
    $decRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($decRows)) {
        echo "  No decisions at all exist for these review rounds.\n";
    } else {
        printf("  %-6s %-6s %-10s %-6s %-6s %-6s  %-40s %s\n",
            'sub_id', 'ed_id', 'rr_id', 'stage', 'round', 'dec', 'decision_label', 'date_decided');
        printf("  %s\n", str_repeat('-', 110));
        foreach ($decRows as $r) {
            $label = $decisionLabels[(int)$r['decision']] ?? '???';
            printf("  %-6s %-6s %-10s %-6s %-6s %-6s  %-40s %s\n",
                $r['submission_id'], $r['edit_decision_id'],
                $r['review_round_id'] ?? 'NULL',
                $r['stage_id'], $r['round'] ?? 'NULL',
                $r['decision'], $label, $r['date_decided']);
        }
    }
    echo "\n";
}

echo "========================================================================\n";
echo "DIAGNOSTIC COMPLETE\n";
echo "========================================================================\n";
