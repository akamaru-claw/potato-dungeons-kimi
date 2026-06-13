<?php
// ============================================================
// AUTH_API.PHP — Account system for Potato Dungeons Fork
// Endpoints: register, login, save, load
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$dbFile = __DIR__ . '/potato_accounts.db';
$db = new SQLite3($dbFile);
$db->busyTimeout(5000);
$db->exec('CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    gold INTEGER DEFAULT 0,
    skin TEXT DEFAULT "potato_default",
    trail TEXT DEFAULT "",
    selected_character TEXT DEFAULT "potato_default",
    unlocked_skins TEXT DEFAULT "potato_default",
    unlocked_trails TEXT DEFAULT "",
    total_kills INTEGER DEFAULT 0,
    best_floor INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME DEFAULT CURRENT_TIMESTAMP
)');
$db->exec('CREATE TABLE IF NOT EXISTS shop_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_key TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,
    type TEXT NOT NULL,
    price INTEGER NOT NULL,
    icon TEXT DEFAULT ""
)');

// Ensure trail and selected_character columns exist
$db->exec('ALTER TABLE users ADD COLUMN trail TEXT DEFAULT \'\'');
$db->exec('ALTER TABLE users ADD COLUMN selected_character TEXT DEFAULT \'potato_default\'');

// Seed shop items if empty
$count = $db->querySingle('SELECT COUNT(*) FROM shop_items');
if ($count == 0) {
    $items = [
        // Cosmetic skins
        ['skin_golden', 'Gold-Glanz', 'skin', 50, '✨'],
        ['skin_fire', 'Feuer-Aura', 'skin', 100, '🔥'],
        ['skin_ice', 'Eis-Kristall', 'skin', 100, '❄️'],
        ['skin_shadow', 'Schatten-Schleier', 'skin', 150, '🖤'],
        ['skin_rainbow', 'Regenbogen-Zyklus', 'skin', 200, '🌈'],
        ['skin_neon', 'Neon-Rand', 'skin', 250, '💚'],
        ['skin_crystal', 'Kristall-Funken', 'skin', 300, '💎'],
        ['skin_diamond', 'Diamant-Rahmen', 'skin', 400, '💠'],
        ['skin_ghost', 'Geister-Kartoffel', 'skin', 500, '👻'],
        // Character classes
        ['potato_fries', 'Pommes', 'character', 50, '🍟'],
        ['potato_sweet', 'Süßkartoffel', 'character', 80, '🍠'],
        ['potato_chips', 'Chips', 'character', 120, '🥔'],
        ['potato_golden', 'Goldene Kartoffel', 'character', 200, '✨'],
        ['potato_shadow', 'Schattenknolle', 'character', 300, '🖤'],
        ['potato_rainbow', 'Regenbogen', 'character', 500, '🌈'],
        ['potato_devil', 'Teufelskartoffel', 'character', 666, '😈'],
        // Trails
        ['trail_fire', 'Feuerspur', 'trail', 100, '🔥'],
        ['trail_ice', 'Eisspur', 'trail', 100, '❄️'],
        ['trail_rainbow', 'Regenbogenspur', 'trail', 250, '🌈'],
        ['trail_particles', 'Sternenstaub', 'trail', 150, '⭐'],
    ];
    $stmt = $db->prepare('INSERT INTO shop_items (item_key, name, type, price, icon) VALUES (?, ?, ?, ?, ?)');
    foreach ($items as $item) {
        $stmt->bindValue(1, $item[0], SQLITE3_TEXT);
        $stmt->bindValue(2, $item[1], SQLITE3_TEXT);
        $stmt->bindValue(3, $item[2], SQLITE3_TEXT);
        $stmt->bindValue(4, $item[3], SQLITE3_INTEGER);
        $stmt->bindValue(5, $item[4], SQLITE3_TEXT);
        $stmt->execute();
    }
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? ($input['action'] ?? '');

function respond($ok, $data = []) {
    echo json_encode(array_merge(['ok' => $ok], $data));
    exit;
}

function generateToken() {
    return bin2hex(random_bytes(32));
}

// ============================================================
// REGISTER
// ============================================================
if ($action === 'register') {
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (strlen($username) < 2 || strlen($username) > 20) respond(false, ['error' => 'Username muss 2-20 Zeichen haben']);
    if (strlen($password) < 4) respond(false, ['error' => 'Passwort muss mindestens 4 Zeichen haben']);
    if (!preg_match('/^[a-zA-Z0-9_öäüÖÄÜß]+$/', $username)) respond(false, ['error' => 'Nur Buchstaben, Zahlen und _ erlaubt']);

    // Check if username exists
    $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    if ($result->fetchArray()) respond(false, ['error' => 'Username bereits vergeben']);

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $token = generateToken();

    $stmt = $db->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $stmt->bindValue(2, $hash, SQLITE3_TEXT);
    $stmt->execute();

    $userId = $db->lastInsertRowID();

    respond(true, ['token' => $token, 'userId' => $userId, 'username' => $username, 'gold' => 0, 'skin' => 'potato_default']);
}

// ============================================================
// LOGIN
// ============================================================
if ($action === 'login') {
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    $stmt = $db->prepare('SELECT id, password_hash, gold, skin, trail, selected_character, unlocked_skins, unlocked_trails, best_floor FROM users WHERE username = ?');
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if (!$row || !password_verify($password, $row['password_hash'])) {
        respond(false, ['error' => 'Falscher Username oder Passwort']);
    }

    $token = generateToken();
    $db->exec("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = {$row['id']}");

    respond(true, [
        'token' => $token,
        'userId' => $row['id'],
        'username' => $username,
        'gold' => (int)$row['gold'],
        'skin' => $row['skin'],
        'trail' => $row['trail'] ?? '',
        'selectedCharacter' => $row['selected_character'] ?? 'potato_default',
        'unlocked_skins' => $row['unlocked_skins'],
        'unlocked_trails' => $row['unlocked_trails'],
        'best_floor' => (int)$row['best_floor']
    ]);
}

// ============================================================
// LOAD (get current state)
// ============================================================
if ($action === 'load') {
    $username = $input['username'] ?? '';
    $stmt = $db->prepare('SELECT id, gold, skin, trail, selected_character, unlocked_skins, unlocked_trails, total_kills, best_floor FROM users WHERE username = ?');
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if (!$row) respond(false, ['error' => 'User nicht gefunden']);

    respond(true, [
        'gold' => (int)$row['gold'],
        'skin' => $row['skin'],
        'trail' => $row['trail'] ?? '',
        'selected_character' => $row['selected_character'] ?? 'potato_default',
        'unlocked_skins' => $row['unlocked_skins'],
        'unlocked_trails' => $row['unlocked_trails'],
        'total_kills' => (int)$row['total_kills'],
        'best_floor' => (int)$row['best_floor']
    ]);
}

// ============================================================
// SAVE (update state after a run)
// ============================================================
if ($action === 'save') {
    $username = $input['username'] ?? '';
    $gold = intval($input['gold'] ?? 0);
    $skin = $input['skin'] ?? 'potato_default';
    $totalKills = intval($input['total_kills'] ?? 0);
    $bestFloor = intval($input['best_floor'] ?? 0);

    $stmt = $db->prepare('SELECT id, gold, total_kills, best_floor FROM users WHERE username = ?');
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if (!$row) respond(false, ['error' => 'User nicht gefunden']);

    // Only add gold, don't reduce (anti-cheat lite)
    $newGold = max($row['gold'], $gold);
    $newKills = max($row['total_kills'], $totalKills);
    $newFloor = max($row['best_floor'], $bestFloor);

    $stmt = $db->prepare('UPDATE users SET gold = ?, skin = ?, total_kills = ?, best_floor = ? WHERE id = ?');
    $stmt->bindValue(1, $newGold, SQLITE3_INTEGER);
    $stmt->bindValue(2, $skin, SQLITE3_TEXT);
    $stmt->bindValue(3, $newKills, SQLITE3_INTEGER);
    $stmt->bindValue(4, $newFloor, SQLITE3_INTEGER);
    $stmt->bindValue(5, $row['id'], SQLITE3_INTEGER);
    $stmt->execute();

    respond(true, ['gold' => $newGold]);
}

// ============================================================
// SHOP - List items
// ============================================================
if ($action === 'shop') {
    $result = $db->query('SELECT item_key, name, type, price, icon FROM shop_items ORDER BY price ASC');
    $items = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $items[] = $row;
    }
    respond(true, ['items' => $items]);
}

// ============================================================
// BUY item
// ============================================================
if ($action === 'buy') {
    $username = $input['username'] ?? '';
    $itemKey = $input['item'] ?? '';

    // Get user
    $stmt = $db->prepare('SELECT id, gold, unlocked_skins, unlocked_trails FROM users WHERE username = ?');
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    if (!$user) respond(false, ['error' => 'User nicht gefunden']);

    // Get item
    $stmt = $db->prepare('SELECT item_key, name, type, price, icon FROM shop_items WHERE item_key = ?');
    $stmt->bindValue(1, $itemKey, SQLITE3_TEXT);
    $result = $stmt->execute();
    $item = $result->fetchArray(SQLITE3_ASSOC);
    if (!$item) respond(false, ['error' => 'Item nicht gefunden']);

    // Check if already owned
    $unlockedList = $item['type'] === 'character' ? $user['unlocked_skins'] : ($item['type'] === 'trail' ? $user['unlocked_trails'] : $user['unlocked_skins']);
    $owned = array_map('trim', explode(',', $unlockedList));
    $isDefault = ($item['type'] === 'character' && $itemKey === 'potato_default') || ($item['type'] === 'skin' && $itemKey === 'skin_default');
    if (in_array($itemKey, $owned) || $isDefault) {
        respond(false, ['error' => 'Bereits besessen']);
    }

    // Check gold
    if ($user['gold'] < $item['price']) respond(false, ['error' => 'Nicht genug Gold']);

    // Deduct gold and add item
    $newGold = $user['gold'] - $item['price'];
    if ($item['type'] === 'skin' || $item['type'] === 'character') {
        $newUnlocked = $user['unlocked_skins'] ? $user['unlocked_skins'] . ',' . $itemKey : $itemKey;
        $stmt = $db->prepare('UPDATE users SET gold = ?, unlocked_skins = ? WHERE id = ?');
        $stmt->bindValue(1, $newGold, SQLITE3_INTEGER);
        $stmt->bindValue(2, $newUnlocked, SQLITE3_TEXT);
    } else {
        $newUnlocked = $user['unlocked_trails'] ? $user['unlocked_trails'] . ',' . $itemKey : $itemKey;
        $stmt = $db->prepare('UPDATE users SET gold = ?, unlocked_trails = ? WHERE id = ?');
        $stmt->bindValue(1, $newGold, SQLITE3_INTEGER);
        $stmt->bindValue(2, $newUnlocked, SQLITE3_TEXT);
    }
    $stmt->bindValue(3, $user['id'], SQLITE3_INTEGER);
    $stmt->execute();

    respond(true, ['gold' => $newGold, 'unlocked' => $newUnlocked]);
}

// ============================================================
// EQUIP skin/trail
// ============================================================
if ($action === 'equip') {
    $username = $input['username'] ?? '';
    $itemKey = $input['item'] ?? '';
    $type = $input['type'] ?? 'skin'; // skin or trail

    $stmt = $db->prepare('SELECT id, unlocked_skins, unlocked_trails FROM users WHERE username = ?');
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    if (!$user) respond(false, ['error' => 'User nicht gefunden']);

    $unlockedList = $type === 'skin' ? $user['unlocked_skins'] : $user['unlocked_trails'];
    $owned = array_map('trim', explode(',', $unlockedList));
    if (!in_array($itemKey, $owned)) respond(false, ['error' => 'Nicht freigeschaltet']);

    if ($type === 'skin') {
        $stmt = $db->prepare('UPDATE users SET skin = ? WHERE id = ?');
        $stmt->bindValue(1, $itemKey, SQLITE3_TEXT);
    } else {
        $stmt = $db->prepare('UPDATE users SET trail = ? WHERE id = ?');
        $stmt->bindValue(1, $itemKey, SQLITE3_TEXT);
    }
    $stmt->bindValue(2, $user['id'], SQLITE3_INTEGER);
    $stmt->execute();

    respond(true, ['equipped' => $itemKey]);
}

// ============================================================
// CHANGE USERNAME
// ============================================================
if ($action === 'change_name') {
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $new_name = trim($input['new_name'] ?? '');

    if (strlen($new_name) < 2 || strlen($new_name) > 20) respond(false, ['error' => 'Name muss 2-20 Zeichen haben']);
    if (!preg_match('/^[a-zA-Z0-9_öäüÖÄÜß]+$/', $new_name)) respond(false, ['error' => 'Nur Buchstaben, Zahlen und _']);

    $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    if (!$user || !password_verify($password, $user['password_hash'])) respond(false, ['error' => 'Falsches Passwort']);

    // Check if new name is taken
    $stmt = $db->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
    $stmt->bindValue(1, $new_name, SQLITE3_TEXT);
    $stmt->bindValue(2, $user['id'], SQLITE3_INTEGER);
    $result = $stmt->execute();
    if ($result->fetchArray()) respond(false, ['error' => 'Name bereits vergeben']);

    $stmt = $db->prepare('UPDATE users SET username = ? WHERE id = ?');
    $stmt->bindValue(1, $new_name, SQLITE3_TEXT);
    $stmt->bindValue(2, $user['id'], SQLITE3_INTEGER);
    $stmt->execute();

    respond(true, ['username' => $new_name]);
}

// ============================================================
// CHANGE PASSWORD
// ============================================================
if ($action === 'change_pass') {
    $username = trim($input['username'] ?? '');
    $old_password = $input['old_password'] ?? '';
    $new_password = $input['new_password'] ?? '';

    if (strlen($new_password) < 4) respond(false, ['error' => 'Mindestens 4 Zeichen']);

    $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    if (!$user || !password_verify($old_password, $user['password_hash'])) respond(false, ['error' => 'Falsches Passwort']);

    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->bindValue(1, $new_hash, SQLITE3_TEXT);
    $stmt->bindValue(2, $user['id'], SQLITE3_INTEGER);
    $stmt->execute();

    respond(true);
}

respond(false, ['error' => 'Unbekannte Aktion']);
?>