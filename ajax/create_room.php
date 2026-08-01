<?php
// ludo/ajax/create_room.php
session_start();
require '../../includes/db.php';
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false,'message'=>'Not logged in']);
    exit;
}
$user_id = $_SESSION['user_id'];
require '../../includes/db.php';

header('Content-Type: application/json');
date_default_timezone_set('Asia/Kolkata');


function getUserBalance($pdo, $uid) {
    $s = $pdo->prepare("SELECT SUM(CASE WHEN type IN ('deposit','winning') THEN amount WHEN type IN ('withdraw','loss') THEN -amount ELSE 0 END) FROM transactions WHERE user_id=?");
    $s->execute([$uid]);
    return (float)($s->fetchColumn() ?: 0);
}

// --- CANCEL ROOM ---
if (isset($_POST['cancel'])) {
    $room_id = (int)$_POST['room_id'];
    $stmt = $pdo->prepare("SELECT * FROM ludo_rooms WHERE id=? AND creator_id=? AND status='waiting'");
    $stmt->execute([$room_id, $user_id]);
    $room = $stmt->fetch();
    if (!$room) { echo json_encode(['success'=>false,'message'=>'Room not found']); exit; }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE ludo_rooms SET status='cancelled' WHERE id=?")->execute([$room_id]);
        // Refund entry fee
        $pdo->prepare("INSERT INTO transactions (user_id,type,amount,remarks) VALUES (?,'deposit',?,'Ludo Room Cancelled - Refund')")
            ->execute([$user_id, $room['entry_amount']]);
        $pdo->commit();
        echo json_encode(['success'=>true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// --- CREATE ROOM ---
$amount = floatval($_POST['amount'] ?? 0);
if ($amount < 50) { echo json_encode(['success'=>false,'message'=>'Minimum amount is ₹50']); exit; }

// Check rooms enabled
$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='ludo_rooms_enabled'");
if ((int)$stmt->fetchColumn() === 0) { echo json_encode(['success'=>false,'message'=>'Ludo is currently disabled']); exit; }

// Check balance
$balance = getUserBalance($pdo, $user_id);
if ($balance < $amount) { echo json_encode(['success'=>false,'message'=>'Insufficient balance']); exit; }

// Check already in game or waiting
$stmt = $pdo->prepare("SELECT id FROM ludo_rooms WHERE creator_id=? AND status IN ('waiting','playing')");
$stmt->execute([$user_id]);
if ($stmt->fetch()) { echo json_encode(['success'=>false,'message'=>'You already have an active room']); exit; }

$stmt = $pdo->prepare("SELECT g.id FROM ludo_games g WHERE (g.player1_id=? OR g.player2_id=?) AND g.status='active'");
$stmt->execute([$user_id, $user_id]);
if ($stmt->fetch()) { echo json_encode(['success'=>false,'message'=>'You are already in a game']); exit; }

try {
    $pdo->beginTransaction();
    // Generate unique room code
    do {
        $room_code = strtoupper(substr(md5(uniqid()), 0, 6));
        $check = $pdo->prepare("SELECT id FROM ludo_rooms WHERE room_code=?");
        $check->execute([$room_code]);
    } while ($check->fetch());

    // Deduct entry fee
    $pdo->prepare("INSERT INTO transactions (user_id,type,amount,remarks) VALUES (?,'loss',?,'Ludo Entry Fee - Room #{code}')")
        ->execute([$user_id, $amount]);

    // Create room
    $pdo->prepare("INSERT INTO ludo_rooms (room_code,entry_amount,creator_id) VALUES (?,?,?)")
        ->execute([$room_code, $amount, $user_id]);
    $room_id = $pdo->lastInsertId();

    $pdo->commit();
    echo json_encode(['success'=>true,'room_id'=>$room_id,'room_code'=>$room_code]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
