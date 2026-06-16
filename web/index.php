<?php
// index.php — Amigo Invisible main UI
require_once __DIR__ . '/db.php';

$sortie_id   = trim($_GET['s'] ?? '');
$sortie_data = null;
$error       = '';

// ── POST: create sortie from landing form ────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$sortie_id) {
    $name      = trim($_POST['name']      ?? '');
    $budget    = trim($_POST['budget']    ?? '');
    $organizer = trim($_POST['organizer'] ?? '');
    $raw_parts = trim($_POST['participants'] ?? '');

    if (!$name) {
        $error = 'Escribí un nombre para el grupo.';
    } else {
        // Parse textarea/comma — split by newline first, then comma
        $lines = preg_split('/[\r\n]+/', $raw_parts);
        $part_names = [];
        foreach ($lines as $line) {
            $subs = array_map('trim', explode(',', $line));
            foreach ($subs as $sub) {
                if ($sub !== '') $part_names[] = $sub;
            }
        }

        // Remove duplicates (case-insensitive)
        $seen    = [];
        $deduped = [];
        foreach ($part_names as $pname) {
            $key = mb_strtolower($pname);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $deduped[]  = $pname;
            }
        }
        $part_names = $deduped;

        if (count($part_names) < 3) {
            $error = 'Ingresá al menos 3 participantes para poder sortear.';
        } else {
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

                run_assignments($pdo, $id);

                header('Location: ?s=' . urlencode($id));
                exit;

            } catch (Exception $e) {
                $error = 'Error al crear el sorteo. Intentá de nuevo.';
            }
        }
    }
}

// ── GET with sortie id: load data ────────────────────────────────────────────

if ($sortie_id) {
    try {
        $pdo         = get_db();
        $sortie_data = load_sortie($pdo, $sortie_id);
        if (!$sortie_data) {
            // Not found → redirect to landing
            header('Location: /amigo/');
            exit;
        }
    } catch (Exception $e) {
        header('Location: /amigo/');
        exit;
    }
}

// ── Page meta ────────────────────────────────────────────────────────────────

$sortie      = $sortie_data['sortie']      ?? null;
$participants = $sortie_data['participants'] ?? [];
$assignments  = $sortie_data['assignments']  ?? [];

$group_name  = $sortie ? htmlspecialchars($sortie['name'])      : '';
$budget_str  = ($sortie && $sortie['budget']) ? htmlspecialchars($sortie['budget']) : '';
$organizer   = ($sortie && $sortie['organizer']) ? htmlspecialchars($sortie['organizer']) : 'El organizador';
$n_parts     = count($participants);

$page_title = $sortie_data
    ? $group_name . ' — Amigo Invisible'
    : 'Amigo Invisible — Organizá el sorteo en segundos';

$og_title = $sortie_data ? $group_name : 'Amigo Invisible';
$og_desc  = $sortie_data
    ? "Sorteo de amigo invisible — {$n_parts} participantes"
    : 'Organizá el sorteo de amigo invisible en segundos, sin apps ni cuentas.';

$canonical = 'https://mammoli.ar/amigo/' . ($sortie_id ? '?s=' . urlencode($sortie_id) : '');

// Precompute JS-safe data for sortie page
$js_sortie_id   = $sortie_id ? json_encode($sortie_id) : 'null';
$js_assignments = $sortie_data ? json_encode($assignments, JSON_UNESCAPED_UNICODE) : '[]';
$js_group_name  = $sortie_data ? json_encode($sortie['name'],    JSON_UNESCAPED_UNICODE) : '""';
$js_budget      = ($sortie && $sortie['budget'])
    ? json_encode($sortie['budget'], JSON_UNESCAPED_UNICODE)
    : 'null';
$js_organizer   = $sortie_data
    ? json_encode($sortie['organizer'] ?? null, JSON_UNESCAPED_UNICODE)
    : 'null';

// Color cycle for participant dots (same 8-color palette as split)
$colors = PARTICIPANT_COLORS;

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $page_title ?></title>
<meta name="description" content="<?= $og_desc ?>">
<link rel="canonical" href="<?= $canonical ?>">

<!-- Open Graph -->
<meta property="og:title"       content="<?= $og_title ?>">
<meta property="og:description" content="<?= $og_desc ?>">
<meta property="og:url"         content="<?= $canonical ?>">
<meta property="og:type"        content="website">
<meta name="twitter:card"       content="summary">

<script type="application/ld+json">
{"@context":"https://schema.org","@type":"WebApplication","name":"Amigo Invisible","url":"https://mammoli.ar/amigo/","description":"Organizá el amigo invisible del grupo online. Sin registro, resultados por link o email.","applicationCategory":"LifestyleApplication","operatingSystem":"Web","inLanguage":"es","offers":{"@type":"Offer","price":"0","priceCurrency":"USD"},"author":{"@type":"Person","name":"Carlos Mammoli","url":"https://mammoli.ar/"}}
</script>

<style>
/* ── Reset & base ───────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:        #f8fafc;
    --surface:   #ffffff;
    --border:    #e2e8f0;
    --text:      #1e293b;
    --muted:     #64748b;
    --accent:    #3b82f6;
    --success:   #22c55e;
    --danger:    #ef4444;
    --warning:   #f59e0b;
    --radius:    12px;
    --radius-sm: 8px;
    --shadow:    0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.06);
    --shadow-md: 0 4px 12px rgba(0,0,0,.10);
}

html { height: 100%; }
body {
    font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100%;
    font-size: 15px;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
}

/* ── Layout ─────────────────────────────────────────────────────────────── */
.app {
    max-width: 480px;
    margin: 0 auto;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* ── Header ─────────────────────────────────────────────────────────────── */
.header {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    padding: 14px 16px;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: var(--shadow);
}
.header-inner {
    display: flex;
    align-items: center;
    gap: 10px;
}
.header-logo {
    font-size: 22px;
    line-height: 1;
    flex-shrink: 0;
    text-decoration: none;
}
.header-title {
    flex: 1;
    min-width: 0;
}
.header-title h1 {
    font-size: 17px;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    background: linear-gradient(135deg, #ef4444, #f59e0b);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
.header-title .subtitle {
    font-size: 12px;
    color: var(--muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Landing header variant */
.header-landing h1 {
    background: linear-gradient(135deg, #ef4444, #f59e0b);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    font-size: 19px;
    font-weight: 800;
}
.header-landing .subtitle { font-size: 13px; color: var(--muted); }

/* ── Main ────────────────────────────────────────────────────────────────── */
.main { flex: 1; padding: 16px; display: flex; flex-direction: column; gap: 16px; }

/* ── Cards ──────────────────────────────────────────────────────────────── */
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px;
    box-shadow: var(--shadow);
}

/* ── Section title ──────────────────────────────────────────────────────── */
.section-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--muted);
    margin-bottom: 10px;
}

/* ── Form ────────────────────────────────────────────────────────────────── */
.form-group { margin-bottom: 14px; }
.form-group:last-child { margin-bottom: 0; }
.form-group label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    margin-bottom: 5px;
    text-transform: uppercase;
    letter-spacing: .04em;
}
.form-group input,
.form-group textarea {
    width: 100%;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 9px 11px;
    font-size: 15px;
    background: var(--surface);
    color: var(--text);
    outline: none;
    transition: border-color .15s, box-shadow .15s;
    font-family: inherit;
    resize: vertical;
}
.form-group input:focus,
.form-group textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(59,130,246,.12);
}
.form-group .hint {
    font-size: 12px;
    color: var(--muted);
    margin-top: 4px;
}

/* ── Error box ──────────────────────────────────────────────────────────── */
.error-box {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: var(--radius-sm);
    padding: 10px 14px;
    color: #dc2626;
    font-size: 14px;
    font-weight: 500;
}

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 11px 18px;
    border-radius: var(--radius-sm);
    font-size: 15px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: opacity .15s, background .15s;
    text-decoration: none;
    font-family: inherit;
    line-height: 1;
}
.btn:hover { opacity: .88; }
.btn:active { opacity: .75; }
.btn-primary  { background: var(--accent);   color: #fff; width: 100%; }
.btn-success  { background: var(--success);  color: #fff; }
.btn-danger   { background: var(--danger);   color: #fff; }
.btn-ghost    { background: var(--bg);       color: var(--muted); border: 1px solid var(--border); }
.btn-full     { width: 100%; }

/* ── Share card ─────────────────────────────────────────────────────────── */
.share-card .share-url {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-bottom: 8px;
}
.share-card .url-text {
    flex: 1;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 8px 10px;
    font-size: 13px;
    color: var(--muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-family: monospace;
    cursor: pointer;
    user-select: all;
    transition: border-color .15s;
}
.share-card .url-text:hover { border-color: var(--accent); }
.btn-copy {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 8px 12px;
    font-size: 13px;
    font-weight: 600;
    color: var(--accent);
    cursor: pointer;
    white-space: nowrap;
    transition: background .15s, border-color .15s;
    flex-shrink: 0;
}
.btn-copy:hover { background: #eff6ff; border-color: var(--accent); }
.btn-copy.copied { background: #f0fdf4; border-color: var(--success); color: var(--success); }
.share-note {
    font-size: 12px;
    color: var(--muted);
    display: flex;
    align-items: flex-start;
    gap: 5px;
}

/* ── Assignment cards ───────────────────────────────────────────────────── */
.assignment-list { display: flex; flex-direction: column; gap: 10px; }

.assignment-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 14px 14px 14px 12px;
    box-shadow: var(--shadow);
    display: flex;
    flex-direction: column;
    gap: 10px;
    border-left-width: 4px;
    transition: box-shadow .15s;
}
.assignment-card:hover { box-shadow: var(--shadow-md); }

.assignment-card-top {
    display: flex;
    align-items: center;
    gap: 10px;
}
.participant-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    flex-shrink: 0;
}
.giver-name {
    font-size: 15px;
    font-weight: 700;
    flex: 1;
    min-width: 0;
}
.receiver-row {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg);
    border-radius: var(--radius-sm);
    padding: 8px 10px;
}
.receiver-label {
    font-size: 12px;
    color: var(--muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
    flex-shrink: 0;
}
.receiver-name {
    font-size: 16px;
    font-weight: 700;
    color: var(--text);
    flex: 1;
}

/* ── WhatsApp send button ───────────────────────────────────────────────── */
.btn-wa {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 12px 16px;
    background: #22c55e;
    color: #fff;
    border: none;
    border-radius: var(--radius-sm);
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    transition: background .15s, opacity .15s;
    font-family: inherit;
}
.btn-wa:hover { background: #16a34a; }
.btn-wa:active { opacity: .82; }
.btn-wa.sent {
    background: #e2e8f0;
    color: #94a3b8;
    cursor: default;
}
.btn-wa.sent:hover { background: #e2e8f0; }

/* ── Regenerate section ─────────────────────────────────────────────────── */
.regen-section {
    padding-bottom: 8px;
    text-align: center;
}
.regen-section .regen-note {
    font-size: 12px;
    color: var(--muted);
    margin-top: 8px;
}

/* ── Landing hero ────────────────────────────────────────────────────────── */
.hero {
    text-align: center;
    padding: 24px 0 8px;
}
.hero-icon { font-size: 52px; line-height: 1; margin-bottom: 10px; }
.hero h2 {
    font-size: 22px;
    font-weight: 800;
    background: linear-gradient(135deg, #ef4444, #f59e0b);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: 6px;
}
.hero p {
    font-size: 15px;
    color: var(--muted);
}

/* ── Footer ──────────────────────────────────────────────────────────────── */
footer {
    text-align: center;
    padding: 20px 16px 28px;
    font-size: 12px;
    color: var(--muted);
}
footer a { color: var(--muted); text-decoration: none; }
footer a:hover { color: var(--accent); }
footer .sep { margin: 0 6px; }

/* ── Spinner (loading state) ─────────────────────────────────────────────── */
.spinner {
    display: inline-block;
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255,255,255,.4);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin .6s linear infinite;
    flex-shrink: 0;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Confirmation overlay ───────────────────────────────────────────────── */
.confirm-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    display: flex;
    align-items: flex-end;
    justify-content: center;
    z-index: 200;
    padding: 0 0 env(safe-area-inset-bottom, 0);
    opacity: 0;
    pointer-events: none;
    transition: opacity .2s;
}
.confirm-overlay.visible {
    opacity: 1;
    pointer-events: all;
}
.confirm-sheet {
    background: var(--surface);
    border-radius: var(--radius) var(--radius) 0 0;
    padding: 20px 20px 28px;
    width: 100%;
    max-width: 480px;
    box-shadow: 0 -4px 24px rgba(0,0,0,.15);
    transform: translateY(20px);
    transition: transform .2s;
}
.confirm-overlay.visible .confirm-sheet {
    transform: translateY(0);
}
.confirm-sheet h3 {
    font-size: 16px;
    font-weight: 700;
    margin-bottom: 8px;
}
.confirm-sheet p {
    font-size: 14px;
    color: var(--muted);
    margin-bottom: 18px;
}
.confirm-actions {
    display: flex;
    gap: 10px;
}
.confirm-actions .btn { flex: 1; }

/* ── Toast ───────────────────────────────────────────────────────────────── */
.toast {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%) translateY(20px);
    background: #1e293b;
    color: #fff;
    padding: 10px 18px;
    border-radius: 999px;
    font-size: 14px;
    font-weight: 500;
    white-space: nowrap;
    opacity: 0;
    transition: opacity .2s, transform .2s;
    z-index: 300;
    pointer-events: none;
}
.toast.show {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
}
</style>
</head>
<body>
<div class="app">

<?php if (!$sortie_data): ?>
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- LANDING PAGE                                                           -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

<header class="header">
    <div class="header-inner header-landing">
        <span class="header-logo" aria-hidden="true">🎁</span>
        <div class="header-title">
            <h1>Amigo Invisible</h1>
            <p class="subtitle">mammoli.ar</p>
        </div>
    </div>
</header>

<main class="main">

    <div class="hero">
        <div class="hero-icon">🎁</div>
        <h2>Organizá el sorteo<br>en segundos</h2>
        <p>Sin apps, sin registros.<br>Cada uno recibe su amigo por WhatsApp.</p>
    </div>

    <?php if ($error): ?>
    <div class="error-box"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="section-title">Nuevo sorteo</div>
        <form method="POST" action="/amigo/" id="landing-form" novalidate>
            <div class="form-group">
                <label for="name">Nombre del grupo</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    placeholder="Ej: Familia Mammoli 2025"
                    value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                    maxlength="80"
                    required
                    autocomplete="off"
                >
            </div>
            <div class="form-group">
                <label for="budget">Presupuesto sugerido <span style="font-weight:400;text-transform:none">(opcional)</span></label>
                <input
                    type="text"
                    id="budget"
                    name="budget"
                    placeholder="Ej: $5.000, $10.000, lo que puedas"
                    value="<?= htmlspecialchars($_POST['budget'] ?? '') ?>"
                    maxlength="50"
                    autocomplete="off"
                >
            </div>
            <div class="form-group">
                <label for="organizer">Tu nombre <span style="font-weight:400;text-transform:none">(opcional)</span></label>
                <input
                    type="text"
                    id="organizer"
                    name="organizer"
                    placeholder="Ej: Ariel"
                    value="<?= htmlspecialchars($_POST['organizer'] ?? '') ?>"
                    maxlength="60"
                    autocomplete="off"
                >
            </div>
            <div class="form-group">
                <label for="participants">Participantes <span style="font-weight:400;text-transform:none">(mínimo 3)</span></label>
                <textarea
                    id="participants"
                    name="participants"
                    rows="5"
                    placeholder="Un nombre por línea, o separados por coma:&#10;Carlos&#10;María&#10;Juan&#10;Ana"
                    required
                ><?= htmlspecialchars($_POST['participants'] ?? '') ?></textarea>
                <p class="hint">Un nombre por línea o separados por coma.</p>
            </div>

            <button type="submit" class="btn btn-primary" id="submit-btn">
                <span id="submit-label">🎲 Sortear</span>
            </button>
        </form>
    </div>

</main>

<footer>
    <a href="https://mammoli.ar" target="_blank" rel="noopener">mammoli.ar</a>
    <span class="sep">·</span>
    <a href="https://cafecito.app/mammoli" target="_blank" rel="noopener">Invitame un cafecito</a>
</footer>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- SORTIE PAGE                                                            -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

<header class="header">
    <div class="header-inner">
        <a href="/amigo/" class="header-logo" aria-label="Inicio">🎁</a>
        <div class="header-title">
            <h1><?= $group_name ?></h1>
            <p class="subtitle">
                <?= $n_parts ?> participantes<?= $budget_str ? ' · Presupuesto: ' . $budget_str : '' ?>
            </p>
        </div>
    </div>
</header>

<main class="main">

    <!-- Share link card -->
    <div class="card share-card">
        <div class="section-title">Link del organizador</div>
        <div class="share-url">
            <span class="url-text" id="share-url-text" title="Clic para copiar"><?= $canonical ?></span>
            <button class="btn-copy" id="btn-copy-url" onclick="copyUrl()">Copiar</button>
        </div>
        <p class="share-note">
            <span>ℹ️</span>
            <span>Guardá este link para reenviar si alguien no recibió su mensaje.</span>
        </p>
    </div>

    <!-- Assignments -->
    <div>
        <div class="section-title">Asignaciones</div>
        <div class="assignment-list" id="assignment-list">
            <?php foreach ($assignments as $i => $asgn):
                $color = $colors[$i % count($colors)];
                $giver_id   = (int)$asgn['giver_id'];
                $giver_name = htmlspecialchars($asgn['giver_name']);
                $recv_name  = htmlspecialchars($asgn['receiver_name']);
                $color_hex  = htmlspecialchars($color);
            ?>
            <div class="assignment-card" id="card-<?= $giver_id ?>" style="border-left-color: <?= $color_hex ?>">
                <div class="assignment-card-top">
                    <span class="participant-dot" style="background: <?= $color_hex ?>"></span>
                    <span class="giver-name"><?= $giver_name ?></span>
                </div>
                <div class="receiver-row">
                    <span class="receiver-label">Regala a</span>
                    <span class="receiver-name"><?= $recv_name ?></span>
                </div>
                <button
                    class="btn-wa"
                    id="btn-wa-<?= $giver_id ?>"
                    data-giver-id="<?= $giver_id ?>"
                    onclick="sendWA(<?= $giver_id ?>)"
                >
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                    </svg>
                    <span id="btn-wa-label-<?= $giver_id ?>">Enviar a <?= $giver_name ?></span>
                </button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Regenerate -->
    <div class="regen-section card">
        <div class="section-title">¿Querés volver a sortear?</div>
        <button class="btn btn-ghost btn-full" onclick="confirmRegen()">
            🔀 Volver a sortear
        </button>
        <p class="regen-note">Los mensajes ya enviados quedarán desactualizados.</p>
    </div>

</main>

<footer>
    <a href="https://mammoli.ar" target="_blank" rel="noopener">mammoli.ar</a>
    <span class="sep">·</span>
    <a href="https://cafecito.app/mammoli" target="_blank" rel="noopener">Invitame un cafecito</a>
</footer>

<!-- Confirm regenerate sheet -->
<div class="confirm-overlay" id="confirm-overlay" onclick="closeConfirm(event)">
    <div class="confirm-sheet" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
        <h3 id="confirm-title">🔀 Volver a sortear</h3>
        <p>¿Rehacés el sorteo? Los mensajes ya enviados quedan desactualizados y no van a coincidir con el nuevo sorteo.</p>
        <div class="confirm-actions">
            <button class="btn btn-ghost" onclick="closeConfirm()">Cancelar</button>
            <button class="btn btn-danger" id="btn-confirm-regen" onclick="doRegen()">Sí, volver a sortear</button>
        </div>
    </div>
</div>

<?php endif; ?>

</div><!-- .app -->

<!-- Toast -->
<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script>
// ── Shared utilities ──────────────────────────────────────────────────────

function showToast(msg, duration) {
    duration = duration || 2200;
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, duration);
}

<?php if (!$sortie_data): ?>
// ── Landing page JS ───────────────────────────────────────────────────────

(function() {
    var form     = document.getElementById('landing-form');
    var submitBtn = document.getElementById('submit-btn');
    var submitLabel = document.getElementById('submit-label');
    var partsArea = document.getElementById('participants');

    form.addEventListener('submit', function(e) {
        var name  = document.getElementById('name').value.trim();
        var parts = partsArea.value.trim();

        if (!name) {
            e.preventDefault();
            showToast('Escribí un nombre para el grupo.');
            document.getElementById('name').focus();
            return;
        }

        // Parse parts to count
        var lines = parts.split(/[\r\n,]+/).map(function(s){ return s.trim(); }).filter(Boolean);
        var unique = [];
        var seen = {};
        lines.forEach(function(l) {
            var k = l.toLowerCase();
            if (!seen[k]) { seen[k] = true; unique.push(l); }
        });

        if (unique.length < 3) {
            e.preventDefault();
            showToast('Necesitás al menos 3 participantes.');
            partsArea.focus();
            return;
        }

        // Show loading state
        submitBtn.disabled = true;
        submitLabel.innerHTML = '<span class="spinner"></span> Sorteando...';
    });
})();

<?php else: ?>
// ── Sortie page JS ────────────────────────────────────────────────────────

var SORTIE_ID  = <?= $js_sortie_id ?>;
var ASSIGNMENTS = <?= $js_assignments ?>;
var GROUP_NAME = <?= $js_group_name ?>;
var BUDGET     = <?= $js_budget ?>;
var ORGANIZER  = <?= $js_organizer ?>;

var LS_KEY = 'amigo_sent_' + SORTIE_ID;

// ── localStorage sent state ───────────────────────────────────────────────

function getSentIds() {
    try {
        var raw = localStorage.getItem(LS_KEY);
        return raw ? JSON.parse(raw) : [];
    } catch(e) {
        return [];
    }
}

function markSent(giverId) {
    try {
        var ids = getSentIds();
        if (ids.indexOf(giverId) === -1) ids.push(giverId);
        localStorage.setItem(LS_KEY, JSON.stringify(ids));
    } catch(e) {}
}

function applyBtnState(giverId, sent) {
    var btn   = document.getElementById('btn-wa-' + giverId);
    var label = document.getElementById('btn-wa-label-' + giverId);
    if (!btn) return;

    // Find giver name
    var giverName = '';
    for (var i = 0; i < ASSIGNMENTS.length; i++) {
        if (parseInt(ASSIGNMENTS[i].giver_id) === giverId) {
            giverName = ASSIGNMENTS[i].giver_name;
            break;
        }
    }

    if (sent) {
        btn.classList.add('sent');
        label.textContent = '✓ Enviado a ' + giverName;
        btn.onclick = null;
    } else {
        btn.classList.remove('sent');
        label.textContent = 'Enviar a ' + giverName;
        btn.onclick = function() { sendWA(giverId); };
    }
}

// Restore sent state on load
(function() {
    var sentIds = getSentIds();
    sentIds.forEach(function(id) { applyBtnState(parseInt(id), true); });
})();

// ── WhatsApp send ─────────────────────────────────────────────────────────

function buildWAMessage(giverName, receiverName) {
    var msg = '🎁 Amigo Invisible — ' + GROUP_NAME + '\n\n';
    msg += 'Hola ' + giverName + '! Tu amigo invisible es: *' + receiverName + '* 🎁\n';
    if (BUDGET) {
        msg += 'Presupuesto sugerido: ' + BUDGET + '\n';
    }
    msg += '\n¡No se lo cuentes a nadie! 🤫\n';
    msg += '— ' + (ORGANIZER || 'El organizador');
    return msg;
}

function sendWA(giverId) {
    // Find assignment
    var asgn = null;
    for (var i = 0; i < ASSIGNMENTS.length; i++) {
        if (parseInt(ASSIGNMENTS[i].giver_id) === giverId) {
            asgn = ASSIGNMENTS[i];
            break;
        }
    }
    if (!asgn) return;

    var msg = buildWAMessage(asgn.giver_name, asgn.receiver_name);
    var url = 'https://wa.me/?text=' + encodeURIComponent(msg);

    // Open WhatsApp
    window.open(url, '_blank', 'noopener,noreferrer');

    // Mark as sent
    markSent(giverId);
    applyBtnState(giverId, true);
    showToast('Mensaje preparado para ' + asgn.giver_name + ' ✅');
}

// ── Copy URL ──────────────────────────────────────────────────────────────

function copyUrl() {
    var url = window.location.href;
    var btn = document.getElementById('btn-copy-url');

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function() {
            btn.textContent = '✓ Copiado';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.textContent = 'Copiar';
                btn.classList.remove('copied');
            }, 2000);
        }).catch(function() {
            fallbackCopy(url, btn);
        });
    } else {
        fallbackCopy(url, btn);
    }
}

function fallbackCopy(text, btn) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try {
        document.execCommand('copy');
        btn.textContent = '✓ Copiado';
        btn.classList.add('copied');
        setTimeout(function() {
            btn.textContent = 'Copiar';
            btn.classList.remove('copied');
        }, 2000);
    } catch(e) {
        showToast('No se pudo copiar. Seleccioná el link manualmente.');
    }
    document.body.removeChild(ta);
}

// ── Regenerate ────────────────────────────────────────────────────────────

function confirmRegen() {
    document.getElementById('confirm-overlay').classList.add('visible');
}

function closeConfirm(e) {
    if (e && e.target !== document.getElementById('confirm-overlay')) return;
    document.getElementById('confirm-overlay').classList.remove('visible');
}

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeConfirm({ target: document.getElementById('confirm-overlay') });
});

function doRegen() {
    var btn = document.getElementById('btn-confirm-regen');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner" style="border-color:rgba(255,255,255,.4);border-top-color:#fff;"></span> Sorteando...';

    fetch('/amigo/api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'regenerate', s: SORTIE_ID })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.ok) {
            showToast('Error: ' + (data.error || 'No se pudo regenerar.'));
            btn.disabled = false;
            btn.textContent = 'Sí, volver a sortear';
            return;
        }
        // Clear sent state for this sortie
        try { localStorage.removeItem(LS_KEY); } catch(e) {}
        // Reload page to show new assignments
        window.location.reload();
    })
    .catch(function() {
        showToast('Error de red. Intentá de nuevo.');
        btn.disabled = false;
        btn.textContent = 'Sí, volver a sortear';
    });
}

<?php endif; ?>
</script>
</body>
</html>
