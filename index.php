<?php
session_start();
require '../includes/db.php';
date_default_timezone_set('Asia/Kolkata');
if (!isset($_SESSION['user_id'])) { header("Location: ../user_login.php"); exit; }

$user_id = $_SESSION['user_id'];

function getUserBalance($pdo, $uid) {
    $s = $pdo->prepare("SELECT SUM(CASE WHEN type IN ('deposit','winning') THEN amount WHEN type IN ('withdraw','loss') THEN -amount ELSE 0 END) FROM transactions WHERE user_id=?");
    $s->execute([$uid]); return (float)($s->fetchColumn() ?: 0);
}

$balance = getUserBalance($pdo, $user_id);
$stmt = $pdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('ludo_commission_rate','ludo_rooms_enabled')");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$commission_rate = (float)($settings['ludo_commission_rate'] ?? 0.10);
$rooms_enabled   = (int)($settings['ludo_rooms_enabled'] ?? 1);
$fixed_amounts   = [50, 100, 500, 1000];

// Redirect if already in active game
$stmt = $pdo->prepare("SELECT g.id,r.id as room_id FROM ludo_games g JOIN ludo_rooms r ON g.room_id=r.id WHERE (g.player1_id=? OR g.player2_id=?) AND g.status='active' LIMIT 1");
$stmt->execute([$user_id,$user_id]);
$active_game = $stmt->fetch();
if ($active_game) { header("Location: game.php?room=".$active_game['room_id']); exit; }

// Waiting room
$stmt = $pdo->prepare("SELECT id,room_code,entry_amount FROM ludo_rooms WHERE creator_id=? AND status='waiting' LIMIT 1");
$stmt->execute([$user_id]);
$waiting_room = $stmt->fetch();

// Also check if waiting room now has opponent (game started)
if ($waiting_room) {
    $stmt2 = $pdo->prepare("SELECT id FROM ludo_games WHERE room_id=? AND status='active' LIMIT 1");
    $stmt2->execute([$waiting_room['id']]);
    if ($stmt2->fetch()) { header("Location: game.php?room=".$waiting_room['id']); exit; }
}

// Open rooms to join
$stmt = $pdo->prepare("SELECT r.*,u.name as creator_name FROM ludo_rooms r JOIN users u ON r.creator_id=u.id WHERE r.status='waiting' AND r.creator_id!=? ORDER BY r.created_at DESC");
$stmt->execute([$user_id]);
$open_rooms = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Ludo — Lobby</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0d16;--surface:#12172a;--surface2:#1a2035;--border:#232b45;
  --text:#eef0ff;--muted:#6b7399;--accent:#7c6dfa;
  --green:#22c55e;--red:#ef4444;--gold:#f59e0b;
  --radius:14px;--radius-sm:8px;
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding-bottom:40px}
.nav{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:var(--surface);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.logo{font-size:19px;font-weight:800;background:linear-gradient(135deg,#7c6dfa,#f59e0b);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.bal-pill{display:flex;align-items:center;gap:6px;background:var(--surface2);border:1px solid var(--border);padding:6px 13px;border-radius:20px;font-size:13px;font-weight:600}
.bal-pill span{color:var(--green)}
.wrap{max-width:560px;margin:0 auto;padding:20px 14px}

/* HERO */
.hero{text-align:center;padding:28px 16px 22px;background:linear-gradient(135deg,rgba(124,109,250,.12),rgba(245,158,11,.06));border:1px solid rgba(124,109,250,.18);border-radius:var(--radius);margin-bottom:22px}
.hero-dice{font-size:48px;margin-bottom:8px;animation:bounce 2s infinite}
@keyframes bounce{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
.hero h1{font-size:22px;font-weight:800;margin-bottom:4px}
.hero p{color:var(--muted);font-size:13px}
.fee-badge{display:inline-block;margin-top:8px;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.25);color:var(--gold);font-size:11px;padding:3px 11px;border-radius:20px;font-weight:600}

/* WAITING BANNER */
.waiting-banner{background:rgba(124,109,250,.1);border:1px solid rgba(124,109,250,.3);border-radius:var(--radius);padding:16px 18px;margin-bottom:20px}
.wb-top{display:flex;align-items:center;gap:12px;margin-bottom:12px}
.spin{width:26px;height:26px;border:3px solid rgba(124,109,250,.3);border-top-color:var(--accent);border-radius:50%;animation:spin 1s linear infinite;flex-shrink:0}
@keyframes spin{to{transform:rotate(360deg)}}
.wb-title{font-size:14px;font-weight:700}
.wb-sub{font-size:12px;color:var(--muted);margin-top:2px}
/* Room code box */
.room-code-box{background:var(--surface);border:2px dashed rgba(124,109,250,.4);border-radius:var(--radius-sm);padding:12px 16px;margin-bottom:10px}
.rc-label{font-size:11px;color:var(--muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.rc-code{font-size:28px;font-weight:900;letter-spacing:6px;color:var(--accent);font-family:monospace;margin-bottom:8px}
.rc-amount{font-size:13px;color:var(--muted)}
.rc-amount b{color:var(--green)}
.rc-actions{display:flex;gap:8px}
.copy-btn{flex:1;padding:9px;background:rgba(124,109,250,.15);color:var(--accent);border:1px solid rgba(124,109,250,.3);border-radius:var(--radius-sm);font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}
.cancel-btn{padding:9px 16px;background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.2);border-radius:var(--radius-sm);font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}

/* FIXED ROOMS */
.sec-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:10px}
.rooms-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:22px}
.room-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px;text-align:center;cursor:pointer;transition:all .2s;position:relative;overflow:hidden}
.room-card:hover{border-color:var(--accent);transform:translateY(-2px)}
.rc-amt{font-size:24px;font-weight:800;color:var(--green);margin-bottom:3px}
.rc-lbl{font-size:11px;color:var(--muted);margin-bottom:8px}
.rc-win{font-size:12px;color:var(--gold);font-weight:600;margin-bottom:12px}
.play-btn{width:100%;padding:9px;background:var(--accent);color:#fff;border:none;border-radius:var(--radius-sm);font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .2s}
.play-btn:hover{background:#6a5ef0}
.play-btn:disabled{background:var(--surface2);color:var(--muted);cursor:not-allowed}

/* CUSTOM AMOUNT */
.custom-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px;margin-bottom:22px}
.custom-box h3{font-size:14px;font-weight:600;margin-bottom:12px}
.custom-row{display:flex;gap:8px}
.custom-row input{flex:1;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:10px 13px;border-radius:var(--radius-sm);font-size:15px;font-family:inherit;outline:none}
.custom-row input:focus{border-color:var(--accent)}
.custom-row input::placeholder{color:var(--muted)}
.custom-row button{padding:10px 18px;background:var(--accent);color:#fff;border:none;border-radius:var(--radius-sm);font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}

/* OPEN ROOMS */
.open-room{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 14px;display:flex;align-items:center;gap:12px;margin-bottom:8px}
.or-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--gold));display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;flex-shrink:0}
.or-info{flex:1;min-width:0}
.or-name{font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.or-meta{font-size:12px;color:var(--muted);margin-top:2px}
.or-meta b{color:var(--green)}
.join-btn{padding:8px 16px;background:var(--green);color:#fff;border:none;border-radius:var(--radius-sm);font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;flex-shrink:0}
.join-btn:disabled{background:var(--surface2);color:var(--muted);cursor:not-allowed}

/* HOW TO */
.how-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px;margin-top:4px}
.how-box h3{font-size:14px;font-weight:600;margin-bottom:12px}
.how-step{display:flex;gap:10px;margin-bottom:9px;align-items:flex-start}
.step-num{width:22px;height:22px;border-radius:50%;background:var(--accent);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
.how-step p{font-size:13px;color:var(--muted)}
.how-step p b{color:var(--text)}

/* TOAST */
.toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%) translateY(60px);background:var(--surface2);border:1px solid var(--border);padding:10px 18px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;transition:transform .3s;z-index:999;white-space:nowrap}
.toast.show{transform:translateX(-50%) translateY(0)}
.toast.success{border-color:var(--green);color:#86efac}
.toast.error{border-color:var(--red);color:#fca5a5}

/* Auto-refresh indicator */
.refresh-bar{display:flex;align-items:center;justify-content:space-between;padding:6px 14px;background:var(--surface2);border-bottom:1px solid var(--border);font-size:11px;color:var(--muted)}
.refresh-dot{width:6px;height:6px;border-radius:50%;background:var(--green);animation:pulse 2s infinite;display:inline-block;margin-right:5px}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
</style>
</head>
<body>

<nav class="nav">
  <div class="logo">🎲 Ludo</div>
  <div class="bal-pill">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
    Balance: <span>₹<?= number_format($balance,2) ?></span>
  </div>
</nav>

<div class="refresh-bar">
  <span><span class="refresh-dot"></span>Live — auto refreshing every 5s</span>
  <span id="last-refresh">Just now</span>
</div>

<div class="wrap">

  <div class="hero">
    <div class="hero-dice">🎲</div>
    <h1>Play Ludo, Win Real Cash</h1>
    <p>1v1 challenge — get all 4 tokens home first</p>
    <span class="fee-badge">⚡ <?= ($commission_rate*100) ?>% platform fee · Instant payouts</span>
  </div>

  <?php if (!$rooms_enabled): ?>
  <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:var(--radius);padding:16px;text-align:center;color:#fca5a5;margin-bottom:20px">
    🔒 Ludo is currently disabled by admin.
  </div>
  <?php else: ?>

  <!-- WAITING BANNER WITH ROOM CODE -->
  <?php if ($waiting_room): ?>
  <div class="waiting-banner">
    <div class="wb-top">
      <div class="spin"></div>
      <div>
        <div class="wb-title">Waiting for opponent...</div>
        <div class="wb-sub">Share your room code or wait for someone to join</div>
      </div>
    </div>
    <div class="room-code-box">
      <div class="rc-label">Your Room Code</div>
      <div class="rc-code" id="roomCodeDisplay"><?= htmlspecialchars($waiting_room['room_code']) ?></div>
      <div class="rc-amount">Entry: <b>₹<?= number_format($waiting_room['entry_amount'],0) ?></b> per player</div>
    </div>
    <div class="rc-actions">
      <button class="copy-btn" onclick="copyCode('<?= $waiting_room['room_code'] ?>')">📋 Copy Code</button>
      <button class="cancel-btn" onclick="cancelRoom(<?= $waiting_room['id'] ?>)">✕ Cancel</button>
    </div>
  </div>
  <?php else: ?>

  <!-- FIXED ROOMS -->
  <div class="sec-label">Choose Entry Amount</div>
  <div class="rooms-grid">
    <?php foreach ($fixed_amounts as $amt):
      $prize = ($amt*2)*(1-$commission_rate);
      $ok = $balance >= $amt;
    ?>
    <div class="room-card">
      <div class="rc-amt">₹<?= $amt ?></div>
      <div class="rc-lbl">Entry Fee</div>
      <div class="rc-win">🏆 Win ₹<?= number_format($prize,0) ?></div>
      <button class="play-btn" <?= !$ok?'disabled':'' ?> onclick="createRoom(<?= $amt ?>)">
        <?= $ok?'Play Now':'Low Balance' ?>
      </button>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- CUSTOM AMOUNT -->
  <div class="custom-box">
    <h3>💰 Custom Amount</h3>
    <div class="custom-row">
      <input type="number" id="customAmt" placeholder="Enter amount (min ₹5)" min="5" step="1">
      <button onclick="createRoom(document.getElementById('customAmt').value)">Create</button>
    </div>
  </div>
  <?php endif; ?>

  <!-- OPEN ROOMS TO JOIN -->
  <div id="open-rooms-list">
  <?php if (!empty($open_rooms)): ?>
  <div class="sec-label">Join a Room</div>
  <?php foreach ($open_rooms as $room):
    $ok = $balance >= $room['entry_amount'];
    $init = strtoupper(substr($room['creator_name'],0,1));
    $win = ($room['entry_amount']*2)*(1-$commission_rate);
  ?>
  <div class="open-room">
    <div class="or-avatar"><?= $init ?></div>
    <div class="or-info">
      <div class="or-name"><?= htmlspecialchars($room['creator_name']) ?></div>
      <div class="or-meta">Entry: <b>₹<?= number_format($room['entry_amount'],0) ?></b> · Win ₹<?= number_format($win,0) ?> · Code: <?= $room['room_code'] ?></div>
    </div>
    <button class="join-btn" <?= !$ok?'disabled':'' ?> onclick="joinRoom(<?= $room['id'] ?>)">
      <?= $ok?'Join':'Low Bal' ?>
    </button>
  </div>
  <?php endforeach; ?>
  <?php elseif (!$waiting_room): ?>
  <div style="text-align:center;padding:24px;color:var(--muted);font-size:13px;border:1px dashed var(--border);border-radius:var(--radius);margin-bottom:20px">
    No open rooms right now — create one above!
  </div>
  <?php endif; ?>
  </div>

  <!-- HOW TO PLAY -->
  <div class="how-box">
    <h3>🎮 How to Play</h3>
    <div class="how-step"><div class="step-num">1</div><p>Choose entry amount and <b>create a room</b></p></div>
    <div class="how-step"><div class="step-num">2</div><p><b>Share the room code</b> or wait for someone to join</p></div>
    <div class="how-step"><div class="step-num">3</div><p>Roll dice on your turn — <b>move your tokens home</b></p></div>
    <div class="how-step"><div class="step-num">4</div><p>First to get all 4 tokens home <b>wins the prize!</b></p></div>
  </div>

  <?php endif; ?>
</div>

<div class="toast" id="toast"></div>

<script>
// ── Auto-refresh every 4s — no full page reload ──────
let lastRefreshSec = 0;
const commission_rate = <?= $commission_rate ?>;

setInterval(() => {
  lastRefreshSec++;
  const el = document.getElementById('last-refresh');
  if (el) el.textContent = lastRefreshSec <= 1 ? 'Just now' : lastRefreshSec + 's ago';
}, 1000);

setInterval(refreshRooms, 4000);

function refreshRooms() {
  lastRefreshSec = 0;

  <?php if ($waiting_room): ?>
  // Waiting for opponent — poll game status
  fetch('ajax/poll_game.php?room_id=<?= $waiting_room['id'] ?>')
    .then(r=>r.json()).then(d=>{
      if (d.status==='playing') {
        showToast('Opponent joined! Starting game...','success');
        setTimeout(()=>window.location.href='game.php?room=<?= $waiting_room['id'] ?>',600);
      } else if (d.status==='timeout'||d.status==='cancelled') {
        showToast('No opponent found — room cancelled, refunded');
        setTimeout(()=>location.reload(),2000);
      }
    }).catch(()=>{});

  <?php else: ?>
  // No waiting room — refresh open rooms list
  fetch('ajax/poll_rooms.php')
    .then(r=>r.json()).then(d=>{
      if (!d || d.error) return;

      // Redirect if we now have an active game
      if (d.redirect) {
        showToast('Game found! Joining...','success');
        setTimeout(()=>window.location.href=d.redirect, 600);
        return;
      }

      // Room cancelled (timeout)
      if (d.room_cancelled) {
        showToast('Room expired — no opponent found');
        setTimeout(()=>location.reload(),1500);
        return;
      }

      // Update balance display
      if (d.balance !== undefined) {
        const balEl = document.querySelector('.bal-pill span');
        if (balEl) balEl.textContent = '₹' + parseFloat(d.balance).toLocaleString('en-IN', {minimumFractionDigits:2,maximumFractionDigits:2});
      }

      // Re-render open rooms list
      const container = document.getElementById('open-rooms-list');
      if (!container) return;

      if (!d.rooms || d.rooms.length === 0) {
        container.innerHTML = '<div style="text-align:center;padding:24px;color:var(--muted);font-size:13px;border:1px dashed var(--border);border-radius:var(--radius);margin-bottom:20px">No open rooms right now — create one above!</div>';
        return;
      }

      let html = '<div class="sec-label">Join a Room</div>';
      d.rooms.forEach(room => {
        const ok = d.balance >= parseFloat(room.entry_amount);
        const init = room.creator_name.charAt(0).toUpperCase();
        const prize = parseFloat(room.entry_amount) * 2 * (1 - commission_rate);
        html += `
        <div class="open-room">
          <div class="or-avatar">${init}</div>
          <div class="or-info">
            <div class="or-name">${escHtml(room.creator_name)}</div>
            <div class="or-meta">Entry: <b>₹${parseFloat(room.entry_amount).toFixed(0)}</b> · Win ₹${prize.toFixed(0)} · Code: ${room.room_code}</div>
          </div>
          <button class="join-btn" ${!ok?'disabled':''} onclick="joinRoom(${room.id})">
            ${ok?'Join':'Low Bal'}
          </button>
        </div>`;
      });
      container.innerHTML = html;

    }).catch(()=>{});
  <?php endif; ?>
}

function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function copyCode(code) {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(code).then(()=>showToast('Room code copied! Share with your opponent','success'));
  } else {
    // Fallback
    const el = document.createElement('textarea');
    el.value = code; document.body.appendChild(el); el.select(); document.execCommand('copy');
    document.body.removeChild(el); showToast('Copied: '+code,'success');
  }
}

function createRoom(amount) {
  amount = parseFloat(amount);
  if (!amount||amount<5) { showToast('Minimum ₹50','error'); return; }
  fetch('ajax/create_room.php',{
    method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'amount='+amount
  }).then(r=>r.json()).then(d=>{
    if (d.success) { showToast('Room created!','success'); setTimeout(()=>location.reload(),800); }
    else showToast(d.message||'Error','error');
  }).catch(()=>showToast('Network error','error'));
}

function joinRoom(room_id) {
  fetch('ajax/join_room.php',{
    method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'room_id='+room_id
  }).then(r=>r.json()).then(d=>{
    if (d.success) { showToast('Joined! Starting...','success'); setTimeout(()=>window.location.href='game.php?room='+room_id,600); }
    else showToast(d.message||'Error','error');
  }).catch(()=>showToast('Network error','error'));
}

function cancelRoom(room_id) {
  fetch('ajax/create_room.php',{
    method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'cancel=1&room_id='+room_id
  }).then(r=>r.json()).then(d=>{
    if (d.success) location.reload();
    else showToast(d.message||'Error','error');
  });
}

function showToast(msg,type) {
  const t=document.getElementById('toast');
  t.textContent=msg; t.className='toast show'+(type?' '+type:'');
  clearTimeout(t._t); t._t=setTimeout(()=>t.classList.remove('show'),3000);
}
</script>
</body>
</html>