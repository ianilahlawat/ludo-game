<?php
// ludo/admin/ludo_admin.php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit;
}
require '../../includes/db.php';

date_default_timezone_set('Asia/Kolkata');
define('ADMIN_USER_ID', 1);

$error   = '';
$success = '';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $commission = floatval($_POST['ludo_commission_rate']) / 100;
    $enabled    = isset($_POST['ludo_rooms_enabled']) ? 1 : 0;
    $timeout    = (int)$_POST['ludo_matchmaking_timeout'];
    $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='ludo_commission_rate'")->execute([$commission]);
    $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='ludo_rooms_enabled'")->execute([$enabled]);
    $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='ludo_matchmaking_timeout'")->execute([$timeout]);
    $success = 'Settings saved!';
}

// Handle manual refund
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refund_game'])) {
    $game_id = (int)$_POST['game_id'];
    $stmt = $pdo->prepare("SELECT g.*, r.entry_amount FROM ludo_games g JOIN ludo_rooms r ON g.room_id=r.id WHERE g.id=? AND g.status='active'");
    $stmt->execute([$game_id]);
    $game = $stmt->fetch();
    if ($game) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO transactions (user_id,type,amount,remarks) VALUES (?,'deposit',?,'Ludo Admin Refund - Game #".$game_id."')")->execute([$game['player1_id'], $game['entry_amount']]);
            $pdo->prepare("INSERT INTO transactions (user_id,type,amount,remarks) VALUES (?,'deposit',?,'Ludo Admin Refund - Game #".$game_id."')")->execute([$game['player2_id'], $game['entry_amount']]);
            $pdo->prepare("UPDATE ludo_games SET status='cancelled' WHERE id=?")->execute([$game_id]);
            $pdo->prepare("UPDATE ludo_rooms SET status='cancelled' WHERE id=?")->execute([$game['room_id']]);
            $pdo->commit();
            $success = 'Game #'.$game_id.' refunded successfully!';
        } catch (Exception $e) { $pdo->rollBack(); $error = $e->getMessage(); }
    } else { $error = 'Game not found or not active'; }
}

// Fetch settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('ludo_commission_rate','ludo_rooms_enabled','ludo_matchmaking_timeout')");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$commission_pct = (float)($settings['ludo_commission_rate'] ?? 0.10) * 100;
$rooms_enabled  = (int)($settings['ludo_rooms_enabled'] ?? 1);
$timeout        = (int)($settings['ludo_matchmaking_timeout'] ?? 120);

// Stats
$total_games    = $pdo->query("SELECT COUNT(*) FROM ludo_games")->fetchColumn();
$active_games   = $pdo->query("SELECT COUNT(*) FROM ludo_games WHERE status='active'")->fetchColumn();
$completed      = $pdo->query("SELECT COUNT(*) FROM ludo_games WHERE status='completed'")->fetchColumn();
$total_prize    = $pdo->query("SELECT SUM(prize_amount) FROM ludo_results")->fetchColumn() ?: 0;
$total_comm     = $pdo->query("SELECT SUM(commission) FROM ludo_results")->fetchColumn() ?: 0;

// Active games
$active_list = $pdo->query("
    SELECT g.id, r.entry_amount, r.room_code,
        u1.name as p1, u2.name as p2,
        g.created_at, g.last_action_at
    FROM ludo_games g
    JOIN ludo_rooms r ON g.room_id=r.id
    JOIN users u1 ON g.player1_id=u1.id
    JOIN users u2 ON g.player2_id=u2.id
    WHERE g.status='active'
    ORDER BY g.created_at DESC
")->fetchAll();

// Recent results
$results = $pdo->query("
    SELECT lr.*, u1.name as winner_name, u2.name as loser_name
    FROM ludo_results lr
    JOIN users u1 ON lr.winner_id=u1.id
    JOIN users u2 ON lr.loser_id=u2.id
    ORDER BY lr.completed_at DESC LIMIT 20
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ludo Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0f1117;--surface:#1a1d27;--surface2:#21253a;--border:#2e3350;--text:#e8eaf6;--muted:#7b82a8;--accent:#6c63ff;--green:#22c55e;--red:#ef4444;--gold:#f59e0b;--radius:12px;--radius-sm:8px}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);padding:0;min-height:100vh}
.nav{display:flex;align-items:center;gap:12px;padding:14px 20px;background:var(--surface);border-bottom:1px solid var(--border)}
.nav h1{font-size:17px;font-weight:700}
.nav a{color:var(--muted);text-decoration:none;font-size:13px;margin-left:auto}
.wrap{max-width:900px;margin:0 auto;padding:24px 16px 60px}
.alert{padding:12px 16px;border-radius:var(--radius-sm);font-size:14px;margin-bottom:20px}
.alert.success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#86efac}
.alert.error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
.stat-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:24px}
.stat-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px}
.stat-box .sb-label{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.stat-box .sb-val{font-size:22px;font-weight:700}
.stat-box .sb-val.green{color:var(--green)}.stat-box .sb-val.gold{color:var(--gold)}.stat-box .sb-val.accent{color:var(--accent)}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px;margin-bottom:20px}
.card h2{font-size:15px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}
.field label{display:block;font-size:12px;color:var(--muted);font-weight:500;margin-bottom:5px}
.field input[type=number],.field input[type=text]{width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:9px 12px;border-radius:var(--radius-sm);font-size:14px;font-family:inherit;outline:none}
.field input:focus{border-color:var(--accent)}
.toggle-row{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.toggle-label{font-size:14px;font-weight:500}
.toggle-desc{font-size:12px;color:var(--muted)}
input[type=checkbox]{width:18px;height:18px;accent-color:var(--accent)}
.save-btn{padding:10px 24px;background:var(--accent);color:#fff;border:none;border-radius:var(--radius-sm);font-size:14px;font-weight:600;cursor:pointer;font-family:inherit}
.table-wrap{overflow-x:auto;border-radius:var(--radius-sm)}
table{width:100%;border-collapse:collapse;min-width:500px}
thead th{padding:9px 12px;background:var(--surface2);font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);text-align:left;white-space:nowrap;border-bottom:1px solid var(--border)}
tbody td{padding:10px 12px;border-bottom:1px solid var(--border);font-size:13px}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover td{background:var(--surface2)}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600}
.badge.win{background:rgba(34,197,94,.15);color:var(--green)}
.badge.loss{background:rgba(239,68,68,.12);color:#fca5a5}
.refund-btn{padding:5px 12px;background:rgba(245,158,11,.12);color:var(--gold);border:1px solid rgba(245,158,11,.3);border-radius:var(--radius-sm);font-size:12px;font-weight:600;cursor:pointer;font-family:inherit}
@media(max-width:600px){.form-row{grid-template-columns:1fr}.stat-grid{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<nav class="nav">
  <h1>🎲 Ludo Admin Panel</h1>
  <a href="../index.php">← Back to Ludo</a>
  <a href="../../dashboard.php">Dashboard</a>
</nav>
<div class="wrap">

<?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- STATS -->
<div class="stat-grid">
  <div class="stat-box"><div class="sb-label">Total Games</div><div class="sb-val accent"><?= $total_games ?></div></div>
  <div class="stat-box"><div class="sb-label">Active Games</div><div class="sb-val gold"><?= $active_games ?></div></div>
  <div class="stat-box"><div class="sb-label">Total Paid Out</div><div class="sb-val green">₹<?= number_format($total_prize, 2) ?></div></div>
  <div class="stat-box"><div class="sb-label">Total Commission</div><div class="sb-val gold">₹<?= number_format($total_comm, 2) ?></div></div>
</div>

<!-- SETTINGS -->
<div class="card">
  <h2>⚙️ Settings</h2>
  <form method="post">
    <div class="form-row">
      <div class="field">
        <label>Commission % (e.g. 10 = 10%)</label>
        <input type="number" name="ludo_commission_rate" value="<?= $commission_pct ?>" min="0" max="50" step="0.5">
      </div>
      <div class="field">
        <label>Matchmaking Timeout (seconds)</label>
        <input type="number" name="ludo_matchmaking_timeout" value="<?= $timeout ?>" min="30" max="600">
      </div>
    </div>
    <div class="toggle-row">
      <input type="checkbox" name="ludo_rooms_enabled" id="rooms_toggle" <?= $rooms_enabled ? 'checked' : '' ?>>
      <div>
        <div class="toggle-label">Ludo Rooms Enabled</div>
        <div class="toggle-desc">Uncheck to disable all Ludo games instantly</div>
      </div>
    </div>
    <button type="submit" name="save_settings" class="save-btn">Save Settings</button>
  </form>
</div>

<!-- ACTIVE GAMES -->
<div class="card">
  <h2>🟢 Active Games (<?= count($active_list) ?>)</h2>
  <?php if (empty($active_list)): ?>
  <p style="color:var(--muted);font-size:14px">No active games right now.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Game ID</th><th>Room</th><th>Players</th><th>Entry</th><th>Started</th><th>Last Action</th><th>Refund</th></tr>
      </thead>
      <tbody>
        <?php foreach ($active_list as $g): ?>
        <tr>
          <td>#<?= $g['id'] ?></td>
          <td><?= $g['room_code'] ?></td>
          <td><?= htmlspecialchars($g['p1']) ?> vs <?= htmlspecialchars($g['p2']) ?></td>
          <td>₹<?= number_format($g['entry_amount'], 0) ?></td>
          <td><?= date('d M H:i', strtotime($g['created_at'])) ?></td>
          <td><?= date('H:i:s', strtotime($g['last_action_at'])) ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Refund this game?')">
              <input type="hidden" name="game_id" value="<?= $g['id'] ?>">
              <button class="refund-btn" name="refund_game">Refund</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- RECENT RESULTS -->
<div class="card">
  <h2>📋 Recent Results</h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Game</th><th>Winner</th><th>Loser</th><th>Entry</th><th>Prize</th><th>Commission</th><th>Date</th></tr>
      </thead>
      <tbody>
        <?php if (empty($results)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:20px">No completed games yet.</td></tr>
        <?php else: ?>
        <?php foreach ($results as $r): ?>
        <tr>
          <td>#<?= $r['game_id'] ?></td>
          <td><span class="badge win">🏆 <?= htmlspecialchars($r['winner_name']) ?></span></td>
          <td><?= htmlspecialchars($r['loser_name']) ?></td>
          <td>₹<?= number_format($r['entry_amount'], 0) ?></td>
          <td style="color:var(--green);font-weight:600">₹<?= number_format($r['prize_amount'], 2) ?></td>
          <td style="color:var(--gold)">₹<?= number_format($r['commission'], 2) ?></td>
          <td><?= date('d M H:i', strtotime($r['completed_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div>
</body>
</html>
