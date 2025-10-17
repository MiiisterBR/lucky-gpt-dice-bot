<?php
session_start();
require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Repositories\UserRepository;
use App\Repositories\GoldenRepository;
use App\Services\GameService;
use App\Services\OpenAIService;
use App\Services\TelegramService;

$app = new App(dirname(__DIR__));
$pdo = $app->pdo();
$users = new UserRepository($pdo);
$goldens = new GoldenRepository($pdo);
$game = new GameService($pdo, $users, $goldens);

$adminCount = (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
$err = '';
$isLogged = !empty($_SESSION['admin_user']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $row = $stmt->fetch();
        $envHash = $app->env('ADMIN_PASSWORD_HASH');
        if (($row && password_verify($password, $row['password_hash'])) || ($username === 'admin' && $envHash && password_verify($password, $envHash))) {
            $_SESSION['admin_user'] = $username ?: 'admin';
            header('Location: index.php');
            exit;
        } else {
            $err = 'Invalid credentials';
        }
    } elseif ($action === 'logout') {
        session_destroy();
        header('Location: index.php');
        exit;
    } elseif ($action === 'register_first' && $adminCount === 0) {
        $email = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Invalid email address';
        } elseif (strlen($password) < 8) {
            $err = 'Password must be at least 8 characters';
        } elseif ($password !== $confirm) {
            $err = 'Passwords do not match';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('INSERT INTO admin_users (username, password_hash) VALUES (:u, :p)');
            $stmt->execute([':u' => $email, ':p' => $hash]);
            $_SESSION['admin_user'] = $email;
            header('Location: index.php');
            exit;
        }
    } elseif ($action === 'generate' && $isLogged) {
        $model = $app->setting('openai_model', $app->env('OPENAI_MODEL', 'gpt-5'));
        $openai = new OpenAIService($app->env('OPENAI_API_KEY', ''));
        $latest = $game->getOrCreateGolden($openai, $model);
        $tg = new TelegramService($app->env('TELEGRAM_BOT_TOKEN', ''));
        $text = $openai->generateAnnouncementText($model);
        $stmt = $pdo->query('SELECT id FROM users');
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($ids as $uid) {
            $tg->sendMessage((int)$uid, $text);
            usleep(50000);
        }
        if (!empty($latest['id'])) {
            $goldens->markAnnounced((int)$latest['id']);
        }
    } elseif ($action === 'update_settings' && $isLogged) {
        $daily = (string)max(0, (int)($_POST['daily_points'] ?? 100));
        $dice = (string)max(1, (int)($_POST['dice_cost'] ?? 5));
        $st = $pdo->prepare('REPLACE INTO settings (`key`, `value`) VALUES (:k, :v)');
        $st->execute([':k' => 'daily_points', ':v' => $daily]);
        $st->execute([':k' => 'dice_cost', ':v' => $dice]);
    }
}

if (!$isLogged && $adminCount === 0) {
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Admin Registration</title><script src="https://cdn.tailwindcss.com"></script></head><body class="min-h-screen bg-gray-100 flex items-center justify-center">';
    echo '<div class="w-full max-w-sm bg-white p-6 rounded shadow">';
    echo '<h1 class="text-xl font-bold mb-4">First Admin Registration</h1>';
    if ($err) echo '<div class="mb-3 p-2 bg-red-100 text-red-700 rounded">'.htmlspecialchars($err, ENT_QUOTES).'</div>';
    echo '<form method="post"><input type="hidden" name="action" value="register_first" />';
    echo '<label class="block mb-2 text-sm">Email</label><input type="email" class="w-full border rounded p-2 mb-4" name="email" required />';
    echo '<label class="block mb-2 text-sm">Password</label><input type="password" class="w-full border rounded p-2 mb-4" name="password" required />';
    echo '<label class="block mb-2 text-sm">Confirm Password</label><input type="password" class="w-full border rounded p-2 mb-4" name="password_confirm" required />';
    echo '<button class="w-full bg-green-600 text-white rounded p-2">Create Admin</button>';
    echo '<p class="text-xs text-gray-500 mt-3">This registration form is only shown when there are no admin users in the system.</p>';
    echo '</form></div></body></html>';
    exit;
}

if (!$isLogged) {
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Admin Login</title><script src="https://cdn.tailwindcss.com"></script></head><body class="min-h-screen bg-gray-100 flex items-center justify-center">';
    echo '<div class="w-full max-w-sm bg-white p-6 rounded shadow">';
    echo '<h1 class="text-xl font-bold mb-4">Admin Login</h1>';
    if ($err) echo '<div class="mb-3 p-2 bg-red-100 text-red-700 rounded">'.htmlspecialchars($err, ENT_QUOTES).'</div>';
    echo '<form method="post"><input type="hidden" name="action" value="login" />';
    echo '<label class="block mb-2 text-sm">Username</label><input class="w-full border rounded p-2 mb-4" name="username" required />';
    echo '<label class="block mb-2 text-sm">Password</label><input type="password" class="w-full border rounded p-2 mb-4" name="password" required />';
    echo '<button class="w-full bg-blue-600 text-white rounded p-2">Login</button>';
    echo '<p class="text-xs text-gray-500 mt-3">Seed an admin in DB or set <code>ADMIN_PASSWORD_HASH</code> in <code>.env</code> for username "admin".</p>';
    echo '</form></div></body></html>';
    exit;
}

$totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$latest = $goldens->latest();
$winners = $users->getTopWinners();
$losers = $users->getTopLosers();
$daily = (int)$app->setting('daily_points', $app->env('DAILY_POINTS', 100));
$dice = (int)$app->setting('dice_cost', $app->env('DICE_COST', 5));
$allUsers = $pdo->query('SELECT id, username, first_name, last_name, coins, wallet_address, created_at, updated_at FROM users ORDER BY id DESC')->fetchAll();

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
</head>
<body class="bg-gray-100 min-h-screen">
  <div class="max-w-6xl mx-auto p-4">
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">Dashboard</h1>
      <form method="post"><input type="hidden" name="action" value="logout"><button class="px-3 py-2 bg-gray-800 text-white rounded">Logout</button></form>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
      <div class="bg-white p-4 rounded shadow"><div class="text-gray-500 text-sm">Total Users</div><div class="text-2xl font-bold"><?php echo $totalUsers; ?></div></div>
      <div class="bg-white p-4 rounded shadow"><div class="text-gray-500 text-sm">Current Golden</div><div class="text-2xl font-bold"><?php echo $latest['number'] ?? '—'; ?></div></div>
      <div class="bg-white p-4 rounded shadow"><form method="post"><input type="hidden" name="action" value="generate"><button class="w-full bg-blue-600 text-white rounded py-2">Generate Golden Number</button></form></div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
      <div class="bg-white p-4 rounded shadow">
        <h2 class="font-semibold mb-2">Top 7 Winners</h2>
        <ul class="text-sm list-disc pl-5">
          <?php foreach ($winners as $i => $r): $name = $r['username'] ?: trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ('ID '.$r['id']); ?>
            <li><?php echo ($i+1).') '.htmlspecialchars($name).' — coins: '.(int)$r['coins']; ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="bg-white p-4 rounded shadow">
        <h2 class="font-semibold mb-2">Top 7 Unlucky</h2>
        <ul class="text-sm list-disc pl-5">
          <?php foreach ($losers as $i => $r): $name = $r['username'] ?: trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ('ID '.$r['id']); ?>
            <li><?php echo ($i+1).') '.htmlspecialchars($name).' — coins: '.(int)$r['coins']; ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <div class="bg-white p-4 rounded shadow">
      <h2 class="font-semibold mb-3">Settings</h2>
      <form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
        <input type="hidden" name="action" value="update_settings" />
        <label class="block"> <span class="text-sm text-gray-600">Daily Points</span>
          <input name="daily_points" value="<?php echo $daily; ?>" class="w-full border rounded p-2" />
        </label>
        <label class="block"> <span class="text-sm text-gray-600">Dice Cost</span>
          <input name="dice_cost" value="<?php echo $dice; ?>" class="w-full border rounded p-2" />
        </label>
        <button class="bg-green-600 text-white rounded py-2">Save</button>
      </form>
    </div>

    <div class="bg-white p-4 rounded shadow mt-6">
      <h2 class="font-semibold mb-3">Users</h2>
      <div class="overflow-auto">
        <table id="usersTable" class="display" style="width:100%">
          <thead>
            <tr>
              <th>ID</th>
              <th>Username</th>
              <th>Name</th>
              <th>Coins</th>
              <th>Wallet</th>
              <th>Created</th>
              <th>Updated</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allUsers as $u): $name = $u['username'] ?: trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')); ?>
              <tr>
                <td><?php echo (int)$u['id']; ?></td>
                <td><?php echo htmlspecialchars((string)($u['username'] ?? ''), ENT_QUOTES); ?></td>
                <td><?php echo htmlspecialchars($name, ENT_QUOTES); ?></td>
                <td><?php echo (int)$u['coins']; ?></td>
                <td><?php echo htmlspecialchars((string)($u['wallet_address'] ?? ''), ENT_QUOTES); ?></td>
                <td><?php echo htmlspecialchars((string)($u['created_at'] ?? ''), ENT_QUOTES); ?></td>
                <td><?php echo htmlspecialchars((string)($u['updated_at'] ?? ''), ENT_QUOTES); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
<script>
  $(function(){
    if ($('#usersTable').length) {
      $('#usersTable').DataTable({
        pageLength: 10,
        order: [[0,'desc']]
      });
    }
  });
</script>
</html>
