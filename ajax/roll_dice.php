<?php
session_start();
require '../../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Not logged in']); exit; }
$user_id = $_SESSION['user_id'];
$game_id = (int)($_POST['game_id'] ?? 0);
if (!$game_id) { echo json_encode(['success'=>false,'message'=>'Invalid game']); exit; }

define('TOTAL_STEPS', 61);
define('FINISHED', 62);

$stmt = $pdo->prepare("SELECT * FROM ludo_games WHERE id=? AND status='active'");
$stmt->execute([$game_id]);
$game = $stmt->fetch();

if (!$game) { echo json_encode(['success'=>false,'message'=>'Game not found']); exit; }
if ((int)$game['current_turn'] !== (int)$user_id) { echo json_encode(['success'=>false,'message'=>'Not your turn']); exit; }
if ($game['dice_rolled']) { echo json_encode(['success'=>false,'message'=>'Already rolled']); exit; }

$dice = rand(1, 6);
$consecutive_six = (int)$game['consecutive_six'];

if ($dice === 6) {
    $consecutive_six++;
    if ($consecutive_six >= 3) {
        $next = ($game['current_turn'] == $game['player1_id']) ? $game['player2_id'] : $game['player1_id'];
        $pdo->prepare("UPDATE ludo_games SET dice_value=?,dice_rolled=0,consecutive_six=0,current_turn=? WHERE id=?")
            ->execute([$dice,$next,$game_id]);
        echo json_encode(['success'=>true,'dice'=>$dice,'turn_lost'=>true]); exit;
    }
} else {
    $consecutive_six = 0;
}

// Check if any valid move exists
$is_p1   = ($user_id == $game['player1_id']);
$my_key  = $is_p1 ? 'player1_pos' : 'player2_pos';
$positions = json_decode($game[$my_key], true);
$has_valid = false;
foreach ($positions as $pos) {
    $pos = (int)$pos;
    if ($pos >= FINISHED) continue;
    if ($pos === 0 && $dice === 6) { $has_valid = true; break; }
    if ($pos > 0 && ($pos + $dice) <= TOTAL_STEPS) { $has_valid = true; break; }
}

if (!$has_valid) {
    $next = ($game['current_turn'] == $game['player1_id']) ? $game['player2_id'] : $game['player1_id'];
    $pdo->prepare("UPDATE ludo_games SET dice_value=?,dice_rolled=0,consecutive_six=0,current_turn=? WHERE id=?")
        ->execute([$dice,$next,$game_id]);
    echo json_encode(['success'=>true,'dice'=>$dice,'no_move'=>true]); exit;
}

$pdo->prepare("UPDATE ludo_games SET dice_value=?,dice_rolled=1,consecutive_six=? WHERE id=?")
    ->execute([$dice,$consecutive_six,$game_id]);

echo json_encode(['success'=>true,'dice'=>$dice,'consecutive_six'=>$consecutive_six]);