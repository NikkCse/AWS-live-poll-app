<?php
require __DIR__ . '/lib.php';

$id = (string) ($_GET['id'] ?? '');
$poll = $id !== '' ? find_poll($id) : null;

if (!$poll) {
    http_response_code(404);
    page_start('Poll not found');
    ?>
    <div class="empty">
      <h1>This poll doesn't exist</h1>
      <p class="muted">Check the link, or ask whoever shared it for a new one.</p>
      <a class="btn" href="/">See open polls</a>
    </div>
    <?php
    page_end();
    exit;
}

$error = '';
$myVote = voted_option($id);

// ---------- Cast a vote ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $optionId = (int) ($_POST['option'] ?? 0);
    $validIds = array_map(fn($o) => (int) $o['id'], $poll['options']);

    if (!$poll['is_open']) {
        $error = 'Voting is closed for this poll.';
    } elseif ($myVote !== null) {
        $error = 'You already voted in this poll.';
    } elseif (!in_array($optionId, $validIds, true)) {
        $error = 'Choose an option, then submit your vote.';
    } else {
        // Atomic +1 in the database, so two votes at the same moment
        // on two different servers can't overwrite each other.
        $st = db()->prepare('UPDATE poll_options SET votes = votes + 1 WHERE id = ? AND poll_id = ?');
        $st->execute([$optionId, $id]);
        setcookie('voted_' . $id, (string) $optionId, cookie_opts(time() + 31536000));
        redirect('/poll.php?id=' . rawurlencode($id) . '#results');
    }
}

$admin = is_admin();
$showResults = $myVote !== null || !$poll['is_open'] || $admin;
$link = poll_url($id);

page_start($poll['question']);
?>
<?php if (isset($_GET['created']) && $admin): ?>
  <div class="notice notice-success" role="status">Poll created. Share the link below so people can vote.</div>
<?php endif; ?>

<article class="poll" data-poll="<?= h($id) ?>" data-live="<?= $showResults && $poll['is_open'] ? '1' : '0' ?>">
  <header class="poll-head">
    <p class="poll-status <?= $poll['is_open'] ? 'is-open' : 'is-closed' ?>">
      <?= $poll['is_open'] ? 'Voting open' : 'Voting closed' ?>
    </p>
    <h1 class="poll-question"><?= h($poll['question']) ?></h1>
  </header>

  <?php if ($error): ?>
    <div class="notice notice-error" role="alert"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if (!$showResults): ?>
    <form method="post" class="ballot">
      <fieldset>
        <legend class="sr-only">Choose one option</legend>
        <?php foreach ($poll['options'] as $o): ?>
          <label class="choice">
            <input type="radio" name="option" value="<?= (int) $o['id'] ?>" required>
            <span class="choice-mark" aria-hidden="true"></span>
            <span class="choice-label"><?= h($o['label']) ?></span>
          </label>
        <?php endforeach; ?>
      </fieldset>
      <button class="btn btn-primary btn-block" type="submit">Submit vote</button>
      <p class="muted small">One vote per person. Results appear after you vote.</p>
    </form>
  <?php else: ?>
    <section id="results" class="results" aria-live="polite">
      <ol class="bars">
        <?php foreach ($poll['options'] as $o):
          $pct = $poll['total'] > 0 ? round($o['votes'] * 100 / $poll['total']) : 0;
          $mine = $myVote === (int) $o['id']; ?>
          <li class="bar<?= $mine ? ' is-mine' : '' ?>" data-option="<?= (int) $o['id'] ?>" style="--pct: <?= $pct ?>%">
            <div class="bar-fill" aria-hidden="true"></div>
            <span class="bar-label"><?= h($o['label']) ?><?php if ($mine): ?> <span class="mine-note">Your vote</span><?php endif; ?></span>
            <span class="bar-value"><span data-pct><?= $pct ?>%</span> <span class="bar-count" data-count><?= (int) $o['votes'] ?></span></span>
          </li>
        <?php endforeach; ?>
      </ol>
      <p class="results-meta">
        <span><span data-total><?= (int) $poll['total'] ?></span> <span data-total-word><?= $poll['total'] === 1 ? 'vote' : 'votes' ?></span></span>
        <?php if ($poll['is_open']): ?>
          <span class="live-dot" aria-hidden="true"></span><span class="live-text">Updating live</span>
        <?php endif; ?>
      </p>
      <?php if ($poll['is_open']): ?>
        <p class="answered" data-answered hidden>Latest update came from <strong data-live-instance></strong> in <strong data-live-az></strong></p>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <section class="share" aria-labelledby="share-title">
    <h2 id="share-title" class="share-title">Share this poll</h2>
    <div class="share-row">
      <input class="share-input" type="text" value="<?= h($link) ?>" readonly aria-label="Poll link">
      <button class="btn" type="button" data-copy="<?= h($link) ?>">Copy link</button>
    </div>
    <?php if ($admin): ?>
      <details class="qr">
        <summary>Show QR code</summary>
        <div class="qr-box" data-qr="<?= h($link) ?>"></div>
        <p class="muted small">Put this on a screen so people can scan it with their phones.</p>
      </details>
    <?php endif; ?>
  </section>
</article>

<?php if ($admin): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
<?php endif; ?>
<?php
page_end();
