<?php
// ludo/ajax/poll_rooms.php
// Returns current open rooms as JSON so lobby can update without full page reload
session_start();
require '../../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'not_logged_in']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Check if this user now has an active game (opponent joined their room)
$stmt = $pdo->prepare("
    SELECT g.id, r.id as room_id
    FROM ludo_games g
    JOIN ludo_rooms r ON g.room_id = r.id
    WHERE (g.player1_id = ? OR g.player2_id = ?) AND g.status = 'active'
    LIMIT 1
");
$stmt->execute([$user_id, $user_id]);
$active = $stmt->fetch();
if ($active) {
    echo json_encode(['redirect' => 'game.php?room=' . $active['room_id']]);
    exit;
}

// Check if waiting room got cancelled (timeout)
$stmt = $pdo->prepare("
    SELECT id, room_code, entry_amount, status
    FROM ludo_rooms
    WHERE creator_id = ? AND status IN ('waiting', 'cancelled')
    ORDER BY created_at DESC LIMIT 1
");
$stmt->execute([$user_id]);
$myRoom = $stmt->fetch();
if ($myRoom && $myRoom['status'] === 'cancelled') {
    echo json_encode(['room_cancelled' => true]);
    exit;
}

// Fetch all open rooms (not created by me)
$stmt = $pdo->prepare("
    SELECT r.id, r.room_code, r.entry_amount, r.created_at,
           u.name as creator_name
    FROM ludo_rooms r
    JOIN users u ON r.creator_id = u.id
    WHERE r.status = 'waiting' AND r.creator_id != ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$user_id]);
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch user balance
$stmt = $pdo->prepare("
    SELECT SUM(CASE
        WHEN type IN ('deposit','winning') THEN amount
        WHEN type IN ('withdraw','loss')   THEN -amount
        ELSE 0 END) as bal
    FROM transactions WHERE user_id = ?
");
$stmt->execute([$user_id]);
$balance = (float)($stmt->fetchColumn() ?: 0);

echo json_encode([
    'rooms'   => $rooms,
    'balance' => $balance,
    'count'   => count($rooms),
]);