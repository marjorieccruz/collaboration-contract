<?php
/* Collaboration Contract · JSON API
   All calls: POST api/?a=<action>  with a JSON body. Session cookie keeps the login. */
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (!cc_config()) fail('not installed: open install.php', 503);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('use POST', 405);
// Basic CSRF protection: browsers cannot send application/json cross-site without CORS preflight.
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) fail('bad content type', 415);

start_session();
$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$a  = $_GET['a'] ?? '';
$db = cc_db();
cc_ensure_schema($db);
$cfg = cc_config();

try {
switch ($a) {

  /* ---------------- AUTH ---------------- */
  case 'register': {
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $pw = (string)($in['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Please enter a valid e-mail.');
    if (strlen($pw) < 8) fail('Password must have at least 8 characters.');
    $dom = trim((string)($cfg['allowed_domain'] ?? ''));
    if ($dom !== '' && !str_ends_with($email, '@' . strtolower($dom))) fail("Please use your @$dom e-mail address.");
    $st = $db->prepare('SELECT 1 FROM cc_users WHERE email = ?'); $st->execute([$email]);
    if ($st->fetchColumn()) fail('An account with this e-mail already exists. Please sign in.');
    $name = trim((string)($in['name'] ?? '')) ?: explode('@', $email)[0];
    $db->prepare('INSERT INTO cc_users (email, name, pw_hash, is_teacher, created_at) VALUES (?,?,?,0,?)')
       ->execute([$email, mb_substr($name, 0, 190), password_hash($pw, PASSWORD_DEFAULT), now()]);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$db->lastInsertId();
    out(['ok' => true]);
  }
  case 'login': {
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $st = $db->prepare('SELECT id, pw_hash FROM cc_users WHERE email = ?'); $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || !password_verify((string)($in['password'] ?? ''), $u['pw_hash'])) { usleep(600000); fail('Wrong e-mail or password.', 401); }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    out(['ok' => true]);
  }
  case 'logout': { $_SESSION = []; session_destroy(); out(['ok' => true]); }
  case 'me': {
    $u = current_user();
    if (!$u) out(['user' => null]);
    $st = $db->prepare('SELECT g.id, g.code, g.name, g.course, g.cohort FROM cc_memberships m JOIN cc_groups g ON g.id = m.group_id WHERE m.user_id = ? ORDER BY m.consent_at DESC');
    $st->execute([$u['id']]);
    out(['user' => ['id' => (int)$u['id'], 'email' => $u['email'], 'name' => $u['name'], 'role' => $u['role'], 'isTeacher' => is_staff($u)],
         'groups' => $st->fetchAll()]);
  }
  case 'change_password': {
    $u = need_user();
    $st = $db->prepare('SELECT pw_hash FROM cc_users WHERE id = ?'); $st->execute([$u['id']]);
    if (!password_verify((string)($in['old'] ?? ''), (string)$st->fetchColumn())) fail('Current password is wrong.', 401);
    if (strlen((string)($in['new'] ?? '')) < 8) fail('New password must have at least 8 characters.');
    $db->prepare('UPDATE cc_users SET pw_hash = ? WHERE id = ?')->execute([password_hash((string)$in['new'], PASSWORD_DEFAULT), $u['id']]);
    out(['ok' => true]);
  }

  /* ---------------- GROUP + CONTRACT ---------------- */
  case 'join': {
    $u = need_user();
    $code = strtoupper(trim((string)($in['code'] ?? '')));
    $st = $db->prepare('SELECT id, code, name, course, cohort FROM cc_groups WHERE code = ?'); $st->execute([$code]);
    $g = $st->fetch();
    if (!$g) fail('Code not found. Check it with your teacher.', 404);
    if (!is_member((int)$u['id'], (int)$g['id']))
      $db->prepare('INSERT INTO cc_memberships (user_id, group_id, consent_at) VALUES (?,?,?)')->execute([$u['id'], $g['id'], now()]);
    out(['group' => $g]);
  }
  case 'contract_get': {
    $u = need_user(); $gid = (int)($in['group'] ?? 0);
    if (!is_member((int)$u['id'], $gid) && !(int)$u['is_teacher']) fail('not allowed', 403);
    $st = $db->prepare('SELECT state, version FROM cc_contracts WHERE group_id = ?'); $st->execute([$gid]);
    $r = $st->fetch();
    out(['state' => $r ? json_decode($r['state'], true) : new stdClass(), 'version' => $r ? (int)$r['version'] : 0]);
  }
  case 'contract_save': {
    $u = need_user(); $gid = (int)($in['group'] ?? 0); $exp = (int)($in['expected'] ?? 0);
    if (!is_member((int)$u['id'], $gid) && !(int)$u['is_teacher']) fail('not allowed', 403);
    $state = json_encode($in['state'] ?? new stdClass(), JSON_UNESCAPED_UNICODE);
    if (strlen($state) > 1_000_000) fail('contract too large');
    $db->beginTransaction();
    $st = $db->prepare('SELECT version FROM cc_contracts WHERE group_id = ?'); $st->execute([$gid]);
    $cur = $st->fetchColumn();
    if ($cur === false) {
      if ($exp !== 0) { $db->rollBack(); fail('conflict', 409); }
      $db->prepare('INSERT INTO cc_contracts (group_id, state, version, round, updated_at, updated_by) VALUES (?,?,1,1,?,?)')->execute([$gid, $state, now(), $u['id']]);
      $v = 1;
    } else {
      if ((int)$cur !== $exp) { $db->rollBack(); fail('conflict', 409); }
      $v = $exp + 1;
      $db->prepare('UPDATE cc_contracts SET state = ?, version = ?, updated_at = ?, updated_by = ? WHERE group_id = ? AND version = ?')
         ->execute([$state, $v, now(), $u['id'], $gid, $exp]);
    }
    $rst = $db->prepare('SELECT round FROM cc_contracts WHERE group_id = ?'); $rst->execute([$gid]); $round = (int)($rst->fetchColumn() ?: 1);
    $db->prepare('INSERT INTO cc_contract_history (group_id, version, round, state, saved_at, saved_by) VALUES (?,?,?,?,?,?)')->execute([$gid, $v, $round, $state, now(), $u['id']]);
    $db->commit();
    out(['version' => $v]);
  }
  case 'events': {
    $u = need_user(); $gid = (int)($in['group'] ?? 0);
    if (!is_member((int)$u['id'], $gid)) fail('not allowed', 403);
    $ins = $db->prepare((cc_is_sqlite($db) ? 'INSERT OR IGNORE' : 'INSERT IGNORE') .
      ' INTO cc_events (group_id, user_id, session_id, seq, event, client_ts, payload, created_at) VALUES (?,?,?,?,?,?,?,?)');
    $n = 0;
    foreach (array_slice((array)($in['rows'] ?? []), 0, 500) as $e) {
      $ins->execute([$gid, $u['id'], mb_substr((string)($e['sessionId'] ?? ''), 0, 64), (int)($e['seq'] ?? 0),
        mb_substr((string)($e['event'] ?? ''), 0, 64), mb_substr((string)($e['ts'] ?? ''), 0, 40),
        json_encode($e, JSON_UNESCAPED_UNICODE), now()]);
      $n++;
    }
    out(['ok' => true, 'n' => $n]);
  }

  /* ---------------- TEACHER ---------------- */
  case 't_overview': {
    need_staff();
    $groups = $db->query('SELECT id, code, name, course, cohort, created_at FROM cc_groups ORDER BY course, id')->fetchAll();
    $con = []; foreach ($db->query('SELECT group_id, state, version, updated_at FROM cc_contracts') as $r)
      $con[$r['group_id']] = ['state' => json_decode($r['state'], true), 'version' => (int)$r['version'], 'updated_at' => $r['updated_at']];
    $mem = []; foreach ($db->query('SELECT m.group_id, u.email, u.name FROM cc_memberships m JOIN cc_users u ON u.id = m.user_id') as $r)
      $mem[$r['group_id']][] = ['email' => $r['email'], 'name' => $r['name']];
    foreach ($groups as &$g) { $g['contract'] = $con[$g['id']] ?? null; $g['members'] = $mem[$g['id']] ?? []; }
    out(['groups' => $groups]);
  }
  case 't_activity': {
    need_staff();
    $since = gmdate('Y-m-d H:i:s', time() - 600);
    $st = $db->prepare('SELECT COUNT(DISTINCT uid) FROM (SELECT user_id AS uid FROM cc_events WHERE created_at >= ?
      UNION SELECT saved_by AS uid FROM cc_contract_history WHERE saved_at >= ?) t'); $st->execute([$since, $since]);
    $active = (int)$st->fetchColumn();
    $users = (int)$db->query('SELECT COUNT(*) FROM cc_users WHERE is_teacher = 0')->fetchColumn();
    $rows = $db->query('SELECT e.event, e.created_at, e.payload, g.name AS gname, g.code, u.name AS uname
      FROM cc_events e JOIN cc_groups g ON g.id = e.group_id JOIN cc_users u ON u.id = e.user_id
      ORDER BY e.id DESC LIMIT 40')->fetchAll();
    $feed = array_map(function($r){ $p = json_decode($r['payload'], true) ?: [];
      return ['event'=>$r['event'], 'at'=>$r['created_at'], 'group'=>$r['gname'], 'code'=>$r['code'], 'user'=>$r['uname'],
              'section'=>$p['sectionName'] ?? null, 'words'=>$p['wordCount'] ?? null, 'dwellMs'=>$p['dwellMs'] ?? null]; }, $rows);
    out(['activeUsers10min'=>$active, 'students'=>$users, 'serverTime'=>now(), 'feed'=>$feed]);
  }
  case 't_create_groups': {
    need_coach();
    $course = trim((string)($in['course'] ?? '')); $cohort = trim((string)($in['cohort'] ?? ''));
    $n = max(1, min(60, (int)($in['n'] ?? 0)));
    if ($course === '') fail('Course is required.');
    $st = $db->prepare('SELECT COUNT(*) FROM cc_groups WHERE course = ?'); $st->execute([$course]);
    $start = (int)$st->fetchColumn();
    $ins = $db->prepare('INSERT INTO cc_groups (code, name, course, cohort, created_at) VALUES (?,?,?,?,?)');
    $made = [];
    for ($i = 1; $i <= $n; $i++) {
      for ($try = 0; $try < 5; $try++) {
        $code = new_code($course);
        try { $ins->execute([$code, 'Group ' . ($start + $i), $course, $cohort, now()]); $made[] = $code; break; }
        catch (PDOException $e) { /* duplicate code, try again */ }
      }
    }
    out(['created' => $made]);
  }
  case 't_reset_password': {
    need_coach();
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $pw = substr(str_replace(['+','/','='], '', base64_encode(random_bytes(9))), 0, 10);
    $st = $db->prepare('UPDATE cc_users SET pw_hash = ? WHERE email = ?');
    $st->execute([password_hash($pw, PASSWORD_DEFAULT), $email]);
    if (!$st->rowCount()) fail('No account with this e-mail.', 404);
    out(['password' => $pw]);
  }
  case 't_remove_member': {
    need_coach();
    $st = $db->prepare('SELECT id FROM cc_users WHERE email = ?'); $st->execute([strtolower(trim((string)($in['email'] ?? '')))]);
    $uid = $st->fetchColumn(); if (!$uid) fail('No account with this e-mail.', 404);
    $db->prepare('DELETE FROM cc_memberships WHERE user_id = ? AND group_id = ?')->execute([$uid, (int)($in['group'] ?? 0)]);
    out(['ok' => true]);
  }
  case 't_export': {
    need_staff();
    $what = $in['what'] ?? 'events';
    if ($what === 'history') $rows = $db->query('SELECT group_id, version, state, saved_at, saved_by FROM cc_contract_history ORDER BY group_id, version')->fetchAll();
    else $rows = array_map(fn($r) => json_decode($r['payload'], true) + ['_groupId' => (int)$r['group_id'], '_userId' => (int)$r['user_id']],
                           $db->query('SELECT group_id, user_id, payload FROM cc_events ORDER BY id')->fetchAll());
    out(['rows' => $rows]);
  }

  /* ---------------- SESSION NOTES (author-only, never exported for research) ---------------- */
  case 'n_list': {
    $u = need_coach();
    $sql = 'SELECT n.*, g.name AS group_name, g.code AS group_code FROM cc_notes n
            LEFT JOIN cc_groups g ON g.id = n.group_id WHERE n.author_id = ?';
    $args = [$u['id']];
    if (!empty($in['group'])) { $sql .= ' AND n.group_id = ?'; $args[] = (int)$in['group']; }
    if (!empty($in['course'])) { $sql .= ' AND n.course = ?'; $args[] = (string)$in['course']; }
    $sql .= ' ORDER BY n.note_date DESC, n.id DESC LIMIT 500';
    $st = $db->prepare($sql); $st->execute($args);
    out(['notes' => $st->fetchAll()]);
  }
  case 'n_save': {
    $u = need_coach();
    $date = (string)($in['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('Please pick a date.');
    $gid = (int)($in['group'] ?? 0) ?: null;
    $course = mb_substr(trim((string)($in['course'] ?? '')), 0, 100);
    $cohort = mb_substr(trim((string)($in['cohort'] ?? '')), 0, 100);
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 190);
    $att = mb_substr(trim((string)($in['attendees'] ?? '')), 0, 500);
    $body = mb_substr((string)($in['body'] ?? ''), 0, 20000);
    $fu = mb_substr((string)($in['followups'] ?? ''), 0, 5000);
    $id = (int)($in['id'] ?? 0);
    if ($id) {
      $st = $db->prepare('UPDATE cc_notes SET note_date=?, group_id=?, course=?, cohort=?, title=?, attendees=?, body=?, followups=?, updated_at=?
                          WHERE id=? AND author_id=?');
      $st->execute([$date, $gid, $course, $cohort, $title, $att, $body, $fu, now(), $id, $u['id']]);
      if (!$st->rowCount()) fail('Note not found.', 404);
    } else {
      $db->prepare('INSERT INTO cc_notes (author_id, note_date, group_id, course, cohort, title, attendees, body, followups, created_at, updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)')
         ->execute([$u['id'], $date, $gid, $course, $cohort, $title, $att, $body, $fu, now(), now()]);
      $id = (int)$db->lastInsertId();
    }
    out(['id' => $id]);
  }
  case 'n_delete': {
    $u = need_coach();
    $st = $db->prepare('DELETE FROM cc_notes WHERE id = ? AND author_id = ?');
    $st->execute([(int)($in['id'] ?? 0), $u['id']]);
    if (!$st->rowCount()) fail('Note not found.', 404);
    out(['ok' => true]);
  }

  /* ---------------- PEOPLE (owner only) ---------------- */
  case 'u_list': {
    need_owner();
    out(['users' => $db->query("SELECT id, email, name, role, created_at FROM cc_users WHERE role <> 'student' ORDER BY role, email")->fetchAll(),
         'studentCount' => (int)$db->query("SELECT COUNT(*) FROM cc_users WHERE role = 'student'")->fetchColumn()]);
  }
  case 'u_create': {
    $me = need_owner();
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $role = (string)($in['role'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Please enter a valid e-mail.');
    if (!in_array($role, ['coach', 'researcher'], true)) fail('Pick coach or researcher.');
    $pw = substr(str_replace(['+','/','='], '', base64_encode(random_bytes(12))), 0, 12);
    $name = mb_substr(trim((string)($in['name'] ?? '')) ?: explode('@', $email)[0], 0, 190);
    $st = $db->prepare('SELECT id FROM cc_users WHERE email = ?'); $st->execute([$email]);
    if ($id = $st->fetchColumn()) {
      $db->prepare('UPDATE cc_users SET role = ?, pw_hash = ?, name = ? WHERE id = ?')
         ->execute([$role, password_hash($pw, PASSWORD_DEFAULT), $name, $id]);
    } else {
      $db->prepare('INSERT INTO cc_users (email, name, pw_hash, is_teacher, role, created_at) VALUES (?,?,?,0,?,?)')
         ->execute([$email, $name, password_hash($pw, PASSWORD_DEFAULT), $role, now()]);
    }
    out(['password' => $pw]);
  }
  case 'u_role': {
    $me = need_owner();
    $uid = (int)($in['id'] ?? 0);
    $role = (string)($in['role'] ?? '');
    if ($uid === (int)$me['id']) fail('You cannot change your own role.');
    if (!in_array($role, ['coach', 'researcher', 'student'], true)) fail('Unknown role.');
    $db->prepare('UPDATE cc_users SET role = ?, is_teacher = 0 WHERE id = ?')->execute([$role, $uid]);
    out(['ok' => true]);
  }

  /* ---------------- CONTRACT ROUNDS ---------------- */
  case 'contract_new_round': {
    $u = need_user(); $gid = (int)($in['group'] ?? 0);
    if (!is_member((int)$u['id'], $gid) && !in_array($u['role'], ['owner','coach'], true)) fail('not allowed', 403);
    $st = $db->prepare('SELECT round FROM cc_contracts WHERE group_id = ?'); $st->execute([$gid]);
    $cur = $st->fetchColumn();
    if ($cur === false) fail('No contract yet.', 404);
    $db->prepare('UPDATE cc_contracts SET round = round + 1 WHERE group_id = ?')->execute([$gid]);
    out(['round' => (int)$cur + 1]);
  }
  case 'contract_rounds': {
    $u = need_user(); $gid = (int)($in['group'] ?? 0);
    if (!is_member((int)$u['id'], $gid) && !is_staff($u)) fail('not allowed', 403);
    $st = $db->prepare('SELECT round, MIN(saved_at) AS first_saved, MAX(saved_at) AS last_saved, COUNT(*) AS saves,
                        MAX(version) AS last_version FROM cc_contract_history WHERE group_id = ? GROUP BY round ORDER BY round');
    $st->execute([$gid]);
    $rounds = $st->fetchAll();
    // the last state of each round, so the client can show what changed between rounds
    $states = [];
    foreach ($rounds as $r) {
      $q = $db->prepare('SELECT state FROM cc_contract_history WHERE group_id = ? AND round = ? ORDER BY version DESC LIMIT 1');
      $q->execute([$gid, $r['round']]);
      $states[(int)$r['round']] = json_decode((string)$q->fetchColumn(), true);
    }
    out(['rounds' => $rounds, 'states' => $states]);
  }

  /* ---------------- INSTRUMENTS (individual submissions) ----------------
     Visible to the person who wrote them, to coaches and to researchers.
     Never to teammates. */
  case 'sub_get': {
    $u = need_user();
    $inst = mb_substr((string)($in['instrument'] ?? ''), 0, 40);
    $uid = (int)($in['user'] ?? 0) ?: (int)$u['id'];
    if ($uid !== (int)$u['id'] && !is_staff($u)) fail('not allowed', 403);
    $st = $db->prepare('SELECT state, version, done, updated_at FROM cc_submissions WHERE user_id = ? AND instrument = ?');
    $st->execute([$uid, $inst]);
    $r = $st->fetch();
    out(['state' => $r ? json_decode($r['state'], true) : new stdClass(),
         'version' => $r ? (int)$r['version'] : 0, 'done' => $r ? (bool)(int)$r['done'] : false,
         'updated_at' => $r['updated_at'] ?? null]);
  }
  case 'sub_save': {
    $u = need_user();
    $inst = mb_substr((string)($in['instrument'] ?? ''), 0, 40);
    if ($inst === '') fail('unknown instrument');
    $state = json_encode($in['state'] ?? new stdClass(), JSON_UNESCAPED_UNICODE);
    if (strlen($state) > 500_000) fail('submission too large');
    $done = !empty($in['done']) ? 1 : 0;
    $gid = (int)($in['group'] ?? 0) ?: null;
    $st = $db->prepare('SELECT id, version FROM cc_submissions WHERE user_id = ? AND instrument = ?');
    $st->execute([$u['id'], $inst]); $row = $st->fetch();
    if ($row) {
      $v = (int)$row['version'] + 1;
      $db->prepare('UPDATE cc_submissions SET state = ?, version = ?, done = ?, group_id = ?, updated_at = ? WHERE id = ?')
         ->execute([$state, $v, $done, $gid, now(), $row['id']]);
      $sid = (int)$row['id'];
    } else {
      $db->prepare('INSERT INTO cc_submissions (user_id, group_id, instrument, state, version, done, created_at, updated_at) VALUES (?,?,?,?,1,?,?,?)')
         ->execute([$u['id'], $gid, $inst, $state, $done, now(), now()]);
      $sid = (int)$db->lastInsertId(); $v = 1;
    }
    $db->prepare('INSERT INTO cc_submission_history (submission_id, user_id, instrument, version, state, saved_at) VALUES (?,?,?,?,?,?)')
       ->execute([$sid, $u['id'], $inst, $v, $state, now()]);
    out(['version' => $v]);
  }
  case 't_submissions': {
    need_staff();
    $inst = mb_substr((string)($in['instrument'] ?? ''), 0, 40);
    $sql = 'SELECT s.id, s.user_id, s.group_id, s.instrument, s.state, s.version, s.done, s.updated_at,
                   u.name AS user_name, u.email AS user_email, g.name AS group_name, g.code AS group_code, g.course, g.cohort
            FROM cc_submissions s JOIN cc_users u ON u.id = s.user_id
            LEFT JOIN cc_groups g ON g.id = s.group_id';
    $args = [];
    if ($inst !== '') { $sql .= ' WHERE s.instrument = ?'; $args[] = $inst; }
    $sql .= ' ORDER BY g.course, g.name, u.name';
    $st = $db->prepare($sql); $st->execute($args);
    $rows = array_map(function($r){ $r['state'] = json_decode($r['state'], true); return $r; }, $st->fetchAll());
    out(['submissions' => $rows]);
  }

  /* ---------------- DANGER ZONE: clear a group's data (owner only) ---------------- */
  case 't_reset_group': {
    need_owner();
    $gid = (int)($in['group'] ?? 0);
    if (!$gid) fail('group required');
    if ((string)($in['confirm'] ?? '') !== 'DELETE') fail('confirmation required');
    $st = $db->prepare('SELECT code FROM cc_groups WHERE id = ?'); $st->execute([$gid]);
    $code = $st->fetchColumn();
    if (!$code) fail('group not found', 404);
    $counts = [];
    foreach ([['cc_contract_history','group_id'], ['cc_contracts','group_id'], ['cc_events','group_id'],
              ['cc_submissions','group_id'], ['cc_memberships','group_id']] as [$t, $col]) {
      $c = $db->prepare("SELECT COUNT(*) FROM $t WHERE $col = ?"); $c->execute([$gid]);
      $counts[$t] = (int)$c->fetchColumn();
      $db->prepare("DELETE FROM $t WHERE $col = ?")->execute([$gid]);
    }
    if (!empty($in['dropGroup'])) { $db->prepare('DELETE FROM cc_groups WHERE id = ?')->execute([$gid]); $counts['cc_groups'] = 1; }
    out(['deleted' => $counts, 'code' => $code]);
  }

  default: fail('unknown action', 404);
}
} catch (Throwable $e) {
  if ($db->inTransaction()) $db->rollBack();
  error_log('cc api: ' . $e->getMessage());
  fail('server error', 500);
}
