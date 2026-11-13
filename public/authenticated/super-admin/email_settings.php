<?php
declare(strict_types=1);
/**
 * super-admin/email_settings.php - which Gmail account the store sends from.
 *
 * Verification codes, password resets, support confirmations and order mail
 * all leave through one SMTP account. This is where it is changed.
 *
 * It lives HERE and not in tools/ for one reason: tools/set_email_settings.php
 * is the same form with no login on it at all, which is why .htaccess blocks
 * that whole folder from the browser (the 403 is the block working, not a
 * fault). A form that redirects where the store's mail comes from has to be
 * behind a session and a role, and the owner panel is the only place that is
 * true of. Same writes as execution/set_email_settings.php, which stays as the
 * terminal path.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_SA'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

require_role(['super-admin']);

$pdo = getConnection();
ensureEmailSettingsTable();

$notice = null;
$noticeKind = 'ok';

/* ---- save a new sending account --------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_smtp') {
    if (!check_csrf()) {
        $notice = 'Your session expired. Refresh the page and try again.';
        $noticeKind = 'err';
    } else {
        $username  = trim((string)($_POST['username'] ?? ''));
        // Google shows app passwords in groups of four and people paste them
        // that way; the spaces are not part of the password.
        $password  = str_replace(' ', '', (string)($_POST['password'] ?? ''));
        $fromName  = trim((string)($_POST['from_name'] ?? 'TechWorld'));
        $fromEmail = trim((string)($_POST['from_email'] ?? '')) ?: $username;
        $provider  = in_array($_POST['provider'] ?? 'gmail', ['gmail', 'mailtrap', 'custom'], true)
                   ? (string)$_POST['provider'] : 'gmail';
        $host      = trim((string)($_POST['host'] ?? ''));
        $port      = (int)($_POST['port'] ?? 587);
        $secure    = in_array($_POST['secure'] ?? 'tls', ['tls', 'ssl', ''], true) ? (string)$_POST['secure'] : 'tls';

        if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $notice = 'Enter the full email address of the sending account.';
            $noticeKind = 'err';
        } elseif ($password === '') {
            $notice = 'Enter the App Password for that account.';
            $noticeKind = 'err';
        } elseif (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $notice = 'The "From" address is not a valid email address.';
            $noticeKind = 'err';
        } else {
            try {
                $pdo->beginTransaction();
                // One active row at a time: getActiveEmailSettings() takes the
                // newest active one, and an old row left active is how a change
                // silently fails to take effect.
                $pdo->exec("UPDATE email_settings SET is_active = 0");
                $pdo->prepare("INSERT INTO email_settings
                    (provider, host, port, username, password, secure, from_email, from_name, is_active)
                    VALUES (?,?,?,?,?,?,?,?,1)")
                    ->execute([$provider, $host ?: null, $port ?: null, $username, $password,
                               $secure ?: null, $fromEmail, $fromName ?: 'TechWorld']);
                $pdo->commit();

                // The password is deliberately not in the audit payload - the
                // log records that the account changed and to what, never the
                // secret itself.
                logAudit('email_settings_changed', 'email_settings', (int)$pdo->lastInsertId(), null, [
                    'username' => $username, 'from_email' => $fromEmail, 'provider' => $provider,
                ]);

                $notice = 'Saved. Mail now sends as ' . $username . '. Send a test below before trusting it.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('Email settings save failed: ' . $e->getMessage());
                $notice = 'Could not save the settings. Please try again.';
                $noticeKind = 'err';
            }
        }
    }
}

/* ---- send a test ------------------------------------------------------- */
// Separate action on purpose: proving the account works is its own step, and
// a test is a real email to a real person.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'test_smtp') {
    if (!check_csrf()) {
        $notice = 'Your session expired. Refresh the page and try again.';
        $noticeKind = 'err';
    } else {
        $to = trim((string)($_POST['test_to'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $notice = 'Enter a valid address to send the test to.';
            $noticeKind = 'err';
        } else {
            $sent = sendEmail(
                $to, '', 'TechWorld email test',
                '<p>This is a test from the owner panel.</p>'
                . '<p>If you are reading it, verification codes will send from this account.</p>',
                'Test from the TechWorld owner panel.'
            );
            $notice = $sent
                ? 'Test sent to ' . $to . '. Check the inbox, and the spam folder.'
                : 'The test could not be sent. The usual cause is a wrong App Password, or 2-Step Verification not being on for that Google account.';
            $noticeKind = $sent ? 'ok' : 'err';
        }
    }
}

$active = getActiveEmailSettings();
// Previous accounts, for the record. Passwords are never selected here - this
// page shows what was used and when, not the secrets.
$history = [];
try {
    $history = $pdo->query("SELECT id, username, from_email, from_name, provider, is_active, created_at
                            FROM email_settings ORDER BY id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('Email settings history failed: ' . $e->getMessage()); }

$pageTitle = 'Email Settings - TechWorld';
$activeNav = 'email';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<style>
.es-wrap { max-width: 860px; margin: 0 auto; padding: 24px 20px 48px; }
.es-head h1 { color: var(--admin-text); font-size: 24px; font-weight: 800; margin: 0 0 6px; }
.es-head p { color: var(--admin-muted); font-size: 14px; margin: 0 0 20px; line-height: 1.6; }

.es-notice { padding: 12px 16px; border-radius: var(--radius-sm); font-size: 14px; font-weight: 600; margin-bottom: 18px; line-height: 1.5; }
.es-notice.ok { background: rgba(95,168,138,.16); color: var(--admin-success, #5fa88a); }
.es-notice.err { background: rgba(194,104,111,.16); color: var(--admin-danger, #c2686f); }

.es-card { background: var(--admin-surface-2); border: 1px solid var(--admin-border); border-radius: var(--radius-lg); padding: 20px; margin-bottom: 18px; }
.es-card h2 { color: var(--admin-text); font-size: 16px; font-weight: 800; margin: 0 0 4px; }
.es-card .note { color: var(--admin-muted); font-size: 13px; line-height: 1.6; margin: 0 0 16px; }

/* The account in force, stated before anything can be changed. */
.es-current { display: grid; grid-template-columns: 130px 1fr; gap: 8px 14px; font-size: 13.5px; }
.es-current dt { color: var(--admin-muted); font-weight: 700; }
.es-current dd { margin: 0; color: var(--admin-text); overflow-wrap: anywhere; }
.es-current dd.mono { font-family: ui-monospace, Consolas, monospace; }

.es-form label { display: block; color: var(--admin-muted); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; margin: 0 0 6px; }
.es-form input, .es-form select { width: 100%; padding: 11px 13px; border-radius: var(--radius-sm); border: 1px solid var(--admin-border); background: var(--admin-surface); color: var(--admin-text); font: inherit; font-size: 14px; margin-bottom: 4px; }
.es-form .hint { color: var(--admin-muted); font-size: 12px; line-height: 1.5; margin: 0 0 14px; }
.es-form .hint a { color: var(--admin-accent, #a05f80); }
.es-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 620px) { .es-row { grid-template-columns: 1fr; } .es-current { grid-template-columns: 1fr; gap: 2px 0; } }

.es-btn { display: inline-flex; align-items: center; gap: 8px; min-height: 42px; padding: 10px 18px; border-radius: var(--radius-sm); border: 1px solid var(--admin-border); background: var(--admin-surface); color: var(--admin-text); font: inherit; font-weight: 700; font-size: 14px; cursor: pointer; }
.es-btn.primary { background: var(--admin-primary-2); border-color: transparent; color: #f2f3f4; }

.es-adv summary { cursor: pointer; color: var(--admin-muted); font-size: 13px; font-weight: 700; margin: 4px 0 14px; }
.es-adv[open] summary { margin-bottom: 12px; }

.es-hist { list-style: none; margin: 0; padding: 0; }
.es-hist li { display: flex; gap: 12px; align-items: baseline; padding: 11px 0; border-bottom: 1px solid var(--admin-border); font-size: 13.5px; color: var(--admin-text); flex-wrap: wrap; }
.es-hist li:last-child { border-bottom: none; }
.es-hist .when { color: var(--admin-muted); font-size: 12px; min-width: 150px; font-variant-numeric: tabular-nums; }
.es-hist .tag { font-size: 10px; font-weight: 800; letter-spacing: .5px; text-transform: uppercase; padding: 3px 8px; border-radius: 999px; background: rgba(95,168,138,.18); color: var(--admin-success, #5fa88a); }
.es-warn { border-left: 3px solid #d6a23a; background: var(--admin-surface); padding: 12px 16px; border-radius: var(--radius-sm); color: var(--admin-muted); font-size: 13px; line-height: 1.6; }
</style>

<main class="container">
<div class="es-wrap">

  <div class="es-head">
    <h1>Email Settings</h1>
    <p>
      The account every outgoing message leaves from &mdash; login verification codes,
      password resets, support confirmations and order mail.
    </p>
  </div>

  <?php if ($notice): ?>
    <div class="es-notice <?= $noticeKind ?>"><?= htmlspecialchars($notice) ?></div>
  <?php endif; ?>

  <div class="es-card">
    <h2>Sending now</h2>
    <?php if ($active): ?>
      <p class="note">This is the account in force. Changing it below takes effect on the next email sent.</p>
      <dl class="es-current">
        <dt>Account</dt><dd class="mono"><?= htmlspecialchars((string)($active['username'] ?: '—')) ?></dd>
        <dt>Shown as</dt><dd><?= htmlspecialchars((string)($active['from_name'] ?: '—')) ?> &lt;<?= htmlspecialchars((string)($active['from_email'] ?: '—')) ?>&gt;</dd>
        <dt>Server</dt><dd class="mono"><?= htmlspecialchars((string)($active['provider'])) ?>
          &middot; <?= htmlspecialchars((string)($active['host'] ?: 'smtp.gmail.com')) ?>:<?= (int)($active['port'] ?: 587) ?>
          &middot; <?= htmlspecialchars(strtoupper((string)($active['secure'] ?: 'none'))) ?></dd>
        <?php /* Length only. The password is needed in plain text to talk to
                 Gmail, so it is stored - but nothing displays it back. */ ?>
        <dt>App Password</dt><dd><?= $active['password'] ? str_repeat('&bull;', 12) . ' (' . strlen((string)$active['password']) . ' characters)' : 'not set' ?></dd>
        <dt>Last changed</dt><dd><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string)($active['updated_at'] ?? $active['created_at'] ?? 'now')))) ?></dd>
      </dl>
    <?php else: ?>
      <p class="note">
        No account is configured, so mail falls back to the <code>SMTP_*</code> values in
        <code>.env</code> and then to PHP's own <code>mail()</code>, which on XAMPP sends nothing.
        Set one below.
      </p>
    <?php endif; ?>
  </div>

  <div class="es-card">
    <h2>Change the sending account</h2>
    <p class="note">
      Gmail needs an <strong>App Password</strong>, not the account's own password. Turn on
      2-Step Verification for that Google account first, then create one at
      <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">myaccount.google.com/apppasswords</a>.
    </p>

    <form method="POST" class="es-form" autocomplete="off"
          data-confirm="Change the account all system email is sent from?" data-confirm-ok="Change it">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_smtp">

      <div class="es-row">
        <div>
          <label for="esUser">Gmail address</label>
          <input type="email" id="esUser" name="username" required maxlength="255"
                 placeholder="store@gmail.com" value="<?= htmlspecialchars((string)($active['username'] ?? '')) ?>">
          <p class="hint">The account that signs in to send.</p>
        </div>
        <div>
          <label for="esPass">App Password</label>
          <input type="password" id="esPass" name="password" required maxlength="255"
                 placeholder="16 characters" autocomplete="new-password">
          <p class="hint">Spaces are ignored, so paste it exactly as Google shows it.</p>
        </div>
      </div>

      <div class="es-row">
        <div>
          <label for="esFromName">Sender name</label>
          <input type="text" id="esFromName" name="from_name" maxlength="255"
                 value="<?= htmlspecialchars((string)($active['from_name'] ?? 'TechWorld')) ?>">
          <p class="hint">What recipients see instead of the raw address.</p>
        </div>
        <div>
          <label for="esFromEmail">&ldquo;From&rdquo; address</label>
          <input type="email" id="esFromEmail" name="from_email" maxlength="255"
                 placeholder="same as the Gmail address"
                 value="<?= htmlspecialchars((string)($active['from_email'] ?? '')) ?>">
          <p class="hint">Leave as the Gmail address: Gmail rewrites anything else unless it is a verified alias.</p>
        </div>
      </div>

      <?php /* Folded away because the Gmail preset is right for every normal
               case - host and port are filled in by sendEmail() when blank. */ ?>
      <details class="es-adv">
        <summary>Advanced (other providers)</summary>
        <div class="es-row">
          <div>
            <label for="esProvider">Provider</label>
            <select id="esProvider" name="provider">
              <?php foreach (['gmail' => 'Gmail', 'mailtrap' => 'Mailtrap', 'custom' => 'Custom SMTP'] as $v => $lbl): ?>
                <option value="<?= $v ?>" <?= ($active['provider'] ?? 'gmail') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
              <?php endforeach; ?>
            </select>
            <p class="hint">Gmail and Mailtrap fill in their own host.</p>
          </div>
          <div>
            <label for="esSecure">Encryption</label>
            <select id="esSecure" name="secure">
              <option value="tls" <?= ($active['secure'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (port 587)</option>
              <option value="ssl" <?= ($active['secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (port 465)</option>
            </select>
            <p class="hint">&nbsp;</p>
          </div>
        </div>
        <div class="es-row">
          <div>
            <label for="esHost">SMTP host</label>
            <input type="text" id="esHost" name="host" maxlength="255" placeholder="leave blank for the preset"
                   value="<?= htmlspecialchars((string)($active['host'] ?? '')) ?>">
            <p class="hint">&nbsp;</p>
          </div>
          <div>
            <label for="esPort">Port</label>
            <input type="number" id="esPort" name="port" min="1" max="65535"
                   value="<?= (int)($active['port'] ?? 587) ?>">
            <p class="hint">&nbsp;</p>
          </div>
        </div>
      </details>

      <button type="submit" class="es-btn primary">Save sending account</button>
    </form>
  </div>

  <div class="es-card">
    <h2>Send a test</h2>
    <p class="note">
      Nothing proves the settings except a message that arrives. Send one to yourself before
      relying on login codes going out.
    </p>
    <form method="POST" class="es-form" style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="test_smtp">
      <div style="flex:1 1 260px;">
        <label for="esTestTo">Send to</label>
        <input type="email" id="esTestTo" name="test_to" required maxlength="255"
               value="<?= htmlspecialchars((string)($_SESSION['email'] ?? '')) ?>">
      </div>
      <button type="submit" class="es-btn" style="margin-top:22px;">Send test email</button>
    </form>
  </div>

  <?php if ($history): ?>
  <div class="es-card">
    <h2>Previous accounts</h2>
    <p class="note">Old rows are kept rather than deleted, so a wrong change can be undone by re-entering the account that worked.</p>
    <ul class="es-hist">
      <?php foreach ($history as $h): ?>
        <li>
          <span class="when"><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string)$h['created_at']))) ?></span>
          <span style="flex:1;min-width:0;overflow-wrap:anywhere;"><?= htmlspecialchars((string)$h['username']) ?></span>
          <?php if ((int)$h['is_active'] === 1): ?><span class="tag">In use</span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <div class="es-card">
    <h2>Why this is not in <code>tools/</code></h2>
    <div class="es-warn">
      <code>tools/set_email_settings.php</code> is the same form with no login on it, which is why
      <code>.htaccess</code> blocks that folder from the browser &mdash; the &ldquo;Forbidden&rdquo; page is that
      block working. Anyone who found the URL could have pointed the store's mail at their own
      account. This page does the same job behind your owner login, and
      <code>execution/set_email_settings.php</code> does it from a terminal. The old file in
      <code>tools/</code> can be deleted.
    </div>
  </div>

</div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
