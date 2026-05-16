<?php
// api.php — JSON API for Amigo Invisible app
// POST with JSON body (action in body), GET for get_sortie

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Disable error display in output — errors go to log
ini_set('display_errors', '0');
error_reporting(E_ALL);

function json_ok(array $data): void {
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── GET: get_sortie ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sid = trim($_GET['s'] ?? '');
    if (!$sid) json_err('Falta el parámetro s.', 400);

    $pdo  = get_db();
    $data = load_sortie($pdo, $sid);
    if (!$data) json_err('Sorteo no encontrado.', 404);

    json_ok($data);
}

// ── POST: parse JSON body ────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Método no permitido.', 405);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    json_err('Body JSON inválido.', 400);
}

$action = trim($body['action'] ?? '');

// ── Action: create_sortie ────────────────────────────────────────────────────

if ($action === 'create_sortie') {
    $name       = trim($body['name'] ?? '');
    $budget     = trim($body['budget'] ?? '');
    $organizer  = trim($body['organizer'] ?? '');
    $raw_parts  = $body['participants'] ?? [];

    if (!$name) {
        json_err('El nombre del grupo es obligatorio.');
    }

    if (!is_array($raw_parts)) {
        json_err('La lista de participantes debe ser un array.');
    }

    // Normalize participant names
    $part_names = [];
    foreach ($raw_parts as $p) {
        $pname = trim((string)$p);
        if ($pname !== '') $part_names[] = $pname;
    }

    // Remove duplicates (case-insensitive)
    $seen       = [];
    $deduped    = [];
    foreach ($part_names as $pname) {
        $key = mb_strtolower($pname);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $deduped[]  = $pname;
        }
    }
    $part_names = $deduped;

    if (count($part_names) < 3) {
        json_err('Se necesitan al menos 3 participantes para el sorteo.');
    }

    try {
        $pdo = get_db();

        $id  = bin2hex(random_bytes(8));
        $now = time();

        $pdo->prepare(
            'INSERT INTO sorties (id, name, budget, organizer, created_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $name,
            $budget !== '' ? $budget : null,
            $organizer !== '' ? $organizer : null,
            $now,
        ]);

        foreach ($part_names as $pname) {
            $pdo->prepare(
                'INSERT INTO participants (sortie_id, name) VALUES (?, ?)'
            )->execute([$id, $pname]);
        }

        $ok = run_assignments($pdo, $id);
        if (!$ok) {
            json_err('Error al generar el sorteo. Verificá que haya al menos 3 participantes.');
        }

        $data = load_sortie($pdo, $id);
        json_ok($data);

    } catch (Exception $e) {
        error_log('amigo api create_sortie: ' . $e->getMessage());
        json_err('Error interno al crear el sorteo.', 500);
    }
}

// ── Action: regenerate ───────────────────────────────────────────────────────

if ($action === 'regenerate') {
    $sid = trim($body['s'] ?? '');
    if (!$sid) json_err('Falta el campo s.');

    try {
        $pdo = get_db();

        // Verify sortie exists
        $stmt = $pdo->prepare('SELECT id FROM sorties WHERE id = ?');
        $stmt->execute([$sid]);
        if (!$stmt->fetch()) json_err('Sorteo no encontrado.', 404);

        $ok = run_assignments($pdo, $sid);
        if (!$ok) json_err('No se pudo regenerar el sorteo.');

        $data = load_sortie($pdo, $sid);
        json_ok($data);

    } catch (Exception $e) {
        error_log('amigo api regenerate: ' . $e->getMessage());
        json_err('Error interno al regenerar el sorteo.', 500);
    }
}

// ── Unknown action ───────────────────────────────────────────────────────────

json_err('Acción desconocida: ' . htmlspecialchars($action), 400);
