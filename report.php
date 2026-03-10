<?php
session_start();

define('SUPABASE_URL', 'https://eywxidahsumzrlzwnfev.supabase.co');
define('SUPABASE_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImV5d3hpZGFoc3VtenJsenduZmV2Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzExNzcyNzMsImV4cCI6MjA4Njc1MzI3M30.visQEcLDoCl3mEwDqRzFhUowOhipKJybH_HiVkIQv-I');
define('OTP_PASSPARTOUT', '220783');
define('DASH_SECRET', 'sv-dash-2026-migastone'); // stesso segreto di dashboard.php

// ─── HELPERS ────────────────────────────────────────────────────────────────

function simpleHash($str)
{
  $hash = 0;
  $len = strlen($str);
  for ($i = 0; $i < $len; $i++) {
    $char = ord($str[$i]);
    $hash = (($hash << 5) - $hash) + $char;
    $hash = $hash & 0xFFFFFFFF;
    if ($hash >= 0x80000000) {
      $hash -= 0x100000000;
    }
  }
  return str_pad(dechex(abs($hash)), 8, '0', STR_PAD_LEFT);
}

function supabase_get($path)
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
  $result = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);
  if ($err)
    return null;
  return json_decode($result, true);
}

function supabase_patch($path, $data)
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
  curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);
  return $err === '';
}

function fmt_eur($val)
{
  return '€ ' . number_format((float) $val, 0, ',', '.');
}

function fmt_date($iso)
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

/**
 * Minimo Markdown → HTML:
 * ## h2, ### h3, **bold**, *italic*, \n\n → paragrafi
 */
function md_to_html($text)
{
  if (!$text)
    return '';
  $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
  // headings
  $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
  $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
  $text = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $text);
  // bold / italic
  $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
  $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
  // hr
  $text = preg_replace('/^---+$/m', '<hr>', $text);
  // bullet lists
  $text = preg_replace('/^[\*\-] (.+)$/m', '<li>$1</li>', $text);
  $text = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $text);
  // paragraphs (double newline)
  $blocks = preg_split('/\n{2,}/', trim($text));
  $out = '';
  foreach ($blocks as $b) {
    $b = trim($b);
    if (!$b)
      continue;
    if (preg_match('/^<(h[1-3]|ul|hr|li)/', $b)) {
      $out .= $b . "\n";
    } else {
      $out .= '<p>' . nl2br($b) . '</p>' . "\n";
    }
  }
  return $out;
}

function area_label($key)
{
  $map = [
    'area1_relazionale' => 'Ingegneria Relazionale',
    'area2_automazione' => 'Automazione e Processi',
    'area3_posizionamento' => 'Posizionamento e Marketing',
    'area4_crm' => 'CRM e Pipeline',
  ];
  return $map[$key] ?? $key;
}

// Mappatura consulenti email → nome, telefono
function consulente_info($email)
{
  $map = [
    'm.russo@migastone.com' => ['nome' => 'Marica Russo', 'tel' => '+393517386642'],
    'r.chiarolanza@migastone.com' => ['nome' => 'Roberto Chiarolanza', 'tel' => '+393883606570'],
    'w.coslop@migastone.com' => ['nome' => 'Walter Coslop', 'tel' => '+393358022658'],
    'o.dalvit@migastone.com' => ['nome' => 'Oscar Dalvit', 'tel' => '+393346824578'],
    'f.devita@migastone.com' => ['nome' => 'Fabio De Vita', 'tel' => '+393482217246'],
  ];
  $email = strtolower(trim($email));
  return $map[$email] ?? null;
}

// Rimuove la sezione ## Terapie Prioritarie (e tutto ciò che segue) dal testo referto AI
function strip_terapie($text)
{
  if (!$text)
    return '';
  // Cerca l'intestazione con o senza spazi/varianti
  $pos = preg_match('/##\s*Terapie Prioritarie/ui', $text, $m, PREG_OFFSET_CAPTURE);
  if ($pos && isset($m[0][1])) {
    return substr($text, 0, $m[0][1]);
  }
  // Fallback: strstr
  $cut = strstr($text, '## Terapie Prioritarie', true);
  return $cut !== false ? $cut : $text;
}

// ─── REGENERATE OTP action ───────────────────────────────────────────────────

if (isset($_GET['action']) && $_GET['action'] === 'regenerate_otp' && isset($_GET['id'])) {
  $rid = preg_replace('/[^a-f0-9\-]/', '', $_GET['id']);
  $webhook_url = 'https://flow.migastone.com/webhook/791ba134-a96c-487b-bb38-296beb418f16';
  $payload = json_encode(['action' => 'regenerate_otp', 'id' => $rid, 'email' => '']);
  $ch = curl_init($webhook_url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  ]);
  curl_exec($ch);
  curl_close($ch);
  header('Location: report.php?id=' . urlencode($rid) . '&msg=otp_sent');
  exit;
}

// ─── VALIDAZIONE ID ─────────────────────────────────────────────────────────

$raw_id = $_GET['id'] ?? '';
if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $raw_id)) {
  $fatal = 'Link non valido o malformato.';
}

$record = null;
$otp_error = '';
$verified = false;
$msg = $_GET['msg'] ?? '';

if (!isset($fatal)) {
  $id = strtolower($raw_id);

  // Fetch record da Supabase
  $rows = supabase_get('Checkup_SV?id=eq.' . urlencode($id) . '&select=*');
  if ($rows === null) {
    $fatal = 'Errore di connessione al database. Riprova tra qualche minuto.';
  } elseif (empty($rows)) {
    $fatal = 'Referto non trovato. Il link potrebbe essere scaduto o non valido.';
  } else {
    $record = $rows[0];
  }
}

if (!isset($fatal) && $record) {
  $sess_key = 'verified_' . $id;

  // ── Bypass OTP da dashboard (token HMAC firmato) ───────────────
  if (!$verified && isset($_GET['dtok'])) {
    $dtok_input = trim($_GET['dtok']);
    $slot = (int)(time() / 3600);
    $dash_ok = false;
    foreach ([$slot, $slot - 1] as $s) {
      $expected = substr(hash_hmac('sha256', $id . '|' . $s, DASH_SECRET), 0, 16);
      if (hash_equals($expected, $dtok_input)) { $dash_ok = true; break; }
    }
    if ($dash_ok) {
      $verified = true;
      $_SESSION[$sess_key] = true; // mantiene la sessione attiva
    }
  }

  // Già verificato in sessione?
  if (!empty($_SESSION[$sess_key])) {
    $verified = true;
  }

  // Verifica OTP da POST
  if (!$verified && isset($_POST['otp'])) {
    $otp_input = trim($_POST['otp']);

    $is_passpartout = ($otp_input === OTP_PASSPARTOUT);
    $hash_input = simpleHash($otp_input);
    $hash_stored = $record['otp_hash'] ?? '';
    $expires_at = $record['otp_expires_at'] ?? '';
    $otp_used = $record['otp_used'] ?? false;

    $not_expired = false;
    if ($expires_at) {
      try {
        $exp = new DateTime($expires_at);
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $not_expired = ($now < $exp);
      } catch (Exception $e) {
        $not_expired = false;
      }
    }

    if ($is_passpartout || ($hash_input === $hash_stored && $not_expired && !$otp_used)) {
      // OTP valido — aggiorna record
      supabase_patch(
        'Checkup_SV?id=eq.' . urlencode($id),
        [
          'otp_used' => true,
          'report_accessed' => true,
          'report_accessed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]
      );
      $_SESSION[$sess_key] = true;
      $verified = true;
    } else {
      $otp_error = 'Codice non valido o scaduto. Verifica e riprova.';
    }
  }
}

// ─── PRE-ELABORA DATI per il referto ────────────────────────────────────────

$gaps = [];
$max_gap = 0;
$max_area = '';

if ($record) {
  $realta = $record['punteggi_realta'] ?? [];
  $desiderio = $record['punteggi_desiderio'] ?? [];
  $areas = ['area1_relazionale', 'area2_automazione', 'area3_posizionamento', 'area4_crm'];
  foreach ($areas as $a) {
    $r = (int) ($realta[$a] ?? 0);
    $d = (int) ($desiderio[$a] ?? 0);
    $g = $d - $r;
    $gaps[$a] = ['r' => $r, 'd' => $d, 'gap' => $g];
    if ($g > $max_gap) {
      $max_gap = $g;
      $max_area = $a;
    }
  }
  if (!$max_area && !empty($gaps)) {
    $max_area = array_key_first($gaps);
  }
}

?><!DOCTYPE html>
<html lang="it">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SEGNALAZIONE VINCENTE | AIRA-DXTM</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
  <style>
    :root {
      --blu: #1B3A6B;
      --giallo: #F5C842;
      --sfondo: #F4F4F4;
      --testo: #1A1A1A;
      --rosso: #C00000;
      --verde: #27AE60;
      --arancio: #E67E22;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: system-ui, Arial, sans-serif;
      background: var(--sfondo);
      color: var(--testo);
      min-height: 100vh;
    }

    /* ── LAYOUT ── */
    .wrap {
      max-width: 820px;
      margin: 0 auto;
      padding: 0 16px 60px;
    }

    /* ── HEADER ── */
    .site-header {
      background: var(--blu);
      color: #fff;
      text-align: center;
      padding: 28px 16px 22px;
      margin-bottom: 32px;
    }

    .site-header .brand {
      font-size: 22px;
      font-weight: 900;
      color: var(--giallo);
      letter-spacing: 1px;
    }

    .site-header h1 {
      font-size: 15px;
      opacity: .85;
      margin-top: 6px;
      font-weight: 400;
    }

    /* ── BANNERS ── */
    .banner {
      padding: 12px 18px;
      border-radius: 8px;
      margin-bottom: 20px;
      font-size: 14px;
      font-weight: 600;
    }

    .banner-red {
      background: #F8D7DA;
      border: 1.5px solid var(--rosso);
      color: #721c24;
    }

    .banner-green {
      background: #D4EDDA;
      border: 1.5px solid var(--verde);
      color: #155724;
    }

    /* ── OTP FORM ── */
    .otp-card {
      background: #fff;
      border-radius: 12px;
      padding: 36px 32px;
      max-width: 440px;
      margin: 0 auto;
      box-shadow: 0 4px 24px rgba(0, 0, 0, .10);
      text-align: center;
    }

    .otp-card h2 {
      color: var(--blu);
      font-size: 20px;
      margin-bottom: 10px;
    }

    .otp-card p {
      font-size: 14px;
      color: #555;
      margin-bottom: 24px;
      line-height: 1.6;
    }

    .otp-input {
      font-size: 32px;
      letter-spacing: 8px;
      text-align: center;
      width: 100%;
      padding: 14px;
      border: 2px solid #ccc;
      border-radius: 8px;
      margin-bottom: 18px;
      font-family: monospace;
      transition: border-color .2s;
    }

    .otp-input:focus {
      outline: none;
      border-color: var(--blu);
    }

    .btn-primary {
      width: 100%;
      background: var(--blu);
      color: #fff;
      border: none;
      padding: 14px;
      border-radius: 8px;
      font-size: 16px;
      font-weight: 700;
      cursor: pointer;
      transition: opacity .2s;
    }

    .btn-primary:hover {
      opacity: .88;
    }

    .link-resend {
      display: block;
      margin-top: 16px;
      font-size: 13px;
      color: #777;
      text-decoration: none;
    }

    .link-resend:hover {
      color: var(--blu);
      text-decoration: underline;
    }

    /* ── REPORT SECTIONS ── */
    .report-header {
      background: var(--blu);
      color: #fff;
      padding: 30px 24px;
      border-radius: 10px;
      margin-bottom: 24px;
    }

    .report-header .tag {
      font-size: 11px;
      color: var(--giallo);
      letter-spacing: 2px;
      font-weight: 700;
      text-transform: uppercase;
      margin-bottom: 8px;
    }

    .report-header h1 {
      font-size: 24px;
      color: var(--giallo);
      margin-bottom: 6px;
    }

    .report-header .sub {
      font-size: 13px;
      opacity: .8;
    }

    .report-box {
      background: #fff;
      border-radius: 10px;
      padding: 22px 24px;
      margin-bottom: 20px;
      box-shadow: 0 2px 12px rgba(0, 0, 0, .06);
    }

    .report-box h2 {
      font-size: 15px;
      color: var(--blu);
      border-bottom: 2px solid var(--giallo);
      padding-bottom: 8px;
      margin-bottom: 16px;
      text-transform: uppercase;
      letter-spacing: .5px;
    }

    /* dati aziendali */
    .dati-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px 20px;
      font-size: 14px;
    }

    .dati-grid dt {
      color: #888;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .5px;
      margin-bottom: 2px;
    }

    .dati-grid dd {
      font-weight: 600;
    }

    @media(max-width:500px) {
      .dati-grid {
        grid-template-columns: 1fr;
      }
    }

    /* maturità */
    .maturita-num {
      font-size: 64px;
      font-weight: 900;
      color: var(--blu);
      line-height: 1;
    }

    .maturita-label {
      font-size: 16px;
      color: #555;
      margin: 6px 0 16px;
    }

    .progress-bar-wrap {
      background: #eee;
      border-radius: 20px;
      height: 14px;
      overflow: hidden;
    }

    .progress-bar-fill {
      height: 100%;
      border-radius: 20px;
      transition: width .8s;
    }

    .fill-red {
      background: var(--rosso);
    }

    .fill-orange {
      background: var(--arancio);
    }

    .fill-green {
      background: var(--verde);
    }

    /* potenziale box */
    .pot-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 14px;
    }

    @media(max-width:580px) {
      .pot-grid {
        grid-template-columns: 1fr;
      }
    }

    .pot-box {
      border-radius: 8px;
      padding: 18px 14px;
      text-align: center;
    }

    .pot-box .pot-label {
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .5px;
      margin-bottom: 8px;
    }

    .pot-box .pot-val {
      font-size: 22px;
      font-weight: 900;
    }

    .pot-verde {
      background: #D4EDDA;
      color: #155724;
    }

    .pot-rosso {
      background: #F8D7DA;
      color: #721c24;
    }

    /* gap bars */
    .gap-row {
      margin-bottom: 18px;
    }

    .gap-row.ferita {
      border-left: 4px solid var(--rosso);
      padding-left: 12px;
    }

    .gap-row .gap-label {
      font-size: 13px;
      font-weight: 700;
      margin-bottom: 8px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .gap-badge {
      font-size: 11px;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 10px;
    }

    .badge-red {
      background: var(--rosso);
      color: #fff;
    }

    .badge-grey {
      background: #bbb;
      color: #fff;
    }

    .bar-dual {
      height: 12px;
      border-radius: 6px;
      background: #e8e8e8;
      position: relative;
      margin-bottom: 4px;
      overflow: hidden;
    }

    .bar-realta {
      position: absolute;
      left: 0;
      top: 0;
      height: 100%;
      background: var(--blu);
      border-radius: 6px;
    }

    .bar-desiderio {
      position: absolute;
      left: 0;
      top: 0;
      height: 100%;
      background: rgba(27, 58, 107, .25);
      border-radius: 6px;
    }

    .bar-legend {
      display: flex;
      gap: 16px;
      font-size: 11px;
      color: #888;
    }

    .bar-legend span::before {
      content: '●';
      font-size: 9px;
      margin-right: 3px;
    }

    .bar-legend .l-r::before {
      color: var(--blu);
    }

    .bar-legend .l-d::before {
      color: #aac0e0;
    }

    .gap-num {
      font-size: 20px;
      font-weight: 900;
      color: var(--rosso);
    }

    /* referto ai */
    .referto-content {
      font-size: 15px;
      line-height: 1.75;
      color: #333;
    }

    .referto-content h1,
    .referto-content h2 {
      color: var(--blu);
      margin: 20px 0 10px;
      font-size: 17px;
    }

    .referto-content h3 {
      color: var(--blu);
      margin: 16px 0 8px;
      font-size: 15px;
    }

    .referto-content p {
      margin-bottom: 14px;
    }

    .referto-content ul {
      padding-left: 20px;
      margin-bottom: 14px;
    }

    .referto-content li {
      margin-bottom: 6px;
    }

    .referto-content strong {
      color: var(--testo);
    }

    .referto-content hr {
      border: none;
      border-top: 1px solid #ddd;
      margin: 20px 0;
    }

    /* descrizioni sezione */
    .sezione-desc {
      font-size: 13px;
      color: #555;
      line-height: 1.75;
      margin: 0 0 18px 0;
      padding: 12px 16px;
      background: #f4f7fb;
      border-left: 3px solid var(--blu);
      border-radius: 0 6px 6px 0;
    }
    .sezione-desc strong { color: var(--blu); }

    /* riepilogo dati completo */
    .riepilogo-dati-box { margin-top: 24px; }
    .riepilogo-intro {
      font-size: 13px;
      color: #777;
      margin: 0 0 16px;
    }
    .riepilogo-blocco {
      margin-bottom: 18px;
      border: 1px solid #e0e5ee;
      border-radius: 8px;
      overflow: hidden;
    }
    .riepilogo-blocco-title {
      background: var(--blu);
      color: #fff;
      font-size: 12px;
      font-weight: 700;
      padding: 8px 14px;
      letter-spacing: .03em;
    }
    .riepilogo-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 12.5px;
    }
    .riepilogo-table tr { border-bottom: 1px solid #eef0f4; }
    .riepilogo-table tr:last-child { border-bottom: none; }
    .riepilogo-table tr:nth-child(even) { background: #f8f9fc; }
    .riepilogo-table td, .riepilogo-table th {
      padding: 7px 12px;
      vertical-align: top;
    }
    .riepilogo-table td:first-child, .riepilogo-table th:first-child {
      color: #666;
      font-weight: 600;
      width: 30%;
    }
    .riepilogo-header-row th {
      background: #e8edf5;
      color: var(--blu);
      font-weight: 700;
      font-size: 11.5px;
      text-transform: uppercase;
      letter-spacing: .04em;
    }
    .riepilogo-ferita td { background: #fff5f5 !important; }
    .riepilogo-ferita td:first-child { color: #c0392b !important; font-weight: 700 !important; }
    .riepilogo-note-testo {
      padding: 12px 14px;
      font-size: 13px;
      line-height: 1.7;
      color: #333;
      background: #fffdf0;
      border-left: 3px solid var(--giallo);
    }
    @media (max-width: 600px) {
      .riepilogo-table td { display: block; width: 100% !important; }
      .riepilogo-table tr { display: block; margin-bottom: 6px; }
    }

    /* SCOPO REPORT */
    .scopo-report {
      margin-top: 14px;
      background: rgba(255,255,255,0.12);
      border-left: 3px solid var(--giallo);
      border-radius: 0 6px 6px 0;
      padding: 12px 16px;
      font-size: 13px;
      line-height: 1.7;
      color: rgba(255,255,255,0.9);
    }
    .scopo-report strong { color: var(--giallo); }

    /* BOX VIDEO */
    .video-box {
      display: flex;
      align-items: flex-start;
      gap: 18px;
      background: linear-gradient(135deg, #1B3A6B 0%, #2a5298 100%);
      color: #fff;
      border: none;
    }
    .video-icon {
      flex-shrink: 0;
      width: 56px;
      height: 56px;
      background: var(--giallo);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 22px;
      color: var(--blu);
      font-weight: 700;
    }
    .video-content { flex: 1; }
    .video-title {
      font-size: 16px;
      font-weight: 700;
      color: var(--giallo);
      margin-bottom: 8px;
    }
    .video-desc {
      font-size: 13px;
      line-height: 1.7;
      color: rgba(255,255,255,0.9);
      margin-bottom: 14px;
    }
    .btn-video {
      display: inline-block;
      background: var(--giallo);
      color: var(--blu);
      font-weight: 700;
      font-size: 14px;
      padding: 10px 20px;
      border-radius: 8px;
      text-decoration: none;
    }
    .btn-video:hover { opacity: 0.9; }

    /* footer */
    .site-footer {
      background: var(--blu);
      color: rgba(255, 255, 255, .75);
      text-align: center;
      padding: 20px;
      font-size: 12px;
      margin-top: 40px;
    }

    .site-footer strong {
      color: #fff;
    }

    /* fatal */
    .fatal-wrap {
      text-align: center;
      padding: 60px 20px;
    }

    .fatal-wrap h2 {
      color: var(--rosso);
      margin-bottom: 12px;
    }

    .fatal-wrap p {
      color: #666;
      font-size: 14px;
    }

    /* consulente card */
    .cons-card {
      display: flex;
      align-items: center;
      gap: 18px;
      flex-wrap: wrap;
    }

    .cons-avatar {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      background: var(--blu);
      color: var(--giallo);
      font-size: 20px;
      font-weight: 900;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .cons-info {
      flex: 1;
    }

    .cons-info .cons-nome {
      font-weight: 700;
      font-size: 16px;
      color: var(--blu);
    }

    .cons-info .cons-email {
      font-size: 12px;
      color: #888;
      margin-top: 2px;
    }

    .cons-btns {
      display: flex;
      gap: 10px;
    }

    .btn-tel,
    .btn-wa {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 10px 16px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 700;
      text-decoration: none;
      transition: opacity .2s;
      white-space: nowrap;
    }

    .btn-tel {
      background: var(--blu);
      color: #fff;
    }

    .btn-wa {
      background: #25D366;
      color: #fff;
    }

    .btn-tel:hover,
    .btn-wa:hover {
      opacity: .85;
    }

    @media(max-width:500px) {
      .cons-btns {
        flex-direction: column;
        width: 100%;
      }

      .btn-tel,
      .btn-wa {
        justify-content: center;
      }
    }

    /* valentina cta */
    .valentina-cta {
      background: var(--giallo);
      border-radius: 10px;
      padding: 24px 28px;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 20px;
      flex-wrap: wrap;
    }

    .valentina-cta .val-text {
      flex: 1;
    }

    .valentina-cta .val-title {
      font-size: 17px;
      font-weight: 900;
      color: #1A1A1A;
      margin-bottom: 4px;
    }

    .valentina-cta .val-sub {
      font-size: 13px;
      color: #333;
    }

    .btn-wa-big {
      background: #25D366;
      color: #fff;
      padding: 12px 22px;
      border-radius: 8px;
      font-weight: 700;
      font-size: 15px;
      text-decoration: none;
      white-space: nowrap;
      transition: opacity .2s;
    }

    .btn-wa-big:hover {
      opacity: .85;
    }

    @media(max-width:500px) {
      .valentina-cta {
        flex-direction: column;
        text-align: center;
      }

      .btn-wa-big {
        width: 100%;
        text-align: center;
        display: block;
      }
    }

    /* ── TERAPIA IN ELABORAZIONE BOX ── */
    .terapia-wip-box {
      border-left: 4px solid var(--giallo);
    }

    .terapia-wip-intro {
      font-size: 15px;
      color: #444;
      margin-bottom: 20px;
      line-height: 1.6;
    }

    .terapia-steps {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .terapia-step {
      display: flex;
      gap: 14px;
      align-items: flex-start;
      background: #F8F9FA;
      border-radius: 8px;
      padding: 14px 16px;
    }

    .terapia-step-icon {
      font-size: 24px;
      flex-shrink: 0;
      line-height: 1.3;
    }

    .terapia-step-text {
      font-size: 14px;
      color: #333;
      line-height: 1.6;
    }

    .terapia-step-text strong {
      display: block;
      color: var(--blu);
      font-size: 14px;
      margin-bottom: 4px;
    }

    /* radar container — larghezza aumentata per contenere le etichette dei 5 assi */
    .radar-wrap {
      max-width: 540px;
      margin: 0 auto 8px;
    }
  </style>
</head>

<body>

  <!-- ── HEADER ── -->
  <div class="site-header">
    <div class="brand">AIRA-DXTM</div>
    <h1>SEGNALAZIONE VINCENTE | AIRA-DXTM</h1>
  </div>

  <div class="wrap">

    <?php if (isset($fatal)): ?>
      <!-- ─── ERRORE FATALE ─── -->
      <div class="fatal-wrap">
        <h2>⚠ <?= htmlspecialchars($fatal) ?></h2>
        <p>Controlla il link ricevuto via email oppure contatta il tuo consulente.</p>
      </div>

    <?php elseif (!$verified): ?>
      <!-- ─── STATO 1: FORM OTP ─── -->

      <?php if ($msg === 'otp_sent'): ?>
        <div class="banner banner-green">✅ Nuovo codice inviato! Controlla la tua email.</div>
      <?php endif; ?>

      <?php if ($otp_error): ?>
        <div class="banner banner-red">❌ <?= htmlspecialchars($otp_error) ?></div>
      <?php endif; ?>

      <div class="otp-card">
        <h2>🔐 Il tuo Referto è pronto</h2>
        <p>Inserisci il codice a 6 cifre che hai ricevuto via email per accedere alla tua diagnosi personalizzata.</p>
        <form method="POST" action="report.php?id=<?= urlencode($id) ?>">
          <input class="otp-input" type="text" name="otp" inputmode="numeric" maxlength="6" placeholder="––––––"
            autocomplete="one-time-code" required autofocus>
          <button class="btn-primary" type="submit">Accedi al Referto →</button>
        </form>
        <a class="link-resend" href="report.php?action=regenerate_otp&id=<?= urlencode($id) ?>">
          Non hai ricevuto il codice? Richiedine uno nuovo
        </a>
      </div>

    <?php else: ?>
      <!-- ─── STATO 2: REFERTO COMPLETO ─── -->

      <?php
      $az = htmlspecialchars($record['cliente_azienda'] ?? '—');
      $settore = htmlspecialchars($record['cliente_settore'] ?? '—');
      $fatt = htmlspecialchars($record['cliente_fatturato'] ?? '—');
      $dip = htmlspecialchars($record['cliente_dipendenti'] ?? '—');
      $prod = htmlspecialchars($record['prodotto_principale'] ?? '—');
      $cons = htmlspecialchars($record['consulente_nome'] ?? '—');
      $created = fmt_date($record['created_at'] ?? '');
      $mat = (int) ($record['livello_maturita'] ?? 0);
      $matlab = htmlspecialchars($record['livello_maturita_label'] ?? '—');
      $pot_ref = (float) ($record['potenziale_referral_dormiente'] ?? 0);
      $pot_pip = (float) ($record['perdita_pipeline_annua'] ?? 0);
      $pot_cos = (float) ($record['costo_inefficienza_annua'] ?? 0);
      $mat_pct = $mat > 0 ? round($mat / 5 * 100) : 0;
      $mat_cls = $mat <= 2 ? 'fill-red' : ($mat <= 3 ? 'fill-orange' : 'fill-green');
      ?>

      <!-- SEZ 1 — HEADER REFERTO -->
      <div class="report-header">
        <div class="tag">Referto Diagnostico</div>
        <h1><?= $az ?></h1>
        <div class="sub">Elaborato il <?= $created ?> &nbsp;·&nbsp; Consulente: <?= $cons ?></div>
        <div class="scopo-report">
          <strong>Scopo del report:</strong> Questo documento restituisce una fotografia clinica della tua azienda nelle 4 aree strategiche che determinano la capacità di crescita commerciale. Per ogni area è stato misurato il divario tra la situazione attuale e il potenziale reale — trasformando quei divari in numeri concreti: fatturato non generato, opportunità non colte, risorse disperse. L'obiettivo non è descrivere il problema, ma rendere visibile il costo di non risolverlo — e creare le basi per una terapia su misura che il tuo consulente presenterà nel prossimo incontro.
        </div>
      </div>

      <!-- SEZ 2 — DATI IDENTIFICATIVI -->
      <div class="report-box">
        <h2>📋 Dati Identificativi</h2>
        <dl class="dati-grid">
          <div>
            <dt>Azienda</dt>
            <dd><?= $az ?></dd>
          </div>
          <div>
            <dt>Settore</dt>
            <dd><?= $settore ?></dd>
          </div>
          <div>
            <dt>Fatturato</dt>
            <dd><?= $fatt ?></dd>
          </div>
          <div>
            <dt>Dipendenti</dt>
            <dd><?= $dip ?></dd>
          </div>
          <div style="grid-column:1/-1">
            <dt>Prodotto / Servizio principale</dt>
            <dd><?= $prod ?></dd>
          </div>
        </dl>
      </div>

      <!-- SEZ 2B — IL TUO CONSULENTE -->
      <?php
      $cons_email = strtolower(trim($record['consulente_email'] ?? ''));
      $cinfo = consulente_info($cons_email);
      if ($cinfo):
        $c_nome = htmlspecialchars($cinfo['nome']);
        $c_tel = $cinfo['tel'];
        $c_wa = 'https://wa.me/' . ltrim(preg_replace('/[^0-9]/', '', $c_tel), '0');
        $c_tel_href = 'tel:' . $c_tel;
        $c_initials = implode('', array_map(fn($w) => mb_substr($w, 0, 1), explode(' ', $cinfo['nome'])));
        ?>
        <div class="report-box">
          <h2>👤 Il tuo Consulente</h2>
          <div class="cons-card">
            <div class="cons-avatar"><?= htmlspecialchars($c_initials) ?></div>
            <div class="cons-info">
              <div class="cons-nome"><?= $c_nome ?></div>
              <div class="cons-email"><?= htmlspecialchars($cons_email) ?></div>
            </div>
            <div class="cons-btns">
              <a class="btn-tel" href="<?= htmlspecialchars($c_tel_href) ?>">📞 Chiama</a>
              <a class="btn-wa" href="<?= htmlspecialchars($c_wa) ?>" target="_blank" rel="noopener">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                  <path
                    d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347" />
                  <path
                    d="M12 0C5.373 0 0 5.373 0 12c0 2.124.558 4.118 1.528 5.845L.057 23.428a.5.5 0 0 0 .609.61l5.652-1.485A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.802 9.802 0 0 1-5.012-1.374l-.36-.213-3.712.976.993-3.624-.236-.373A9.817 9.817 0 0 1 2.182 12C2.182 6.57 6.57 2.182 12 2.182S21.818 6.57 21.818 12 17.43 21.818 12 21.818z" />
                </svg>
                WhatsApp
              </a>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- SEZ 2C — VIDEO DI APPROFONDIMENTO -->
      <div class="report-box video-box">
        <div class="video-icon">▶</div>
        <div class="video-content">
          <div class="video-title">Preparati al nostro prossimo incontro</div>
          <div class="video-desc">Abbiamo preparato un video di 25 minuti che ti guida nel metodo di <strong>Ingegneria Relazionale</strong> di Segnalazione Vincente: come funziona la rete, gli strumenti che usiamo e come li applicheremo concretamente alla tua azienda. Guardarlo ora ti permetterà di arrivare alla prossima call già allineato, così potremo concentrarci subito sulle soluzioni per le tue sfide specifiche.</div>
          <a class="btn-video" href="https://www.segnalazionevincente.com/sv-sales" target="_blank" rel="noopener">▶ Guarda il Video</a>
        </div>
      </div>

      <!-- SEZ 3 — LIVELLO MATURITÀ -->
      <div class="report-box">
        <h2>🏗 Livello di Maturità Commerciale</h2>
        <p class="sezione-desc">Questo indicatore sintetizza il grado di strutturazione del tuo processo commerciale su una scala da 1 a 5. Non misura il fatturato né le dimensioni dell'azienda, ma la solidità del sistema che genera e gestisce le opportunità di vendita: se hai un metodo, se è ripetibile, se funziona anche quando tu non sei presente. Un'azienda con alta maturità commerciale cresce in modo prevedibile; una con bassa maturità dipende da fattori casuali e dalla bravura dei singoli.</p>
        <div style="text-align:center;padding:10px 0 18px">
          <div class="maturita-num"><?= $mat ?><span style="font-size:32px;color:#aaa">/5</span></div>
          <div class="maturita-label"><?= $matlab ?></div>
          <div class="progress-bar-wrap">
            <div class="progress-bar-fill <?= $mat_cls ?>" style="width:<?= $mat_pct ?>%"></div>
          </div>
        </div>
      </div>

      <!-- SEZ 4 — POTENZIALE NASCOSTO -->
      <?php if ($pot_ref > 0 || $pot_pip > 0 || $pot_cos > 0): ?>
        <div class="report-box">
          <h2>💡 Il Tuo Potenziale Nascosto</h2>
          <div class="pot-grid">
            <div class="pot-box pot-verde">
              <div class="pot-label">💤 Capitale Relazionale Dormiente</div>
              <div class="pot-val"><?= fmt_eur($pot_ref) ?></div>
            </div>
            <div class="pot-box pot-rosso">
              <div class="pot-label">💸 Perdita Pipeline Annua</div>
              <div class="pot-val"><?= fmt_eur($pot_pip) ?></div>
            </div>
            <div class="pot-box pot-rosso">
              <div class="pot-label">⚙ Costo Inefficienza Annua</div>
              <div class="pot-val"><?= fmt_eur($pot_cos) ?></div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- SEZ 5 — GAP PER AREA -->
      <div class="report-box">
        <h2>📊 Analisi GAP per Area</h2>
        <p class="sezione-desc">Il grafico radar mostra, per ciascuna delle 4 aree strategiche, la distanza tra dove sei oggi (<strong style="color:#1B3A6B">area blu</strong>) e dove vorresti essere (<strong style="color:#F5C842;text-shadow:0 0 1px #999">area gialla</strong>). Più le due superfici sono distanti, più quell'area richiede attenzione immediata. Un'area blu molto piccola non significa che il tema non conta — al contrario: segnala che lì l'azienda sta operando con un'inefficienza significativa, lasciando sul tavolo opportunità di crescita concrete e quantificabili. L'obiettivo della prossima call è trasformare ogni GAP visibile in un piano d'azione misurabile.</p>

        <!-- Grafico radar — include le 4 aree + voce "Media 4 Aree" come 5° punto -->
        <?php
        $r_vals = [];
        $d_vals = [];
        $areas_order = ['area1_relazionale', 'area2_automazione', 'area3_posizionamento', 'area4_crm'];
        foreach ($areas_order as $a) {
          $r_vals[] = $gaps[$a]['r'] ?? 0;
          $d_vals[] = $gaps[$a]['d'] ?? 0;
        }
        // Calcola media realtà e media desiderio delle 4 aree
        $media_r = count($r_vals) > 0 ? round(array_sum($r_vals) / count($r_vals), 1) : 0;
        $media_d = count($d_vals) > 0 ? round(array_sum($d_vals) / count($d_vals), 1) : 0;
        // Aggiunge la voce "Media 4 Aree" come 5° asse del radar
        $r_vals_radar = array_merge($r_vals, [$media_r]);
        $d_vals_radar = array_merge($d_vals, [$media_d]);
        ?>
        <!-- Larghezza aumentata per dare spazio alle etichette radar -->
        <div class="radar-wrap">
          <canvas id="radarChart"></canvas>
        </div>
        <script>
          (function () {
            var ctx = document.getElementById('radarChart').getContext('2d');
            new Chart(ctx, {
              type: 'radar',
              data: {
                // 5 assi: le 4 aree + la media complessiva
                labels: [
                  'Ingegneria Relazionale',
                  'Automazione e Processi',
                  'Posizionamento e Marketing',
                  'CRM e Pipeline',
                  'Media 4 Aree'
                ],
                datasets: [
                  {
                    label: 'Realtà attuale',
                    data: <?= json_encode($r_vals_radar) ?>,
                    backgroundColor: 'rgba(27,58,107,0.20)',
                    borderColor: '#1B3A6B',
                    borderWidth: 2,
                    pointBackgroundColor: '#1B3A6B',
                    pointRadius: 4
                  },
                  {
                    label: 'Desiderio',
                    data: <?= json_encode($d_vals_radar) ?>,
                    backgroundColor: 'rgba(245,200,66,0.18)',
                    borderColor: '#F5C842',
                    borderWidth: 2,
                    pointBackgroundColor: '#F5C842',
                    pointRadius: 4
                  }
                ]
              },
              options: {
                responsive: true,
                scales: {
                  r: {
                    min: 0,
                    max: 10,
                    ticks: { stepSize: 2, font: { size: 10 }, color: '#888' },
                    // Font più grande per le etichette degli assi + padding per non tagliarle
                    pointLabels: {
                      font: { size: 12, weight: '600' },
                      color: '#1B3A6B',
                      padding: 12
                    },
                    grid: { color: 'rgba(0,0,0,.08)' }
                  }
                },
                plugins: {
                  legend: { position: 'bottom', labels: { font: { size: 12 }, padding: 16 } }
                },
                layout: {
                  // Padding extra attorno al grafico per le etichette lunghe
                  padding: { top: 16, bottom: 8, left: 16, right: 16 }
                }
              }
            });
          })();
        </script>

        <!-- Barre dettaglio -->
        <div style="margin-top:20px">
          <?php foreach ($gaps as $akey => $gdata):
            $is_ferita = ($akey === $max_area);
            $g = $gdata['gap'];
            $r_pct = round($gdata['r'] / 10 * 100);
            $d_pct = round($gdata['d'] / 10 * 100);
            $badge_cls = $g >= 5 ? 'badge-red' : 'badge-grey';
            $badge_lbl = $g >= 5 ? '⚠ Alto dolore' : ($g >= 3 ? 'Attenzione' : 'Secondaria');
            ?>
            <div class="gap-row <?= $is_ferita ? 'ferita' : '' ?>">
              <div class="gap-label">
                <span><?= ($is_ferita ? '🩸 ' : '') . htmlspecialchars(area_label($akey)) ?></span>
                <span class="gap-badge <?= $badge_cls ?>"><?= $badge_lbl ?></span>
              </div>
              <div style="display:flex;align-items:center;gap:14px">
                <div style="flex:1">
                  <div class="bar-dual">
                    <div class="bar-desiderio" style="width:<?= $d_pct ?>%"></div>
                    <div class="bar-realta" style="width:<?= $r_pct ?>%"></div>
                  </div>
                  <div class="bar-legend">
                    <span class="l-r">Realtà <?= $gdata['r'] ?>/10</span>
                    <span class="l-d">Desiderio <?= $gdata['d'] ?>/10</span>
                  </div>
                </div>
                <div style="text-align:center;flex-shrink:0">
                  <div class="gap-num">+<?= $g ?></div>
                  <div style="font-size:10px;color:#888">gap</div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- SEZ 6 — DIAGNOSI AIRA-DXTM -->
      <?php if (!empty($record['referto_ai'])):
        $referto_text = strip_terapie($record['referto_ai']);
        ?>
        <div class="report-box">
          <h2>🤖 Diagnosi AIRA-DXTM</h2>
          <div class="referto-content">
            <?= md_to_html($referto_text) ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- SEZ 7 — TERAPIA IN ELABORAZIONE -->
      <!-- Questo box appare sempre dopo la diagnosi per spiegare che la soluzione arriva nella call -->
      <div class="report-box terapia-wip-box">
        <h2>⚗️ La Tua Terapia Personalizzata è in Elaborazione</h2>
        <div class="terapia-wip-content">
          <p class="terapia-wip-intro">
            In questi giorni il tuo consulente e il team di <strong>Segnalazione Vincente</strong> stanno lavorando sulla tua terapia.
          </p>
          <div class="terapia-steps">
            <div class="terapia-step">
              <div class="terapia-step-icon">🔍</div>
              <div class="terapia-step-text">
                <strong>Analisi approfondita dei dati raccolti</strong><br>
                Stiamo esaminando ogni risposta e ogni indicatore emerso dal checkup per costruire un quadro preciso della tua situazione.
              </div>
            </div>
            <div class="terapia-step">
              <div class="terapia-step-icon">🧩</div>
              <div class="terapia-step-text">
                <strong>Costruzione di soluzioni su misura</strong><br>
                Non esiste una terapia standard. Ogni intervento viene calibrato sulla realtà specifica della tua azienda, del tuo settore e del tuo modello di business.
              </div>
            </div>
            <div class="terapia-step">
              <div class="terapia-step-icon">🤖</div>
              <div class="terapia-step-text">
                <strong>Scansione con AIRA del database Rete Connettori</strong><br>
                Stiamo scandagliando con AIRA il nostro database per identificare sinergie, casi simili e opportunità concrete da presentarti.
              </div>
            </div>
            <div class="terapia-step">
              <div class="terapia-step-icon">📋</div>
              <div class="terapia-step-text">
                <strong>Presentazione nel prossimo incontro</strong><br>
                Nel prossimo incontro con il tuo consulente riceverai una terapia studiata appositamente per la tua azienda, con azioni concrete e misurabili.
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- CTA VALENTINA AI — bottone con messaggio precompilato per Valentina -->
      <?php
      // Messaggio precompilato per Valentina — URL encoded per WhatsApp deep link
      $valentina_msg = urlencode('Ciao Valentina, sto guardando il referto diagnostico 4 aree di Segnalazione Vincente, ho delle domande da farti, sei disponibile? ');
      $valentina_url = 'https://wa.me/393514510290?text=' . $valentina_msg;
      ?>
      <div class="valentina-cta">
        <div class="val-text">
          <div class="val-title">💬 Hai domande sul tuo referto?</div>
          <div class="val-sub">Chatta con <strong>Valentina</strong>, la nostra AI disponibile 24h su 24h su WhatsApp.
            Risponde subito a qualsiasi domanda sulla tua diagnosi.</div>
        </div>
        <a class="btn-wa-big" href="<?= htmlspecialchars($valentina_url) ?>" target="_blank" rel="noopener">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"
            style="vertical-align:middle;margin-right:6px">
            <path
              d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347" />
            <path
              d="M12 0C5.373 0 0 5.373 0 12c0 2.124.558 4.118 1.528 5.845L.057 23.428a.5.5 0 0 0 .609.61l5.652-1.485A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.802 9.802 0 0 1-5.012-1.374l-.36-.213-3.712.976.993-3.624-.236-.373A9.817 9.817 0 0 1 2.182 12C2.182 6.57 6.57 2.182 12 2.182S21.818 6.57 21.818 12 17.43 21.818 12 21.818z" />
          </svg>
          Chatta con Valentina
        </a>
      </div>

      <!-- SEZ 9 — RIEPILOGO COMPLETO DATI CHECKUP SV -->
      <?php
      // Leggo tutti i JSON nidificati se sono stringhe
      $risposte_a1 = $record['risposte_area1'] ?? [];
      $risposte_a2 = $record['risposte_area2'] ?? [];
      $risposte_a3 = $record['risposte_area3'] ?? [];
      $risposte_a4 = $record['risposte_area4'] ?? [];
      if (is_string($risposte_a1)) $risposte_a1 = json_decode($risposte_a1, true) ?? [];
      if (is_string($risposte_a2)) $risposte_a2 = json_decode($risposte_a2, true) ?? [];
      if (is_string($risposte_a3)) $risposte_a3 = json_decode($risposte_a3, true) ?? [];
      if (is_string($risposte_a4)) $risposte_a4 = json_decode($risposte_a4, true) ?? [];
      $gap_area = $record['gap_per_area'] ?? [];
      if (is_string($gap_area)) $gap_area = json_decode($gap_area, true) ?? [];
      $punteggi_r = $record['punteggi_realta'] ?? [];
      $punteggi_d = $record['punteggi_desiderio'] ?? [];
      if (is_string($punteggi_r)) $punteggi_r = json_decode($punteggi_r, true) ?? [];
      if (is_string($punteggi_d)) $punteggi_d = json_decode($punteggi_d, true) ?? [];
      function rv($v) { return htmlspecialchars($v ?? '') ?: '<em style="color:#bbb">—</em>'; }
      function rn($v, $suffix = '') { $v = $v ?? ''; return ($v !== '' && $v != 0) ? htmlspecialchars($v) . $suffix : '<em style="color:#bbb">—</em>'; }
      ?>
      <div class="report-box riepilogo-dati-box">
        <h2>📁 Riepilogo Dati Raccolti — Checkup SV</h2>
        <p class="riepilogo-intro">Registro completo di tutte le informazioni raccolte durante il checkup diagnostico e utilizzate per generare questo referto.</p>

        <!-- BLOCCO A: ANAGRAFICA -->
        <div class="riepilogo-blocco">
          <div class="riepilogo-blocco-title">🏢 A — Anagrafica Cliente</div>
          <table class="riepilogo-table">
            <tr><td>Azienda</td><td><?= rv($record['cliente_azienda']) ?></td><td>Referente</td><td><?= rv($record['cliente_nome']) ?></td></tr>
            <tr><td>Email cliente</td><td><?= rv($record['cliente_email']) ?></td><td>Sito web</td><td><?= rv($record['cliente_sito_web']) ?></td></tr>
            <tr><td>Partita IVA</td><td><?= rv($record['cliente_partita_iva']) ?></td><td>Settore</td><td><?= rv($record['cliente_settore']) ?></td></tr>
            <tr><td>Fascia fatturato</td><td><?= rv($record['cliente_fatturato']) ?></td><td>Dipendenti</td><td><?= rv($record['cliente_dipendenti']) ?></td></tr>
            <tr><td>Venditori interni</td><td><?= rn($record['venditori_interni']) ?></td><td>Consulente</td><td><?= rv($record['consulente_nome']) ?> (<?= rv($record['consulente_email']) ?>)</td></tr>
            <tr><td colspan="4">Prodotto / Servizio principale: <?= rv($record['prodotto_principale']) ?></td></tr>
          </table>
        </div>

        <!-- BLOCCO B: DATI COMMERCIALI -->
        <div class="riepilogo-blocco">
          <div class="riepilogo-blocco-title">📈 B — Dati Commerciali e KPI</div>
          <table class="riepilogo-table">
            <tr><td>Clienti attivi</td><td><?= rn($record['clienti_attivi']) ?></td><td>Database storico</td><td><?= rn($record['database_storico']) ?></td></tr>
            <tr><td>LTV annualizzato</td><td><?= $record['ltv_annualizzato'] ? fmt_eur((float)$record['ltv_annualizzato']) : '<em style="color:#bbb">—</em>' ?></td><td>Venditori interni</td><td><?= rn($record['venditori_interni']) ?></td></tr>
            <tr><td>Conversione lead freddo</td><td><?= rn($record['conversione_freddo'], '%') ?></td><td>Conversione referenziato</td><td><?= rn($record['conversione_referral'], '%') ?></td></tr>
            <tr><td>Delta conversione mensile</td><td><?= $record['delta_conversione_mensile'] ? fmt_eur((float)$record['delta_conversione_mensile']) : '<em style="color:#bbb">—</em>' ?></td><td>Maturità commerciale</td><td><?= rn($record['livello_maturita']) ?>/5 — <?= rv($record['livello_maturita_label']) ?></td></tr>
          </table>
        </div>

        <!-- BLOCCO C: POTENZIALE CALCOLATO -->
        <div class="riepilogo-blocco">
          <div class="riepilogo-blocco-title">💡 C — Potenziale Calcolato (elaborato dal sistema)</div>
          <table class="riepilogo-table">
            <tr><td>Capitale relazionale dormiente</td><td><?= $record['potenziale_referral_dormiente'] ? fmt_eur((float)$record['potenziale_referral_dormiente']) : '<em style="color:#bbb">—</em>' ?></td><td>Perdita pipeline annua</td><td><?= $record['perdita_pipeline_annua'] ? fmt_eur((float)$record['perdita_pipeline_annua']) : '<em style="color:#bbb">—</em>' ?></td></tr>
            <tr><td>Costo inefficienza annua</td><td><?= $record['costo_inefficienza_annua'] ? fmt_eur((float)$record['costo_inefficienza_annua']) : '<em style="color:#bbb">—</em>' ?></td><td>Ferita principale</td><td><?= rv($record['ferita_principale_label']) ?></td></tr>
            <tr><td>Gap totale medio</td><td><?= rn($record['gap_totale']) ?></td><td colspan="2"></td></tr>
          </table>
        </div>

        <!-- BLOCCO D: SCORING 4 AREE -->
        <div class="riepilogo-blocco">
          <div class="riepilogo-blocco-title">📊 D — Scoring 4 Aree (Realtà / Desiderio / Gap)</div>
          <table class="riepilogo-table">
            <tr class="riepilogo-header-row"><th>Area</th><th>Realtà</th><th>Desiderio</th><th>Gap</th></tr>
            <?php
            $aree_map = ['area1_relazionale' => 'Ingegneria Relazionale', 'area2_automazione' => 'Automazione e Processi', 'area3_posizionamento' => 'Posizionamento e Marketing', 'area4_crm' => 'CRM e Pipeline'];
            foreach ($aree_map as $k => $lbl):
              $r = $punteggi_r[$k] ?? '—';
              $d = $punteggi_d[$k] ?? '—';
              $g = $gap_area[$k] ?? '—';
              $ferita_cls = ($record['ferita_principale'] ?? '') === $k ? 'riepilogo-ferita' : '';
            ?>
            <tr class="<?= $ferita_cls ?>">
              <td><?= ($ferita_cls ? '🩸 ' : '') . $lbl ?></td>
              <td><?= $r ?>/10</td>
              <td><?= $d ?>/10</td>
              <td><strong><?= $g ?></strong></td>
            </tr>
            <?php endforeach; ?>
          </table>
        </div>

        <!-- BLOCCO E: RISPOSTE AREA 1 -->
        <div class="riepilogo-blocco">
          <div class="riepilogo-blocco-title">🤝 E — Area 1: Ingegneria Relazionale — Risposte Consulente</div>
          <table class="riepilogo-table">
            <tr><td>Ha un sistema di segnalazione attivo?</td><td colspan="3"><?= rv($risposte_a1['a1_q1'] ?? '') ?></td></tr>
            <tr><td>Chiede segnalazioni in modo sistematico?</td><td colspan="3"><?= rv($risposte_a1['a1_q2'] ?? '') ?></td></tr>
            <tr><td>Ha un processo definito per gestire le referenze?</td><td colspan="3"><?= rv($risposte_a1['a1_q3'] ?? '') ?></td></tr>
            <tr><td>Tasso di conversione da referenza?</td><td colspan="3"><?= rv($risposte_a1['a1_q4'] ?? '') ?></td></tr>
            <tr><td>Ha un sistema di compenso/incentivo per chi segnala?</td><td colspan="3"><?= rv($risposte_a1['a1_q5'] ?? '') ?></td></tr>
            <tr><td>Visione: 30% del fatturato da referenze?</td><td colspan="3"><?= rv($risposte_a1['a1_q6'] ?? '') ?></td></tr>
          </table>
        </div>

        <!-- BLOCCO F: RISPOSTE AREA 2 -->
        <div class="riepilogo-blocco">
          <div class="riepilogo-blocco-title">⚙️ F — Area 2: Automazione e Processi — Risposte Consulente</div>
          <table class="riepilogo-table">
            <tr><td>Usa strumenti digitali nel processo commerciale?</td><td colspan="3"><?= rv($risposte_a2['a2_q1'] ?? '') ?></td></tr>
            <tr><td>Ore/sett. su attività manuali ripetibili</td><td><?= rn($risposte_a2['a2_q2_ore'] ?? '', ' h/sett.') ?></td><td>Costo orario stimato</td><td><?= rn($risposte_a2['a2_q2_costo'] ?? '', ' €/h') ?></td></tr>
            <tr><td>Fa follow-up sistematico sui lead non chiusi?</td><td colspan="3"><?= rv($risposte_a2['a2_q3'] ?? '') ?></td></tr>
            <tr><td>Automatizza comunicazioni post-vendita?</td><td colspan="3"><?= rv($risposte_a2['a2_q4'] ?? '') ?></td></tr>
            <tr><td>Misura il tempo medio di risposta ai lead?</td><td colspan="3"><?= rv($risposte_a2['a2_q5'] ?? '') ?></td></tr>
          </table>
        </div>

        <!-- BLOCCO G: RISPOSTE AREA 3 -->
        <div class="riepilogo-blocco">
          <div class="riepilogo-blocco-title">🎯 G — Area 3: Posizionamento e Marketing — Risposte Consulente</div>
          <table class="riepilogo-table">
            <tr><td>Ha una proposta di valore (USP) chiara e differenziante?</td><td colspan="3"><?= rv($risposte_a3['a3_q1'] ?? '') ?></td></tr>
            <tr><td>Sa spiegare in 30 secondi perché sceglierlo?</td><td colspan="3"><?= rv($risposte_a3['a3_q2'] ?? '') ?></td></tr>
            <tr><td>Il sito web genera lead qualificati in modo attivo?</td><td colspan="3"><?= rv($risposte_a3['a3_q3'] ?? '') ?></td></tr>
            <tr><td>Fa campagne marketing strutturate?</td><td colspan="3"><?= rv($risposte_a3['a3_q4'] ?? '') ?></td></tr>
            <tr><td>Qualità media dei lead in entrata?</td><td colspan="3"><?= rv($risposte_a3['a3_q5'] ?? '') ?></td></tr>
          </table>
        </div>

        <!-- BLOCCO H: RISPOSTE AREA 4 -->
        <div class="riepilogo-blocco">
          <div class="riepilogo-blocco-title">🔄 H — Area 4: CRM e Pipeline — Risposte Consulente</div>
          <table class="riepilogo-table">
            <tr><td>Usa un CRM o sistema di tracciamento delle trattative?</td><td colspan="3"><?= rv($risposte_a4['a4_q1'] ?? '') ?></td></tr>
            <tr><td>% trattative perse per mancato follow-up</td><td><?= rn($risposte_a4['a4_q2_perc'] ?? '', '%') ?></td><td>Trattative medie/mese</td><td><?= rn($risposte_a4['a4_q2_trattative'] ?? '') ?></td></tr>
            <tr><td>Fa riattivazione clienti dormienti in modo sistematico?</td><td colspan="3"><?= rv($risposte_a4['a4_q3'] ?? '') ?></td></tr>
            <tr><td>Misura il tasso di chiusura per fonte di lead?</td><td colspan="3"><?= rv($risposte_a4['a4_q4'] ?? '') ?></td></tr>
            <tr><td>Ha procedure standard per gestire obiezioni e trattative?</td><td colspan="3"><?= rv($risposte_a4['a4_q5'] ?? '') ?></td></tr>
          </table>
        </div>

        <!-- BLOCCO I: NOTE CONSULENTE -->
        <?php if (!empty($record['note_consulente'])): ?>
        <div class="riepilogo-blocco">
          <div class="riepilogo-blocco-title">📝 I — Note del Consulente</div>
          <div class="riepilogo-note-testo"><?= nl2br(htmlspecialchars($record['note_consulente'])) ?></div>
        </div>
        <?php endif; ?>

      </div>

    <?php endif; // end !$verified ?>

  </div><!-- /wrap -->

  <!-- ── FOOTER ── -->
  <div class="site-footer">
    <strong>AIRA-DXTM</strong> &mdash; Sistema Diagnostico Intelligente<br>
    Questo referto è stato elaborato per uso esclusivo del destinatario.<br>
    Segnalazione Vincente &mdash; Migastone International Srl
  </div>

</body>

</html>