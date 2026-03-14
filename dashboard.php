<?php
// ═══════════════════════════════════════════════════════
//  DASHBOARD OPERATORI — AIRA-DXTM / Checkup SV
// ═══════════════════════════════════════════════════════
define('SUPABASE_URL', 'https://eywxidahsumzrlzwnfev.supabase.co');
define('SUPABASE_URL', 'https://eywxidahsumzrlzwnfev.supabase.co');
define('SESSION_COOKIE', 'sv_operator_session');
define('SESSION_DAYS', 14);
define('SENDGRID_FROM', 'no-reply@migastone.com');

// Le API keys sono state spostate per sicurezza
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    // Fallback locale in caso mancasse il file in dev
    define('SUPABASE_KEY', '');
    define('SENDGRID_KEY', '');
    define('PERPLEXITY_KEY', '');
    define('OTP_PASSPARTOUT', '220783');
    define('DASH_SECRET', 'sv-dash-2026-migastone');
}

// ─── HELPERS ────────────────────────────────────────────

function sb_get($path)
{
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Content-Type: application/json',
        ],
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return json_decode($r, true) ?: [];
}

function sb_post($path, $data)
{
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Content-Type: application/json',
            'Prefer: return=minimal',
        ],
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function sb_patch($path, $data)
{
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Content-Type: application/json',
            'Prefer: return=minimal',
        ],
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function send_otp_email($to_email, $otp)
{
    $body = json_encode([
        'personalizations' => [
            [
                'to' => [['email' => $to_email]],
                'subject' => 'Il tuo codice di accesso Dashboard SV',
            ]
        ],
        'from' => ['email' => SENDGRID_FROM, 'name' => 'AIRA-DXTM'],
        'content' => [
            [
                'type' => 'text/plain',
                'value' => "Il tuo codice di accesso alla Dashboard Operatori è:\n\n  {$otp}\n\nIl codice scade tra 15 minuti.\n\nSe non hai richiesto questo codice, ignora questa email.",
            ]
        ],
    ]);
    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . SENDGRID_KEY,
            'Content-Type: application/json',
        ],
    ]);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_exec($ch);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function fmt_date_it($iso)
{
    if (!$iso)
        return '—';
    try {
        $dt = new DateTime($iso);
        $dt->setTimezone(new DateTimeZone('Europe/Rome'));
        return $dt->format('d/m/Y H:i');
    } catch (Exception $e) {
        return $iso;
    }
}

// ─── Token HMAC per bypass OTP dalla dashboard ────────────
// Valido per l'ora corrente + quella precedente (58 min margine)
function dash_token(string $id): string
{
    $slot = (int) (time() / 3600);
    return substr(hash_hmac('sha256', $id . '|' . $slot, DASH_SECRET), 0, 16);
}

function verify_dash_token(string $id, string $token): bool
{
    $slot = (int) (time() / 3600);
    foreach ([$slot, $slot - 1] as $s) {
        $expected = substr(hash_hmac('sha256', $id . '|' . $s, DASH_SECRET), 0, 16);
        if (hash_equals($expected, $token))
            return true;
    }
    return false;
}

function clean_phone($num)
{
    return preg_replace('/[^0-9]/', '', ltrim(trim($num), '+'));
}

// ─── SESSION CHECK ───────────────────────────────────────

$operator = null;  // array with 'nome','email','cellulare' when authenticated
$login_error = '';
$login_step = 'email'; // 'email' | 'otp'
$login_email = '';

// Check persistent cookie
if (!$operator && isset($_COOKIE[SESSION_COOKIE])) {
    $token = $_COOKIE[SESSION_COOKIE];
    $rows = sb_get('operator_sessions?session_token=eq.' . urlencode($token) . '&select=operator_email');
    if (!empty($rows)) {
        $op_email = $rows[0]['operator_email'];
        $op_rows = sb_get('operatori_sv?email=eq.' . urlencode($op_email) . '&attivo=eq.true&select=nome,email,cellulare');
        if (!empty($op_rows)) {
            $operator = $op_rows[0];
            // Refresh last_seen_at
            sb_patch(
                'operator_sessions?session_token=eq.' . urlencode($token),
                ['last_seen_at' => gmdate('Y-m-d\TH:i:s\Z')]
            );
        }
    }
}

// ─── LOGOUT ─────────────────────────────────────────────

if (!$operator && isset($_GET['logout'])) {
    // won't reach here without operator, but handle anyway
}
if ($operator && isset($_GET['logout'])) {
    // Delete session from DB
    if (isset($_COOKIE[SESSION_COOKIE])) {
        $ch = curl_init(SUPABASE_URL . '/rest/v1/operator_sessions?session_token=eq.' . urlencode($_COOKIE[SESSION_COOKIE]));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . SUPABASE_KEY,
                'Authorization: Bearer ' . SUPABASE_KEY,
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
    setcookie(SESSION_COOKIE, '', time() - 3600, '/', '', false, true);
    header('Location: dashboard.php');
    exit;
}

// ─── LOGIN FLOW ──────────────────────────────────────────

if (!$operator) {

    // STEP 1 — Email submit
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['op_email']) && !isset($_POST['op_otp'])) {
        $email_input = strtolower(trim($_POST['op_email']));
        // Check operator exists and is active
        $op_check = sb_get('operatori_sv?email=eq.' . urlencode($email_input) . '&attivo=eq.true&select=nome,email,cellulare');
        if (empty($op_check)) {
            $login_error = 'Email non riconosciuta o account non attivo.';
            $login_step = 'email';
        } else {
            // Generate OTP
            $otp_plain = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $otp_hashed = password_hash($otp_plain, PASSWORD_DEFAULT);
            $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 900); // 15 min
            sb_post('operator_otp', [
                'operator_email' => $email_input,
                'otp_hash' => $otp_hashed,
                'expires_at' => $expires,
                'used' => false,
            ]);
            send_otp_email($email_input, $otp_plain);
            $login_step = 'otp';
            $login_email = $email_input;
        }
    }

    // STEP 2 — OTP submit
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['op_otp']) && isset($_POST['op_email_hidden'])) {
        $email_input = strtolower(trim($_POST['op_email_hidden']));
        $otp_input = trim($_POST['op_otp']);
        $login_email = $email_input;
        $login_step = 'otp';

        $is_passpartout = ($otp_input === OTP_PASSPARTOUT);
        $otp_valid = false;

        if (!$is_passpartout) {
            // Fetch most recent unused non-expired OTP for this email
            $otp_rows = sb_get(
                'operator_otp?operator_email=eq.' . urlencode($email_input)
                . '&used=eq.false&order=created_at.desc&limit=5&select=id,otp_hash,expires_at'
            );
            $now = new DateTime('now', new DateTimeZone('UTC'));
            foreach ($otp_rows as $orow) {
                $exp = new DateTime($orow['expires_at']);
                if ($now < $exp && password_verify($otp_input, $orow['otp_hash'])) {
                    // Mark as used
                    sb_patch('operator_otp?id=eq.' . $orow['id'], ['used' => true]);
                    $otp_valid = true;
                    break;
                }
            }
        } else {
            $otp_valid = true;
        }

        if ($otp_valid) {
            // Check operator still active
            $op_rows = sb_get('operatori_sv?email=eq.' . urlencode($email_input) . '&attivo=eq.true&select=nome,email,cellulare');
            if (!empty($op_rows)) {
                $operator = $op_rows[0];
                // Create persistent session cookie
                $token = bin2hex(random_bytes(32));
                sb_post('operator_sessions', [
                    'operator_email' => $email_input,
                    'session_token' => $token,
                ]);
                $cookie_opts = [
                    'expires' => time() + SESSION_DAYS * 86400,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Strict',
                ];
                setcookie(SESSION_COOKIE, $token, $cookie_opts);
                header('Location: dashboard.php');
                exit;
            } else {
                $login_error = 'Operatore non trovato o non attivo.';
            }
        } else {
            $login_error = 'Codice non valido o scaduto. Riprova.';
        }
    }
}

// ─── DASHBOARD DATA (only when authenticated) ─────────────────────────────

$records = [];
$operators = [];
$filter_op = '';

if ($operator) {
    // Load all active operators for filter dropdown
    $operators = sb_get('operatori_sv?attivo=eq.true&select=nome,email&order=nome.asc');

    // Filter
    $filter_op = trim($_GET['op'] ?? $operator['email']);

    $q = 'Checkup_SV?select=id,created_at,consulente_nome,consulente_email,cliente_nome,cliente_email,cliente_azienda,cliente_partita_iva,cliente_cellulare,ferita_principale_label,gap_totale,livello_maturita,trascrizione,analisi_call,offerta_doc_url,offerta_generata_at,offerta_in_elaborazione&order=created_at.desc';
    if ($filter_op && $filter_op !== 'all') {
        $q .= '&consulente_email=eq.' . urlencode($filter_op);
    }
    $records = sb_get($q);
}


// ─── AJAX: save trascrizione ─────────────────────────────
// Called as POST with ?ajax=trascrizione
if ($operator && isset($_GET['ajax']) && $_GET['ajax'] === 'trascrizione' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);
    $rid = preg_replace('/[^a-f0-9\-]/', '', $body['id'] ?? '');
    $text = $body['trascrizione'] ?? '';
    if (!$rid) {
        echo json_encode(['ok' => false, 'msg' => 'ID non valido']);
        exit;
    }
    // Salva trascrizione + azzera analisi precedente (verrà rigenerata dal frontend)
    $ok = sb_patch('Checkup_SV?id=eq.' . $rid, ['trascrizione' => $text, 'analisi_call' => null]);
    echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Salvato correttamente.' : 'Errore durante il salvataggio.']);
    exit;
}

// ─── AJAX: Get Full Checkup Data ────────────────────────────────
if ($operator && isset($_GET['ajax']) && $_GET['ajax'] === 'get_checkup_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    $rid = preg_replace('/[^a-f0-9\-]/', '', $_GET['id'] ?? '');
    if (!$rid) {
        echo json_encode(['ok' => false]);
        exit;
    }
    $rows = sb_get('Checkup_SV?id=eq.' . $rid);
    if (!empty($rows)) {
        $row = $rows[0];

        // Appiattisce risposte aree (JSONB → chiavi flat con suffisso _risposta)
        $area_map = [
            'risposte_area1' => ['a1_q1', 'a1_q2', 'a1_q3', 'a1_q4', 'a1_q5', 'a1_q6'],
            'risposte_area2' => ['a2_q1', 'a2_q2_ore', 'a2_q2_costo', 'a2_q3', 'a2_q4', 'a2_q5'],
            'risposte_area3' => ['a3_q1', 'a3_q2', 'a3_q3', 'a3_q4', 'a3_q5'],
            'risposte_area4' => ['a4_q1', 'a4_q2_perc', 'a4_q2_trattative', 'a4_q3', 'a4_q4', 'a4_q5'],
        ];
        foreach ($area_map as $col => $keys) {
            $jsonb = $row[$col] ?? [];
            if (is_string($jsonb))
                $jsonb = json_decode($jsonb, true) ?? [];
            foreach ($keys as $k) {
                $is_num = in_array($k, ['a2_q2_ore', 'a2_q2_costo', 'a4_q2_perc', 'a4_q2_trattative']);
                $flat_key = $is_num ? $k : $k . '_risposta';
                $row[$flat_key] = $jsonb[$k] ?? '';
            }
        }

        // Appiattisce punteggi_realta e punteggi_desiderio → realta_areaN, desiderio_areaN
        $aree_keys = ['area1_relazionale', 'area2_automazione', 'area3_posizionamento', 'area4_crm'];
        $pr = $row['punteggi_realta'] ?? [];
        $pd = $row['punteggi_desiderio'] ?? [];
        if (is_string($pr))
            $pr = json_decode($pr, true) ?? [];
        if (is_string($pd))
            $pd = json_decode($pd, true) ?? [];
        foreach ($aree_keys as $ak) {
            $row['realta_' . $ak] = $pr[$ak] ?? '';
            $row['desiderio_' . $ak] = $pd[$ak] ?? '';
        }

        echo json_encode(['ok' => true, 'data' => $row]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

// ─── AJAX: Edit Checkup Data (Full) ─────────────────────────────
if ($operator && isset($_GET['ajax']) && $_GET['ajax'] === 'edit_checkup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);
    $rid = preg_replace('/[^a-f0-9\-]/', '', $body['id'] ?? '');

    if (!$rid) {
        echo json_encode(['ok' => false, 'msg' => 'ID mancante.']);
        exit;
    }

    $patch_data = [];

    // Campi scalari diretti
    $scalar_fields = ['cliente_nome', 'cliente_azienda', 'cliente_email', 'cliente_cellulare', 'cliente_sito_web', 'cliente_partita_iva', 'cliente_settore', 'cliente_fatturato', 'cliente_dipendenti'];
    foreach ($scalar_fields as $f) {
        if (array_key_exists($f, $body))
            $patch_data[$f] = $body[$f];
    }

    // Riassembla risposte_area1
    $a1 = [];
    foreach (['a1_q1', 'a1_q2', 'a1_q3', 'a1_q4', 'a1_q5', 'a1_q6'] as $k) {
        if (array_key_exists($k . '_risposta', $body))
            $a1[$k] = $body[$k . '_risposta'];
    }
    if (!empty($a1))
        $patch_data['risposte_area1'] = $a1;

    // Riassembla risposte_area2
    $a2 = [];
    foreach (['a2_q1', 'a2_q3', 'a2_q4', 'a2_q5'] as $k) {
        if (array_key_exists($k . '_risposta', $body))
            $a2[$k] = $body[$k . '_risposta'];
    }
    foreach (['a2_q2_ore', 'a2_q2_costo'] as $k) {
        if (array_key_exists($k, $body))
            $a2[$k] = is_numeric($body[$k]) ? (float) $body[$k] : $body[$k];
    }
    if (!empty($a2))
        $patch_data['risposte_area2'] = $a2;

    // Riassembla risposte_area3
    $a3 = [];
    foreach (['a3_q1', 'a3_q2', 'a3_q3', 'a3_q4', 'a3_q5'] as $k) {
        if (array_key_exists($k . '_risposta', $body))
            $a3[$k] = $body[$k . '_risposta'];
    }
    if (!empty($a3))
        $patch_data['risposte_area3'] = $a3;

    // Riassembla risposte_area4
    $a4 = [];
    foreach (['a4_q1', 'a4_q3', 'a4_q4', 'a4_q5'] as $k) {
        if (array_key_exists($k . '_risposta', $body))
            $a4[$k] = $body[$k . '_risposta'];
    }
    foreach (['a4_q2_perc', 'a4_q2_trattative'] as $k) {
        if (array_key_exists($k, $body))
            $a4[$k] = is_numeric($body[$k]) ? (float) $body[$k] : $body[$k];
    }
    if (!empty($a4))
        $patch_data['risposte_area4'] = $a4;

    // Riassembla punteggi_realta
    $aree_keys = ['area1_relazionale', 'area2_automazione', 'area3_posizionamento', 'area4_crm'];
    $pr = [];
    $pd = [];
    foreach ($aree_keys as $ak) {
        $rk = 'realta_' . $ak;
        $dk = 'desiderio_' . $ak;
        if (array_key_exists($rk, $body) && $body[$rk] !== '')
            $pr[$ak] = (int) $body[$rk];
        if (array_key_exists($dk, $body) && $body[$dk] !== '')
            $pd[$ak] = (int) $body[$dk];
    }
    if (!empty($pr))
        $patch_data['punteggi_realta'] = $pr;
    if (!empty($pd))
        $patch_data['punteggi_desiderio'] = $pd;

    if (empty($patch_data)) {
        echo json_encode(['ok' => false, 'msg' => 'Nessun campo da aggiornare.']);
        exit;
    }

    $res = sb_patch('Checkup_SV?id=eq.' . $rid, $patch_data);
    if ($res !== false) {
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Impossibile aggiornare i dati.']);
    }
    exit;
}

// ─── AJAX: poll stato offerta ────────────────────────────────────────────────
if ($operator && isset($_GET['ajax']) && $_GET['ajax'] === 'poll_offerta' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    $rid = preg_replace('/[^a-f0-9\-]/', '', $_GET['id'] ?? '');
    if (!$rid) {
        echo json_encode(['in_elaborazione' => false, 'doc_url' => null]);
        exit;
    }

    $rows = sb_get('Checkup_SV?id=eq.' . $rid . '&select=offerta_doc_url,offerta_in_elaborazione');
    $row = $rows[0] ?? [];
    echo json_encode([
        'in_elaborazione' => (bool) ($row['offerta_in_elaborazione'] ?? false),
        'doc_url' => $row['offerta_doc_url'] ?? null,
    ]);
    exit;
}

// ─── AJAX: genera offerta → chiama webhook N8n ──────────────────────────────
if ($operator && isset($_GET['ajax']) && $_GET['ajax'] === 'genera_offerta' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);

    $checkup_id = preg_replace('/[^a-f0-9\-]/', '', $body['checkup_id'] ?? '');
    if (!$checkup_id) {
        echo json_encode(['success' => false, 'error' => 'ID checkup non valido']);
        exit;
    }

    $payload = [
        'id' => $checkup_id,
        'checkup_id' => $checkup_id,
        'consulente' => $operator['nome'] ?? '',
        'includi_app' => $body['includi_app'] ?? 'NO',
        'includi_app_pro' => $body['includi_app_pro'] ?? 'NO',
        'includi_scouting' => $body['includi_scouting'] ?? 'NO',
        'includi_automazione' => $body['includi_automazione'] ?? 'NO',
        'includi_migacrm' => $body['includi_migacrm'] ?? 'NO',
        'includi_enterprise' => $body['includi_enterprise'] ?? 'NO',
        'importo_app' => (float) ($body['importo_app'] ?? 0),
        'importo_app_pro' => (float) ($body['importo_app_pro'] ?? 0),
        'importo_scouting' => (float) ($body['importo_scouting'] ?? 0),
        'importo_automazione' => (float) ($body['importo_automazione'] ?? 0),
        'importo_migacrm' => (float) ($body['importo_migacrm'] ?? 0),
        'importo_enterprise' => (float) ($body['importo_enterprise'] ?? 0),
        'desc_automazione' => substr(strip_tags($body['desc_automazione'] ?? ''), 0, 500),
        'totale' => (float) ($body['totale'] ?? 0),
    ];

    // Segna in Supabase che l'offerta è in elaborazione (resetta url precedente)
    sb_patch('Checkup_SV?id=eq.' . $checkup_id, [
        'offerta_in_elaborazione' => true,
        'offerta_doc_url' => null,
        'offerta_generata_at' => null,
    ]);

    // Webhook N8n (da configurare in config.php quando il workflow è pronto)
    $webhook_url = defined('N8N_OFFERTA_WEBHOOK') ? N8N_OFFERTA_WEBHOOK : '';

    if (!$webhook_url) {
        // Webhook non ancora configurato — l'icona grigia rimane attiva per test
        echo json_encode([
            'success' => true,
            'warning' => 'Webhook N8n non ancora configurato. Aggiungi N8N_OFFERTA_WEBHOOK in config.php.',
            'payload' => $payload,
        ]);
        exit;
    }

    $ch = curl_init($webhook_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err_curl = curl_error($ch);
    curl_close($ch);

    if ($err_curl) {
        echo json_encode(['success' => false, 'error' => 'Errore cURL: ' . $err_curl]);
        exit;
    }

    $resp_data = json_decode($response, true);
    if ($code >= 200 && $code < 300 && !empty($resp_data['doc_url'])) {
        // Workflow sincrono: ha già il doc pronto
        echo json_encode(['success' => true, 'doc_url' => $resp_data['doc_url']]);
    } elseif ($code >= 200 && $code < 300 && !empty($resp_data['success'])) {
        // Workflow asincrono: avviato correttamente, doc_url arriverà via polling
        echo json_encode(['success' => true, 'polling' => true]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $resp_data['error'] ?? 'Errore generazione (HTTP ' . $code . ')',
        ]);
    }
    exit;
}

// ─── AJAX: delete record ─────────────────────────────────
if ($operator && isset($_GET['ajax']) && $_GET['ajax'] === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);
    $rid = preg_replace('/[^a-f0-9\-]/', '', $body['id'] ?? '');
    if (!$rid) {
        echo json_encode(['ok' => false, 'msg' => 'ID non valido']);
        exit;
    }
    $ch = curl_init(SUPABASE_URL . '/rest/v1/Checkup_SV?id=eq.' . $rid);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ],
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ok = $code >= 200 && $code < 300;
    echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Record eliminato.' : 'Errore durante l\'eliminazione.']);
    exit;
}

// ─── AJAX: analisi call (Perplexity) ─────────────────────
if ($operator && isset($_GET['ajax']) && $_GET['ajax'] === 'analisi_call' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);
    $rid = preg_replace('/[^a-f0-9\-]/', '', $body['id'] ?? '');
    if (!$rid) {
        echo json_encode(['ok' => false, 'msg' => 'ID non valido']);
        exit;
    }
    // Fetch record completo
    $rows = sb_get('Checkup_SV?id=eq.' . $rid . '&select=*');
    if (empty($rows)) {
        echo json_encode(['ok' => false, 'msg' => 'Record non trovato.']);
        exit;
    }
    $rec = $rows[0];

    // ─── Istruzioni call prequalifica (file statico) ──────
    $istruzioni = file_get_contents(__DIR__ . '/01_Call Prequalifica 4Aree - ISTRUZIONI.md');
    if (!$istruzioni)
        $istruzioni = '[File istruzioni non trovato]';

    // ─── Componi i dati del checkup ──────────────────────
    function fmt_json_field($data, $label)
    {
        if (empty($data))
            return "$label: (non compilato)";
        if (is_array($data)) {
            $out = "$label:\n";
            foreach ($data as $k => $v)
                $out .= "  - $k: $v\n";
            return $out;
        }
        return "$label: $data";
    }

    $area_labels = [
        'area1_relazionale' => 'Area 1 - Ingegneria Relazionale',
        'area2_automazione' => 'Area 2 - Automazione e Processi',
        'area3_posizionamento' => 'Area 3 - Posizionamento e Marketing',
        'area4_crm' => 'Area 4 - CRM e Pipeline',
    ];

    $dati_checkup = "";
    $dati_checkup .= "=== DATI AZIENDALI ===\n";
    $dati_checkup .= "Consulente: " . ($rec['consulente_nome'] ?? '—') . " (" . ($rec['consulente_email'] ?? '') . ")\n";
    $dati_checkup .= "Cliente: " . ($rec['cliente_nome'] ?? '—') . "\n";
    $dati_checkup .= "Azienda: " . ($rec['cliente_azienda'] ?? '—') . "\n";
    $dati_checkup .= "Email: " . ($rec['cliente_email'] ?? '—') . "\n";
    $dati_checkup .= "Cellulare: " . ($rec['cliente_cellulare'] ?? '—') . "\n";
    $dati_checkup .= "Sito web: " . ($rec['cliente_sito_web'] ?? '—') . "\n";
    $dati_checkup .= "Settore: " . ($rec['cliente_settore'] ?? '—') . "\n";
    $dati_checkup .= "Fatturato: " . ($rec['cliente_fatturato'] ?? '—') . "\n";
    $dati_checkup .= "Dipendenti: " . ($rec['cliente_dipendenti'] ?? '—') . "\n";
    $dati_checkup .= "Partita IVA: " . ($rec['cliente_partita_iva'] ?? '—') . "\n";
    $dati_checkup .= "Prodotto/servizio principale: " . ($rec['prodotto_principale'] ?? '—') . "\n";
    $dati_checkup .= "Clienti attivi: " . ($rec['clienti_attivi'] ?? '—') . "\n";
    $dati_checkup .= "Database storico totale: " . ($rec['database_storico'] ?? '—') . "\n";
    $dati_checkup .= "Venditori interni: " . ($rec['venditori_interni'] ?? '—') . "\n";
    $dati_checkup .= "LTV annualizzato: € " . number_format((float) ($rec['ltv_annualizzato'] ?? 0), 0, ',', '.') . "\n";
    $dati_checkup .= "% conversione lead freddo: " . ($rec['conversione_freddo'] ?? '—') . "%\n";
    $dati_checkup .= "% conversione lead referenziato: " . ($rec['conversione_referral'] ?? '—') . "%\n";

    $dati_checkup .= "\n=== KPI CALCOLATI ===\n";
    $dati_checkup .= "Potenziale referral dormiente: € " . number_format((float) ($rec['potenziale_referral_dormiente'] ?? 0), 0, ',', '.') . "\n";
    $dati_checkup .= "Delta conversione mensile: € " . number_format((float) ($rec['delta_conversione_mensile'] ?? 0), 0, ',', '.') . "\n";
    $dati_checkup .= "Costo inefficienza annua: € " . number_format((float) ($rec['costo_inefficienza_annua'] ?? 0), 0, ',', '.') . "\n";
    $dati_checkup .= "Perdita pipeline annua: € " . number_format((float) ($rec['perdita_pipeline_annua'] ?? 0), 0, ',', '.') . "\n";

    $dati_checkup .= "\n=== SCORING PER AREA ===\n";
    $realta = is_array($rec['punteggi_realta']) ? $rec['punteggi_realta'] : (json_decode($rec['punteggi_realta'] ?? '{}', true) ?: []);
    $desiderio = is_array($rec['punteggi_desiderio']) ? $rec['punteggi_desiderio'] : (json_decode($rec['punteggi_desiderio'] ?? '{}', true) ?: []);
    $gap_area = is_array($rec['gap_per_area']) ? $rec['gap_per_area'] : (json_decode($rec['gap_per_area'] ?? '{}', true) ?: []);
    foreach ($area_labels as $k => $lbl) {
        $r = $realta[$k] ?? '?';
        $d = $desiderio[$k] ?? '?';
        $g = $gap_area[$k] ?? '?';
        $dati_checkup .= "$lbl → Realtà: $r/10 | Desiderio: $d/10 | Gap: $g\n";
    }
    $dati_checkup .= "Gap totale: " . ($rec['gap_totale'] ?? '—') . "\n";
    $dati_checkup .= "Ferita principale: " . ($rec['ferita_principale_label'] ?? '—') . "\n";
    $dati_checkup .= "Livello maturità: " . ($rec['livello_maturita'] ?? '—') . "/10 — " . ($rec['livello_maturita_label'] ?? '') . "\n";

    $dati_checkup .= "\n=== RISPOSTE QUALITATIVE ===\n";
    $r1 = is_array($rec['risposte_area1']) ? $rec['risposte_area1'] : (json_decode($rec['risposte_area1'] ?? '{}', true) ?: []);
    $r2 = is_array($rec['risposte_area2']) ? $rec['risposte_area2'] : (json_decode($rec['risposte_area2'] ?? '{}', true) ?: []);
    $r3 = is_array($rec['risposte_area3']) ? $rec['risposte_area3'] : (json_decode($rec['risposte_area3'] ?? '{}', true) ?: []);
    $r4 = is_array($rec['risposte_area4']) ? $rec['risposte_area4'] : (json_decode($rec['risposte_area4'] ?? '{}', true) ?: []);
    foreach ([1 => $r1, 2 => $r2, 3 => $r3, 4 => $r4] as $n => $risposte) {
        $dati_checkup .= "-- Area $n --\n";
        if (!empty($risposte)) {
            foreach ($risposte as $k => $v) {
                if ($v)
                    $dati_checkup .= "  [$k]: $v\n";
            }
        } else {
            $dati_checkup .= "  (nessuna risposta inserita)\n";
        }
    }

    $dati_checkup .= "\n=== NOTE CONSULENTE ===\n" . ($rec['note_consulente'] ?? '(vuote)') . "\n";
    $dati_checkup .= "\n=== TRASCRIZIONE CALL ===\n" . (trim($rec['trascrizione'] ?? '') ?: '(trascrizione non inserita)') . "\n";

    // ─── Prompt finale ────────────────────────────────────
    $system_prompt = <<<'PROMPT'
Agisci come un Coach di vendita B2B specializzato nel "Metodo Stoico della Prequalifica". Il tuo framework si basa sulla DICOTOMIA DEL CONTROLLO: spostare il focus del prospect da cio' che subisce (il caos esterno) a cio' che controlla (le leve interne). Hai come obiettivo primario FAR CRESCERE il consulente — valorizzando ogni suo successo e guidandolo con precisione chirurgica sulle aree di miglioramento.

Il tuo compito e' analizzare la call di prequalifica sulle 4 AREE DIAGNOSTICHE:
- Area 1: Ingegneria Relazionale (capitale referral dormiente)
- Area 2: Automazione e Processi (tempo bruciato in attivita' manuali)
- Area 3: Posizionamento e Marketing (guerra dei prezzi vs autorita')
- Area 4: CRM e Gestione Pipeline (fragilita' commerciale)

FASE 0: CONTROLLO INFORMAZIONI
Prima di iniziare, verifica se il materiale e' sufficiente. Se mancano informazioni cruciali, elenca le domande specifiche e indica che devono essere inserite nel campo TRASCRIZIONE.
Se i punteggi Realta' e Desiderio sono tutti uguali (es. 5/5) con Gap = 0 su tutte le aree, significa che il consulente NON ha fatto le domande REALTÀ-DESIDERIO: segnalalo chiaramente e penalizza il voto di 2 punti.
Se le informazioni sono sufficienti, rivolgiti direttamente al consulente dandogli del "tu" e struttura l'analisi cosi':

─────────────────────────────────────────────
1. PUNTI DI FORZA — CIO' CHE HAI FATTO BENE
─────────────────────────────────────────────
Questa e' la sezione piu' importante e valorizzante. Celebra le cose positive che il consulente ha fatto. Sii generoso e motivante. Spiega PERCHE' le scelte sono state efficaci e quale impatto hanno avuto sul prospect, connettendo il comportamento al metodo corretto.
IMPORTANTE: In questa sezione (e nelle sezioni 2, 3, 4, 5) NON citare esempi specifici dalla trascrizione. Mantieni un livello di analisi macro e discorsivo.

─────────────────────────────────────────────
2. FRAME DI APERTURA E INVERSIONE DEL POTERE
─────────────────────────────────────────────
Valuta se il consulente ha applicato correttamente:
- L'INVERSIONE DEL POTERE: "Non stiamo vendendo, stiamo valutando se l'azienda merita l'accesso alla nostra rete"
- La SCARSITA': "Non tutti vengono accettati. I nostri professionisti ci mettono la faccia."
- Il POSIZIONAMENTO: "Rappresentiamo 2.000 professionisti che rischiano la propria reputazione"
Ha chiesto il "tu"? Ha impostato la call come valutazione, non come vendita? Il prospect ha sentito che c'e' qualcosa da guadagnare o perdere?

─────────────────────────────────────────────
3. RACCOLTA DATI BASE E DIAGNOSI 4 AREE
─────────────────────────────────────────────
Valuta la completezza dei dati raccolti nel BLOCCO 0:
- Dati aziendali (settore, fatturato, dipendenti, venditori interni)
- KPI critici (LTV annualizzato, % conversione freddo vs referenziato, clienti attivi, database storico)
Ha usato il tono corretto? ("Dammi un ordine di grandezza, non serve la precisione assoluta")

Per OGNI delle 4 AREE valuta:
a) Ha fatto le DOMANDE DIAGNOSTICHE corrette per far emergere il "sintomo" (l'ansia)?
b) Ha raccolto il punteggio REALTA'? ("Da 1 a 10, com'e' la situazione attuale?")
c) Ha raccolto il punteggio DESIDERIO? ("Da 1 a 10, quanto e' importante sistemarla?")
d) Ha fatto emergere il GAP come "ferita" strategica?
e) Ha applicato l'ANALISI STOICA facendo capire cosa e' SOTTO IL CONTROLLO del prospect?

─────────────────────────────────────────────
4. COSTO DELL'INAZIONE E TRADUZIONE IN EURO
─────────────────────────────────────────────
Ha trasformato i "sintomi" in costi economici concreti? Ha quantificato il COSTO DELL'INAZIONE?
- Potenziale referral dormiente (clienti soddisfatti x 15% x LTV)
- Delta conversione (% referral - % freddo) x lead mensili x LTV
- Ore perse in attivita' manuali x costo orario = euro bruciati
- Trattative perse per mancato follow-up

Il prospect ha VERBALIZZATO il proprio dolore? (Il consulente deve far parlare il prospect, non parlare lui. Rispettare i silenzi.)

─────────────────────────────────────────────
5. CHIUSURA E NEXT STEP
─────────────────────────────────────────────
Valuta la fase finale:
- Ha RISPECCHIATO il dolore usando le parole esatte del prospect? ("Dimmi se riconosci questo quadro...")
- Ha fatto calcolare il COSTO DELL'INAZIONE al prospect stesso? ("Se dovessi stimare quanto ti costa ogni mese...")
- Ha fatto la DOMANDA VISIONE? ("Se risolvessimo questi problemi in 90 giorni, cosa cambierebbe?")
- Ha fissato un NEXT STEP con DATA PRECISA? (Mai "ci sentiamo presto" = non ci sentiamo piu')
- NON ha parlato di prezzi? (Parlare di prezzi prima che il dolore emerga brucia l'interesse)
- Ha spiegato cosa succedera' nella prossima call? (Referto diagnostico, soluzioni personalizzate, valutazione se ci sono i presupposti per un percorso insieme)

─────────────────────────────────────────────
6. AREE DI MIGLIORAMENTO CON ESEMPI PRATICI
─────────────────────────────────────────────
QUESTA E' L'UNICA SEZIONE CON CITAZIONI TESTUALI dalla trascrizione.

Inizia con un paragrafo generale che spiega dove il consulente deve migliorare in modo prioritario.
Per ogni aspetto da migliorare, usa ESATTAMENTE questo schema:

COSA E' SUCCESSO: "[citazione esatta dalla trascrizione]"
PERCHE' NON E' OTTIMALE: [spiegazione di come ha indebolito la trattativa]
COME POTEVI DIRLO: "[riformulazione ideale — le parole esatte che avresti dovuto usare]"

Non inventare situazioni: cita solo passaggi realmente presenti nella trascrizione.

─────────────────────────────────────────────
7. PAGELLA DI PERFORMANCE FINALE
─────────────────────────────────────────────
Concludi con la pagella riassuntiva. Usa ESATTAMENTE questo formato:

PAGELLA DI PERFORMANCE
━━━━━━━━━━━━━━━━━━━━━━━━
FRAME E INVERSIONE POTERE:    __/10
  [motivazione max 15 parole]
RACCOLTA DATI BASE:           __/10
  [motivazione max 15 parole]
DIAGNOSI 4 AREE + SISTEMA REALTÀ-DESIDERIO: __/10
  [motivazione max 15 parole]
COSTO DELL'INAZIONE:          __/10
  [motivazione max 15 parole]
GESTIONE OBIEZIONI:           __/10
  [motivazione max 15 parole]
CHIUSURA E NEXT STEP:         __/10
  [motivazione max 15 parole]
━━━━━━━━━━━━━━━━━━━━━━━━
VOTO GENERALE:                __/10
━━━━━━━━━━━━━━━━━━━━━━━━

─────────────────────────────────────────────
REGOLE ASSOLUTE DI FORMATO:
─────────────────────────────────────────────
- Il rapporto puo' essere lungo fino a 1000 parole, ma anche piu' breve se sufficiente. L'importante e' che sia qualitativamente perfetto.
- Scrivi in modo discorsivo e narrativo: le sezioni devono avere paragrafi argomentativi.
- Gli unici virgolettati testuali estratti dalla trascrizione DEVONO essere raggruppati SOLO nella sezione "6. AREE DI MIGLIORAMENTO CON ESEMPI PRATICI".
- Il tono finale deve essere costruttivo, motivante e orientato alla crescita.
- Usa il "tu" diretto con il consulente.
- Valorizza SEMPRE prima i punti di forza (sezione 1) prima di passare alle aree di miglioramento.
PROMPT;


    $user_message = "=== DOCUMENTO 1: ISTRUZIONI CALL PREQUALIFICA ===\n$istruzioni\n\n=== DOCUMENTO 2: DATI RACCOLTI DURANTE IL CHECKUP ===\n$dati_checkup";

    // ─── Chiamata Perplexity API ──────────────────────────
    $payload = json_encode([
        'model' => 'sonar-pro',
        'messages' => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $user_message],
        ],
        'max_tokens' => 8000,
        'temperature' => 0.2,
    ]);

    $ch = curl_init('https://api.perplexity.ai/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . PERPLEXITY_KEY,
            'Content-Type: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $curl_err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_err) {
        echo json_encode(['ok' => false, 'msg' => 'Errore di rete: ' . $curl_err]);
        exit;
    }
    $data = json_decode($resp, true);
    if ($http_code !== 200 || !isset($data['choices'][0]['message']['content'])) {
        $err_msg = $data['error']['message'] ?? $resp;
        echo json_encode(['ok' => false, 'msg' => 'Errore Perplexity: ' . $err_msg]);
        exit;
    }
    $analisi = $data['choices'][0]['message']['content'];

    // Estrazione del voto (es. VOTO GENERALE: 8/10)
    $voto = null;
    if (preg_match('/VOTO GENERALE:\s*\*?(\d+(?:\.\d+)?)\s*\/\s*10/i', $analisi, $matches)) {
        $voto = (float) $matches[1];
    }

    // ─── Salva analisi nel DB per non doverla rigenerare ──
    $update_data = ['analisi_call' => $analisi];
    if ($voto !== null) {
        $update_data['analisi_voto'] = $voto;
    }
    sb_patch('Checkup_SV?id=eq.' . $rid, $update_data);

    echo json_encode(['ok' => true, 'analisi' => $analisi, 'voto' => $voto]);
    exit;
}

// ─── AJAX: get_analisi (legge analisi salvata) ────────────
if ($operator && isset($_GET['ajax']) && $_GET['ajax'] === 'get_analisi' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);
    $rid = preg_replace('/[^a-f0-9\-]/', '', $body['id'] ?? '');
    if (!$rid) {
        echo json_encode(['ok' => false, 'msg' => 'ID non valido']);
        exit;
    }
    $rows = sb_get('Checkup_SV?id=eq.' . $rid . '&select=analisi_call');
    $analisi = $rows[0]['analisi_call'] ?? null;
    if ($analisi) {
        echo json_encode(['ok' => true, 'analisi' => $analisi]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Analisi non ancora generata.']);
    }
    exit;
}

?><!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Operatori — AIRA-DXTM</title>
    <style>
        :root {
            --blu: #1B3A6B;
            --blu-dark: #122849;
            --giallo: #F5C842;
            --sfondo: #F0F2F5;
            --bianco: #fff;
            --testo: #1A1A1A;
            --bordo: #DDE3EC;
            --verde: #25D366;
            --rosso: #C00000;
            --grigio: #6B7280;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: system-ui, -apple-system, Arial, sans-serif;
            background: var(--sfondo);
            color: var(--testo);
            min-height: 100vh;
            font-size: 14px;
        }

        /* ── TOPBAR ── */
        .topbar {
            background: var(--blu);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            height: 56px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .2);
        }

        .topbar .brand {
            font-size: 18px;
            font-weight: 900;
            color: var(--giallo);
            letter-spacing: .5px;
        }

        .topbar .brand span {
            font-weight: 400;
            font-size: 13px;
            color: rgba(255, 255, 255, .7);
            margin-left: 10px;
        }

        .topbar .op-info {
            font-size: 13px;
            color: rgba(255, 255, 255, .8);
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .btn-logout {
            background: rgba(255, 255, 255, .12);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, .25);
            padding: 5px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: background .15s;
        }

        .btn-logout:hover {
            background: rgba(255, 255, 255, .22);
        }

        /* ── MAIN WRAP ── */
        .wrap {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px 20px 60px;
        }

        /* ── FILTERS BAR ── */
        .filters-bar {
            background: var(--bianco);
            border: 1px solid var(--bordo);
            border-radius: 10px;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filters-bar label {
            font-size: 12px;
            font-weight: 700;
            color: var(--grigio);
            text-transform: uppercase;
        }

        .filters-bar select {
            border: 1px solid var(--bordo);
            border-radius: 6px;
            padding: 7px 12px;
            font-size: 14px;
            background: var(--bianco);
            cursor: pointer;
            color: var(--testo);
        }

        .btn-filter {
            background: var(--blu);
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 7px 16px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: opacity .15s;
        }

        .btn-filter:hover {
            opacity: .88;
        }

        .record-count {
            margin-left: auto;
            font-size: 13px;
            color: var(--grigio);
        }

        /* ── TABLE ── */
        .table-wrap {
            background: var(--bianco);
            border: 1px solid var(--bordo);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .05);
        }

        .table-scroll {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        thead th {
            background: var(--blu);
            color: #fff;
            padding: 11px 12px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .5px;
            font-weight: 700;
            white-space: nowrap;
        }

        tbody tr {
            border-bottom: 1px solid var(--bordo);
            transition: background .1s;
        }

        tbody tr:last-child {
            border-bottom: none;
        }

        tbody tr:nth-child(even) {
            background: #F7F9FC;
        }

        tbody tr:hover {
            background: #EEF2F9;
        }

        td {
            padding: 10px 12px;
            font-size: 13px;
            vertical-align: middle;
        }

        td.td-date {
            white-space: nowrap;
            color: var(--grigio);
            font-size: 12px;
        }

        td.td-op {
            font-size: 12px;
            color: #444;
        }

        td.td-az {
            font-weight: 600;
            color: var(--blu);
        }

        td.td-piva {
            font-size: 12px;
            font-family: monospace;
            color: #555;
        }

        /* ── ACTION BUTTONS ── */
        .actions {
            display: flex;
            gap: 5px;
            flex-wrap: nowrap;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 5px 9px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            text-decoration: none;
            white-space: nowrap;
            transition: opacity .15s;
            line-height: 1;
        }

        .btn-action:hover:not(:disabled) {
            opacity: .82;
        }

        .btn-action:disabled {
            opacity: .35;
            cursor: not-allowed;
        }

        .btn-report {
            background: var(--blu);
            color: #fff;
        }

        .btn-trascr {
            background: #7C3AED;
            color: #fff;
        }

        .btn-trascr.has-trascr {
            background: #16A34A;
            color: #fff;
        }

        .btn-analisi {
            background: #0891B2;
            color: #fff;
        }

        .btn-analisi-empty {
            background: #9CA3AF;
            color: #fff;
            cursor: not-allowed;
            opacity: 0.75;
        }

        .btn-delete {
            background: #DC2626;
            color: #fff;
        }

        .btn-tel {
            background: #1B3A6B;
            color: #fff;
        }

        .btn-wa {
            background: var(--verde);
            color: #fff;
        }

        .btn-offerta {
            background: #EA580C;
            color: #fff;
            border: 1px solid #C2410C;
        }
        .btn-offerta:hover {
            background: #C2410C;
        }

        /* ── FOOTER VERSIONING ── */
        .footer-version {
            text-align: center;
            padding: 20px;
            font-size: 11px;
            color: var(--grigio);
            opacity: 0.7;
        }

        /* ── ICONA GOOGLE DOC OFFERTA ── */
        .offerta-cell {
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .gdoc-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 6px;
            font-size: 16px;
            text-decoration: none;
            transition: transform .15s, opacity .15s;
            flex-shrink: 0;
        }

        .gdoc-icon.pending {
            background: #E5E7EB;
            color: #9CA3AF;
            cursor: default;
            animation: pulse-grey 1.5s ease-in-out infinite;
        }

        .gdoc-icon.ready {
            background: #1A73E8;
            color: #fff;
            cursor: pointer;
        }

        .gdoc-icon.ready:hover {
            transform: scale(1.12);
            opacity: .9;
        }

        @keyframes pulse-grey {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: .45;
            }
        }

        /* ── MODAL OFFERTA ── */
        .modal-offerta-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .55);
            z-index: 600;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-offerta-overlay.open {
            display: flex;
        }

        .modal-offerta-box {
            background: #fff;
            border-radius: 14px;
            width: 100%;
            max-width: 620px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 24px 64px rgba(0, 0, 0, .3);
            display: flex;
            flex-direction: column;
        }

        .modal-offerta-header {
            background: #EA580C;
            color: #fff;
            padding: 18px 22px;
            border-radius: 14px 14px 0 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-offerta-header h3 {
            font-size: 16px;
            font-weight: 700;
            margin: 0;
        }

        .modal-offerta-close {
            background: none;
            border: none;
            color: rgba(255, 255, 255, .75);
            font-size: 24px;
            cursor: pointer;
            line-height: 1;
            padding: 0 4px;
        }

        .modal-offerta-close:hover {
            color: #fff;
        }

        .modal-offerta-body {
            padding: 22px;
            flex: 1;
        }

        .offerta-subtitle {
            font-size: 13px;
            color: #6B7280;
            margin-bottom: 18px;
        }

        .offerta-row {
            display: grid;
            grid-template-columns: 36px 1fr auto;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #F3F4F6;
        }

        .offerta-row:last-child {
            border-bottom: none;
        }

        .offerta-checkbox {
            width: 20px;
            height: 20px;
            accent-color: #EA580C;
            cursor: pointer;
        }

        .offerta-label-wrap {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .offerta-product-name {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
        }

        .offerta-product-desc {
            font-size: 12px;
            color: #6B7280;
        }

        .offerta-desc-row {
            display: none;
            grid-column: 2 / -1;
            padding: 8px 0 4px;
        }

        .offerta-desc-row.visible {
            display: block;
        }

        .offerta-desc-input {
            width: 100%;
            border: 1.5px solid #D1D5DB;
            border-radius: 8px;
            padding: 9px 12px;
            font-size: 13px;
            font-family: inherit;
            resize: none;
            min-height: 60px;
        }

        .offerta-desc-input:focus {
            outline: none;
            border-color: #EA580C;
            box-shadow: 0 0 0 2px rgba(234, 88, 12, .12);
        }

        .offerta-importo {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .offerta-importo input[type=number] {
            width: 90px;
            border: 1.5px solid #D1D5DB;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 14px;
            font-weight: 600;
            text-align: right;
            font-family: inherit;
        }

        .offerta-importo input[type=number]:focus {
            outline: none;
            border-color: #EA580C;
        }

        .offerta-importo span {
            font-size: 13px;
            color: #6B7280;
        }

        .offerta-totale-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 18px;
            padding-top: 16px;
            border-top: 2px solid #EA580C;
        }

        .offerta-totale-label {
            font-size: 15px;
            font-weight: 700;
            color: #111827;
        }

        .offerta-totale-valore {
            font-size: 20px;
            font-weight: 800;
            color: #EA580C;
        }

        .modal-offerta-footer {
            padding: 16px 22px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-top: 1px solid #E5E7EB;
        }

        .btn-genera-offerta {
            background: #EA580C;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 11px 26px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: opacity .15s;
        }

        .btn-genera-offerta:hover {
            opacity: .88;
        }

        .btn-genera-offerta:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .offerta-msg {
            font-size: 13px;
            font-weight: 600;
            margin-left: auto;
        }

        .offerta-msg.ok {
            color: #065F46;
        }

        .offerta-msg.err {
            color: #B91C1C;
        }

        /* ── EMPTY STATE ── */
        .empty-state {
            text-align: center;
            padding: 48px 20px;
            color: var(--grigio);
        }

        .empty-state .es-icon {
            font-size: 36px;
            margin-bottom: 10px;
        }

        .empty-state p {
            font-size: 14px;
        }

        /* ── GAP BADGE ── */
        .gap-badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
        }

        .gap-high {
            background: #FEE2E2;
            color: #991B1B;
        }

        .gap-mid {
            background: #FEF3C7;
            color: #92400E;
        }

        .gap-low {
            background: #D1FAE5;
            color: #065F46;
        }

        /* ── MODALI ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .55);
            z-index: 500;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            background: var(--bianco);
            border-radius: 12px;
            width: 100%;
            max-width: 640px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .25);
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            background: var(--blu);
            color: #fff;
            padding: 16px 20px;
            border-radius: 12px 12px 0 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-header h3 {
            font-size: 15px;
            font-weight: 700;
        }

        .modal-close {
            background: none;
            border: none;
            color: rgba(255, 255, 255, .7);
            font-size: 22px;
            cursor: pointer;
            line-height: 1;
            padding: 0 4px;
        }

        .modal-close:hover {
            color: #fff;
        }

        .modal-body {
            padding: 20px;
            flex: 1;
        }

        .modal-body textarea {
            width: 100%;
            min-height: 280px;
            border: 1.5px solid var(--bordo);
            border-radius: 8px;
            padding: 12px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            line-height: 1.6;
        }

        .modal-body textarea:focus {
            outline: none;
            border-color: var(--blu);
            box-shadow: 0 0 0 2px rgba(27, 58, 107, .1);
        }

        .modal-footer {
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-top: 1px solid var(--bordo);
        }

        .btn-save {
            background: var(--blu);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 22px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: opacity .15s;
        }

        .btn-save:hover {
            opacity: .88;
        }

        .btn-cancel {
            background: #E5E7EB;
            color: #374151;
            border: none;
            border-radius: 8px;
            padding: 10px 18px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity .15s;
        }

        .btn-cancel:hover {
            opacity: .8;
        }

        .modal-msg {
            font-size: 13px;
            margin-left: auto;
            font-weight: 600;
        }

        .modal-msg.ok {
            color: #065F46;
        }

        .modal-msg.err {
            color: var(--rosso);
        }

        /* ── LOGIN ── */
        .login-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: var(--sfondo);
        }

        .login-card {
            background: var(--bianco);
            border-radius: 14px;
            padding: 40px 36px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 6px 30px rgba(0, 0, 0, .10);
            text-align: center;
        }

        .login-brand {
            font-size: 24px;
            font-weight: 900;
            color: var(--blu);
            letter-spacing: .5px;
            margin-bottom: 4px;
        }

        .login-sub {
            font-size: 13px;
            color: var(--grigio);
            margin-bottom: 28px;
        }

        .login-card h2 {
            font-size: 17px;
            color: var(--blu);
            margin-bottom: 6px;
        }

        .login-card p {
            font-size: 13px;
            color: #6B7280;
            margin-bottom: 22px;
            line-height: 1.5;
        }

        .login-field {
            margin-bottom: 14px;
            text-align: left;
        }

        .login-field label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--grigio);
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .login-field input {
            width: 100%;
            border: 1.5px solid var(--bordo);
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 15px;
            transition: border-color .2s;
        }

        .login-field input:focus {
            outline: none;
            border-color: var(--blu);
        }

        .login-field input.otp-big {
            font-size: 28px;
            letter-spacing: 10px;
            text-align: center;
            font-family: monospace;
        }

        .btn-login {
            width: 100%;
            background: var(--blu);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 13px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: opacity .2s;
            margin-top: 6px;
        }

        .btn-login:hover {
            opacity: .88;
        }

        .login-err {
            background: #FEE2E2;
            border: 1.5px solid #F87171;
            color: #991B1B;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .login-back {
            display: inline-block;
            margin-top: 14px;
            font-size: 13px;
            color: var(--grigio);
            text-decoration: none;
        }

        .login-back:hover {
            color: var(--blu);
        }
    </style>
</head>

<body>

    <?php if (!$operator): ?>
        <!-- ═══════════════════════════════════════════════
     STATO 1 — LOGIN
═══════════════════════════════════════════════════ -->
        <div class="login-wrap">
            <div class="login-card">
                <div class="login-brand">⚡ AIRA-DXTM</div>
                <div class="login-sub">Dashboard Operatori — Segnalazione Vincente</div>

                <?php if ($login_error): ?>
                    <div class="login-err">❌
                        <?= htmlspecialchars($login_error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($login_step === 'email'): ?>
                    <!-- STEP 1: email -->
                    <h2>🔐 Accedi alla Dashboard</h2>
                    <p>Inserisci la tua email aziendale per ricevere il codice OTP.</p>
                    <form method="POST" action="dashboard.php">
                        <div class="login-field">
                            <label for="op_email">Email aziendale</label>
                            <input type="email" id="op_email" name="op_email" required autofocus
                                placeholder="nome@migastone.com" value="<?= htmlspecialchars($_POST['op_email'] ?? '') ?>">
                        </div>
                        <button class="btn-login" type="submit">Invia codice OTP →</button>
                    </form>

                <?php else: ?>
                    <!-- STEP 2: OTP -->
                    <h2>📬 Controlla la tua email</h2>
                    <p>Abbiamo inviato un codice a 6 cifre a <strong>
                            <?= htmlspecialchars($login_email) ?>
                        </strong>. Inseriscilo qui sotto.</p>
                    <form method="POST" action="dashboard.php">
                        <input type="hidden" name="op_email_hidden" value="<?= htmlspecialchars($login_email) ?>">
                        <div class="login-field">
                            <label for="op_otp">Codice OTP</label>
                            <input class="otp-big" type="text" id="op_otp" name="op_otp" required autofocus maxlength="6"
                                inputmode="numeric" placeholder="––––––">
                        </div>
                        <button class="btn-login" type="submit">Accedi →</button>
                    </form>
                    <a class="login-back" href="dashboard.php">← Usa un'altra email</a>
                <?php endif; ?>
            </div>
        </div>

    <?php else: ?>
        <!-- ═══════════════════════════════════════════════
     STATO 2 — DASHBOARD
═══════════════════════════════════════════════════ -->

        <!-- TOPBAR -->
        <div class="topbar">
            <div class="brand">⚡ AIRA-DXTM <span>Dashboard Operatori</span></div>
            <div class="op-info">
                <span>👤
                    <?= htmlspecialchars($operator['nome']) ?>
                </span>
                <a class="btn-logout" href="dashboard.php?logout=1">Logout</a>
            </div>
        </div>

        <div class="wrap">

            <!-- FILTRI -->
            <form method="GET" action="dashboard.php" class="filters-bar">
                <label for="op-filter">Operatore:</label>
                <select id="op-filter" name="op">
                    <option value="all" <?= $filter_op === 'all' ? 'selected' : '' ?>>Tutti gli operatori</option>
                    <?php foreach ($operators as $op): ?>
                        <option value="<?= htmlspecialchars($op['email']) ?>" <?= $filter_op === $op['email'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($op['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn-filter" type="submit">Filtra</button>
                <?php if ($filter_op && $filter_op !== 'all' && $filter_op !== $operator['email']): ?>
                    <a href="dashboard.php" style="font-size:12px;color:var(--grigio);text-decoration:none;">✕ Rimuovi
                        filtro</a>
                <?php endif; ?>
                <span class="record-count">
                    <?= count($records) ?> record
                </span>
            </form>

            <!-- TABELLA -->
            <div class="table-wrap">
                <?php if (empty($records)): ?>
                    <div class="empty-state">
                        <div class="es-icon">📂</div>
                        <p>Nessun report trovato per i filtri selezionati.</p>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Operatore</th>
                                    <th>Azienda</th>
                                    <th>Cliente</th>
                                    <th>P.IVA</th>
                                    <th>Gap</th>
                                    <th>Report</th>
                                    <th>Trascrizione</th>
                                    <th>Analisi Call</th>
                                    <th>Telefono</th>
                                    <th>WhatsApp</th>
                                    <th>Offerta</th>
                                    <th>Cancella</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($records as $row):
                                    $rid = $row['id'];
                                    $cellulare = trim($row['cliente_cellulare'] ?? '');
                                    $wa_num = $cellulare ? clean_phone($cellulare) : '';
                                    $gap = isset($row['gap_totale']) ? round((float) $row['gap_totale'], 1) : null;
                                    $gap_cls = $gap === null ? '' : ($gap >= 5 ? 'gap-high' : ($gap >= 3 ? 'gap-mid' : 'gap-low'));
                                    $ferita_lbl = htmlspecialchars($row['ferita_principale_label'] ?? '—');
                                    $trascr_esc = htmlspecialchars($row['trascrizione'] ?? '', ENT_QUOTES);
                                    $az_esc = htmlspecialchars($row['cliente_azienda'] ?? '—', ENT_QUOTES);
                                    ?>
                                    <tr>
                                        <td class="td-date">
                                            <?= fmt_date_it($row['created_at']) ?>
                                        </td>
                                        <td class="td-op">
                                            <?= htmlspecialchars($row['consulente_nome'] ?? '—') ?>
                                        </td>
                                        <td class="td-az">
                                            <?= htmlspecialchars($row['cliente_azienda'] ?? '—') ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($row['cliente_nome'] ?? '—') ?>
                                        </td>
                                        <td class="td-piva">
                                            <?= htmlspecialchars($row['cliente_partita_iva'] ?? '—') ?>
                                        </td>
                                        <td>
                                            <?php if ($gap !== null): ?>
                                                <span class="gap-badge <?= $gap_cls ?>">
                                                    <?= $gap ?>
                                                </span>
                                            <?php else: ?>—
                                            <?php endif; ?>
                                        </td>
                                        <!-- Report -->
                                        <td>
                                            <?php $dtok = dash_token($rid); ?>
                                            <div style="display:flex; gap:6px; align-items:center;">
                                                <a class="btn-action btn-report"
                                                    href="report.php?id=<?= urlencode($rid) ?>&dtok=<?= urlencode($dtok) ?>"
                                                    target="_blank" style="flex:1;">
                                                    📄 Report
                                                </a>
                                                <button class="btn-action" style="padding:6px; background:#F1F5F9; color:#475569;"
                                                    onclick="openEditCheckup('<?= htmlspecialchars($rid, ENT_QUOTES) ?>')"
                                                    title="Modifica dati">✏️</button>
                                            </div>
                                        </td>
                                        <!-- Trascrizione -->
                                        <td>
                                            <?php $has_trascr = !empty(trim($row['trascrizione'] ?? '')); ?>
                                            <button class="btn-action btn-trascr<?= $has_trascr ? ' has-trascr' : '' ?>"
                                                onclick="openTrascrizione('<?= htmlspecialchars($rid, ENT_QUOTES) ?>','<?= $az_esc ?>',this)"
                                                data-id="<?= htmlspecialchars($rid, ENT_QUOTES) ?>" data-text="<?= $trascr_esc ?>">
                                                <?= $has_trascr ? '✅' : '📝' ?> Trascrizione
                                            </button>
                                        </td>
                                        <!-- Analisi Call -->
                                        <td>
                                            <?php
                                            $has_analisi = !empty(trim($row['analisi_call'] ?? ''));
                                            $analisi_esc = $has_analisi ? htmlspecialchars(base64_encode($row['analisi_call']), ENT_QUOTES) : '';

                                            // Estrazione voto per fallback o uso DB
                                            $voto_txt = '';
                                            if ($has_analisi) {
                                                if (!empty($row['analisi_voto'])) {
                                                    $voto_txt = ' ' . floatval($row['analisi_voto']) . '/10';
                                                } else if (preg_match('/VOTO GENERALE:\s*\*?(\d+(?:\.\d+)?)\s*\/\s*10/i', $row['analisi_call'], $m)) {
                                                    $voto_txt = ' ' . floatval($m[1]) . '/10';
                                                }
                                            }
                                            ?>
                                            <button class="btn-action <?= $has_analisi ? 'btn-analisi' : 'btn-analisi-empty' ?>"
                                                data-id="<?= htmlspecialchars($rid, ENT_QUOTES) ?>" data-b64="<?= $analisi_esc ?>"
                                                onclick="openAnalisiCall('<?= htmlspecialchars($rid, ENT_QUOTES) ?>','<?= $az_esc ?>',this)"
                                                <?= $has_analisi ? '' : 'title="Salva prima la trascrizione per generare l\'analisi"' ?>>
                                                <?= $has_analisi ? '🎯 Analisi' . htmlspecialchars($voto_txt) : '🔒 Analisi' ?>
                                            </button>
                                        </td>
                                        <!-- Tel -->
                                        <td>
                                            <?php if ($cellulare): ?>
                                                <a class="btn-action btn-tel" href="tel:<?= htmlspecialchars($cellulare) ?>">📞
                                                    Chiama</a>
                                            <?php else: ?>
                                                <button class="btn-action btn-tel" disabled title="Cellulare non disponibile">📞
                                                    Chiama</button>
                                            <?php endif; ?>
                                        </td>
                                        <!-- WA -->
                                        <td>
                                            <?php if ($wa_num): ?>
                                                <a class="btn-action btn-wa" href="https://wa.me/<?= $wa_num ?>" target="_blank"
                                                    rel="noopener">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor">
                                                        <path
                                                            d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347" />
                                                        <path
                                                            d="M12 0C5.373 0 0 5.373 0 12c0 2.124.558 4.118 1.528 5.845L.057 23.428a.5.5 0 0 0 .609.61l5.652-1.485A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.802 9.802 0 0 1-5.012-1.374l-.36-.213-3.712.976.993-3.624-.236-.373A9.817 9.817 0 0 1 2.182 12C2.182 6.57 6.57 2.182 12 2.182S21.818 6.57 21.818 12 17.43 21.818 12 21.818z" />
                                                    </svg>
                                                    WA
                                                </a>
                                            <?php else: ?>
                                                <button class="btn-action btn-wa" disabled title="Cellulare non disponibile">WA</button>
                                            <?php endif; ?>
                                        </td>
                                        <!-- Offerta -->
                                        <td>
                                            <div class="offerta-cell">
                                                <button class="btn-action btn-offerta" title="Crea Offerta"
                                                    onclick="openOfferta('<?= htmlspecialchars($rid, ENT_QUOTES) ?>', '<?= htmlspecialchars($row['cliente_azienda'] ?? '', ENT_QUOTES) ?>')">
                                                    📋 Offerta
                                                </button>
                                                <?php
                                                $in_elab = !empty($row['offerta_in_elaborazione']);
                                                $doc_url = $row['offerta_doc_url'] ?? '';
                                                if ($in_elab || $doc_url):
                                                    ?>
                                                    <?php if ($doc_url && !$in_elab): ?>
                                                        <a class="gdoc-icon ready" href="<?= htmlspecialchars($doc_url, ENT_QUOTES) ?>"
                                                            target="_blank" title="Apri offerta Google Doc"
                                                            id="gdoc-<?= htmlspecialchars($rid, ENT_QUOTES) ?>">
                                                            📄
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="gdoc-icon pending" id="gdoc-<?= htmlspecialchars($rid, ENT_QUOTES) ?>"
                                                            title="Offerta in elaborazione...">
                                                            📄
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <!-- Cancella -->
                                        <td>
                                            <button class="btn-action btn-delete"
                                                data-id="<?= htmlspecialchars($rid, ENT_QUOTES) ?>" data-az="<?= $az_esc ?>"
                                                onclick="deleteRecord(this)">
                                                🗑️ Cancella
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /wrap -->

        <!-- ══ MODALE TRASCRIZIONE ══ -->
        <div class="modal-overlay" id="modal-trascr" onclick="closeModal(event)">
            <div class="modal-box" id="modal-trascr-box">
                <div class="modal-header">
                    <h3 id="modal-trascr-title">Trascrizione</h3>
                    <button class="modal-close" onclick="closeTrascrizione()">✕</button>
                </div>
                <div class="modal-body">
                    <textarea id="modal-trascr-text" placeholder="Incolla qui la trascrizione della chiamata..."></textarea>
                    <!-- Progress bar generazione analisi (nascosta di default) -->
                    <div id="trascr-progress" style="display:none;margin-top:16px;">
                        <div style="font-size:13px;font-weight:600;color:#374151;margin-bottom:10px;"
                            id="trascr-progress-label">⏳ Salvataggio...</div>
                        <div style="background:#E5E7EB;border-radius:999px;height:8px;overflow:hidden;">
                            <div id="trascr-progress-bar"
                                style="height:100%;width:0%;background:linear-gradient(90deg,#0891B2,#6366F1);border-radius:999px;transition:width 0.5s ease;">
                            </div>
                        </div>
                        <div
                            style="display:flex;justify-content:space-between;margin-top:8px;font-size:11px;color:#9CA3AF;">
                            <span id="pstep1" style="color:#9CA3AF;">1. Salvataggio</span>
                            <span id="pstep2" style="color:#9CA3AF;">2. Analisi AI</span>
                            <span id="pstep3" style="color:#9CA3AF;">3. Completato</span>
                        </div>
                        <div id="trascr-err-box"
                            style="display:none;margin-top:10px;background:#FEE2E2;border:1.5px solid #F87171;color:#991B1B;border-radius:8px;padding:10px;font-size:13px;font-weight:600;">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-save" id="btn-salva-trascr" onclick="saveTrascrizione()">💾 Salva</button>
                    <button class="btn-cancel" id="btn-chiudi-trascr" onclick="closeTrascrizione()">Chiudi</button>
                    <span class="modal-msg" id="modal-trascr-msg"></span>
                </div>
            </div>
        </div>

        <!-- ══ MODALE ANALISI CALL ══ -->
        <div class="modal-overlay" id="modal-analisi" onclick="closeModalAnalisi(event)">
            <div class="modal-box" style="max-width:800px;" id="modal-analisi-box">
                <div class="modal-header" style="background:#0891B2;">
                    <h3 id="modal-analisi-title">🎯 Analisi Call</h3>
                    <button class="modal-close" onclick="closeAnalisi()">✕</button>
                </div>
                <div class="modal-body" id="modal-analisi-body">
                    <div id="analisi-spinner"
                        style="text-align:center;padding:40px 0;color:#0891B2;font-size:15px;font-weight:600;">
                        ⏳ Elaborazione in corso con Perplexity AI...<br>
                        <small style="color:#666;font-weight:400;margin-top:8px;display:block;">Potrebbe richiedere 20-40
                            secondi</small>
                    </div>
                    <div id="modal-analisi-text"
                        style="display:none;width:100%;min-height:420px;max-height:65vh;overflow-y:auto;border:1.5px solid #CBD5E1;border-radius:8px;padding:18px 20px;font-size:13.5px;font-family:system-ui,sans-serif;line-height:1.85;background:#F8FAFC;color:#1E293B;box-sizing:border-box;">
                    </div>
                    <!-- textarea nascosta solo per il copia-testo -->
                    <textarea id="modal-analisi-raw" style="display:none;" readonly></textarea>
                    <div id="analisi-error"
                        style="display:none;background:#FEE2E2;border:1.5px solid #F87171;color:#991B1B;border-radius:8px;padding:12px;font-size:13px;font-weight:600;">
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-cancel" onclick="closeAnalisi()">Chiudi</button>
                    <button class="btn-save" id="btn-copy-analisi" style="background:#0891B2;display:none;"
                        onclick="copyAnalisi()">📋 Copia testo</button>
                    <button class="btn-save" id="btn-retry-analisi" style="display:none;" onclick="retryAnalisi()">🔄
                        Riprova</button>
                    <span class="modal-msg" id="modal-analisi-msg"></span>
                </div>
            </div>
        </div>

        <!-- ══ MODALE EDIT DATA ══ -->
        <div class="modal-overlay" id="modal-edit-chk" onclick="closeModalEdit(event)">
            <div class="modal-box" id="modal-edit-box"
                style="max-width:800px; max-height: 90vh; display:flex; flex-direction:column;">
                <div class="modal-header" style="flex-shrink:0;">
                    <h3 id="modal-edit-title">Modifica Totale Checkup</h3>
                    <button class="modal-close" onclick="closeEditCheckup()">✕</button>
                </div>

                <div class="modal-body" style="overflow-y:auto; flex-grow:1; padding: 20px;">
                    <div id="edit-loading" style="text-align:center; padding: 40px; color:#64748B;">
                        ⏳ Caricamento dati...
                    </div>

                    <div id="edit-form-wrap" style="display:none; flex-direction:column; gap:20px;">
                        <!-- Anagrafica -->
                        <div style="background:#F8FAFC; padding:16px; border-radius:8px; border:1px solid #E2E8F0;">
                            <h4 style="margin:0 0 12px; color:#0F172A; font-size:15px;">👤 Anagrafica Azienda</h4>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                                <div><label class="form-lbl">Azienda</label><input type="text" class="form-inp"
                                        id="e_cliente_azienda"></div>
                                <div><label class="form-lbl">Nome Cliente</label><input type="text" class="form-inp"
                                        id="e_cliente_nome"></div>
                                <div><label class="form-lbl">Cellulare</label><input type="text" class="form-inp"
                                        id="e_cliente_cellulare"></div>
                                <div><label class="form-lbl">Email</label><input type="email" class="form-inp"
                                        id="e_cliente_email"></div>
                                <div><label class="form-lbl">Sito Web</label><input type="text" class="form-inp"
                                        id="e_cliente_sito_web"></div>
                                <div><label class="form-lbl">Partita IVA</label><input type="text" class="form-inp"
                                        id="e_cliente_partita_iva"></div>
                                <div><label class="form-lbl">Settore</label><input type="text" class="form-inp"
                                        id="e_cliente_settore"></div>
                                <div><label class="form-lbl">Dipendenti</label><input type="text" class="form-inp"
                                        id="e_cliente_dipendenti"></div>
                                <div style="grid-column: span 2;"><label class="form-lbl">Fatturato</label><input
                                        type="text" class="form-inp" id="e_cliente_fatturato"></div>
                            </div>
                        </div>

                        <!-- A1: Vendite -->
                        <div style="background:#F8FAFC; padding:16px; border-radius:8px; border:1px solid #E2E8F0;">
                            <h4 style="margin:0 0 12px; color:#0F172A; font-size:15px;">🛒 Area 1: Vendite (Processo e
                                Numeri)</h4>
                            <div style="display:flex; flex-direction:column; gap:12px;">
                                <div><label class="form-lbl">Q1: Il prodotto/servizio di punta</label><textarea
                                        class="form-inp" rows="2" id="e_a1_q1_risposta"></textarea></div>
                                <div><label class="form-lbl">Q2: Quanto tempo richiede una trattativa</label><textarea
                                        class="form-inp" rows="2" id="e_a1_q2_risposta"></textarea></div>
                                <div><label class="form-lbl">Q3: Obiezione più frequente</label><textarea class="form-inp"
                                        rows="2" id="e_a1_q3_risposta"></textarea></div>
                                <div><label class="form-lbl">Q4: Come gestisci il post-vendita</label><textarea
                                        class="form-inp" rows="2" id="e_a1_q4_risposta"></textarea></div>
                                <div><label class="form-lbl">Q5: Quale azione porta più clienti</label><textarea
                                        class="form-inp" rows="2" id="e_a1_q5_risposta"></textarea></div>
                                <div><label class="form-lbl">Q6: Quanti commerciali attivi</label><textarea class="form-inp"
                                        rows="2" id="e_a1_q6_risposta"></textarea></div>
                            </div>
                        </div>

                        <!-- A2: Delega -->
                        <div style="background:#F8FAFC; padding:16px; border-radius:8px; border:1px solid #E2E8F0;">
                            <h4 style="margin:0 0 12px; color:#0F172A; font-size:15px;">⏳ Area 2: Tempo e Delega</h4>
                            <div style="display:flex; flex-direction:column; gap:12px;">
                                <div><label class="form-lbl">Q1: Quante ore lavori in azienda vs fuori</label><textarea
                                        class="form-inp" rows="2" id="e_a2_q1_risposta"></textarea></div>
                                <div style="display:flex; gap:12px;">
                                    <div style="flex:1;"><label class="form-lbl">Q2a: Ore perse (settimanali)</label><input
                                            type="number" step="0.5" class="form-inp" id="e_a2_q2_ore"></div>
                                    <div style="flex:1;"><label class="form-lbl">Q2b: Valore Orario (€)</label><input
                                            type="number" class="form-inp" id="e_a2_q2_costo"></div>
                                </div>
                                <div><label class="form-lbl">Q3: Attività che odi ma devi fare</label><textarea
                                        class="form-inp" rows="2" id="e_a2_q3_risposta"></textarea></div>
                                <div><label class="form-lbl">Q4: Chi gestisce l'emergenza</label><textarea class="form-inp"
                                        rows="2" id="e_a2_q4_risposta"></textarea></div>
                                <div><label class="form-lbl">Q5: Quante ferie stacchi davvero</label><textarea
                                        class="form-inp" rows="2" id="e_a2_q5_risposta"></textarea></div>
                            </div>
                        </div>

                        <!-- A3: Marketing -->
                        <div style="background:#F8FAFC; padding:16px; border-radius:8px; border:1px solid #E2E8F0;">
                            <h4 style="margin:0 0 12px; color:#0F172A; font-size:15px;">📢 Area 3: Marketing e
                                Posizionamento</h4>
                            <div style="display:flex; flex-direction:column; gap:12px;">
                                <div><label class="form-lbl">Q1: Investimento in Marketing Mese</label><textarea
                                        class="form-inp" rows="2" id="e_a3_q1_risposta"></textarea></div>
                                <div><label class="form-lbl">Q2: Canale marketing migliore</label><textarea class="form-inp"
                                        rows="2" id="e_a3_q2_risposta"></textarea></div>
                                <div><label class="form-lbl">Q3: Prezzi rispetto concorrenza</label><textarea
                                        class="form-inp" rows="2" id="e_a3_q3_risposta"></textarea></div>
                                <div><label class="form-lbl">Q4: Sconto medio in trattativa</label><textarea
                                        class="form-inp" rows="2" id="e_a3_q4_risposta"></textarea></div>
                                <div><label class="form-lbl">Q5: Elemento differenziante</label><textarea class="form-inp"
                                        rows="2" id="e_a3_q5_risposta"></textarea></div>
                            </div>
                        </div>

                        <!-- A4: Referral -->
                        <div style="background:#F8FAFC; padding:16px; border-radius:8px; border:1px solid #E2E8F0;">
                            <h4 style="margin:0 0 12px; color:#0F172A; font-size:15px;">🤝 Area 4: Referral e Conversioni
                            </h4>
                            <div style="display:flex; flex-direction:column; gap:12px;">
                                <div><label class="form-lbl">Q1: Metodo attuale Referral</label><textarea class="form-inp"
                                        rows="2" id="e_a4_q1_risposta"></textarea></div>
                                <div style="display:flex; gap:12px;">
                                    <div style="flex:1;"><label class="form-lbl">Q2a: % Conversioni Fredde</label><input
                                            type="number" class="form-inp" id="e_a4_q2_perc"></div>
                                    <div style="flex:1;"><label class="form-lbl">Q2b: N. Trattative Attive
                                            Mese</label><input type="number" class="form-inp" id="e_a4_q2_trattative"></div>
                                </div>
                                <div><label class="form-lbl">Q3: LTV stimato (Valore a vita cliente)</label><textarea
                                        class="form-inp" rows="2" id="e_a4_q3_risposta"></textarea></div>
                                <div><label class="form-lbl">Q4: Motivo principale disdetta</label><textarea
                                        class="form-inp" rows="2" id="e_a4_q4_risposta"></textarea></div>
                                <div><label class="form-lbl">Q5: Costo Acquisizione (CPA)</label><textarea class="form-inp"
                                        rows="2" id="e_a4_q5_risposta"></textarea></div>
                            </div>
                        </div>

                        <!-- Punteggi Realtà e Desiderio -->
                        <div style="background:#FFFBEB; padding:16px; border-radius:8px; border:1px solid #FDE68A;">
                            <h4 style="margin:0 0 4px; color:#0F172A; font-size:15px;">📊 Punteggi Radar (1–10)</h4>
                            <p style="margin:0 0 14px; font-size:12px; color:#92400E;">Realtà = situazione attuale ·
                                Desiderio = obiettivo percepito dal cliente</p>
                            <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:10px; margin-bottom:10px;">
                                <div
                                    style="text-align:center; font-size:11px; font-weight:700; color:#475569; grid-column:1;">
                                    Area</div>
                                <div
                                    style="text-align:center; font-size:11px; font-weight:700; color:#1B3A6B; grid-column:2;">
                                    Realtà</div>
                                <div
                                    style="text-align:center; font-size:11px; font-weight:700; color:#F59E0B; grid-column:3;">
                                    Desiderio</div>
                                <div style="grid-column:4;"></div>
                            </div>
                            <?php
                            $radar_aree = [
                                'area1_relazionale' => '🛒 Vendite',
                                'area2_automazione' => '⏳ Delega',
                                'area3_posizionamento' => '📢 Marketing',
                                'area4_crm' => '🤝 Referral',
                            ];
                            foreach ($radar_aree as $ak => $label): ?>
                                <div
                                    style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; align-items:center; margin-bottom:8px;">
                                    <div style="font-size:13px; font-weight:600; color:#374151;"><?= $label ?></div>
                                    <input type="number" min="1" max="10" class="form-inp" id="e_realta_<?= $ak ?>"
                                        placeholder="1–10" style="text-align:center;">
                                    <input type="number" min="1" max="10" class="form-inp" id="e_desiderio_<?= $ak ?>"
                                        placeholder="1–10" style="text-align:center;">
                                </div>
                            <?php endforeach; ?>
                        </div>

                    </div>

                    <div id="edit-progress" style="display:none; margin-top:16px;">
                        <div style="font-size:13px; font-weight:600; color:#374151;">⏳ Salvataggio in corso...</div>
                    </div>
                </div>

                <div class="modal-footer" style="flex-shrink:0;">
                    <button class="btn-cancel" onclick="closeEditCheckup()">Annulla</button>
                    <button class="btn-save" id="btn-save-edit" onclick="saveEditCheckup()" disabled>Salva Tutto</button>
                    <span class="modal-msg" id="modal-edit-msg"></span>
                </div>
            </div>
        </div>

        <style>
            .form-lbl {
                font-size: 12px;
                font-weight: 600;
                color: #475569;
                display: block;
                margin-bottom: 4px;
            }

            .form-inp {
                width: 100%;
                padding: 8px 10px;
                border: 1px solid #CBD5E1;
                border-radius: 6px;
                font-size: 14px;
                font-family: inherit;
                box-sizing: border-box;
                background: #fff;
            }

            .form-inp:focus {
                outline: none;
                border-color: #0891B2;
                box-shadow: 0 0 0 2px rgba(8, 145, 178, 0.2);
            }
        </style>

        <script>
            // ── EDIT CHECKUP DATA ────────────────────────────────────
            var _currentEditId = '';
            var _editFieldsMap = [
                'cliente_nome', 'cliente_azienda', 'cliente_email', 'cliente_cellulare', 'cliente_sito_web', 'cliente_partita_iva', 'cliente_settore', 'cliente_fatturato', 'cliente_dipendenti',
                'a1_q1_risposta', 'a1_q2_risposta', 'a1_q3_risposta', 'a1_q4_risposta', 'a1_q5_risposta', 'a1_q6_risposta',
                'a2_q1_risposta', 'a2_q2_ore', 'a2_q2_costo', 'a2_q3_risposta', 'a2_q4_risposta', 'a2_q5_risposta',
                'a3_q1_risposta', 'a3_q2_risposta', 'a3_q3_risposta', 'a3_q4_risposta', 'a3_q5_risposta',
                'a4_q1_risposta', 'a4_q2_perc', 'a4_q2_trattative', 'a4_q3_risposta', 'a4_q4_risposta', 'a4_q5_risposta',
                'realta_area1_relazionale', 'realta_area2_automazione', 'realta_area3_posizionamento', 'realta_area4_crm',
                'desiderio_area1_relazionale', 'desiderio_area2_automazione', 'desiderio_area3_posizionamento', 'desiderio_area4_crm'
            ];

            function openEditCheckup(id) {
                _currentEditId = id;
                document.getElementById('modal-edit-chk').classList.add('open');

                document.getElementById('edit-loading').style.display = 'block';
                document.getElementById('edit-form-wrap').style.display = 'none';
                document.getElementById('btn-save-edit').disabled = true;
                document.getElementById('modal-edit-msg').textContent = '';

                // Fetch data from server
                fetch('dashboard.php?ajax=get_checkup_data&id=' + id)
                    .then(r => r.json())
                    .then(res => {
                        if (res.ok && res.data) {
                            // Populate fields
                            _editFieldsMap.forEach(f => {
                                var el = document.getElementById('e_' + f);
                                if (el) {
                                    el.value = res.data[f] || '';
                                }
                            });

                            document.getElementById('edit-loading').style.display = 'none';
                            document.getElementById('edit-form-wrap').style.display = 'flex';
                            document.getElementById('btn-save-edit').disabled = false;
                        } else {
                            document.getElementById('edit-loading').textContent = '❌ Impossibile caricare i dati.';
                        }
                    })
                    .catch(err => {
                        document.getElementById('edit-loading').textContent = '❌ Errore di connessione.';
                    });
            }

            function closeEditCheckup() {
                if (document.getElementById('btn-save-edit').disabled && document.getElementById('edit-progress').style.display === 'block') return;
                document.getElementById('modal-edit-chk').classList.remove('open');
            }

            function closeModalEdit(e) {
                if (e.target && e.target.id === 'modal-edit-chk') closeEditCheckup();
            }

            function saveEditCheckup() {
                var btn = document.getElementById('btn-save-edit');
                btn.disabled = true;
                document.getElementById('edit-progress').style.display = 'block';
                document.getElementById('modal-edit-msg').textContent = '';

                var payload = { id: _currentEditId };
                _editFieldsMap.forEach(f => {
                    var el = document.getElementById('e_' + f);
                    if (el) {
                        payload[f] = el.value;
                    }
                });

                fetch('dashboard.php?ajax=edit_checkup', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                })
                    .then(r => r.json())
                    .then(data => {
                        if (data.ok) {
                            document.getElementById('modal-edit-msg').style.color = '#059669';
                            document.getElementById('modal-edit-msg').textContent = '✅ Salvato! Ricarica in corso...';
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            document.getElementById('modal-edit-msg').style.color = '#DC2626';
                            document.getElementById('modal-edit-msg').textContent = '❌ Errore: ' + data.msg;
                            btn.disabled = false;
                            document.getElementById('edit-progress').style.display = 'none';
                        }
                    })
                    .catch(err => {
                        document.getElementById('modal-edit-msg').style.color = '#DC2626';
                        document.getElementById('modal-edit-msg').textContent = '❌ Errore di rete.';
                        btn.disabled = false;
                        document.getElementById('edit-progress').style.display = 'none';
                    });
            }

            var _currentTrascrId = '';
            var _currentAnalisiId = '';
            var _currentAnalisiAz = '';
            // Cache locale analisi già caricate (key=id)
            var _analisiCache = {};

            // ── UTILITÀ ─────────────────────────────────────────────
            function _getAnalisiBtn(id) {
                return document.querySelector('.btn-analisi[data-id="' + id + '"],.btn-analisi-empty[data-id="' + id + '"]');
            }

            function _setAnalisiBtn(id, hasAnalisi, analisiText, voto) {
                var btn = _getAnalisiBtn(id);
                if (!btn) return;
                btn.className = 'btn-action ' + (hasAnalisi ? 'btn-analisi' : 'btn-analisi-empty');

                var vTxt = '';
                if (hasAnalisi) {
                    if (voto !== undefined && voto !== null) {
                        vTxt = ' ' + voto + '/10';
                    } else if (analisiText) {
                        var m = analisiText.match(/VOTO GENERALE:\s*\*?(\d+(?:\.\d+)?)\s*\/\s*10/i);
                        if (m) vTxt = ' ' + m[1] + '/10';
                    }
                }

                btn.textContent = hasAnalisi ? '🎯 Analisi' + vTxt : '🔒 Analisi';
                btn.title = hasAnalisi ? '' : 'Salva prima la trascrizione per generare l\'analisi';
                if (analisiText) {
                    btn.setAttribute('data-b64', btoa(unescape(encodeURIComponent(analisiText))));
                    _analisiCache[id] = analisiText;
                } else {
                    btn.setAttribute('data-b64', '');
                    delete _analisiCache[id];
                }
            }

            function _showAnalisiInModal(text) {
                document.getElementById('analisi-spinner').style.display = 'none';
                document.getElementById('analisi-error').style.display = 'none';

                // Salva il raw per il pulsante copia
                document.getElementById('modal-analisi-raw').value = text;

                // Semplice parser markdown con supporto H1/H2/H3 e Liste
                var html = text
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') // Escape HTML di base
                    // Headers
                    .replace(/^### (.*$)/gim, '<h3 style="margin:24px 0 12px;font-size:16px;color:#0F172A;">$1</h3>')
                    .replace(/^## (.*$)/gim, '<h2 style="margin:28px 0 14px;font-size:18px;color:#0F172A;border-bottom:1px solid #E2E8F0;padding-bottom:6px;">$1</h2>')
                    .replace(/^# (.*$)/gim, '<h1 style="margin:32px 0 16px;font-size:22px;color:#0F172A;">$1</h1>')
                    // Liste puntate
                    .replace(/^[\-|\*]\s+(.*$)/gim, '<li style="margin-left:20px;margin-bottom:6px;">$1</li>')
                    // Grassetto e Corsivo (compatibile multi-linea)
                    .replace(/\*\*([\s\S]*?)\*\*/g, '<strong>$1</strong>')
                    .replace(/\*([\s\S]*?)\*/g, '<em>$1</em>')
                    // Formattazione blocchi
                    .replace(/\n\n/g, '<br><br>')
                    .replace(/\n/g, '<br>')
                    // Rimuovi <br> estranei attorno ai tag di blocco (H1/H2/H3/LI)
                    .replace(/<br>\s*<h/g, '<h')
                    .replace(/<\/h1>\s*<br>/g, '</h1>')
                    .replace(/<\/h2>\s*<br>/g, '</h2>')
                    .replace(/<\/h3>\s*<br>/g, '</h3>')
                    .replace(/<br>\s*<li/g, '<li')
                    .replace(/<\/li>\s*<br>/g, '</li>');

                var div = document.getElementById('modal-analisi-text');
                div.innerHTML = html;
                div.style.display = 'block';

                document.getElementById('btn-copy-analisi').style.display = 'inline-flex';
                document.getElementById('btn-retry-analisi').style.display = 'none';
            }

            function _showAnalisiError(msg) {
                document.getElementById('analisi-spinner').style.display = 'none';
                document.getElementById('modal-analisi-text').style.display = 'none';
                var err = document.getElementById('analisi-error');
                err.textContent = '❌ ' + msg;
                err.style.display = 'block';
                document.getElementById('btn-copy-analisi').style.display = 'none';
                document.getElementById('btn-retry-analisi').style.display = 'inline-flex';
            }

            // ── TRASCRIZIONE ─────────────────────────────────────────
            function _progressStep(pct, label, step) {
                document.getElementById('trascr-progress-bar').style.width = pct + '%';
                document.getElementById('trascr-progress-label').textContent = label;
                var steps = ['pstep1', 'pstep2', 'pstep3'];
                steps.forEach(function (s, i) {
                    document.getElementById(s).style.color = (i < step) ? '#0891B2' : '#9CA3AF';
                    document.getElementById(s).style.fontWeight = (i === step - 1) ? '700' : '400';
                });
            }

            function _showTrascrProgress(show) {
                document.getElementById('trascr-progress').style.display = show ? 'block' : 'none';
                document.getElementById('btn-salva-trascr').disabled = show;
                document.getElementById('btn-chiudi-trascr').disabled = show;
                document.getElementById('modal-trascr-text').disabled = show;
            }

            function openTrascrizione(id, azienda, btn) {
                _currentTrascrId = id;
                var text = btn.getAttribute('data-text') || '';
                document.getElementById('modal-trascr-title').textContent = 'Trascrizione — ' + azienda;
                document.getElementById('modal-trascr-text').value = text;
                document.getElementById('modal-trascr-msg').textContent = '';
                document.getElementById('trascr-progress').style.display = 'none';
                document.getElementById('trascr-err-box').style.display = 'none';
                document.getElementById('btn-salva-trascr').disabled = false;
                document.getElementById('btn-chiudi-trascr').disabled = false;
                document.getElementById('modal-trascr-text').disabled = false;
                document.getElementById('modal-trascr').classList.add('open');
            }

            function closeTrascrizione() {
                // Blocca chiusura se salvataggio in corso
                if (document.getElementById('btn-chiudi-trascr').disabled) return;
                document.getElementById('modal-trascr').classList.remove('open');
            }

            function closeModal(e) {
                if (e.target && e.target.id === 'modal-trascr') closeTrascrizione();
            }

            function saveTrascrizione() {
                var text = document.getElementById('modal-trascr-text').value;
                var savedId = _currentTrascrId; // cattura prima di operazioni async

                // Mostra barra, blocca UI
                _showTrascrProgress(true);
                document.getElementById('trascr-err-box').style.display = 'none';
                _progressStep(15, '⏳ Salvataggio trascrizione...', 1);

                fetch('dashboard.php?ajax=trascrizione', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: savedId, trascrizione: text })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.ok) {
                            _showTrascrProgress(false);
                            var eb = document.getElementById('trascr-err-box');
                            eb.textContent = '❌ Salvataggio fallito: ' + data.msg;
                            eb.style.display = 'block';
                            document.getElementById('trascr-progress').style.display = 'block';
                            return;
                        }

                        // Aggiorna bottone Trascrizione nella tabella
                        var btns = document.querySelectorAll('[data-id="' + savedId + '"]');
                        btns.forEach(function (b) {
                            b.setAttribute('data-text', text);
                            if (b.classList.contains('btn-trascr') || b.classList.contains('has-trascr')) {
                                if (text.trim()) {
                                    b.classList.add('has-trascr'); b.textContent = '✅ Trascrizione';
                                } else {
                                    b.classList.remove('has-trascr'); b.textContent = '📝 Trascrizione';
                                }
                            }
                        });
                        _setAnalisiBtn(savedId, false, null, null);

                        if (!text.trim()) {
                            _progressStep(100, '✅ Salvato.', 3);
                            setTimeout(function () {
                                _showTrascrProgress(false);
                                document.getElementById('modal-trascr').classList.remove('open');
                            }, 800);
                            return;
                        }

                        // Step 2 — chiama Perplexity (20-40 sec)
                        _progressStep(40, '🧠 Analisi AI in corso... (attendere 20-40 sec)', 2);
                        var ab = _getAnalisiBtn(savedId);
                        if (ab) { ab.textContent = '⏳ Analisi...'; ab.disabled = true; }

                        // Avanza barra lentamente durante l'attesa
                        var fakeW = 40;
                        var fakeTimer = setInterval(function () {
                            fakeW = Math.min(fakeW + 1, 85);
                            document.getElementById('trascr-progress-bar').style.width = fakeW + '%';
                        }, 600);

                        fetch('dashboard.php?ajax=analisi_call', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: savedId })
                        })
                            .then(function (r) { return r.json(); })
                            .then(function (d) {
                                clearInterval(fakeTimer);
                                if (ab) { ab.disabled = false; }
                                if (d.ok) {
                                    _setAnalisiBtn(savedId, true, d.analisi, d.voto);
                                    _progressStep(100, '✅ Analisi generata con successo! Chiusura...', 3);
                                    setTimeout(function () {
                                        _showTrascrProgress(false);
                                        document.getElementById('modal-trascr').classList.remove('open');
                                    }, 1500);
                                } else {
                                    _setAnalisiBtn(savedId, false, null, null);
                                    _showTrascrProgress(false);
                                    var eb = document.getElementById('trascr-err-box');
                                    eb.textContent = '⚠️ Trascrizione salvata ✅ — ma analisi AI fallita: ' + d.msg + '. Riprova cliccando 🔒 Analisi.';
                                    eb.style.display = 'block';
                                    document.getElementById('trascr-progress').style.display = 'block';
                                    document.getElementById('btn-chiudi-trascr').disabled = false;
                                }
                            })
                            .catch(function (err) {
                                clearInterval(fakeTimer);
                                if (ab) { ab.disabled = false; }
                                _setAnalisiBtn(savedId, false, null, null);
                                _showTrascrProgress(false);
                                var eb = document.getElementById('trascr-err-box');
                                eb.textContent = '⚠️ Trascrizione salvata ✅ — errore di rete sull\'analisi: ' + err.message;
                                eb.style.display = 'block';
                                document.getElementById('trascr-progress').style.display = 'block';
                                document.getElementById('btn-chiudi-trascr').disabled = false;
                            });
                    })
                    .catch(function () {
                        _showTrascrProgress(false);
                        var eb = document.getElementById('trascr-err-box');
                        eb.textContent = '❌ Errore di rete. Controlla la connessione e riprova.';
                        eb.style.display = 'block';
                        document.getElementById('trascr-progress').style.display = 'block';
                    });
            }

            // Chiama Perplexity e salva in DB — callback(ok, resultOrErrorMsg)
            function _generateAnalisi(id, cb) {
                fetch('dashboard.php?ajax=analisi_call', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) { cb(true, data.analisi); }
                        else { cb(false, data.msg); }
                    })
                    .catch(function (err) { cb(false, err.message); });
            }

            // ── ANALISI CALL (solo visualizzatore) ───────────────────
            function openAnalisiCall(id, azienda, btn) {
                _currentAnalisiId = id;
                _currentAnalisiAz = azienda;

                // Se il bottone è disabilitato o in generazione, non aprire
                if (btn && btn.disabled) return;

                document.getElementById('modal-analisi-title').textContent = '🎯 Analisi Call — ' + azienda;
                document.getElementById('modal-analisi-text').style.display = 'none';
                document.getElementById('modal-analisi-text').innerHTML = '';
                document.getElementById('modal-analisi-raw').value = '';
                document.getElementById('analisi-spinner').style.display = 'none';
                document.getElementById('analisi-error').style.display = 'none';
                document.getElementById('btn-copy-analisi').style.display = 'none';
                document.getElementById('btn-retry-analisi').style.display = 'none';
                document.getElementById('modal-analisi-msg').textContent = '';
                document.getElementById('modal-analisi').classList.add('open');

                // 1: prova cache JS
                if (_analisiCache[id]) {
                    _showAnalisiInModal(_analisiCache[id]);
                    return;
                }
                // 2: prova data-b64 sul bottone
                var b64 = btn ? btn.getAttribute('data-b64') : '';
                if (b64) {
                    try {
                        var decoded = decodeURIComponent(escape(atob(b64)));
                        _analisiCache[id] = decoded;
                        _showAnalisiInModal(decoded);
                        return;
                    } catch (e) { }
                }
                // 3: se il bottone non ha analisi, mostra messaggio
                var errDiv = document.getElementById('analisi-error');
                errDiv.textContent = '⚠️ Analisi non ancora disponibile. Salva la trascrizione per generarla.';
                errDiv.style.display = 'block';
            }

            function closeAnalisi() {
                document.getElementById('modal-analisi').classList.remove('open');
            }

            function closeModalAnalisi(e) {
                if (e.target && e.target.id === 'modal-analisi') closeAnalisi();
            }

            function copyAnalisi() {
                var text = document.getElementById('modal-analisi-raw').value;
                navigator.clipboard.writeText(text).then(function () {
                    var btn2 = document.getElementById('btn-copy-analisi');
                    var orig = btn2.textContent;
                    btn2.textContent = '✅ Copiato!';
                    setTimeout(function () { btn2.textContent = orig; }, 2000);
                });
            }

            function retryAnalisi() {
                // Riprova generazione manuale dal modal
                document.getElementById('analisi-spinner').style.display = 'block';
                document.getElementById('analisi-error').style.display = 'none';
                document.getElementById('btn-retry-analisi').style.display = 'none';
                _generateAnalisi(_currentAnalisiId, function (ok, result) {
                    if (ok) {
                        _setAnalisiBtn(_currentAnalisiId, true, result, null);
                        _showAnalisiInModal(result);
                    } else {
                        _showAnalisiError(result);
                    }
                });
            }

            // ── CANCELLA RECORD ───────────────────────────────────────
            function deleteRecord(btn) {
                var id = btn.getAttribute('data-id');
                var az = btn.getAttribute('data-az') || 'questo record';
                if (!confirm('⚠️ Sei sicuro di voler cancellare il checkup di "' + az + '"?\n\nQuesta azione è IRREVERSIBILE.')) return;
                btn.disabled = true;
                btn.textContent = '⏳...';

                fetch('dashboard.php?ajax=delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) {
                            var row = btn.closest('tr');
                            row.style.transition = 'opacity 0.3s';
                            row.style.opacity = '0';
                            setTimeout(function () { row.remove(); }, 350);
                        } else {
                            alert('Errore: ' + data.msg);
                            btn.disabled = false;
                            btn.textContent = '🗑️ Cancella';
                        }
                    })
                    .catch(function () {
                        alert('Errore di rete. Riprova.');
                        btn.disabled = false;
                        btn.textContent = '🗑️ Cancella';
                    });
            }
        </script>

        <!-- FOOTER -->
        <div
            style="text-align:center;padding:20px;font-size:12px;color:#9CA3AF;border-top:1px solid var(--bordo);margin-top:20px;">
            AIRA-DXTM &mdash; Dashboard Operatori Segnalazione Vincente &mdash; Migastone International Srl
        </div>

    <?php endif; ?>

    <!-- ══════════════════════════════════════════
         MODAL CREA OFFERTA
    ══════════════════════════════════════════ -->
    <div class="modal-offerta-overlay" id="modal-offerta" onclick="closeOffertaOverlay(event)">
        <div class="modal-offerta-box">

            <div class="modal-offerta-header">
                <h3>📋 Crea Offerta — <span id="offerta-azienda-title"></span></h3>
                <button class="modal-offerta-close" onclick="closeOfferta()">✕</button>
            </div>

            <div class="modal-offerta-body">
                <p class="offerta-subtitle">Seleziona i prodotti da includere nell'offerta e conferma gli importi.</p>

                <!-- APP PRM SV -->
                <div class="offerta-row" id="row-app">
                    <input type="checkbox" class="offerta-checkbox" id="chk-app"
                        onchange="toggleImporto('app'); ricalcolaTotale()">
                    <div class="offerta-label-wrap">
                        <span class="offerta-product-name">APP PRM SV</span>
                        <span class="offerta-product-desc">App brandizzata iOS/Android per gestione referral</span>
                    </div>
                    <div class="offerta-importo">
                        <input type="number" id="imp-app" value="3497" min="0" step="1" oninput="ricalcolaTotale()"
                            disabled>
                        <span>€</span>
                    </div>
                </div>

                <!-- APP UPGRADE PRO -->
                <div class="offerta-row" id="row-app-pro">
                    <input type="checkbox" class="offerta-checkbox" id="chk-app-pro"
                        onchange="toggleImporto('app-pro'); ricalcolaTotale()">
                    <div class="offerta-label-wrap">
                        <span class="offerta-product-name">APP Upgrade PRO</span>
                        <span class="offerta-product-desc">Server dedicato, CRM integration, verticalizzazioni</span>
                    </div>
                    <div class="offerta-importo">
                        <input type="number" id="imp-app-pro" value="497" min="0" step="1" oninput="ricalcolaTotale()"
                            disabled>
                        <span>€</span>
                    </div>
                </div>

                <!-- SCOUTING -->
                <div class="offerta-row" id="row-scouting">
                    <input type="checkbox" class="offerta-checkbox" id="chk-scouting"
                        onchange="toggleImporto('scouting'); ricalcolaTotale()">
                    <div class="offerta-label-wrap">
                        <span class="offerta-product-name">Scouting</span>
                        <span class="offerta-product-desc">10 Connettori prequalificati + AIRA© Matching +
                            Coaching</span>
                    </div>
                    <div class="offerta-importo">
                        <input type="number" id="imp-scouting" value="1497" min="0" step="1" oninput="ricalcolaTotale()"
                            disabled>
                        <span>€</span>
                    </div>
                </div>

                <!-- AUTOMATION -->
                <div class="offerta-row" id="row-automation">
                    <input type="checkbox" class="offerta-checkbox" id="chk-automation"
                        onchange="toggleImporto('automation'); toggleDesc('automation'); ricalcolaTotale()">
                    <div class="offerta-label-wrap">
                        <span class="offerta-product-name">Automation</span>
                        <span class="offerta-product-desc">Flusso automazione su misura (N8N / Make / Zapier)</span>
                    </div>
                    <div class="offerta-importo">
                        <input type="number" id="imp-automation" value="0" min="0" step="1" oninput="ricalcolaTotale()"
                            disabled placeholder="Importo">
                        <span>€</span>
                    </div>
                    <div class="offerta-desc-row" id="desc-row-automation">
                        <textarea class="offerta-desc-input" id="desc-automation"
                            placeholder="Descrivi l'automazione da realizzare (es. WhatsApp CRM sync, lead routing automatico...)"></textarea>
                    </div>
                </div>

                <!-- MIGACRM -->
                <div class="offerta-row" id="row-migacrm">
                    <input type="checkbox" class="offerta-checkbox" id="chk-migacrm"
                        onchange="toggleImporto('migacrm'); ricalcolaTotale()">
                    <div class="offerta-label-wrap">
                        <span class="offerta-product-name">MigaCRM</span>
                        <span class="offerta-product-desc">CRM setup completo + formazione team (4 persone)</span>
                    </div>
                    <div class="offerta-importo">
                        <input type="number" id="imp-migacrm" value="997" min="0" step="1" oninput="ricalcolaTotale()"
                            disabled>
                        <span>€</span>
                    </div>
                </div>

                <!-- ENTERPRISE -->
                <div class="offerta-row" id="row-enterprise">
                    <input type="checkbox" class="offerta-checkbox" id="chk-enterprise"
                        onchange="toggleImporto('enterprise'); ricalcolaTotale()">
                    <div class="offerta-label-wrap">
                        <span class="offerta-product-name">Enterprise – Il Garante</span>
                        <span class="offerta-product-desc">Kickoff 1 giornata (8h) + 3 mesi follow-up (24h)</span>
                    </div>
                    <div class="offerta-importo">
                        <input type="number" id="imp-enterprise" value="4997" min="0" step="1"
                            oninput="ricalcolaTotale()" disabled>
                        <span>€</span>
                    </div>
                </div>

                <!-- TOTALE -->
                <div class="offerta-totale-row">
                    <span class="offerta-totale-label">Totale Offerta</span>
                    <span class="offerta-totale-valore">€ <span id="offerta-totale">0</span></span>
                </div>
            </div>

            <div class="modal-offerta-footer">
                <button class="btn-genera-offerta" id="btn-genera-offerta" onclick="generaOfferta()">
                    🚀 Genera Offerta
                </button>
                <button class="btn-cancel" onclick="closeOfferta()">Annulla</button>
                <span class="offerta-msg" id="offerta-msg"></span>
            </div>

        </div>
    </div>

    <script>
        // ── MODAL OFFERTA ──────────────────────────────────────────────
        var _offertaCheckupId = null;

        function openOfferta(checkupId, azienda) {
            _offertaCheckupId = checkupId;
            document.getElementById('offerta-azienda-title').textContent = azienda || 'Cliente';
            // Reset stato
            ['app', 'app-pro', 'scouting', 'automation', 'migacrm', 'enterprise'].forEach(function (k) {
                var chk = document.getElementById('chk-' + k);
                var inp = document.getElementById('imp-' + k);
                if (chk) chk.checked = false;
                if (inp) inp.disabled = true;
            });
            var descRow = document.getElementById('desc-row-automation');
            if (descRow) descRow.classList.remove('visible');
            var descTxt = document.getElementById('desc-automation');
            if (descTxt) descTxt.value = '';
            document.getElementById('offerta-totale').textContent = '0';
            document.getElementById('offerta-msg').textContent = '';
            document.getElementById('btn-genera-offerta').disabled = false;
            document.getElementById('modal-offerta').classList.add('open');
        }

        function closeOfferta() {
            document.getElementById('modal-offerta').classList.remove('open');
        }

        function closeOffertaOverlay(e) {
            if (e.target === document.getElementById('modal-offerta')) closeOfferta();
        }

        function toggleImporto(key) {
            var chk = document.getElementById('chk-' + key);
            var inp = document.getElementById('imp-' + key);
            if (inp) inp.disabled = !chk.checked;
        }

        function toggleDesc(key) {
            var chk = document.getElementById('chk-' + key);
            var row = document.getElementById('desc-row-' + key);
            if (row) {
                if (chk.checked) row.classList.add('visible');
                else row.classList.remove('visible');
            }
        }

        function ricalcolaTotale() {
            var prodotti = ['app', 'app-pro', 'scouting', 'automation', 'migacrm', 'enterprise'];
            var totale = 0;
            prodotti.forEach(function (k) {
                var chk = document.getElementById('chk-' + k);
                var inp = document.getElementById('imp-' + k);
                if (chk && chk.checked && inp) {
                    totale += parseFloat(inp.value) || 0;
                }
            });
            document.getElementById('offerta-totale').textContent = totale.toLocaleString('it-IT');
        }

        function generaOfferta() {
            var payload = {
                checkup_id: _offertaCheckupId,
                includi_app: document.getElementById('chk-app').checked ? 'SI' : 'NO',
                includi_app_pro: document.getElementById('chk-app-pro').checked ? 'SI' : 'NO',
                includi_scouting: document.getElementById('chk-scouting').checked ? 'SI' : 'NO',
                includi_automazione: document.getElementById('chk-automation').checked ? 'SI' : 'NO',
                includi_migacrm: document.getElementById('chk-migacrm').checked ? 'SI' : 'NO',
                includi_enterprise: document.getElementById('chk-enterprise').checked ? 'SI' : 'NO',
                importo_app: parseFloat(document.getElementById('imp-app').value) || 0,
                importo_app_pro: parseFloat(document.getElementById('imp-app-pro').value) || 0,
                importo_scouting: parseFloat(document.getElementById('imp-scouting').value) || 0,
                importo_automazione: parseFloat(document.getElementById('imp-automation').value) || 0,
                importo_migacrm: parseFloat(document.getElementById('imp-migacrm').value) || 0,
                importo_enterprise: parseFloat(document.getElementById('imp-enterprise').value) || 0,
                desc_automazione: document.getElementById('desc-automation').value.trim(),
                totale: parseFloat(document.getElementById('offerta-totale').textContent.replace(/\./g, '')) || 0
            };

            // Validazione: almeno un prodotto selezionato
            var selezionati = ['app', 'app-pro', 'scouting', 'automation', 'migacrm', 'enterprise']
                .filter(function (k) { return document.getElementById('chk-' + k).checked; });
            if (selezionati.length === 0) {
                setOffertaMsg('Seleziona almeno un prodotto.', 'err');
                return;
            }

            // Validazione: se automation selezionata e importo = 0
            if (document.getElementById('chk-automation').checked &&
                (parseFloat(document.getElementById('imp-automation').value) || 0) === 0) {
                setOffertaMsg("Inserisci l'importo per l'Automation.", 'err');
                return;
            }

            var checkupId = _offertaCheckupId;

            // Chiudi il popup immediatamente
            closeOfferta();

            // Mostra icona grigia pulsante nella riga
            showGdocPending(checkupId);

            // Invia al backend
            fetch('?ajax=genera_offerta', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        // Avvia polling su Supabase fino a quando offerta_in_elaborazione = false
                        startPollingOfferta(checkupId);
                    } else {
                        // Errore immediato: rimuovi l'icona grigia
                        removeGdocIcon(checkupId);
                        alert('Errore: ' + (data.error || 'Generazione fallita'));
                    }
                })
                .catch(function (err) {
                    removeGdocIcon(checkupId);
                    alert('Errore di rete: ' + err.message);
                });
        }

        // Mostra/sostituisce icona grigia pulsante nella cella offerta
        function showGdocPending(checkupId) {
            var existing = document.getElementById('gdoc-' + checkupId);
            if (existing) {
                // Resetta: rimuovi eventuali classi ready/href e rimetti pending
                existing.className = 'gdoc-icon pending';
                existing.removeAttribute('href');
                existing.removeAttribute('target');
                existing.title = 'Offerta in elaborazione...';
                // Rimuovi onclick se era un link
                existing.onclick = null;
                // Trasforma in span se era <a>
                if (existing.tagName === 'A') {
                    var span = document.createElement('span');
                    span.className = 'gdoc-icon pending';
                    span.id = 'gdoc-' + checkupId;
                    span.title = 'Offerta in elaborazione...';
                    span.textContent = '📄';
                    existing.parentNode.replaceChild(span, existing);
                }
            } else {
                // Crea nuova icona e aggiungila nella cella offerta
                var cell = document.querySelector('button[onclick*="' + checkupId + '"]');
                if (cell) {
                    var container = cell.closest('.offerta-cell') || cell.parentNode;
                    var span = document.createElement('span');
                    span.className = 'gdoc-icon pending';
                    span.id = 'gdoc-' + checkupId;
                    span.title = 'Offerta in elaborazione...';
                    span.textContent = '📄';
                    container.appendChild(span);
                }
            }
        }

        // Converte l'icona grigia in icona colorata con link
        function showGdocReady(checkupId, docUrl) {
            var el = document.getElementById('gdoc-' + checkupId);
            if (!el) return;
            var a = document.createElement('a');
            a.className = 'gdoc-icon ready';
            a.id = 'gdoc-' + checkupId;
            a.href = docUrl;
            a.target = '_blank';
            a.title = 'Apri offerta Google Doc';
            a.textContent = '📄';
            el.parentNode.replaceChild(a, el);
        }

        // Rimuove l'icona (in caso di errore)
        function removeGdocIcon(checkupId) {
            var el = document.getElementById('gdoc-' + checkupId);
            if (el) el.remove();
        }

        // Polling ogni 4 secondi su Supabase per controllare offerta_in_elaborazione e offerta_doc_url
        var _pollingTimers = {};

        function startPollingOfferta(checkupId) {
            // Cancella polling precedente se esiste
            if (_pollingTimers[checkupId]) {
                clearInterval(_pollingTimers[checkupId]);
            }
            var attempts = 0;
            var maxAttempts = 75; // 5 minuti max (75 × 4s)

            _pollingTimers[checkupId] = setInterval(function () {
                attempts++;
                if (attempts > maxAttempts) {
                    clearInterval(_pollingTimers[checkupId]);
                    removeGdocIcon(checkupId);
                    alert('Timeout: la generazione dell\'offerta ha impiegato troppo tempo. Riprova.');
                    return;
                }

                fetch('?ajax=poll_offerta&id=' + encodeURIComponent(checkupId))
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.doc_url && !data.in_elaborazione) {
                            clearInterval(_pollingTimers[checkupId]);
                            showGdocReady(checkupId, data.doc_url);
                        }
                        // Se ancora in elaborazione, continua il polling
                    })
                    .catch(function () {
                        // Errore di rete temporaneo: continua il polling
                    });
            }, 4000);
        }

        function setOffertaMsg(txt, tipo) {
            var el = document.getElementById('offerta-msg');
            el.textContent = txt;
            el.className = 'offerta-msg' + (tipo ? ' ' + tipo : '');
        }
    </script>

    <footer class="footer-version">
        AIRA-DXTM v2.1.0 — Pubblicato il 2026-03-14 10:33
    </footer>

</body>

</html>