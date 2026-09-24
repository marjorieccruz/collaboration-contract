<?php
/* Collaboration Contract · PHP backend (shared helpers)
   Works on standard shared hosting (PHP 8 + MySQL/MariaDB). */

declare(strict_types=1);

const CC_CONFIG_FILE = __DIR__ . '/config.php';

function cc_config(): ?array {
  if (!is_file(CC_CONFIG_FILE)) return null;
  $c = require CC_CONFIG_FILE;
  return is_array($c) ? $c : null;
}

function cc_db(?array $cfg = null): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $cfg = $cfg ?? cc_config();
  if (!$cfg) throw new RuntimeException('not installed');
  $pdo = new PDO($cfg['dsn'], $cfg['user'] ?? null, $cfg['pass'] ?? null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

function cc_is_sqlite(PDO $db): bool { return $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'; }

function cc_schema(PDO $db): array {
  $ai   = cc_is_sqlite($db) ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
  $big  = cc_is_sqlite($db) ? 'TEXT' : 'LONGTEXT';
  $eng  = cc_is_sqlite($db) ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
  return [
    "CREATE TABLE IF NOT EXISTS cc_users (
       id $ai, email VARCHAR(190) NOT NULL UNIQUE, name VARCHAR(190) NULL,
       pw_hash VARCHAR(255) NOT NULL, is_teacher TINYINT NOT NULL DEFAULT 0,
       created_at DATETIME NOT NULL)$eng",
    "CREATE TABLE IF NOT EXISTS cc_groups (
       id $ai, code VARCHAR(40) NOT NULL UNIQUE, name VARCHAR(190) NULL,
       course VARCHAR(100) NULL, cohort VARCHAR(100) NULL, created_at DATETIME NOT NULL)$eng",
    "CREATE TABLE IF NOT EXISTS cc_memberships (
       user_id INT NOT NULL, group_id INT NOT NULL, consent_at DATETIME NOT NULL,
       PRIMARY KEY (user_id, group_id))$eng",
    "CREATE TABLE IF NOT EXISTS cc_contracts (
       group_id INT PRIMARY KEY, state $big NOT NULL, version INT NOT NULL DEFAULT 0,
       updated_at DATETIME NULL, updated_by INT NULL)$eng",
    "CREATE TABLE IF NOT EXISTS cc_contract_history (
       id $ai, group_id INT NOT NULL, version INT NOT NULL, state $big NOT NULL,
       saved_at DATETIME NOT NULL, saved_by INT NULL)$eng",
    "CREATE TABLE IF NOT EXISTS cc_notes (
       id $ai, author_id INT NOT NULL, note_date VARCHAR(10) NOT NULL,
       group_id INT NULL, course VARCHAR(100) NULL, cohort VARCHAR(100) NULL,
       title VARCHAR(190) NULL, attendees VARCHAR(500) NULL, body $big NULL, followups $big NULL,
       created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)$eng",
    "CREATE TABLE IF NOT EXISTS cc_meta (
       k VARCHAR(50) PRIMARY KEY, v VARCHAR(190) NOT NULL)$eng",
    "CREATE TABLE IF NOT EXISTS cc_events (
       id $ai, group_id INT NOT NULL, user_id INT NOT NULL, session_id VARCHAR(64) NOT NULL,
       seq INT NOT NULL, event VARCHAR(64) NOT NULL, client_ts VARCHAR(40) NULL, payload $big NOT NULL,
       created_at DATETIME NOT NULL, UNIQUE (user_id, session_id, seq))$eng",
  ];
}

/* Schema upgrades, applied once. Safe to call on every request (one small read). */
function cc_ensure_schema(PDO $db): void {
  static $done = false;
  if ($done) return;
  $done = true;
  $v = 0;
  try {
    $st = $db->query("SELECT v FROM cc_meta WHERE k = 'schema_version'");
    $v = (int)($st->fetchColumn() ?: 0);
  } catch (Throwable $e) { $v = 0; }
  if ($v >= 2) return;
  foreach (cc_schema($db) as $sql) { try { $db->exec($sql); } catch (Throwable $e) {} }
  // role column on users: owner | coach | researcher | student
  try { $db->exec("ALTER TABLE cc_users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'student'"); } catch (Throwable $e) {}
  try { $db->exec("UPDATE cc_users SET role = 'owner' WHERE is_teacher = 1 AND role <> 'owner'"); } catch (Throwable $e) {}
  try {
    $st = $db->prepare('INSERT INTO cc_meta (k, v) VALUES (?, ?)');
    $st->execute(['schema_version', '2']);
  } catch (Throwable $e) {
    try { $db->prepare('UPDATE cc_meta SET v = ? WHERE k = ?')->execute(['2', 'schema_version']); } catch (Throwable $e2) {}
  }
}

function now(): string { return gmdate('Y-m-d H:i:s'); }

function out($data, int $status = 200): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}
function fail(string $msg, int $status = 400): never { out(['error' => $msg], $status); }

function start_session(): void {
  session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30, 'path' => '/', 'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax',
  ]);
  ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
  session_name('ccsess');
  session_start();
}

function current_user(): ?array {
  if (empty($_SESSION['uid'])) return null;
  $st = cc_db()->prepare('SELECT id, email, name, is_teacher, role FROM cc_users WHERE id = ?');
  $st->execute([$_SESSION['uid']]);
  $u = $st->fetch();
  if (!$u) return null;
  if (empty($u['role'])) $u['role'] = ((int)$u['is_teacher'] ? 'owner' : 'student');
  return $u;
}
function need_user(): array { $u = current_user(); if (!$u) fail('not authenticated', 401); return $u; }
/* owner: full control · coach: groups + own session notes · researcher: read + exports, no notes */
function is_staff(array $u): bool { return in_array($u['role'], ['owner', 'coach', 'researcher'], true); }
function need_staff(): array { $u = need_user(); if (!is_staff($u)) fail('not allowed', 403); return $u; }
function need_coach(): array { $u = need_user(); if (!in_array($u['role'], ['owner', 'coach'], true)) fail('not allowed', 403); return $u; }
function need_owner(): array { $u = need_user(); if ($u['role'] !== 'owner') fail('not allowed', 403); return $u; }
function need_teacher(): array { return need_staff(); }

function is_member(int $uid, int $gid): bool {
  $st = cc_db()->prepare('SELECT 1 FROM cc_memberships WHERE user_id = ? AND group_id = ?');
  $st->execute([$uid, $gid]);
  return (bool)$st->fetchColumn();
}

function new_code(string $prefix, int $len = 4): string {
  $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $s = '';
  for ($i = 0; $i < $len; $i++) $s .= $alpha[random_int(0, strlen($alpha) - 1)];
  $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix)) ?: 'TEAM';
  return $prefix . '-' . $s;
}
