<?php
// ludo/ajax/make_move.php
session_start();
require '../../includes/db.php';
header('Content-Type: application/json');
date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Not logged in']); exit; }
$user_id   = $_SESSION['user_id'];
$game_id   = (int)($_POST['game_id']     ?? 0);
$token_idx = (int)($_POST['token_index'] ?? -1);
if (!$game_id || $token_idx < 0 || $token_idx > 3) {
    echo json_encode(['success'=>false,'message'=>'Invalid input']); exit;
}

define('TOTAL', 61);  // path length
define('DONE',  62);  // finished marker

// Safe positions for each player (1-indexed path steps)
// Step 1 = exit/start square — always safe (standard Ludo rule)
// Home stretch (55+) always safe — no capture ever
$RED_SAFE    = [1,2,10,14,24,28,38,42,54,55,56,57,58,59,60,61];
$YELLOW_SAFE = [1,10,14,26,30,38,42,52,55,56,57,58,59,60,61];

// Capture mapping: when P1 lands on step X, P2 at step Y occupies same physical cell
// Generated mathematically from both path arrays
$CAPTURE_MAP_P1_TO_P2 = [
    1=>29,2=>30,3=>31,4=>32,5=>33,6=>34,7=>35,8=>36,9=>37,10=>38,
    11=>39,12=>40,13=>41,14=>42,15=>43,16=>44,17=>45,18=>46,19=>47,20=>48,
    21=>49,22=>50,23=>51,24=>52,25=>53,26=>54,27=>55,
    29=>1,30=>2,31=>3,32=>4,33=>5,34=>6,35=>7,36=>8,37=>9,38=>10,
    39=>11,40=>12,41=>13,42=>14,43=>15,44=>16,45=>17,46=>18,47=>19,48=>20,
    49=>21,50=>22,51=>23,52=>24,53=>25,54=>26,55=>27
];

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("SELECT * FROM ludo_games WHERE id=? AND status='active'");
    $stmt->execute([$game_id]);
    $game = $stmt->fetch();
    if (!$game) throw new Exception('Game not found');
    if ((int)$game['current_turn'] !== (int)$user_id) throw new Exception('Not your turn');
    if (!$game['dice_rolled']) throw new Exception('Roll dice first');

    $is_p1   = ($user_id == $game['player1_id']);
    $my_key  = $is_p1 ? 'player1_pos' : 'player2_pos';
    $opp_key = $is_p1 ? 'player2_pos' : 'player1_pos';
    $my_safe  = $is_p1 ? $RED_SAFE    : $YELLOW_SAFE;
    $opp_safe = $is_p1 ? $YELLOW_SAFE : $RED_SAFE;

    $my_pos  = json_decode($game[$my_key],  true);
    $opp_pos = json_decode($game[$opp_key], true);
    $dice    = (int)$game['dice_value'];
    $cur_pos = (int)$my_pos[$token_idx];

    // ── Validate ─────────────────────────────────────
    if ($cur_pos >= DONE)               throw new Exception('Token already home');
    if ($cur_pos === 0 && $dice !== 6)  throw new Exception('Need a 6 to come out');
    $new_pos = ($cur_pos === 0) ? 1 : $cur_pos + $dice;
    if ($new_pos > TOTAL)               throw new Exception('Cannot move — overshoots home');

    $is_home_stretch = ($new_pos >= 55);
    $is_safe         = in_array($new_pos, $my_safe);

    // ── Capture check ────────────────────────────────
    // Only on shared outer track cells, not on safe squares or home stretch
    $captured = false;
    if (!$is_safe && !$is_home_stretch) {
        // Find what position on OPPONENT'S path shares same physical cell
        $opp_equiv = $CAPTURE_MAP_P1_TO_P2[$new_pos] ?? null;
        if ($opp_equiv !== null) {
            foreach ($opp_pos as $oi => $op) {
                $op = (int)$op;
                if ($op === 0 || $op >= DONE) continue; // in base or finished
                // Check opponent is on same physical cell
                if ($op === $opp_equiv && !in_array($op, $opp_safe)) {
                    $opp_pos[$oi] = 0; // send back to base!
                    $captured = true;
                }
            }
        }
    }

    // Mark finished if reached end
    $finished = ($new_pos === TOTAL);
    if ($finished) $new_pos = DONE;
    $my_pos[$token_idx] = $new_pos;

    // ── Win check ────────────────────────────────────
    $won = count(array_filter($my_pos, fn($p) => (int)$p >= DONE)) === 4;
    if ($won) {
        $s2 = $pdo->prepare("SELECT entry_amount FROM ludo_rooms WHERE id=?");
        $s2->execute([$game['room_id']]);
        $entry = (float)$s2->fetchColumn();
        $s3 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='ludo_commission_rate'");
        $rate  = (float)(($s3->fetch())['setting_value'] ?? 0.10);
        $pool  = $entry * 2;
        $prize = $pool - $pool * $rate;
        $loser = $is_p1 ? $game['player2_id'] : $game['player1_id'];

        $pdo->prepare("INSERT INTO transactions (user_id,type,amount,remarks) VALUES (?,'winning',?,'Ludo Win - Game #$game_id')")
            ->execute([$user_id, $prize]);
        $pdo->prepare("UPDATE ludo_games SET {$my_key}=?,{$opp_key}=?,status='completed',winner_id=?,dice_rolled=0 WHERE id=?")
            ->execute([json_encode($my_pos), json_encode($opp_pos), $user_id, $game_id]);
        $pdo->prepare("UPDATE ludo_rooms SET status='completed' WHERE id=?")->execute([$game['room_id']]);
        $pdo->prepare("INSERT INTO ludo_results (game_id,room_id,winner_id,loser_id,entry_amount,prize_amount,commission) VALUES (?,?,?,?,?,?,?)")
            ->execute([$game_id,$game['room_id'],$user_id,$loser,$entry,$prize,$pool*$rate]);
        $pdo->prepare("INSERT INTO ludo_moves (game_id,player_id,token_index,from_pos,to_pos,dice_value) VALUES (?,?,?,?,?,?)")
            ->execute([$game_id,$user_id,$token_idx,$cur_pos,$new_pos,$dice]);
        $pdo->commit();
        echo json_encode(['success'=>true,'won'=>true,'winner_id'=>$user_id,'prize'=>$prize,'my_pos'=>$my_pos,'opp_pos'=>$opp_pos]);
        exit;
    }

    // ── Next turn ────────────────────────────────────
    $extra = ($dice === 6 || $captured);
    $next  = $extra ? $user_id : ($is_p1 ? $game['player2_id'] : $game['player1_id']);

    $pdo->prepare("INSERT INTO ludo_moves (game_id,player_id,token_index,from_pos,to_pos,dice_value) VALUES (?,?,?,?,?,?)")
        ->execute([$game_id,$user_id,$token_idx,$cur_pos,$new_pos,$dice]);
    $pdo->prepare("UPDATE ludo_games SET {$my_key}=?,{$opp_key}=?,current_turn=?,dice_rolled=0,dice_value=NULL WHERE id=?")
        ->execute([json_encode($my_pos), json_encode($opp_pos), $next, $game_id]);
    $pdo->commit();

    echo json_encode([
        'success'    => true,
        'won'        => false,
        'captured'   => $captured,
        'extra_turn' => $extra,
        'my_pos'     => $my_pos,
        'opp_pos'    => $opp_pos,
        'next_turn'  => $next,
        'new_pos'    => $new_pos,
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}