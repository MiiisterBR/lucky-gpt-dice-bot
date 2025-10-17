<?php
session_start();
require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Repositories\UserRepository;
use App\Repositories\GoldenRepository;
use App\Repositories\TransactionRepository;
use App\Services\GameService;
use App\Services\OpenAIService;
use App\Services\TelegramService;

$app = new App(dirname(__DIR__));
$pdo = $app->pdo();
$users = new UserRepository($pdo);
$goldens = new GoldenRepository($pdo);
$transactions = new TransactionRepository($pdo);
$game = new GameService($pdo, $users, $goldens, $transactions);

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

$depositAddr = (string)$app->setting('deposit_wallet_address', '');
$sleepMs = (int)$app->setting('sleep_ms_between_rolls', 3000);
$quietStart = (string)$app->setting('quiet_hours_start', '23:00');
$quietEnd = (string)$app->setting('quiet_hours_end', '00:00');
$score3 = (int)$app->setting('score_match_3', 10);
$score5 = (int)$app->setting('score_match_5', 15);
$scoreUnord = (int)$app->setting('score_all_unordered', 30);
$scoreExact = (int)$app->setting('score_exact_ordered', 10000);
$minBal = (int)$app->setting('withdraw_min_balance', 1001);
$startCoins = (int)$app->setting('start_coins', 1000);
$modelName = (string)$app->setting('openai_model', 'gpt-5');
$timezone = (string)$app->setting('timezone', 'UTC');

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
      <div class="bg-white p-4 rounded shadow"><button id="generateBtn" class="w-full bg-blue-600 text-white rounded py-2 hover:bg-blue-700 transition">Generate Golden Number</button></div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
      <div class="bg-white p-4 rounded shadow">
        <h2 class="font-semibold mb-3">Quiet Hours Control</h2>
        <div class="mb-3">
          <div class="text-sm text-gray-600 mb-1">Status:</div>
          <div class="font-semibold <?php echo ((int)$app->setting('quiet_hours_active', 0) === 1) ? 'text-orange-600' : 'text-green-600'; ?>">
            <?php echo ((int)$app->setting('quiet_hours_active', 0) === 1) ? '🌙 Bot Inactive (Quiet Hours)' : '✅ Bot Active'; ?>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <button id="activateQuietBtn" class="bg-orange-600 text-white rounded py-2 hover:bg-orange-700 transition text-sm">
            🌙 Activate Quiet Hours
          </button>
          <button id="deactivateQuietBtn" class="bg-green-600 text-white rounded py-2 hover:bg-green-700 transition text-sm">
            ✅ Deactivate Quiet Hours
          </button>
        </div>
      </div>
      <div class="bg-white p-4 rounded shadow">
        <h2 class="font-semibold mb-3">Quick Info</h2>
        <div class="text-sm space-y-1">
          <div><span class="font-medium">Quiet Hours:</span> <?php echo htmlspecialchars($app->setting('quiet_hours_start', '23:00')); ?> - <?php echo htmlspecialchars($app->setting('quiet_hours_end', '00:00')); ?></div>
          <div><span class="font-medium">Timezone:</span> <?php echo htmlspecialchars($timezone); ?></div>
          <div><span class="font-medium">Sleep Between Rolls:</span> <?php echo $sleepMs; ?>ms</div>
        </div>
      </div>
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
      <div class="flex justify-between items-center mb-3">
        <h2 class="font-semibold">Settings</h2>
        <button id="editSettingsBtn" class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 transition">Edit Settings</button>
      </div>
      <div id="settingsDisplay" class="space-y-2 text-sm">
        <div><span class="font-medium">Timezone:</span> <?php echo htmlspecialchars($timezone); ?></div>
        <div><span class="font-medium">Deposit Wallet:</span> <?php echo htmlspecialchars($depositAddr ?: 'Not set'); ?></div>
        <div><span class="font-medium">Sleep Between Rolls:</span> <?php echo $sleepMs; ?>ms</div>
        <div><span class="font-medium">Quiet Hours:</span> <?php echo htmlspecialchars($quietStart); ?> - <?php echo htmlspecialchars($quietEnd); ?></div>
        <div><span class="font-medium">Scoring:</span> 3match=<?php echo $score3; ?>, 5match=<?php echo $score5; ?>, Unord=<?php echo $scoreUnord; ?>, Exact=<?php echo $scoreExact; ?></div>
        <div><span class="font-medium">Start Coins:</span> <?php echo $startCoins; ?>, Min Withdraw: <?php echo $minBal; ?></div>
        <div><span class="font-medium">OpenAI Model:</span> <?php echo htmlspecialchars($modelName); ?></div>
      </div>
    </div>

    <!-- Settings Modal -->
    <div id="settingsModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-6">
          <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold">Edit Settings</h2>
            <button id="closeModalBtn" class="text-gray-500 hover:text-gray-700">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
            </button>
          </div>
          <form id="settingsForm" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <label class="block"> <span class="text-sm text-gray-600">Timezone</span>
            <select name="timezone" class="w-full border rounded p-2">
              <option value="UTC" <?php echo $timezone === 'UTC' ? 'selected' : ''; ?>>UTC (GMT+0)</option>
              <option value="Asia/Tehran" <?php echo $timezone === 'Asia/Tehran' ? 'selected' : ''; ?>>Asia/Tehran (GMT+3:30)</option>
              <option value="Europe/London" <?php echo $timezone === 'Europe/London' ? 'selected' : ''; ?>>Europe/London (GMT+0/+1)</option>
              <option value="Europe/Paris" <?php echo $timezone === 'Europe/Paris' ? 'selected' : ''; ?>>Europe/Paris (GMT+1/+2)</option>
              <option value="America/New_York" <?php echo $timezone === 'America/New_York' ? 'selected' : ''; ?>>America/New_York (GMT-5/-4)</option>
              <option value="America/Los_Angeles" <?php echo $timezone === 'America/Los_Angeles' ? 'selected' : ''; ?>>America/Los_Angeles (GMT-8/-7)</option>
              <option value="Asia/Dubai" <?php echo $timezone === 'Asia/Dubai' ? 'selected' : ''; ?>>Asia/Dubai (GMT+4)</option>
              <option value="Asia/Shanghai" <?php echo $timezone === 'Asia/Shanghai' ? 'selected' : ''; ?>>Asia/Shanghai (GMT+8)</option>
              <option value="Asia/Tokyo" <?php echo $timezone === 'Asia/Tokyo' ? 'selected' : ''; ?>>Asia/Tokyo (GMT+9)</option>
              <option value="Australia/Sydney" <?php echo $timezone === 'Australia/Sydney' ? 'selected' : ''; ?>>Australia/Sydney (GMT+10/+11)</option>
            </select>
          </label>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <label class="block"> <span class="text-sm text-gray-600">Deposit Wallet Address</span>
            <input name="deposit_wallet_address" value="<?php echo htmlspecialchars($depositAddr); ?>" class="w-full border rounded p-2" />
          </label>
          <label class="block"> <span class="text-sm text-gray-600">Sleep Between Rolls (ms)</span>
            <input name="sleep_ms_between_rolls" type="number" value="<?php echo $sleepMs; ?>" class="w-full border rounded p-2" />
          </label>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <label class="block"> <span class="text-sm text-gray-600">Quiet Hours Start (HH:MM)</span>
            <input name="quiet_hours_start" value="<?php echo htmlspecialchars($quietStart); ?>" class="w-full border rounded p-2" />
          </label>
          <label class="block"> <span class="text-sm text-gray-600">Quiet Hours End (HH:MM)</span>
            <input name="quiet_hours_end" value="<?php echo htmlspecialchars($quietEnd); ?>" class="w-full border rounded p-2" />
          </label>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <label class="block"> <span class="text-sm text-gray-600">Score: 3 Match</span>
            <input name="score_match_3" type="number" value="<?php echo $score3; ?>" class="w-full border rounded p-2" />
          </label>
          <label class="block"> <span class="text-sm text-gray-600">Score: 5 Match</span>
            <input name="score_match_5" type="number" value="<?php echo $score5; ?>" class="w-full border rounded p-2" />
          </label>
          <label class="block"> <span class="text-sm text-gray-600">Score: All Unordered</span>
            <input name="score_all_unordered" type="number" value="<?php echo $scoreUnord; ?>" class="w-full border rounded p-2" />
          </label>
          <label class="block"> <span class="text-sm text-gray-600">Score: Exact Ordered</span>
            <input name="score_exact_ordered" type="number" value="<?php echo $scoreExact; ?>" class="w-full border rounded p-2" />
          </label>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <label class="block"> <span class="text-sm text-gray-600">Start Coins</span>
            <input name="start_coins" type="number" value="<?php echo $startCoins; ?>" class="w-full border rounded p-2" />
          </label>
          <label class="block"> <span class="text-sm text-gray-600">Withdraw Min Balance</span>
            <input name="withdraw_min_balance" type="number" value="<?php echo $minBal; ?>" class="w-full border rounded p-2" />
          </label>
          <label class="block"> <span class="text-sm text-gray-600">OpenAI Model</span>
            <input name="openai_model" value="<?php echo htmlspecialchars($modelName); ?>" class="w-full border rounded p-2" />
          </label>
        </div>
            <div class="flex gap-2">
              <button type="button" id="cancelBtn" class="flex-1 bg-gray-300 text-gray-700 rounded py-2 hover:bg-gray-400 transition">Cancel</button>
              <button type="submit" class="flex-1 bg-green-600 text-white rounded py-2 hover:bg-green-700 transition">Save Settings</button>
            </div>
          </form>
        </div>
      </div>
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
<!-- Toast Container -->
<div id="toastContainer" class="fixed top-4 right-4 z-50 space-y-2"></div>

<!-- Confirm Modal -->
<div id="confirmModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
    <h3 class="text-lg font-bold mb-2">Confirm Action</h3>
    <p id="confirmMessage" class="text-gray-600 mb-4"></p>
    <div class="flex gap-2">
      <button id="confirmCancel" class="flex-1 bg-gray-300 text-gray-700 rounded py-2 hover:bg-gray-400">Cancel</button>
      <button id="confirmOk" class="flex-1 bg-blue-600 text-white rounded py-2 hover:bg-blue-700">Confirm</button>
    </div>
  </div>
</div>

<script>
  $(function(){
    if ($('#usersTable').length) {
      $('#usersTable').DataTable({
        pageLength: 10,
        order: [[0,'desc']]
      });
    }
    
    // Toast function
    function showToast(message, type = 'success') {
      const toast = $('<div class="px-4 py-3 rounded shadow-lg text-white transition-all transform " style="min-width:250px">');
      toast.addClass(type === 'success' ? 'bg-green-600' : 'bg-red-600');
      toast.text(message);
      $('#toastContainer').append(toast);
      toast.addClass('translate-x-0').removeClass('translate-x-full');
      setTimeout(() => {
        toast.addClass('translate-x-full');
        setTimeout(() => toast.remove(), 300);
      }, 3000);
    }
    
    // Generate Golden Number
    $('#generateBtn').click(function() {
      const btn = $(this);
      btn.prop('disabled', true).text('Generating...');
      
      $.post('ajax.php', { action: 'generate_golden' }, function(res) {
        if (res.needs_confirm) {
          $('#confirmMessage').text(res.message + ' Do you want to create a new one?');
          $('#confirmModal').removeClass('hidden');
          
          $('#confirmOk').off('click').click(function() {
            $('#confirmModal').addClass('hidden');
            $.post('ajax.php', { action: 'generate_golden', force: '1' }, function(res2) {
              if (res2.success) {
                showToast(res2.message + ' New number: ' + res2.number);
                location.reload();
              } else {
                showToast(res2.message, 'error');
              }
              btn.prop('disabled', false).text('Generate Golden Number');
            });
          });
          
          $('#confirmCancel').off('click').click(function() {
            $('#confirmModal').addClass('hidden');
            btn.prop('disabled', false).text('Generate Golden Number');
          });
        } else if (res.success) {
          showToast(res.message + ' Number: ' + res.number);
          location.reload();
        } else {
          showToast(res.message, 'error');
          btn.prop('disabled', false).text('Generate Golden Number');
        }
      }).fail(function() {
        showToast('Network error', 'error');
        btn.prop('disabled', false).text('Generate Golden Number');
      });
    });
    
    // Activate Quiet Hours
    $('#activateQuietBtn').click(function() {
      const btn = $(this);
      if (!confirm('⚠️ This will notify all users that the bot is going inactive. Continue?')) {
        return;
      }
      
      btn.prop('disabled', true).text('Activating...');
      
      $.post('ajax.php', { action: 'activate_quiet_hours' }, function(res) {
        if (res.success) {
          showToast(res.message);
          setTimeout(() => location.reload(), 1500);
        } else {
          showToast(res.message, 'error');
          btn.prop('disabled', false).text('🌙 Activate Quiet Hours');
        }
      }).fail(function() {
        showToast('Network error', 'error');
        btn.prop('disabled', false).text('🌙 Activate Quiet Hours');
      });
    });
    
    // Deactivate Quiet Hours
    $('#deactivateQuietBtn').click(function() {
      const btn = $(this);
      if (!confirm('✅ This will notify all users that the bot is back online. Continue?')) {
        return;
      }
      
      btn.prop('disabled', true).text('Deactivating...');
      
      $.post('ajax.php', { action: 'deactivate_quiet_hours' }, function(res) {
        if (res.success) {
          showToast(res.message);
          setTimeout(() => location.reload(), 1500);
        } else {
          showToast(res.message, 'error');
          btn.prop('disabled', false).text('✅ Deactivate Quiet Hours');
        }
      }).fail(function() {
        showToast('Network error', 'error');
        btn.prop('disabled', false).text('✅ Deactivate Quiet Hours');
      });
    });
    
    // Settings Modal
    $('#editSettingsBtn').click(function() {
      $('#settingsModal').removeClass('hidden');
    });
    
    $('#closeModalBtn, #cancelBtn').click(function() {
      $('#settingsModal').addClass('hidden');
    });
    
    // Save Settings
    $('#settingsForm').submit(function(e) {
      e.preventDefault();
      const data = $(this).serialize() + '&action=update_settings';
      
      $.post('ajax.php', data, function(res) {
        if (res.success) {
          showToast(res.message);
          setTimeout(() => location.reload(), 1000);
        } else {
          showToast(res.message, 'error');
        }
      }).fail(function() {
        showToast('Network error', 'error');
      });
    });
  });
</script>
</html>
