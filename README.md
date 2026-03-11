# ⚡ Checkup Diagnostico SV — AIRA-DXTM

> **Strumento diagnostico commerciale** per la metodologia "Segnalazione Vincente" di Migastone International Srl.  
> URL di produzione: `aira.reteconnettori.com/checkupsv/`  
> Database: Supabase — progetto `MigaMATCH AIRA` (`eywxidahsumzrlzwnfev`)

---

## 📋 Panoramica

Il sistema **Checkup SV** è un tool web che permette ai consulenti Migastone di condurre una sessione diagnostica strutturata con i propri prospect. Al termine della sessione viene generato automaticamente un **referto personalizzato** con analisi AI e metriche economiche, accessibile dal prospect tramite OTP.

Il sistema comprende 3 componenti principali:

| File | Tipo | Ruolo |
|------|------|-------|
| `index.html` | HTML + JS | Form diagnostico multi-step per il consulente |
| `dashboard.php` | PHP | Dashboard operatori con login OTP e gestione record |
| `report.php` | PHP | Pagina referto cliente con accesso OTP |
| `config.php` | PHP | File di configurazione centralizzato (chiavi API e segreti) |

---

## 🗂️ Struttura del Database (Supabase)

### Tabella principale: `Checkup_SV`

| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `id` | UUID | Chiave primaria |
| `consulente_nome` / `consulente_email` | TEXT | Operatore che ha condotto la sessione |
| `cliente_nome` / `cliente_email` / `cliente_azienda` | TEXT | Dati anagrafici prospect |
| `cliente_partita_iva` | TEXT UNIQUE | P.IVA prospect |
| `cliente_cellulare` / `cliente_sito_web` | TEXT | Contatti |
| `punteggi_realta` | JSONB | Punteggi realtà per le 4 aree (scala 1-10) |
| `punteggi_desiderio` | JSONB | Punteggi desiderio per le 4 aree (scala 1-10) |
| `gap_per_area` | JSONB | GAP per area: `{area1_relazionale, area2_automazione, area3_posizionamento, area4_crm}` |
| `gap_totale` | NUMERIC | Somma totale dei gap |
| `ferita_principale` / `ferita_principale_label` | TEXT | Area con il gap maggiore |
| `livello_maturita` | INT (1-5) | Indice di maturità commerciale |
| `livello_maturita_label` | TEXT | Label: Caos / Gestito / Definito / Ottimizzato / Strategico |
| `risposte_area1..4` | JSONB | Risposte qualitative per ciascuna area |
| `costo_inefficienza_annua` | NUMERIC | `ore × 52 × costo_orario × venditori` |
| `perdita_pipeline_annua` | NUMERIC | `(perc/100) × trattative × ltv × 12` |
| `potenziale_referral_dormiente` | NUMERIC | `clienti_attivi × 0.15 × ltv_annualizzato` |
| `delta_conversione_mensile` | NUMERIC | `(conv_referral - conv_freddo) / 100 × 10 × ltv` |
| `referto_ai` | TEXT | Testo del referto generato da AI |
| `otp_hash` | TEXT | Hash SHA-256 del codice OTP del cliente |
| `otp_expires_at` | TIMESTAMPTZ | Scadenza OTP (30 min dalla creazione) |
| `otp_used` | BOOLEAN | Flag OTP già consumato |
| `report_accessed` / `report_accessed_at` | BOOL/TIMESTAMP | Tracking accesso al report |
| `trascrizione` | TEXT | Trascrizione della call (editabile dalla dashboard) |
| `analisi_call` | TEXT | Analisi AI della call generata da Perplexity (auto-generata al salvataggio trascrizione) |

### Tabelle di supporto

| Tabella | Descrizione |
|---------|-------------|
| `operatori_sv` | Consulenti abilitati: `nome`, `email`, `cellulare`, `attivo` |
| `operator_otp` | OTP di accesso alla dashboard operatori (6 cifre, scadenza 15 min) |
| `operator_sessions` | Sessioni persistenti operatori (cookie 14 giorni) |

---

## 📁 File Dettagliati

### `index.html` — Form Diagnostico

Il form multi-step che guida il consulente durante la call con il prospect.

#### Flusso: 7 sezioni (sec0 → sec6)

| Sezione | ID | Contenuto |
|---------|----|-----------|
| 0 | `sec0` | Selezione consulente (dropdown hardcoded) |
| 1 | `sec1` | **Blocco 0** — Dati aziendali e commerciali |
| 2 | `sec2` | **Area 1** — Ingegneria Relazionale (referral, network) |
| 3 | `sec3` | **Area 2** — Automazione e Processi |
| 4 | `sec4` | **Area 3** — Posizionamento e Marketing |
| 5 | `sec5` | **Area 4** — CRM e Gestione Pipeline |
| 6 | `sec6` | Riepilogo diagnostico + invio |

#### Funzionalità principali

- **Calcoli live**: potenziale referral dormiente, delta conversione, costo inefficienza, perdita pipeline
- **Slider doppi** per ogni area: `Realtà` e `Desiderio` (scala 1-10) → calcolo gap automatico
- **Script coach**: suggerimenti testuali dinamici in base al gap rilevato
- **Sessione locale**: salvataggio progressivo in `localStorage` con chiave `checkup_sv_data`
- **TEST connessione**: bottone per verificare la connessione Supabase prima della sottomissione
- **Invio finale**: POST a webhook N8N (`flow.migastone.com/webhook/...`) che elabora i dati e genera il referto AI
- **Reset sessione**: cancella `localStorage` e riporta a sec0

#### Consulenti disponibili (hardcoded)

```
Russo Marica         → m.russo@migastone.com
Chiarolanza Roberto  → r.chiarolanza@migastone.com
Coslop Walter        → w.coslop@migastone.com
Dalvit Oscar         → o.dalvit@migastone.com
De Vita Fabio        → f.devita@migastone.com
```

---

### `dashboard.php` — Dashboard Operatori

Pannello di amministrazione riservato agli operatori Migastone, con autenticazione OTP via email.

#### Autenticazione (2 step)

1. **Step 1 — Email**: l'operatore inserisce la sua email aziendale
   - Verifica che l'email sia in `operatori_sv` con `attivo = true`
   - Genera OTP a 6 cifre → hasha con `password_hash()` → salva in `operator_otp`
   - Invia OTP via **SendGrid** (`no-reply@migastone.com`)
2. **Step 2 — OTP**: l'operatore inserisce il codice
   - Verifica hash con `password_verify()` + controllo scadenza (15 min)
   - Se valido: crea token di sessione (64 caratteri hex) in `operator_sessions`
   - Cookie persistente `sv_operator_session` (14 giorni, `HttpOnly`, `SameSite=Strict`)
   - **Passpartout**: codice `220783` bypassa la verifica (uso interno)

#### Dashboard (post-login)

- **Filtro per operatore**: mostra i propri record di default, può vedere tutti
- **Tabella record** con colonne: Data, Operatore, Azienda, Cliente, P.IVA, Gap, e azioni:

| Bottone | Colore | Comportamento |
|---------|--------|---------------|
| 📄 **Report** | Blu | Apre `report.php` in nuova tab **senza OTP** (bypass via token HMAC) |
| 📝 / ✅ **Trascrizione** | Viola / Verde | Viola = vuota, Verde = presente. Apre modal per editare e salvare la trascrizione. Al salvataggio avvia automaticamente l'analisi AI |
| 🎯 **Analisi** | Cyan | Disponibile (cyan) se analisi generata, grigio (🔒) se assente. Apre modal con pagella + analisi discorsiva |
| 📞 **Chiama** | Verde | Link `tel:` diretto al cellulare del prospect |
| 💬 **WA** | Verde | Link `wa.me/` con numero pulito (solo cifre) |
| 📋 **Offerta** | Arancio | Placeholder — funzione in sviluppo |
| 🗑️ **Cancella** | Rosso | Elimina il record da Supabase dopo conferma. Rimuove la riga con animazione fade |

- **Badge GAP** colorati: rosso (≥5), arancio (≥3), verde (<3)
- **Logout**: elimina la sessione da DB e cancella il cookie

#### Funzionalità ANALISI CALL (Perplexity AI)

Flusso automatico al salvataggio trascrizione:

1. Operatore incolla la trascrizione nel modal → clicca **Salva**
2. La trascrizione viene salvata + l'analisi precedente viene cancellata
3. Viene avviata automaticamente una chiamata a **Perplexity `sonar-pro`**
4. Il pulsante 🔒 Analisi diventa ⏳ Analisi... (disabilitato)
5. L'analisi viene salvata nel campo `analisi_call` del record Supabase
6. Il pulsante diventa 🎯 Analisi (cyan, cliccabile)
7. Cliccando si apre un modal (ora con supporto al rendering Markdown per grassetti e newline) con:
   - **Analisi discorsiva** focalizzata sulla motivazione del consulente e valutazioni puntuali sulle varie aree (fino a 1000 parole)
   - **Aree di miglioramento pratiche**, con esempi e riformulazioni specifiche estratte testualmente dalla trascrizione
   - **📊 PAGELLA DI PERFORMANCE** (spostata sul fondo) con 6 criteri base 10 (Apertura, Raccolta Dati, Emorragia, Proposta, Obiezioni, Tempo) e Voto Generale
   - Bottone **📋 Copia testo**

Il prompt segue il **Metodo Stoico** e include le istruzioni del file `01_Call Prequalifica 4Aree - ISTRUZIONI.md` + tutti i dati del record Supabase.

#### Bypass OTP per Report dalla Dashboard

Quando dalla dashboard si clicca **📄 Report**, l'URL include un parametro `dtok` — un token HMAC-SHA256 (16 char) firmato con `DASH_SECRET` e con finestra di validità di ±1 ora. `report.php` verifica il token e, se valido, salta la richiesta di OTP e marca la sessione PHP come verificata.

#### Costanti di configurazione (in `config.php` e altri script)

```php
SUPABASE_URL     → https://eywxidahsumzrlzwnfev.supabase.co
SUPABASE_KEY     → anon key JWT
SENDGRID_KEY     → API key SendGrid
SENDGRID_FROM    → no-reply@migastone.com
OTP_PASSPARTOUT  → 220783
SESSION_COOKIE   → sv_operator_session
SESSION_DAYS     → 14
PERPLEXITY_KEY   → API key Perplexity AI (modello sonar-pro)
DASH_SECRET      → sv-dash-2026-migastone (segreto HMAC per bypass OTP)
```

#### AJAX endpoint

| Endpoint | Metodo | Descrizione |
|----------|--------|-------------|
| `?ajax=trascrizione` | POST | Salva trascrizione + azzera `analisi_call` nel record |
| `?ajax=analisi_call` | POST | Genera analisi con Perplexity + salva in `analisi_call` |
| `?ajax=get_analisi` | POST | Legge `analisi_call` salvata (senza rigenerare) |
| `?ajax=delete` | POST | Elimina il record da `Checkup_SV` |

---

### `report.php` — Referto Cliente

Pagina pubblica (con protezione OTP) che il prospect consulta per leggere la propria diagnosi.

#### Accesso OTP cliente

- L'URL contiene `?id={uuid}` del record in `Checkup_SV`
- Il prospect deve inserire il codice OTP ricevuto via email (generato dal workflow N8N)
- Verifica: `simpleHash(otp_input) === otp_hash` + controllo scadenza + `otp_used === false`
- Dopo verifica: `otp_used = true`, `report_accessed = true`, sessione PHP `$_SESSION["verified_{id}"]`
- **Passpartout**: codice `220783` bypassa sempre la verifica
- **Bypass dashboard**: se `?dtok={token}` è presente e valido (HMAC firmato da `dashboard.php`), l'OTP viene saltato

#### Struttura del referto (sezioni)

1. **Header**: nome azienda, data elaborazione, consulente
2. **Dati Identificativi**: azienda, settore, fatturato, dipendenti, prodotto principale
3. **Il tuo Consulente**: avatar con iniziali, email, bottoni "📞 Chiama" e "💬 WhatsApp"
4. **Livello di Maturità Commerciale**: numero grande (1-5/5) + label + progress bar colorata
5. **Potenziale Economico**: 3 box colorati (Capitale Dormiente, Perdita Pipeline, Costo Inefficienza)
6. **GAP per Area**: barre duali (realtà vs desiderio) + badge rosso sulla "ferita principale" + **Radar chart** (Chart.js)
7. **Referto AI**: testo AI (sezione "Terapie Prioritarie" rimossa automaticamente via `strip_terapie()`)
8. **Terapia in Elaborazione**: 3 step illustrativi del processo successivo
9. **Valentina AI**: CTA box giallo con link WhatsApp al servizio AI
10. **Riepilogo Dati Completo**: tabelle dettagliate di tutte le risposte del checkup
11. **Footer**: AIRA-DXTM / Migastone International Srl

---

## 🔄 Flusso Completo

```
1. Consulente apre index.html
2. Seleziona se stesso + inserisce dati prospect (7 sezioni)
3. Clicca "Genera Referto" → POST webhook N8N
4. N8N elabora i dati:
   - Calcola gap, livello maturità, metriche economiche
   - Genera referto AI (GPT)
   - Crea OTP + salva tutto in Checkup_SV
   - Invia email al prospect con link report.php?id={uuid}
5. Prospect clicca il link, inserisce OTP → accede al referto
6. Consulente vede tutto dalla dashboard.php:
   - Filtra i propri record
   - Apre il report direttamente (senza OTP, bypass HMAC)
   - Inserisce la trascrizione → l'analisi AI si genera automaticamente
   - Legge la pagella + analisi discorsiva nel modal Analisi Call
   - Chiama o manda WA al prospect
   - Cancella record se necessario
```

---

## 🔌 Integrazioni Esterne

| Servizio | Uso |
|----------|-----|
| **Supabase** | Database principale (REST API via anon key) |
| **N8N** (flow.migastone.com) | Webhook di ricezione form + generazione AI + invio email |
| **SendGrid** | Invio email OTP agli operatori dalla dashboard |
| **Perplexity AI** (`sonar-pro`) | Generazione analisi call dalla dashboard operatori |
| **Chart.js** (CDN jsDelivr) | Radar chart nel referto cliente |

---

## 🔐 Sicurezza

- RLS abilitata su tutte le tabelle Supabase
- Policy: `anon` può fare SELECT/INSERT/UPDATE/DELETE sulle tabelle di sessione
- OTP operatori: `password_hash()` / `password_verify()` (bcrypt)
- OTP clienti: `simpleHash()` (hash custom 8 char hex — hash debole, adeguato per uso interno)
- Cookie sessione: `HttpOnly`, `SameSite=Strict`, 14 giorni
- Bypass dashboard: token HMAC-SHA256 firmato con `DASH_SECRET`, validità ±1 ora
- Input sanitizzati con `htmlspecialchars()` / `preg_replace()` prima dell'uso in query

---

## 🛠️ Requisiti di Deployment

- **PHP** ≥ 7.4 con estensioni: `curl`, `json`, `session`
- **HTTPS** consigliato (cookie SameSite funziona meglio)
- Nessun framework, no dipendenze Composer — tutto standalone
- File serviti direttamente da webserver (Apache/Nginx con PHP)
- Chiave Perplexity AI configurata in `PERPLEXITY_KEY` in `dashboard.php`
