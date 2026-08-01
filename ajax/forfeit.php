<?php
session_start();
require '../../includes/db.php';
header('Content-Type: application/json');
date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Not logged in']); exit; }
$user_id = $_SESSION['user_id'];
$game_id = (int)($_POST['game_id'] ?? 0);
if (!$game_id) { echo json_encode(['success'=>false,'message'=>'Invalid game']); exit; }

$stmt = $pdo->prepare("SELECT * FROM ludo_games WHERE id=? AND status='active'");
$stmt->execute([$game_id]); $game = $stmt->fetch();
if (!$game) { echo json_encode(['success'=>false,'message'=>'Game not found']); exit; }
if ($game['player1_id'] != $user_id && $game['player2_id'] != $user_id) {
    echo json_encode(['success'=>false,'message'=>'Not your game']); exit;
}

$winner_id = ($game['player1_id'] == $user_id) ? $game['player2_id'] : $game['player1_id'];

try {
    $pdo->beginTransaction();
    $s = $pdo->prepare("SELECT entry_amount FROM ludo_rooms WHERE id=?");
    $s->execute([$game['room_id']]); $entry = (float)$s->fetchColumn();
    $s2 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='ludo_commission_rate'");
    $rate  = (float)(($s2->fetch())['setting_value'] ?? 0.10);
    $pool  = $entry * 2;
    $prize = $pool - ($pool * $rate);

    $pdo->prepare("INSERT INTO transactions (user_id,type,amount,remarks) VALUES (?,'winning',?,'Ludo Win by Forfeit - Game #".$game_id."')")
        ->execute([$winner_id,$prize]);
    $pdo->prepare("UPDATE ludo_games SET status='completed',winner_id=? WHERE id=?")->execute([$winner_id,$game_id]);
    $pdo->prepare("UPDATE ludo_rooms SET status='completed' WHERE id=?")->execute([$game['room_id']]);
    $pdo->prepare("INSERT INTO ludo_results (game_id,room_id,winner_id,loser_id,entry_amount,prize_amount,commission) VALUES (?,?,?,?,?,?,?)")
        ->execute([$game_id,$game['room_id'],$winner_id,$user_id,$entry,$prize,$pool*$rate]);
    $pdo->commit();
    echo json_encode(['success'=>true,'winner_id'=>$winner_id,'prize'=>$prize]);
} catch(Exception $e){ $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
