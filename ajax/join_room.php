<?php
session_start();
require '../../includes/db.php';
header('Content-Type: application/json');
date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Not logged in']); exit; }
$user_id = $_SESSION['user_id'];
$room_id = (int)($_POST['room_id'] ?? 0);
if (!$room_id) { echo json_encode(['success'=>false,'message'=>'Invalid room']); exit; }

function getUserBalance($pdo, $uid) {
    $s = $pdo->prepare("SELECT SUM(CASE WHEN type IN ('deposit','winning') THEN amount WHEN type IN ('withdraw','loss') THEN -amount ELSE 0 END) FROM transactions WHERE user_id=?");
    $s->execute([$uid]); return (float)($s->fetchColumn() ?: 0);
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM ludo_rooms WHERE id=? AND status='waiting'");
    $stmt->execute([$room_id]); $room = $stmt->fetch();
    if (!$room) { $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>'Room not available']); exit; }
    if ($room['creator_id'] == $user_id) { $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>'Cannot join your own room']); exit; }

    $s = $pdo->prepare("SELECT id FROM ludo_games WHERE (player1_id=? OR player2_id=?) AND status='active'");
    $s->execute([$user_id,$user_id]);
    if ($s->fetch()) { $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>'Already in a game']); exit; }

    if (getUserBalance($pdo,$user_id) < $room['entry_amount']) {
        $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>'Insufficient balance']); exit;
    }

    $pdo->prepare("INSERT INTO transactions (user_id,type,amount,remarks) VALUES (?,'loss',?,'Ludo Entry Fee - Room #".$room['room_code']."')")
        ->execute([$user_id,$room['entry_amount']]);
    $pdo->prepare("UPDATE ludo_rooms SET opponent_id=?,status='playing' WHERE id=?")->execute([$user_id,$room_id]);

    // Random first turn
    $first = (rand(0,1)===0) ? $room['creator_id'] : $user_id;
    $pdo->prepare("INSERT INTO ludo_games (room_id,player1_id,player2_id,current_turn) VALUES (?,?,?,?)")
        ->execute([$room_id,$room['creator_id'],$user_id,$first]);

    $pdo->commit();
    echo json_encode(['success'=>true,'room_id'=>$room_id]);
} catch(Exception $e){ $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
