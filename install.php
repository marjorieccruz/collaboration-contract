<?php
/* Collaboration Contract · one-time installer.
   Open https://your-domain/install.php, fill in the form, then DELETE this file. */
declare(strict_types=1);
require __DIR__ . '/api/lib.php';
$done = false; $err = '';
if (cc_config()) { $err = 'Already installed. Delete install.php from the server.'; }
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $host = trim($_POST['host'] ?? ''); $name = trim($_POST['db'] ?? '');
    $cfg = ['dsn' => "mysql:host=$host;dbname=$name;charset=utf8mb4",
            'user' => trim($_POST['user'] ?? ''), 'pass' => (string)($_POST['pass'] ?? ''),
            'allowed_domain' => trim($_POST['domain'] ?? '')];
    $email = strtolower(trim($_POST['temail'] ?? '')); $tpw = (string)($_POST['tpw'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($tpw) < 10) throw new Exception('Teacher e-mail and a password with at least 10 characters are required.');
    $db = cc_db($cfg);
    foreach (cc_schema($db) as $sql) $db->exec($sql);
    $st = $db->prepare('SELECT id FROM cc_users WHERE email = ?'); $st->execute([$email]);
    if ($id = $st->fetchColumn()) $db->prepare('UPDATE cc_users SET is_teacher = 1, pw_hash = ? WHERE id = ?')->execute([password_hash($tpw, PASSWORD_DEFAULT), $id]);
    else $db->prepare('INSERT INTO cc_users (email, name, pw_hash, is_teacher, created_at) VALUES (?,?,?,1,?)')->execute([$email, 'Teacher', password_hash($tpw, PASSWORD_DEFAULT), now()]);
    if (file_put_contents(CC_CONFIG_FILE, "<?php\nreturn " . var_export($cfg, true) . ";\n") === false) throw new Exception('Could not write api/config.php (check folder permissions).');
    $done = true;
  } catch (Throwable $e) { $err = $e->getMessage(); }
}
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install · Collaboration Contract</title>
<style>body{font:15px/1.5 system-ui,sans-serif;max-width:560px;margin:40px auto;padding:0 16px}label{display:block;margin-top:12px;font-weight:600}
input{width:100%;padding:8px;font:inherit;box-sizing:border-box}button{margin-top:18px;padding:10px 18px;font:inherit;font-weight:700}
.err{color:#b5333a;font-weight:700}.ok{color:#1f8a70;font-weight:700}small{color:#666}</style></head><body>
<h1>Collaboration Contract · Install</h1>
<?php if ($done): ?>
  <p class="ok">✓ Installed. Tables created and teacher account ready.</p>
  <p><b>Now delete <code>install.php</code> from the server</b> (file manager or FTP).</p>
  <p>Then open <a href="teacher.html">teacher.html</a> and sign in.</p>
<?php else: ?>
  <?php if ($err): ?><p class="err"><?= htmlspecialchars($err) ?></p><?php endif; ?>
  <?php if (!cc_config()): ?>
  <form method="post">
    <h3>1. Database (from your hosting panel)</h3>
    <label>Host</label><input name="host" value="localhost" required>
    <label>Database name</label><input name="db" required>
    <label>Database user</label><input name="user" required>
    <label>Database password</label><input name="pass" type="password">
    <h3>2. Your teacher account</h3>
    <label>E-mail</label><input name="temail" type="email" required>
    <label>Password <small>(min. 10 characters)</small></label><input name="tpw" type="password" required>
    <h3>3. Optional</h3>
    <label>Only accept student e-mails from this domain <small>(e.g. it-u.at, leave empty for any)</small></label><input name="domain">
    <button>Install</button>
  </form>
  <?php endif; ?>
<?php endif; ?>
</body></html>
