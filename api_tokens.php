<?php
// api_tokens.php
require_once __DIR__ . '/auth.php';
require_login();
include __DIR__ . '/header.php';
require_once __DIR__ . '/config.php'; // biến $pdo

$msg = '';

// ======================= XỬ LÝ POST =======================
$action   = $_POST['action'] ?? '';
$tokenId  = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$username = trim($_POST['username'] ?? '');
$expires  = trim($_POST['expires_at'] ?? null);

if ($action) {
    try {
        switch ($action) {
            case 'create':
    if (!$username) throw new Exception("Username is required");

    // Token ngắn: 8 ký tự alphanumeric
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $newToken = '';
    for($i=0;$i<8;$i++){
        $newToken .= $chars[random_int(0, strlen($chars)-1)];
    }

    $stmt = $pdo->prepare("
        INSERT INTO api_keys (username, token, is_active, expires_at)
        VALUES (:username, :token, 1, :expires)
    ");
    $stmt->execute([
        ':username' => $username,
        ':token'    => $newToken,
        ':expires'  => $expires ?: null
    ]);
    $msg = "✅ Token created: <strong>$newToken</strong>";
    break;

            case 'toggle':
                if (!$tokenId) throw new Exception("Token ID missing");
                $stmt = $pdo->prepare("UPDATE api_keys SET is_active = 1 - is_active WHERE id = :id");
                $stmt->execute([':id' => $tokenId]);
                $msg = "✅ Token toggled.";
                break;

            case 'delete':
                if (!$tokenId) throw new Exception("Token ID missing");
                $stmt = $pdo->prepare("DELETE FROM api_keys WHERE id = :id");
                $stmt->execute([':id' => $tokenId]);
                $msg = "✅ Token deleted.";
                break;

            default:
                throw new Exception("Unknown action");
        }
    } catch (Exception $e) {
        $msg = "❌ Error: " . htmlspecialchars($e->getMessage());
    }
}

// ======================= LẤY DANH SÁCH TOKEN =======================
$stmt = $pdo->query("SELECT id, username, token, is_active, expires_at FROM api_keys ORDER BY id DESC");
$tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="content-card" style="max-width:900px;margin:20px auto;padding:20px;background:#111b2d;border-radius:12px;color:#e7ecfa;">
    <h1 style="text-align:center;color:#3b82f6;">API Tokens Manager</h1>

    <?php if ($msg): ?>
        <div style="background:#333;padding:10px;margin:10px 0;border-left:4px solid #3b82f6;">
            <?= $msg ?>
        </div>
    <?php endif; ?>

    <!-- CREATE NEW TOKEN -->
    <h2>Create New Token</h2>
    <form method="post" style="margin-bottom:20px;">
        <input type="hidden" name="action" value="create">
        <input type="text" name="username" placeholder="Username" required style="padding:5px;">
        <input type="datetime-local" name="expires_at" placeholder="Expires at (optional)" style="padding:5px;">
        <button type="submit" style="padding:5px 10px;">Create</button>
    </form>

    <!-- LIST EXISTING TOKENS -->
    <h2>Existing Tokens</h2>
    <table style="width:100%;border-collapse:collapse;">
        <thead>
            <tr>
                <th style="border-bottom:1px solid #555;padding:5px;">ID</th>
                <th style="border-bottom:1px solid #555;padding:5px;">Username</th>
                <th style="border-bottom:1px solid #555;padding:5px;">Token</th>
                <th style="border-bottom:1px solid #555;padding:5px;">Active</th>
                <th style="border-bottom:1px solid #555;padding:5px;">Expires At</th>
                <th style="border-bottom:1px solid #555;padding:5px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tokens as $t): ?>
                <tr>
                    <td style="padding:5px;"><?= $t['id'] ?></td>
                    <td style="padding:5px;"><?= htmlspecialchars($t['username']) ?></td>
                    <td style="padding:5px;"><?= htmlspecialchars($t['token']) ?></td>
                    <td style="padding:5px;"><?= $t['is_active'] ? 'Yes' : 'No' ?></td>
                    <td style="padding:5px;"><?= $t['expires_at'] ? date('Y-m-d H:i', strtotime($t['expires_at'])) : '-' ?></td>
                    <td style="padding:5px;">
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <input type="hidden" name="action" value="toggle">
                            <button type="submit" style="padding:2px 5px;">Toggle</button>
                        </form>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" style="padding:2px 5px;" onclick="return confirm('Delete token?')">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>