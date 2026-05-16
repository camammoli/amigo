<?php
// db.php — Database initialization for Amigo Invisible app
// Creates SQLite DB and all tables on first run

define('AMIGO_DB_PATH', __DIR__ . '/data/amigo.db');
define('AMIGO_DATA_DIR', __DIR__ . '/data');

define('PARTICIPANT_COLORS', [
    '#3b82f6', // blue
    '#22c55e', // green
    '#ef4444', // red
    '#f59e0b', // amber
    '#8b5cf6', // violet
    '#ec4899', // pink
    '#14b8a6', // teal
    '#f97316', // orange
]);

function get_db(): PDO {
    // Ensure data dir exists and has a blocking .htaccess
    if (!is_dir(AMIGO_DATA_DIR)) {
        mkdir(AMIGO_DATA_DIR, 0755, true);
    }
    $htaccess = AMIGO_DATA_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Order Allow,Deny\nDeny from all\n");
    }

    $pdo = new PDO('sqlite:' . AMIGO_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');

    // Create schema
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sorties (
            id          TEXT PRIMARY KEY,
            name        TEXT NOT NULL,
            budget      TEXT,
            organizer   TEXT,
            created_at  INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS participants (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            sortie_id   TEXT NOT NULL REFERENCES sorties(id) ON DELETE CASCADE,
            name        TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS assignments (
            giver_id    INTEGER PRIMARY KEY REFERENCES participants(id) ON DELETE CASCADE,
            receiver_id INTEGER NOT NULL REFERENCES participants(id) ON DELETE CASCADE
        );
    ");

    return $pdo;
}

/**
 * Load full sortie data including participants and assignments.
 * Returns null if not found.
 */
function load_sortie(PDO $pdo, string $sortie_id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM sorties WHERE id = ?');
    $stmt->execute([$sortie_id]);
    $sortie = $stmt->fetch();
    if (!$sortie) return null;

    // Participants
    $stmt = $pdo->prepare('SELECT * FROM participants WHERE sortie_id = ? ORDER BY id');
    $stmt->execute([$sortie_id]);
    $participants = $stmt->fetchAll();

    // Assignments with names
    $stmt = $pdo->prepare('
        SELECT
            a.giver_id,
            g.name AS giver_name,
            a.receiver_id,
            r.name AS receiver_name
        FROM assignments a
        JOIN participants g ON g.id = a.giver_id
        JOIN participants r ON r.id = a.receiver_id
        WHERE g.sortie_id = ?
        ORDER BY g.id
    ');
    $stmt->execute([$sortie_id]);
    $assignments = $stmt->fetchAll();

    // sent_count placeholder (managed client-side via localStorage)
    $sent_count = 0;

    return [
        'sortie'       => $sortie,
        'participants' => $participants,
        'assignments'  => $assignments,
        'sent_count'   => $sent_count,
    ];
}

/**
 * Derangement algorithm with no mutual pairs when possible.
 * $ids = array of participant ids (integers).
 * Returns shuffled array such that no element stays in its original position,
 * and ideally no mutual pairs (A→B and B→A).
 */
function make_derangement(array $ids): array {
    $n = count($ids);
    if ($n < 2) return $ids;

    $best = null;

    for ($attempt = 0; $attempt < 500; $attempt++) {
        $shuffled = $ids;
        shuffle($shuffled);

        // Check for self-assignments
        $self = false;
        for ($i = 0; $i < $n; $i++) {
            if ($shuffled[$i] === $ids[$i]) {
                $self = true;
                break;
            }
        }
        if ($self) continue;

        // Check for mutual pairs (A→B and B→A)
        $pos = array_flip($ids); // id → index in original
        $mutual = false;
        for ($i = 0; $i < $n; $i++) {
            $receiver_idx = $pos[$shuffled[$i]];
            if ($shuffled[$receiver_idx] === $ids[$i]) {
                $mutual = true;
                break;
            }
        }

        if (!$mutual) return $shuffled; // Perfect: no self, no mutual

        // Save first valid derangement (no self, but has mutual) as fallback
        if ($best === null) $best = $shuffled;
    }

    // Fallback: return best found (no self-assignments) or original if all failed
    return $best ?? $ids;
}

/**
 * Run assignment algorithm and persist to DB.
 * Deletes existing assignments for the sortie before inserting new ones.
 */
function run_assignments(PDO $pdo, string $sortie_id): bool {
    // Get participant ids
    $stmt = $pdo->prepare('SELECT id FROM participants WHERE sortie_id = ? ORDER BY id');
    $stmt->execute([$sortie_id]);
    $rows = $stmt->fetchAll();
    $ids = array_column($rows, 'id');

    if (count($ids) < 3) return false;

    // Delete existing assignments (via giver_id belonging to this sortie)
    $pdo->prepare('
        DELETE FROM assignments
        WHERE giver_id IN (SELECT id FROM participants WHERE sortie_id = ?)
    ')->execute([$sortie_id]);

    // Generate derangement
    $receivers = make_derangement($ids);

    // Insert new assignments
    $stmt = $pdo->prepare('INSERT INTO assignments (giver_id, receiver_id) VALUES (?, ?)');
    for ($i = 0; $i < count($ids); $i++) {
        $stmt->execute([$ids[$i], $receivers[$i]]);
    }

    return true;
}
