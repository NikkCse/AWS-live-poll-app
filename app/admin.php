<?php
require __DIR__ . '/lib.php';

$error = '';
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'login') {
        $key = (string) ($_POST['key'] ?? '');
        if (hash_equals(config()['admin_key'], $key)) {
            setcookie('poll_admin', admin_token(), cookie_opts(time() + 86400 * 7));
            redirect('/admin.php');
        }
        $error = "That admin key isn't right. Check it and try again.";
    } elseif ($action === 'logout') {
        setcookie('poll_admin', '', cookie_opts(time() - 3600));
        redirect('/');
    } elseif (is_admin()) {
        $pollId = (string) ($_POST['poll'] ?? '');

        if ($action === 'create') {
            $question = trim((string) ($_POST['question'] ?? ''));
            $options = array_values(array_filter(
                array_map(fn($o) => trim((string) $o), (array) ($_POST['options'] ?? [])),
                fn($o) => $o !== ''
            ));
            $options = array_slice(array_unique($options), 0, 6);

            if ($question === '' || mb_strlen($question) > 200) {
                $error = 'Write a question of up to 200 characters.';
            } elseif (count($options) < 2) {
                $error = 'Add at least two different options.';
            } elseif (max(array_map('mb_strlen', $options)) > 100) {
                $error = 'Keep each option to 100 characters or fewer.';
            } else {
                $pdo = db();
                $newId = new_poll_id();
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO polls (id, question) VALUES (?, ?)')->execute([$newId, $question]);
                $ins = $pdo->prepare('INSERT INTO poll_options (poll_id, label, position) VALUES (?, ?, ?)');
                foreach ($options as $i => $label) {
                    $ins->execute([$newId, $label, $i]);
                }
                $pdo->commit();
                redirect('/poll.php?id=' . $newId . '&created=1');
            }
        } elseif ($action === 'toggle') {
            db()->prepare('UPDATE polls SET is_open = 1 - is_open WHERE id = ?')->execute([$pollId]);
            redirect('/admin.php');
        } elseif ($action === 'delete') {
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM poll_options WHERE poll_id = ?')->execute([$pollId]);
            $pdo->prepare('DELETE FROM polls WHERE id = ?')->execute([$pollId]);
            $pdo->commit();
            redirect('/admin.php');
        }
    }
}

// ---------- Sign-in screen ----------
if (!is_admin()) {
    page_start('Admin sign in');
    ?>
    <section class="narrow">
      <h1>Admin sign in</h1>
      <p class="muted">Enter the admin key to create and manage polls.</p>
      <?php if ($error): ?><div class="notice notice-error" role="alert"><?= h($error) ?></div><?php endif; ?>
      <form method="post" class="form">
        <input type="hidden" name="action" value="login">
        <label class="field">
          <span class="field-label">Admin key</span>
          <input type="password" name="key" required autocomplete="current-password" autofocus>
        </label>
        <button class="btn btn-primary btn-block" type="submit">Sign in</button>
      </form>
    </section>
    <?php
    page_end();
    exit;
}

// ---------- Dashboard ----------
$polls = db()->query(
    'SELECT p.id, p.question, p.is_open, p.created_at,
            (SELECT COALESCE(SUM(o.votes), 0) FROM poll_options o WHERE o.poll_id = p.id) AS total
       FROM polls p
      ORDER BY p.created_at DESC'
)->fetchAll();

$old = $_POST['options'] ?? [];
page_start('Dashboard');
?>
<div class="dash-head">
  <h1>Dashboard</h1>
  <form method="post"><input type="hidden" name="action" value="logout"><button class="btn btn-quiet" type="submit">Sign out</button></form>
</div>

<section id="new" class="card" aria-labelledby="new-title">
  <h2 id="new-title" class="section-title">Create a poll</h2>
  <?php if ($error): ?><div class="notice notice-error" role="alert"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="form" data-poll-form>
    <input type="hidden" name="action" value="create">
    <label class="field">
      <span class="field-label">Question</span>
      <input type="text" name="question" maxlength="200" required placeholder="Which AWS service should we learn next?" value="<?= h($_POST['question'] ?? '') ?>">
    </label>
    <div class="field">
      <span class="field-label">Options <span class="muted">(2 to 6)</span></span>
      <div class="option-inputs" data-options>
        <?php for ($i = 0; $i < 4; $i++): ?>
          <input type="text" name="options[]" maxlength="100" <?= $i < 2 ? 'required' : '' ?> placeholder="Option <?= $i + 1 ?><?= $i >= 2 ? ' (optional)' : '' ?>" value="<?= h($old[$i] ?? '') ?>">
        <?php endfor; ?>
      </div>
      <button class="btn btn-quiet btn-small" type="button" data-add-option>Add another option</button>
    </div>
    <button class="btn btn-primary" type="submit">Create poll</button>
  </form>
</section>

<section aria-labelledby="all-title">
  <h2 id="all-title" class="section-title">Your polls</h2>
  <?php if (!$polls): ?>
    <div class="empty"><p>You haven't created a poll yet. Use the form above to make your first one.</p></div>
  <?php else: ?>
    <ul class="admin-list">
      <?php foreach ($polls as $p): $link = poll_url($p['id']); ?>
        <li class="admin-item">
          <div class="admin-item-main">
            <a class="admin-item-q" href="/poll.php?id=<?= h($p['id']) ?>"><?= h($p['question']) ?></a>
            <p class="admin-item-meta">
              <span class="poll-status <?= $p['is_open'] ? 'is-open' : 'is-closed' ?>"><?= $p['is_open'] ? 'Open' : 'Closed' ?></span>
              <?= (int) $p['total'] ?> <?= (int) $p['total'] === 1 ? 'vote' : 'votes' ?>
            </p>
          </div>
          <div class="admin-item-actions">
            <button class="btn btn-small" type="button" data-copy="<?= h($link) ?>">Copy link</button>
            <form method="post">
              <input type="hidden" name="action" value="toggle"><input type="hidden" name="poll" value="<?= h($p['id']) ?>">
              <button class="btn btn-small btn-quiet" type="submit"><?= $p['is_open'] ? 'Close voting' : 'Reopen voting' ?></button>
            </form>
            <form method="post" data-confirm="Delete this poll and all its votes? This can't be undone.">
              <input type="hidden" name="action" value="delete"><input type="hidden" name="poll" value="<?= h($p['id']) ?>">
              <button class="btn btn-small btn-danger" type="submit">Delete</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php
page_end();
