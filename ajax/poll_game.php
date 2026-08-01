<?php
session_start();
require '../../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode(['status'=>'error','message'=>'Not logged in']); exit; }
$user_id = $_SESSION['user_id'];
$room_id = (int)($_GET['room_id'] ?? $_POST['room_id'] ?? 0);
if (!$room_id) { echo json_encode(['status'=>'error']); exit; }

$stmt = $pdo->prepare("
    SELECT r.status as room_status, r.entry_amount, r.creator_id, r.created_at as room_created,
           g.*
    FROM ludo_rooms r
    LEFT JOIN ludo_games g ON g.room_id = r.id
    WHERE r.id = ?
");
$stmt->execute([$room_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) { echo json_encode(['status'=>'error','message'=>'Room not found']); exit; }

// ── Waiting — check timeout ────────────────────────
if ($data['room_status'] === 'waiting') {
    $age     = time() - strtotime($data['room_created']);
    $timeout = 120;
    $s = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='ludo_matchmaking_timeout'");
    if ($r = $s->fetch()) $timeout = (int)$r['setting_value'];
    if ($age > $timeout) {
        $pdo->prepare("UPDATE ludo_rooms SET status='cancelled' WHERE id=?")->execute([$room_id]);
        $pdo->prepare("INSERT INTO transactions (user_id,type,amount,remarks) VALUES (?,'deposit',?,'Ludo Refund - No opponent found')")
            ->execute([$data['creator_id'], $data['entry_amount']]);
        echo json_encode(['status'=>'timeout']); exit;
    }
    echo json_encode(['status'=>'waiting','seconds_waiting'=>$age]); exit;
}

// ── Playing ────────────────────────────────────────
if ($data['room_status'] === 'playing' && !empty($data['id'])) {
    $isCompleted = ($data['status'] === 'completed');
    echo json_encode([
        'status'       => $isCompleted ? 'completed' : 'playing',
        'game_id'      => (int)$data['id'],
        'player1_pos'  => json_decode($data['player1_pos']),
        'player2_pos'  => json_decode($data['player2_pos']),
        'current_turn' => (int)$data['current_turn'],
        'dice_value'   => $data['dice_value'] ? (int)$data['dice_value'] : null,
        'dice_rolled'  => (bool)$data['dice_rolled'],
        'winner_id'    => $data['winner_id'] ? (int)$data['winner_id'] : null,
        'my_turn'      => ((int)$data['current_turn'] === (int)$user_id),
        'i_am_winner'  => $data['winner_id'] ? ((int)$data['winner_id'] === (int)$user_id) : null,
        'prize'        => $data['winner_id'] ? (function() use ($pdo, $data) {
            $s = $pdo->prepare("SELECT prize_amount FROM ludo_results WHERE game_id=? LIMIT 1");
            $s->execute([$data['id']]);
            return (float)($s->fetchColumn() ?: 0);
        })() : null,
    ]);
    exit;
}

echo json_encode(['status' => $data['room_status'] ?? 'unknown']);