<?php
session_start();
require '../includes/db.php';
date_default_timezone_set('Asia/Kolkata');
if (!isset($_SESSION['user_id'])) { header("Location: ../user_login.php"); exit; }

$user_id = $_SESSION['user_id'];
$room_id = (int)($_GET['room'] ?? 0);
if (!$room_id) { header('Location: index.php'); exit; }

$stmt = $pdo->prepare("
    SELECT r.*, g.*, g.id as game_id,
        u1.name as p1_name, u2.name as p2_name
    FROM ludo_rooms r
    JOIN ludo_games g ON g.room_id = r.id
    JOIN users u1 ON g.player1_id = u1.id
    JOIN users u2 ON g.player2_id = u2.id
    WHERE r.id = ? AND (g.player1_id=? OR g.player2_id=?)
");
$stmt->execute([$room_id,$user_id,$user_id]);
$game = $stmt->fetch();
if (!$game) { header('Location: index.php'); exit; }

$is_p1    = ($user_id == $game['player1_id']);
$my_name  = $is_p1 ? $game['p1_name'] : $game['p2_name'];
$opp_name = $is_p1 ? $game['p2_name'] : $game['p1_name'];
// P1 uses RED path, P2 uses YELLOW path — always
$my_pos   = json_decode($is_p1 ? $game['player1_pos'] : $game['player2_pos'], true);
$opp_pos  = json_decode($is_p1 ? $game['player2_pos'] : $game['player1_pos'], true);
$s2 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='ludo_commission_rate'");
$commission_rate = (float)(($s2->fetch())['setting_value'] ?? 0.10);
$prize = ($game['entry_amount'] * 2) * (1 - $commission_rate);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
<title>Ludo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
:root{
  --bg:#0a0d16;--surface:#12172a;--surface2:#1a2035;--border:#232b45;
  --text:#eef0ff;--muted:#6b7399;--accent:#7c6dfa;
  --green:#22c55e;--gold:#f59e0b;--red:#e53e3e;
}
html,body{height:100%;overflow:hidden}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);display:flex;flex-direction:column}

.hdr{display:flex;align-items:center;justify-content:space-between;padding:9px 13px;background:var(--surface);border-bottom:1px solid var(--border);flex-shrink:0}
.hdr-back{display:flex;align-items:center;gap:4px;color:var(--muted);text-decoration:none;font-size:12px;font-weight:500}
.hdr-back svg{width:15px;height:15px}
.prize-pill{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:var(--gold);font-size:12px;font-weight:700;padding:4px 11px;border-radius:20px}
.forfeit-btn{padding:5px 10px;background:rgba(244,63,94,.1);color:#fda4af;border:1px solid rgba(244,63,94,.2);border-radius:7px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit}

.pbar{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:5px;padding:6px 13px;background:var(--surface);border-bottom:1px solid var(--border);flex-shrink:0}
.pi{display:flex;align-items:center;gap:6px}
.pi.right{flex-direction:row-reverse}
.pav{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;transition:box-shadow .3s}
.pav.p1{background:rgba(229,62,62,.2);color:#f30f00;border:2px solid #f30f00}
.pav.p2{background:rgba(255,196,0,.2);color:#c47a00;border:2px solid #ffc400}
.pav.glow1{box-shadow:0 0 0 3px rgba(243,15,0,.5),0 0 10px rgba(243,15,0,.3)}
.pav.glow2{box-shadow:0 0 0 3px rgba(255,196,0,.5),0 0 10px rgba(255,196,0,.3)}
.pname{font-size:11px;font-weight:600;max-width:65px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.phome{font-size:9px;color:var(--muted)}
.vs-c{width:22px;height:22px;border-radius:50%;background:var(--surface2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:7px;font-weight:800;color:var(--muted)}

.timer-bar{display:flex;align-items:center;justify-content:space-between;padding:4px 13px;background:var(--surface2);border-bottom:1px solid var(--border);flex-shrink:0;min-height:26px}
#turn-txt{font-size:11px;font-weight:700}
.tw{display:flex;align-items:center;gap:4px;font-size:11px}
#tdisplay{font-weight:800;color:var(--green);min-width:24px;text-align:right}
#tdisplay.urgent{color:#ef4444;animation:blink .4s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}
#autolbl{font-size:9px;color:var(--muted)}

.game-area{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:4px;overflow:hidden;gap:8px}
.board-wrap{position:relative;flex-shrink:0}
#bsvg{display:block;filter:drop-shadow(0 3px 14px rgba(0,0,0,.7))}

.dice-row{display:flex;align-items:center;gap:14px;flex-shrink:0}
#dsvg{border-radius:10px;filter:drop-shadow(0 2px 6px rgba(0,0,0,.5));flex-shrink:0}
@keyframes dShake{0%,100%{transform:rotate(0)scale(1)}20%{transform:rotate(-18deg)scale(1.15)}40%{transform:rotate(16deg)scale(1.2)}60%{transform:rotate(-10deg)scale(1.08)}80%{transform:rotate(7deg)scale(1.04)}}
.shaking{animation:dShake .55s ease}

.roll-btn{padding:11px 22px;background:linear-gradient(135deg,var(--accent),#9f7aea);color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:800;cursor:pointer;font-family:inherit;transition:all .2s;box-shadow:0 4px 14px rgba(124,109,250,.4)}
.roll-btn:hover:not(:disabled){transform:translateY(-2px)}
.roll-btn:active:not(:disabled){transform:translateY(0)}
.roll-btn:disabled{background:var(--surface2);color:var(--muted);box-shadow:none;cursor:not-allowed}

.overlay{position:fixed;inset:0;background:rgba(0,0,0,.9);display:flex;align-items:center;justify-content:center;z-index:200;padding:20px;backdrop-filter:blur(8px)}
.overlay.hidden{display:none}
.result-card{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:30px 22px;text-align:center;max-width:260px;width:100%}
.res-emoji{font-size:56px;display:block;margin-bottom:8px}
.res-title{font-size:20px;font-weight:800;margin-bottom:4px}
.res-prize{font-size:26px;font-weight:800;margin:8px 0}
.res-prize.win{color:var(--green)}.res-prize.loss{color:#ef4444}
.res-msg{color:var(--muted);font-size:12px;margin-bottom:16px}
.res-home{display:block;padding:11px;background:var(--accent);color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:13px}

.toast{position:fixed;bottom:12px;left:50%;transform:translateX(-50%) translateY(60px);background:var(--surface2);border:1px solid var(--border);padding:8px 14px;border-radius:8px;font-size:12px;font-weight:600;transition:transform .3s;z-index:300;pointer-events:none;white-space:nowrap;max-width:90vw;text-align:center}
.toast.show{transform:translateX(-50%) translateY(0)}
.toast.error{border-color:#ef4444;color:#fca5a5}
.toast.win{border-color:var(--green);color:#86efac}
</style>
</head>
<body>
<div class="hdr">
  <a href="index.php" class="hdr-back" onclick="return confirmLeave()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>Lobby
  </a>
  <div class="prize-pill">🏆 Win ₹<?= number_format($prize,0) ?></div>
  <button class="forfeit-btn" onclick="doForfeit()">Forfeit</button>
</div>
<div class="pbar">
  <div class="pi">
    <div class="pav <?= $is_p1?'p1':'p2' ?>" id="av-me"><?= strtoupper(substr($my_name,0,1)) ?></div>
    <div><div class="pname"><?= htmlspecialchars($my_name) ?></div><div class="phome" id="my-home">0/4 home</div></div>
  </div>
  <div class="vs-c">VS</div>
  <div class="pi right">
    <div class="pav <?= $is_p1?'p2':'p1' ?>" id="av-opp"><?= strtoupper(substr($opp_name,0,1)) ?></div>
    <div style="text-align:right"><div class="pname"><?= htmlspecialchars($opp_name) ?></div><div class="phome" id="opp-home">0/4 home</div></div>
  </div>
</div>
<div class="timer-bar">
  <span id="turn-txt">Loading...</span>
  <div class="tw">
    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    <span id="tdisplay">--</span><span id="autolbl"></span>
  </div>
</div>

<div class="game-area">
  <div class="board-wrap" id="bwrap">
    <svg id="bsvg" viewBox="0 0 1000 1000" xmlns="http://www.w3.org/2000/svg">
      <rect x="33" y="34" width="934" height="932" fill="white" stroke="#000" stroke-width="3"/>
      <path style="fill:#000000;stroke:#000004;stroke-width:2.07;stroke-linejoin:round" d="m 33.617188,34.494141 v 62.08789 62.089849 62.08789 62.08789 62.08984 62.08789 h 62.08789 62.087892 62.08789 62.08984 62.08789 62.08789 V 344.9375 282.84766 220.75977 158.67188 96.582031 34.494141 H 344.05859 281.9707 219.88086 157.79297 95.705078 Z m 558.794922,0 v 62.08789 62.089849 62.08789 62.08789 62.08984 62.08789 h 62.08984 62.08789 62.08789 62.08985 62.08789 62.08789 V 344.9375 282.84766 220.75977 158.67188 96.582031 34.494141 H 902.85547 840.76758 778.67773 716.58984 654.50195 Z M 95.705078,96.582031 h 62.087892 62.08789 62.08984 62.08789 v 62.089849 62.08789 62.08789 62.08984 H 281.9707 219.88086 157.79297 95.705078 v -62.08984 -62.08789 -62.08789 z m 558.796872,0 h 62.08789 62.08789 62.08985 62.08789 v 62.089849 62.08789 62.08789 62.08984 H 840.76758 778.67773 716.58984 654.50195 V 282.84766 220.75977 158.67188 Z M 33.617188,593.29102 v 62.08789 62.08984 62.08789 62.08789 62.08985 62.08789 h 62.08789 62.087892 62.08789 62.08984 62.08789 62.08789 V 903.73438 841.64453 779.55664 717.46875 655.37891 593.29102 H 344.05859 281.9707 219.88086 157.79297 95.705078 Z m 558.794922,0 v 62.08789 62.08984 62.08789 62.08789 62.08985 62.08789 h 62.08984 62.08789 62.08789 62.08985 62.08789 62.08789 V 903.73438 841.64453 779.55664 717.46875 655.37891 593.29102 H 902.85547 840.76758 778.67773 716.58984 654.50195 Z M 95.705078,655.37891 h 62.087892 62.08789 62.08984 62.08789 v 62.08984 62.08789 62.08789 62.08985 H 281.9707 219.88086 157.79297 95.705078 v -62.08985 -62.08789 -62.08789 z m 558.796872,0 h 62.08789 62.08789 62.08985 62.08789 v 62.08984 62.08789 62.08789 62.08985 h -62.08789 -62.08985 -62.08789 -62.08789 v -62.08985 -62.08789 -62.08789 z"/>
      <path style="fill:#00a300;stroke:#000004;stroke-width:2.07;stroke-linejoin:round" d="m 95.704788,282.84839 h 62.088492 v 62.08842 H 95.704788 Z m 62.088482,0 h 62.0885 v 62.08842 h -62.0885 z m 62.0885,0 h 62.0885 v 62.08842 h -62.0885 z m 62.08851,0 h 62.08849 v 62.08842 H 281.97028 Z M 95.704788,96.582794 H 157.79328 V 158.67122 H 95.704788 Z m 62.088482,0 h 62.0885 v 62.088426 h -62.0885 z m 62.0885,0 h 62.0885 v 62.088426 h -62.0885 z m 62.08851,0 h 62.08849 V 158.67122 H 281.97028 Z M 95.704788,220.75986 h 62.088492 v 62.08842 H 95.704788 Z m 186.265492,0 h 62.08849 v 62.08842 H 281.97028 Z M 95.704788,158.67133 h 62.088492 v 62.08842 H 95.704788 Z m 186.265492,0 h 62.08849 v 62.08842 h -62.08849 z"/>
      <path style="fill:#ffc400;stroke:#000004;stroke-width:2.07;stroke-linejoin:round" d="m 778.67895,282.84908 h 62.08849 v 62.08842 h -62.08849 z m -124.177,0 h 62.08849 v 62.08842 h -62.08849 z m 186.2655,0 h 62.0885 v 62.08842 h -62.0885 z m -124.177,0 h 62.08849 v 62.08842 h -62.08849 z m 62.0885,-186.265596 h 62.08849 v 62.088426 h -62.08849 z m -124.177,0 h 62.08849 v 62.088426 h -62.08849 z m 186.2655,0 h 62.0885 v 62.088426 h -62.0885 z m -124.177,0 h 62.08849 v 62.088426 h -62.08849 z m -62.0885,124.177066 h 62.08849 v 62.08842 h -62.08849 z m 186.2655,0 h 62.0885 v 62.08842 h -62.0885 z m -186.2655,-62.08853 h 62.08849 v 62.08842 h -62.08849 z m 186.2655,0 h 62.0885 v 62.08842 h -62.0885 z"/>
      <path style="fill:#f30f00;stroke:#000004;stroke-width:2.07;stroke-linejoin:round" d="m 95.704788,841.64514 h 62.088492 v 62.08843 H 95.704788 Z m 62.088482,0 h 62.0885 v 62.08843 h -62.0885 z m 62.0885,0 h 62.0885 v 62.08843 h -62.0885 z m 62.08851,0 h 62.08849 v 62.08843 H 281.97028 Z M 95.704788,779.55664 h 62.088492 v 62.08843 H 95.704788 Z m 186.265492,0 h 62.08849 v 62.08843 H 281.97028 Z M 95.704788,717.46808 H 157.79328 V 779.5565 H 95.704788 Z m 186.265492,0 h 62.08849 V 779.5565 H 281.97028 Z M 95.704788,655.37958 H 157.79328 V 717.468 H 95.704788 Z m 62.088482,0 h 62.0885 V 717.468 h -62.0885 z m 62.0885,0 h 62.0885 V 717.468 h -62.0885 z m 62.08851,0 h 62.08849 V 717.468 h -62.08849 z"/>
      <path style="fill:#008cf8;stroke:#000004;stroke-width:2.07;stroke-linejoin:round" d="m 778.67816,841.64514 h 62.08849 v 62.08843 h -62.08849 z m -124.177,0 h 62.08849 v 62.08843 h -62.08849 z m 186.2655,0 h 62.0885 v 62.08843 h -62.0885 z m -124.177,0 h 62.08849 v 62.08843 h -62.08849 z m -62.0885,-62.0885 h 62.08849 v 62.08843 h -62.08849 z m 186.2655,0 h 62.0885 v 62.08843 h -62.0885 z m -186.2655,-62.08856 h 62.08849 v 62.08842 h -62.08849 z m 186.2655,0 h 62.0885 v 62.08842 h -62.0885 z m -62.0885,-62.0885 h 62.08849 V 717.468 h -62.08849 z m -124.177,0 h 62.08849 V 717.468 h -62.08849 z m 186.2655,0 h 62.0885 V 717.468 h -62.0885 z m -124.177,0 h 62.08849 V 717.468 h -62.08849 z"/>
      <path style="fill:none;stroke:#000004;stroke-width:2.07;stroke-linejoin:round" d="m 778.67816,779.55664 h 62.08849 v 62.08843 h -62.08849 z m -620.88489,0 h 62.0885 v 62.08843 h -62.0885 z m 62.0885,0 h 62.0885 v 62.08843 h -62.0885 z m 496.70789,0 h 62.08849 v 62.08843 h -62.08849 z m 62.0885,-558.79678 h 62.08849 v 62.08842 h -62.08849 z m -620.88489,0 h 62.0885 v 62.08842 h -62.0885 z m 62.0885,0 h 62.0885 v 62.08842 h -62.0885 z m 496.70789,0 h 62.08849 v 62.08842 h -62.08849 z m 62.0885,496.70822 h 62.08849 v 62.08842 h -62.08849 z m -620.88489,0 h 62.0885 v 62.08842 h -62.0885 z m 62.0885,0 h 62.0885 v 62.08842 h -62.0885 z m 496.70789,0 h 62.08849 v 62.08842 h -62.08849 z m 62.0885,-558.79675 h 62.08849 v 62.08842 h -62.08849 z m -620.88489,0 h 62.0885 v 62.08842 h -62.0885 z m 62.0885,0 h 62.0885 v 62.08842 h -62.0885 z m 496.70789,0 h 62.08849 v 62.08842 h -62.08849 z"/>
      <path style="fill:#00a300;stroke:#000000;stroke-width:2.07" d="m 95.704788,469.11398 h 62.088492 v 62.08843 H 95.704788 Z m 62.088482,0 h 62.0885 v 62.08843 h -62.0885 z m 62.0885,0 h 62.0885 v 62.08843 h -62.0885 z m 124.17698,0 h 62.08849 v 62.08843 h -62.08849 z m -62.08847,0 h 62.08849 v 62.08843 H 281.97028 Z M 95.704788,407.02545 h 62.088492 v 62.08843 H 95.704788 Z"/>
      <path style="fill:#ffc400;stroke:#000000;stroke-width:2.07" d="m 468.23572,282.84839 h 62.08849 v 62.08842 h -62.08849 z m 62.0885,-186.265596 h 62.08849 v 62.088426 h -62.08849 z m -62.0885,0 h 62.08849 v 62.088426 h -62.08849 z m 0,248.354126 h 62.08849 v 62.08842 h -62.08849 z m 0,-124.17706 h 62.08849 v 62.08842 h -62.08849 z m 0,-62.08853 h 62.08849 v 62.08842 h -62.08849 z"/>
      <path style="fill:#f30f00;stroke:#000000;stroke-width:2.07" d="m 406.14725,841.64514 h 62.08849 v 62.08843 h -62.08849 z m 62.08847,0 h 62.08849 v 62.08843 h -62.08849 z m 0,-62.0885 h 62.08849 v 62.08843 h -62.08849 z m 0,-186.26562 h 62.08849 v 62.08842 h -62.08849 z m 0,124.17706 h 62.08849 v 62.08842 h -62.08849 z m 0,-62.0885 h 62.08849 V 717.468 h -62.08849 z"/>
      <path style="fill:#008cf8;stroke:#000000;stroke-width:2.07" d="m 840.76666,531.20251 h 62.0885 v 62.08843 h -62.0885 z m -62.0885,-62.08853 h 62.08849 v 62.08843 h -62.08849 z m -124.177,0 h 62.08849 v 62.08843 h -62.08849 z m -62.08844,0 h 62.08849 v 62.08843 h -62.08849 z m 248.35394,0 h 62.0885 v 62.08843 h -62.0885 z m -124.177,0 h 62.08849 v 62.08843 h -62.08849 z"/>
      <path style="fill:#646464;stroke:#000004;stroke-width:2.07" d="m 530.32422,779.55664 h 62.08849 v 62.08843 H 530.32422 Z M 157.79327,531.20251 h 62.0885 v 62.08843 h -62.0885 z M 778.67816,407.02545 h 62.08849 v 62.08843 H 778.67816 Z M 406.14725,158.67133 h 62.08849 v 62.08842 h -62.08849 z"/>
      <path style="fill:none;stroke:#000004;stroke-width:2.07" d="m 530.32422,282.84839 h 62.08849 v 62.08842 h -62.08849 z m -124.17697,0 h 62.08849 v 62.08842 H 406.14725 Z M 530.32422,34.49427 h 62.08849 v 62.088425 h -62.08849 z m -124.17697,0 h 62.08849 v 62.088425 h -62.08849 z m 62.08847,0 h 62.08849 v 62.088425 h -62.08849 z m -62.08847,62.088524 h 62.08849 V 158.67122 H 406.14725 Z M 530.32422,903.7337 h 62.08849 v 62.08843 h -62.08849 z m -124.17697,0 h 62.08849 v 62.08843 h -62.08849 z m 62.08847,0 h 62.08849 v 62.08843 h -62.08849 z m 62.0885,-62.08856 h 62.08849 v 62.08843 h -62.08849 z m -124.17697,-62.0885 h 62.08849 v 62.08843 H 406.14725 Z M 530.32422,344.93692 h 62.08849 v 62.08842 h -62.08849 z m -124.17697,0 h 62.08849 v 62.08842 H 406.14725 Z M 530.32422,220.75986 h 62.08849 v 62.08842 h -62.08849 z m -124.17697,0 h 62.08849 v 62.08842 H 406.14725 Z M 33.616295,531.20251 h 62.088493 v 62.08843 H 33.616295 Z m 62.088493,0 h 62.088492 v 62.08843 H 95.704788 Z m 682.973372,0 h 62.08849 v 62.08843 h -62.08849 z m -124.177,0 h 62.08849 v 62.08843 h -62.08849 z m -434.61939,0 h 62.0885 v 62.08843 h -62.0885 z m 372.53095,0 h 62.08849 v 62.08843 h -62.08849 z m -248.35397,0 h 62.08849 v 62.08843 h -62.08849 z m 558.79641,0 h 62.0885 v 62.08843 h -62.0885 z m -186.2655,0 h 62.08849 v 62.08843 h -62.08849 z m -434.61938,0 h 62.08849 v 62.08843 h -62.08849 z m 248.35394,62.08851 h 62.08849 v 62.08842 h -62.08849 z m -124.17697,0 h 62.08849 v 62.08842 h -62.08849 z m 124.17697,124.17706 h 62.08849 v 62.08842 h -62.08849 z m -124.17697,0 h 62.08849 V 779.5565 H 406.14725 Z M 33.616295,469.11398 h 62.088493 v 62.08843 H 33.616295 Z m 869.238865,0 h 62.0885 v 62.08843 h -62.0885 z M 33.616295,407.02545 h 62.088493 v 62.08843 H 33.616295 Z m 620.884865,0 h 62.08849 v 62.08843 h -62.08849 z m -496.70789,0 h 62.0885 v 62.08843 h -62.0885 z m 62.0885,0 h 62.0885 v 62.08843 h -62.0885 z m 372.53095,0 h 62.08849 v 62.08843 h -62.08849 z m 248.35394,0 h 62.0885 v 62.08843 h -62.0885 z m -496.70791,0 h 62.08849 v 62.08843 h -62.08849 z m 558.79641,0 h 62.0885 v 62.08843 h -62.0885 z m -186.2655,0 h 62.08849 v 62.08843 h -62.08849 z m -434.61938,0 h 62.08849 v 62.08843 H 281.97028 Z M 530.32422,158.67133 h 62.08849 v 62.08842 h -62.08849 z m 0,496.70825 h 62.08849 V 717.468 h -62.08849 z m -124.17697,0 h 62.08849 V 717.468 h -62.08849 z"/>
      <path style="fill:#6700dc;stroke:#000000;stroke-width:2.07" d="M 406.14648,593.29102 592.41211,407.02539"/>
      <path style="fill:#6700dc;stroke:#000000;stroke-width:2.07" d="M 406.14648,407.02539 592.41211,593.29102"/>
      <path style="fill:#f30f00;stroke:#f30f00;stroke-width:.89" d="m 454.17269,547.10525 45.10522,-45.10523 45.10523,45.10523 45.10523,45.10522 h -90.21046 -90.21045 z"/>
      <path style="fill:#008cf8;stroke:#008cf8;stroke-width:.89" d="m 546.06782,545.06355 -44.88941,-44.89095 44.99784,-44.9963 44.99783,-44.99629 v 89.88725 c 0,49.43799 -0.0488,89.88725 -0.10842,89.88725 -0.0596,0 -20.30866,-20.20093 -44.99784,-44.89096 z"/>
      <path style="fill:#ffc400;stroke:#ffc400;stroke-width:.89" d="m 454.24287,453.2386 -44.99629,-44.99784 h 89.99413 89.99412 l -44.99629,44.99784 c -24.74796,24.74881 -44.99698,44.99783 -44.99783,44.99783 -8.5e-4,0 -20.24988,-20.24902 -44.99784,-44.99783 z"/>
      <path style="fill:#00a300;stroke:#00a300;stroke-width:.89" d="m 407.50915,500.06726 v -89.88725 l 44.99783,44.99629 44.99784,44.9963 -44.88941,44.89095 c -24.68918,24.69003 -44.93821,44.89096 -44.99784,44.89096 -0.0596,0 -0.10842,-40.44926 -0.10842,-89.88725 z"/>
      <!-- ★ SAFE SQUARES — golden stars, no capture allowed -->
      <rect x="406.1" y="158.7" width="62.1" height="62.1" fill="rgba(255,215,0,0.22)" stroke="#daa520" stroke-width="1.5"/>
      <text x="437.2" y="189.7" text-anchor="middle" dominant-baseline="middle" font-size="30" fill="#ffd700" opacity="0.95">★</text>
      <rect x="530.3" y="34.5" width="62.1" height="62.1" fill="rgba(255,215,0,0.22)" stroke="#daa520" stroke-width="1.5"/>
      <text x="561.4" y="65.5" text-anchor="middle" dominant-baseline="middle" font-size="30" fill="#ffd700" opacity="0.95">★</text>
      <rect x="33.6" y="407.0" width="62.1" height="62.1" fill="rgba(255,215,0,0.22)" stroke="#daa520" stroke-width="1.5"/>
      <text x="64.7" y="438.1" text-anchor="middle" dominant-baseline="middle" font-size="30" fill="#ffd700" opacity="0.95">★</text>
      <rect x="157.8" y="531.2" width="62.1" height="62.1" fill="rgba(255,215,0,0.22)" stroke="#daa520" stroke-width="1.5"/>
      <text x="188.8" y="562.2" text-anchor="middle" dominant-baseline="middle" font-size="30" fill="#ffd700" opacity="0.95">★</text>
      <rect x="406.1" y="779.6" width="62.1" height="62.1" fill="rgba(255,215,0,0.22)" stroke="#daa520" stroke-width="1.5"/>
      <text x="437.2" y="810.6" text-anchor="middle" dominant-baseline="middle" font-size="30" fill="#ffd700" opacity="0.95">★</text>
      <rect x="530.3" y="903.7" width="62.1" height="62.1" fill="rgba(255,215,0,0.22)" stroke="#daa520" stroke-width="1.5"/>
      <text x="561.4" y="934.8" text-anchor="middle" dominant-baseline="middle" font-size="30" fill="#ffd700" opacity="0.95">★</text>
      <rect x="902.8" y="531.2" width="62.1" height="62.1" fill="rgba(255,215,0,0.22)" stroke="#daa520" stroke-width="1.5"/>
      <text x="933.9" y="562.2" text-anchor="middle" dominant-baseline="middle" font-size="30" fill="#ffd700" opacity="0.95">★</text>
      <rect x="778.7" y="407.0" width="62.1" height="62.1" fill="rgba(255,215,0,0.22)" stroke="#daa520" stroke-width="1.5"/>
      <text x="809.7" y="438.1" text-anchor="middle" dominant-baseline="middle" font-size="30" fill="#ffd700" opacity="0.95">★</text>
      <!-- Start/exit squares — SAFE ZONES (star + color highlight) -->
      <!-- Red start: col6,row13 = step 1 for red path -->
      <rect x="406.1" y="841.6" width="62.1" height="62.1" fill="rgba(243,15,0,0.25)" stroke="#f30f00" stroke-width="2"/>
      <text x="437.2" y="872.7" text-anchor="middle" dominant-baseline="middle" font-size="26" fill="#ffd700" opacity="0.95">★</text>
      <!-- Yellow start: col8,row1 = step 1 for yellow path -->
      <rect x="530.3" y="96.6" width="62.1" height="62.1" fill="rgba(255,196,0,0.25)" stroke="#ffc400" stroke-width="2"/>
      <text x="561.4" y="127.6" text-anchor="middle" dominant-baseline="middle" font-size="26" fill="#ffd700" opacity="0.95">★</text>
      <g id="tok-layer"></g>
    </svg>
  </div>
  <div class="dice-row">
    <svg id="dsvg" width="62" height="62" viewBox="0 0 62 62">
      <rect x="2" y="2" width="58" height="58" rx="10" fill="white" stroke="#bbb" stroke-width="1.5"/>
      <g id="dpips"></g>
    </svg>
    <button class="roll-btn" id="rollBtn" onclick="rollDice()" disabled>🎲 Roll</button>
  </div>
</div>

<div class="overlay hidden" id="resultOverlay">
  <div class="result-card">
    <span class="res-emoji" id="resEmoji"></span>
    <div class="res-title" id="resTitle"></div>
    <div class="res-prize" id="resPrize"></div>
    <div class="res-msg" id="resMsg"></div>
    <a href="index.php" class="res-home">← Back to Lobby</a>
  </div>
</div>
<div class="toast" id="toast"></div>

<script>
// ══ SOUNDS ══
let _AC=null;
function ac(){if(!_AC)_AC=new(window.AudioContext||window.webkitAudioContext)();return _AC;}
function tone(f,d,t='sine',v=.28){try{const c=ac(),o=c.createOscillator(),g=c.createGain();o.connect(g);g.connect(c.destination);o.type=t;o.frequency.value=f;g.gain.setValueAtTime(v,c.currentTime);g.gain.exponentialRampToValueAtTime(.001,c.currentTime+d);o.start();o.stop(c.currentTime+d);}catch(e){}}
const sndRoll=()=>[180,300,240,400,340].forEach((f,i)=>setTimeout(()=>tone(f,.07,'sawtooth',.14),i*48));
const sndMove=()=>{tone(660,.09,'sine',.18);setTimeout(()=>tone(880,.07,'sine',.14),90);};
const sndCapture=()=>{tone(110,.28,'sawtooth',.38);setTimeout(()=>tone(75,.28,'sawtooth',.28),130);};
const sndSix=()=>[523,659,784].forEach((f,i)=>setTimeout(()=>tone(f,.14,'sine',.26),i*95));
const sndWin=()=>[523,659,784,1047].forEach((f,i)=>setTimeout(()=>tone(f,.2,'sine',.32),i*120));
const sndLose=()=>{tone(280,.45,'sawtooth',.26);setTimeout(()=>tone(185,.42,'sawtooth',.18),180);};
const sndTick=()=>tone(1100,.035,'square',.09);

// ══ CONSTANTS ══
const GAME_ID  = <?= $game['game_id'] ?>;
const ROOM_ID  = <?= $room_id ?>;
const MY_ID    = <?= $user_id ?>;
const IS_P1    = <?= $is_p1?'true':'false' ?>;
const TOTAL    = 61;   // path length
const DONE     = 62;   // finished value

// ══ BOARD COORDINATES (SVG 1000×1000) ══
// Cell: OX=33.617, OY=34.494, CS=62.088
// cc(col,row) = center of cell

// P1 = RED path  (bottom-left home, exits col6 row13 going UP)
// P2 = YELLOW path (top-right home, exits col8 row1 going DOWN)
// Both paths are 61 steps. Positions stored in DB per player's own path.

const RED_PATH = [
  // 1-6: exit home, go UP col6 (rows 13→8)
  [437.2,872.7],[437.2,810.6],[437.2,748.5],[437.2,686.4],[437.2,624.3],[437.2,562.2],
  // 7-12: LEFT across row8
  [375.1,562.2],[313.0,562.2],[250.9,562.2],[188.8,562.2],[126.7,562.2],[64.7,562.2],
  // 13-14: UP col0
  [64.7,500.2],[64.7,438.1],
  // 15-19: RIGHT row6
  [126.7,438.1],[188.8,438.1],[250.9,438.1],[313.0,438.1],[375.1,438.1],
  // 20-27: UP col6 then across row0
  [437.2,438.1],[437.2,376.0],[437.2,313.9],[437.2,251.8],[437.2,189.7],[437.2,127.6],[437.2,65.5],[499.3,65.5],
  // 28-33: RIGHT then DOWN col8
  [561.4,65.5],[561.4,127.6],[561.4,189.7],[561.4,251.8],[561.4,313.9],[561.4,376.0],
  // 34-40: RIGHT row6 to col14
  [561.4,438.1],[623.5,438.1],[685.5,438.1],[747.6,438.1],[809.7,438.1],[871.8,438.1],[933.9,438.1],
  // 41-42: DOWN col14
  [933.9,500.2],[933.9,562.2],
  // 43-47: LEFT row8
  [871.8,562.2],[809.7,562.2],[747.6,562.2],[685.5,562.2],[623.5,562.2],
  // 48-55: DOWN col8 to bottom then corner
  [561.4,562.2],[561.4,624.3],[561.4,686.4],[561.4,748.5],[561.4,810.6],[561.4,872.7],[561.4,934.8],[499.3,934.8],
  // 56-61: HOME STRETCH col7 UP toward center (safe zone)
  [499.3,872.7],[499.3,810.6],[499.3,748.5],[499.3,686.4],[499.3,624.3],[499.3,562.2]
];

const YELLOW_PATH = [
  // 1-6: exit home, go DOWN col8 (rows 1→6)
  [561.4,127.6],[561.4,189.7],[561.4,251.8],[561.4,313.9],[561.4,376.0],[561.4,438.1],
  // 7-12: RIGHT across row6 to col14
  [623.5,438.1],[685.5,438.1],[747.6,438.1],[809.7,438.1],[871.8,438.1],[933.9,438.1],
  // 13-14: DOWN col14
  [933.9,500.2],[933.9,562.2],
  // 15-19: LEFT row8
  [871.8,562.2],[809.7,562.2],[747.6,562.2],[685.5,562.2],[623.5,562.2],
  // 20-27: DOWN col8 to bottom then corner
  [561.4,562.2],[561.4,624.3],[561.4,686.4],[561.4,748.5],[561.4,810.6],[561.4,872.7],[561.4,934.8],[499.3,934.8],
  // 28-33: LEFT then UP col6
  [437.2,934.8],[437.2,872.7],[437.2,810.6],[437.2,748.5],[437.2,686.4],[437.2,624.3],
  // 34-40: LEFT row8 to col0
  [437.2,562.2],[375.1,562.2],[313.0,562.2],[250.9,562.2],[188.8,562.2],[126.7,562.2],[64.7,562.2],
  // 41-42: UP col0
  [64.7,500.2],[64.7,438.1],
  // 43-48: RIGHT row6
  [126.7,438.1],[188.8,438.1],[250.9,438.1],[313.0,438.1],[375.1,438.1],[437.2,438.1],
  // 49-55: UP col6 to top then corner
  [437.2,376.0],[437.2,313.9],[437.2,251.8],[437.2,189.7],[437.2,127.6],[437.2,65.5],[499.3,65.5],
  // 56-61: HOME STRETCH col7 DOWN toward center (safe zone)
  [499.3,127.6],[499.3,189.7],[499.3,251.8],[499.3,313.9],[499.3,376.0],[499.3,438.1]
];

// Home base slots (where tokens sit at pos=0)
// P1 = RED home = bottom-left quadrant (rows 9-14, cols 0-5)
const RED_BASE   = [[126.7,686.4],[250.9,686.4],[126.7,810.6],[250.9,810.6]];
// P2 = YELLOW home = top-right quadrant (rows 0-5, cols 9-14)
const YELLOW_BASE= [[685.5,127.6],[809.7,127.6],[685.5,251.8],[809.7,251.8]];

// PERSPECTIVE: Every player ALWAYS sees themselves on RED path (bottom-left)
// and opponent on YELLOW path (top-right)
// Step numbers from DB = "steps from home" — same meaning for both
const MY_PATH  = RED_PATH;    // I always appear at red side (bottom-left)
const OPP_PATH = YELLOW_PATH; // Opponent always appears at yellow side (top-right)
const MY_BASE  = RED_BASE;    // My home = red home (bottom-left)
const OPP_BASE = YELLOW_BASE; // Opp home = yellow home (top-right)

// PERSPECTIVE: I always see myself as RED, opponent as YELLOW
// regardless of which player I am in the database
const MY_FILL  = '#c41a00';  // Always RED for self
const MY_LITE  = '#ff8866';
const OPP_FILL = '#b07000';  // Always YELLOW for opponent
const OPP_LITE = '#ffd060';

// Safe positions (1-indexed) for DISPLAY purposes
// Step 1 = exit square (always safe in Ludo)
// Steps 55-61 = home stretch (always safe)
// MY_SAFE used for display/highlight only — server enforces real safe logic
const MY_SAFE  = new Set([1,2,10,14,24,28,38,42,54,55,56,57,58,59,60,61]);
const OPP_SAFE = new Set([1,10,14,26,30,38,42,52,55,56,57,58,59,60,61]);

// Board center for finished tokens
const CTR = [499.3, 500.2];
const DONE_OFF = [[-18,-18],[18,-18],[-18,18],[18,18]];

// ══ STATE ══
let myPos      = <?= json_encode($my_pos) ?>;
let oppPos     = <?= json_encode($opp_pos) ?>;
let myTurn     = <?= ($game['current_turn']==$user_id)?'true':'false' ?>;
let diceVal    = <?= $game['dice_value']??'null' ?>;
let diceRolled = <?= $game['dice_rolled']?'true':'false' ?>;
let gameOver   = <?= ($game['status']==='completed')?'true':'false' ?>;
let selToken   = -1;
let animating  = false;
let pollTimer  = null;
let timerSec=15, timerInt=null, autoRolls=0;

// Resize board to fit screen
function resize(){
  const size=Math.min(window.innerWidth*.93,window.innerHeight*.54,430);
  const svg=document.getElementById('bsvg');
  svg.style.width=size+'px'; svg.style.height=size+'px';
  document.getElementById('bwrap').style.cssText=`width:${size}px;height:${size}px`;
}
resize(); window.addEventListener('resize',resize);

// ══ DICE — FLAT SVG, ALWAYS CORRECT ══
const DPIPS={1:[[31,31]],2:[[15,15],[47,47]],3:[[15,15],[31,31],[47,47]],4:[[15,15],[47,15],[15,47],[47,47]],5:[[15,15],[47,15],[31,31],[15,47],[47,47]],6:[[15,13],[47,13],[15,31],[47,31],[15,49],[47,49]]};
function drawDice(n){
  const g=document.getElementById('dpips'); g.innerHTML='';
  const p=DPIPS[parseInt(n)||1];
  p.forEach(([cx,cy])=>{const c=document.createElementNS('http://www.w3.org/2000/svg','circle');c.setAttribute('cx',cx);c.setAttribute('cy',cy);c.setAttribute('r','5.5');c.setAttribute('fill','#1a0830');g.appendChild(c);});
}
function animDice(val,cb){
  sndRoll();
  const el=document.getElementById('dsvg'); el.classList.add('shaking');
  let i=0;
  const t=setInterval(()=>{drawDice(Math.ceil(Math.random()*6));if(++i>=10){clearInterval(t);el.classList.remove('shaking');drawDice(parseInt(val));if(cb)cb();}},55);
}

// ══ GET VISUAL POSITION ══
function getXY(pos, isMe, idx){
  pos=parseInt(pos);
  if(pos<=0) return isMe ? MY_BASE[idx] : OPP_BASE[idx];
  if(pos>=DONE) return [CTR[0]+DONE_OFF[idx][0], CTR[1]+DONE_OFF[idx][1]];
  const path = isMe ? MY_PATH : OPP_PATH;
  return path[Math.min(pos,TOTAL)-1] || CTR;
}

// ══ DRAW ALL TOKENS ══
const NS='http://www.w3.org/2000/svg';
const TL=document.getElementById('tok-layer');

function drawTokens(){
  TL.innerHTML='';
  // Draw opp behind, mine on top
  oppPos.forEach((p,i)=>renderTok(p,false,i,OPP_FILL,OPP_LITE,false,false));
  const movable = myTurn&&diceRolled&&!gameOver&&!animating ? getMovable() : [];
  myPos.forEach((p,i)=>renderTok(p,true,i,MY_FILL,MY_LITE,selToken===i,movable.includes(i)));
}

function getMovable(){
  // Returns indices of tokens that CAN move with current dice
  if(!diceRolled||!diceVal)return[];
  return myPos.reduce((a,p,i)=>{
    p=parseInt(p);
    if(p>=DONE)return a;
    if(p===0&&diceVal===6){a.push(i);return a;}
    if(p>0&&p+diceVal<=TOTAL){a.push(i);return a;}
    return a;
  },[]);
}

function renderTok(pos,isMe,idx,fill,lite,sel,movable){
  pos=parseInt(pos);
  const [x,y]=getXY(pos,isMe,idx);
  const R=24; // BIGGER tokens as requested

  // Glow ring for selectable tokens (not selected, but can move)
  if(movable&&!sel){
    const ring=document.createElementNS(NS,'circle');
    ring.setAttribute('cx',x);ring.setAttribute('cy',y);ring.setAttribute('r',R+10);
    ring.setAttribute('fill','none');ring.setAttribute('stroke','rgba(255,255,255,0.6)');ring.setAttribute('stroke-width','3');
    const a=document.createElementNS(NS,'animate');
    a.setAttribute('attributeName','r');a.setAttribute('values',`${R+6};${R+14};${R+6}`);
    a.setAttribute('dur','0.8s');a.setAttribute('repeatCount','indefinite');
    ring.appendChild(a);TL.appendChild(ring);
    // Also pulse opacity
    const a2=document.createElementNS(NS,'animate');
    a2.setAttribute('attributeName','stroke-opacity');a2.setAttribute('values','0.8;0.2;0.8');
    a2.setAttribute('dur','0.8s');a2.setAttribute('repeatCount','indefinite');
    ring.appendChild(a2);
  }

  // Selected: gold ring
  if(sel){
    const ring=document.createElementNS(NS,'circle');
    ring.setAttribute('cx',x);ring.setAttribute('cy',y);ring.setAttribute('r',R+12);
    ring.setAttribute('fill','none');ring.setAttribute('stroke','gold');ring.setAttribute('stroke-width','5');
    const a=document.createElementNS(NS,'animate');
    a.setAttribute('attributeName','r');a.setAttribute('values',`${R+8};${R+16};${R+8}`);
    a.setAttribute('dur','0.6s');a.setAttribute('repeatCount','indefinite');
    ring.appendChild(a);TL.appendChild(ring);
  }

  // Shadow
  const sh=document.createElementNS(NS,'circle');
  sh.setAttribute('cx',x+2);sh.setAttribute('cy',y+4);sh.setAttribute('r',R);
  sh.setAttribute('fill','rgba(0,0,0,.45)');TL.appendChild(sh);

  // Body with gradient effect
  const c=document.createElementNS(NS,'circle');
  c.setAttribute('cx',x);c.setAttribute('cy',y);c.setAttribute('r',R);
  c.setAttribute('fill',fill);c.setAttribute('stroke',sel?'gold':(movable?'white':lite));
  c.setAttribute('stroke-width',sel?'5':(movable?'3':'2.5'));
  TL.appendChild(c);

  // Inner ring (ludo-style)
  const inner=document.createElementNS(NS,'circle');
  inner.setAttribute('cx',x);inner.setAttribute('cy',y);inner.setAttribute('r',R*0.62);
  inner.setAttribute('fill','none');inner.setAttribute('stroke',lite);inner.setAttribute('stroke-width','2');
  TL.appendChild(inner);

  // Shine
  const shine=document.createElementNS(NS,'circle');
  shine.setAttribute('cx',x-7);shine.setAttribute('cy',y-7);shine.setAttribute('r',8);
  shine.setAttribute('fill','rgba(255,255,255,.55)');TL.appendChild(shine);

  // Number
  const t=document.createElementNS(NS,'text');
  t.setAttribute('x',x);t.setAttribute('y',y+1.5);
  t.setAttribute('text-anchor','middle');t.setAttribute('dominant-baseline','middle');
  t.setAttribute('font-size','19');t.setAttribute('font-weight','900');
  t.setAttribute('fill','white');t.setAttribute('font-family','Inter,sans-serif');
  t.textContent=idx+1;TL.appendChild(t);

  // Click hit area (only my tokens when movable)
  if(isMe&&myTurn&&diceRolled&&!gameOver&&!animating){
    const hit=document.createElementNS(NS,'circle');
    hit.setAttribute('cx',x);hit.setAttribute('cy',y);hit.setAttribute('r',R+8);
    hit.setAttribute('fill','transparent');hit.style.cursor='pointer';
    hit.addEventListener('click',()=>onToken(idx));
    hit.addEventListener('touchend',e=>{e.preventDefault();onToken(idx);},{passive:false});
    TL.appendChild(hit);
  }
}

// ══ STEP-BY-STEP ANIMATION ══
function animMove(isMe,tokIdx,fromPos,toPos,cb){
  fromPos=parseInt(fromPos); toPos=parseInt(toPos);
  const fill=isMe?MY_FILL:OPP_FILL, lite=isMe?MY_LITE:OPP_LITE;
  const R=24;
  const steps=[];
  if(fromPos===0) steps.push(getXY(0,isMe,tokIdx));
  for(let p=Math.max(fromPos,1);p<=Math.min(toPos,TOTAL);p++) steps.push(getXY(p,isMe,tokIdx));
  if(toPos>=DONE) steps.push(getXY(DONE,isMe,tokIdx));
  if(steps.length<2){if(cb)cb();return;}

  const g=document.createElementNS(NS,'g');
  const sd=document.createElementNS(NS,'circle');sd.setAttribute('r',R);sd.setAttribute('fill','rgba(0,0,0,.4)');
  const cc=document.createElementNS(NS,'circle');cc.setAttribute('r',R);cc.setAttribute('fill',fill);cc.setAttribute('stroke',lite);cc.setAttribute('stroke-width','2.5');
  const ci=document.createElementNS(NS,'circle');ci.setAttribute('r',R*0.62);ci.setAttribute('fill','none');ci.setAttribute('stroke',lite);ci.setAttribute('stroke-width','2');
  const sh=document.createElementNS(NS,'circle');sh.setAttribute('r',8);sh.setAttribute('fill','rgba(255,255,255,.5)');
  const tx=document.createElementNS(NS,'text');tx.setAttribute('text-anchor','middle');tx.setAttribute('dominant-baseline','middle');tx.setAttribute('font-size','19');tx.setAttribute('font-weight','900');tx.setAttribute('fill','white');tx.setAttribute('font-family','Inter,sans-serif');tx.textContent=tokIdx+1;
  g.appendChild(sd);g.appendChild(cc);g.appendChild(ci);g.appendChild(sh);g.appendChild(tx);TL.appendChild(g);

  let step=0;
  function next(){
    if(step>=steps.length){g.remove();if(cb)cb();return;}
    const[x,y]=steps[step++];
    sd.setAttribute('cx',x+2);sd.setAttribute('cy',y+4);
    cc.setAttribute('cx',x);cc.setAttribute('cy',y);
    ci.setAttribute('cx',x);ci.setAttribute('cy',y);
    sh.setAttribute('cx',x-7);sh.setAttribute('cy',y-7);
    tx.setAttribute('x',x);tx.setAttribute('y',y+1.5);
    sndMove();setTimeout(next,120);
  }
  next();
}

// ══ VALID MOVES ══
function getValidMoves(){
  if(!diceRolled||!diceVal)return[];
  return myPos.reduce((a,p,i)=>{
    p=parseInt(p);
    if(p>=DONE)return a;
    if(p===0&&diceVal===6){a.push(i);return a;}
    if(p>0&&p+diceVal<=TOTAL){a.push(i);return a;}
    return a;
  },[]);
}

// ══ TIMER ══
function startTimer(forRoll=false){
  stopTimer(); timerSec=15; showTD();
  timerInt=setInterval(()=>{
    timerSec--;
    if(timerSec<=5&&timerSec>0) sndTick();
    showTD();
    if(timerSec<=0){stopTimer();forRoll||!diceRolled?autoRollMove():autoMove();}
  },1000);
}
function stopTimer(){clearInterval(timerInt);timerInt=null;document.getElementById('tdisplay').textContent='--';document.getElementById('tdisplay').className='';document.getElementById('autolbl').textContent='';}
function showTD(){const el=document.getElementById('tdisplay');el.textContent=timerSec+'s';el.className=timerSec<=5?'urgent':'';if(autoRolls>0)document.getElementById('autolbl').textContent=`(auto ${autoRolls}/5)`;}

function autoRollMove(){
  autoRolls++;
  if(autoRolls>5){showToast('5 timeouts — forfeiting!','error');setTimeout(doForfeit,1500);return;}
  showToast(`⏰ Auto-rolling (${autoRolls}/5)`);
  fetch('ajax/roll_dice.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'game_id='+GAME_ID})
    .then(r=>r.json()).then(d=>{
      if(!d.success)return;
      diceVal=parseInt(d.dice);
      animDice(diceVal,()=>{
        if(d.turn_lost||d.no_move){myTurn=false;diceRolled=false;updateUI();return;}
        diceRolled=true;
        setTimeout(autoMove,500);
      });
    }).catch(()=>{});
}

function autoMove(){
  if(!myTurn||!diceRolled||gameOver)return;
  const moves=getValidMoves();
  if(!moves.length)return;
  onToken(moves[Math.floor(Math.random()*moves.length)]);
}

// ══ MAIN ACTIONS ══
function onToken(idx){
  if(!myTurn||!diceRolled||gameOver||animating)return;
  const pos=parseInt(myPos[idx]);
  if(pos===0&&diceVal!==6){showToast('Need a 6 to come out!');return;}
  if(pos>=DONE){showToast('Already home!');return;}
  if(pos>0&&pos+diceVal>TOTAL){showToast("Can't move — overshoots!");return;}
  stopTimer(); selToken=idx; drawTokens(); makeMove(idx);
}

function rollDice(){
  if(!myTurn||diceRolled||gameOver||animating)return;
  stopTimer(); document.getElementById('rollBtn').disabled=true;
  fetch('ajax/roll_dice.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'game_id='+GAME_ID})
    .then(r=>r.json()).then(d=>{
      if(d.success){
        diceVal=parseInt(d.dice);
        animDice(diceVal,()=>{
          if(d.turn_lost){showToast('3 sixes! Turn lost 😅');myTurn=false;diceRolled=false;autoRolls=0;updateUI();return;}
          if(d.no_move){showToast('No valid move — turn passed');myTurn=false;diceRolled=false;autoRolls=0;updateUI();return;}
          if(diceVal===6)sndSix();
          diceRolled=true;
          // ── KEY FEATURE: auto-move if only 1 valid token ──
          const moves=getValidMoves();
          if(moves.length===1){
            showToast(diceVal===6?'🎉 Six! Auto-moving...':'Rolled '+diceVal+' — auto-moving!');
            drawTokens(); updateUI();
            setTimeout(()=>onToken(moves[0]),600);
          } else if(moves.length===0){
            showToast('No valid move — turn passed');
            myTurn=false; diceRolled=false; autoRolls=0; updateUI();
          } else {
            showToast(diceVal===6?'🎉 Six! Tap token!':'Rolled '+diceVal+' — tap token');
            drawTokens(); updateUI();
            startTimer(false);
          }
        });
      } else {
        showToast(d.message||'Error','error');
        document.getElementById('rollBtn').disabled=false;
        startTimer(true);
      }
    }).catch(()=>{showToast('Network error','error');document.getElementById('rollBtn').disabled=false;startTimer(true);});
}

function makeMove(tokIdx){
  animating=true;
  fetch('ajax/make_move.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`game_id=${GAME_ID}&token_index=${tokIdx}`})
    .then(r=>r.json()).then(d=>{
      selToken=-1;
      if(!d.success){showToast(d.message||'Invalid move','error');animating=false;drawTokens();startTimer(false);return;}
      const from=parseInt(myPos[tokIdx]);
      animMove(true,tokIdx,from,d.my_pos[tokIdx],()=>{
        myPos=d.my_pos; oppPos=d.opp_pos; animating=false;
        if(d.captured){sndCapture();showToast('💥 Captured!');}
        else if(d.extra_turn&&diceVal===6)showToast('🎲 Roll again!');
        if(d.won){gameOver=true;sndWin();drawTokens();updateUI();setTimeout(()=>showResult(true,d.prize),600);return;}
        diceRolled=false; diceVal=null; drawDice(1);
        myTurn=(d.next_turn===MY_ID);
        if(!d.extra_turn)autoRolls=0;
        drawTokens(); updateUI();
        if(myTurn&&!diceRolled) startTimer(true);
      });
    }).catch(()=>{showToast('Network error','error');animating=false;});
}

// ══ POLLING ══
function startPoll(){pollTimer=setInterval(poll,2000);}
function poll(){
  if(gameOver||animating)return;
  fetch(`ajax/poll_game.php?room_id=${ROOM_ID}`)
    .then(r=>r.json()).then(d=>{
      if(d.status==='timeout'){showToast('No opponent — refunded');setTimeout(()=>location.href='index.php',2000);return;}
      if(d.winner_id){
        clearInterval(pollTimer);gameOver=true;stopTimer();
        // Use server-confirmed i_am_winner flag — not just client-side ID comparison
        const iWon = (d.i_am_winner === true) || (d.winner_id === MY_ID);
        iWon ? sndWin() : sndLose();
        showResult(iWon, d.prize || 0);
        return;
      }
      if(d.status==='completed'){
        clearInterval(pollTimer);gameOver=true;stopTimer();
        // completed but no winner_id yet — wait one more poll
        return;
      }
      if(d.status!=='playing')return;

      // P1 positions = red path steps, P2 positions = yellow path steps
      const newMy  = IS_P1 ? d.player1_pos : d.player2_pos;
      const newOpp = IS_P1 ? d.player2_pos : d.player1_pos;
      const wasMine=myTurn, nowMine=(d.current_turn===MY_ID);
      const posChg=JSON.stringify(newMy)!==JSON.stringify(myPos)||JSON.stringify(newOpp)!==JSON.stringify(oppPos);
      myPos=newMy; oppPos=newOpp;
      diceVal=d.dice_value?parseInt(d.dice_value):null;
      diceRolled=!!d.dice_rolled; myTurn=nowMine;
      if(!wasMine&&nowMine&&!diceRolled){autoRolls=0;drawDice(1);startTimer(true);}
      else if(wasMine&&!nowMine){stopTimer();if(diceVal)drawDice(diceVal);}
      if(posChg)drawTokens();
      updateUI();
    }).catch(()=>{});
}

// ══ UI ══
function updateUI(){
  const btn=document.getElementById('rollBtn');
  const txt=document.getElementById('turn-txt');
  const avMe=document.getElementById('av-me');
  const avOp=document.getElementById('av-opp');
  const myGlow=IS_P1?'glow1':'glow2';
  const opGlow=IS_P1?'glow2':'glow1';
  const myClass=IS_P1?'p1':'p2';
  const opClass=IS_P1?'p2':'p1';
  if(myTurn&&!diceRolled){txt.textContent='🎲 Your turn — Roll!';txt.style.color='#22c55e';btn.disabled=false;avMe.className='pav '+myClass+' '+myGlow;avOp.className='pav '+opClass;}
  else if(myTurn&&diceRolled){txt.textContent='👆 Tap token to move';txt.style.color='#f59e0b';btn.disabled=true;avMe.className='pav '+myClass+' '+myGlow;avOp.className='pav '+opClass;}
  else{txt.textContent='⏳ Opponent\'s turn...';txt.style.color='#6b7399';btn.disabled=true;avMe.className='pav '+myClass;avOp.className='pav '+opClass+' '+opGlow;}
  document.getElementById('my-home').textContent=myPos.filter(p=>parseInt(p)>=DONE).length+'/4 home';
  document.getElementById('opp-home').textContent=oppPos.filter(p=>parseInt(p)>=DONE).length+'/4 home';
}

function showResult(won,prize){
  clearInterval(pollTimer);stopTimer();
  document.getElementById('resEmoji').textContent=won?'🏆':'😔';
  document.getElementById('resTitle').textContent=won?'You Won!':'You Lost!';
  const pr=document.getElementById('resPrize');
  if(won){pr.textContent='+₹'+parseFloat(prize||0).toFixed(0);pr.className='res-prize win';document.getElementById('resMsg').textContent='Prize credited to your wallet!';}
  else{pr.textContent='-₹<?= number_format($game['entry_amount'],0) ?>';pr.className='res-prize loss';document.getElementById('resMsg').textContent='Better luck next time!';}
  document.getElementById('resultOverlay').classList.remove('hidden');
}

function doForfeit(){if(gameOver)return;if(!confirm('Forfeit? Opponent wins immediately.'))return;stopTimer();gameOver=true;fetch('ajax/forfeit.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'game_id='+GAME_ID}).then(r=>r.json()).then(d=>{if(d.success)showResult(false,0);else showToast(d.message,'error');});}
function confirmLeave(){
  if(gameOver)return true;
  const ok=confirm('Leaving = forfeit. Opponent wins immediately. Continue?');
  if(ok){
    // Use sendBeacon so the request fires even as page closes
    const fd=new FormData();fd.append('game_id',GAME_ID);
    navigator.sendBeacon('ajax/forfeit.php',fd);
  }
  return ok;
}

// Also handle tab close / browser back without confirm
window.addEventListener('beforeunload',function(e){
  if(gameOver)return;
  // Fire forfeit silently
  const fd=new FormData();fd.append('game_id',GAME_ID);
  navigator.sendBeacon('ajax/forfeit.php',fd);
});
function showToast(msg,type){const t=document.getElementById('toast');t.textContent=msg;t.className='toast show'+(type?' '+type:'');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3000);}

// ══ INIT ══
drawTokens(); updateUI(); drawDice(diceVal?parseInt(diceVal):1);
if(!gameOver){startPoll();if(myTurn&&!diceRolled)startTimer(true);}
<?php if($game['status']==='completed'&&$game['winner_id']): ?>
showResult(<?= $game['winner_id']==$user_id?'true':'false' ?>,0);
<?php endif; ?>
</script>
</body>
</html>