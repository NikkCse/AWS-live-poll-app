<?php
require __DIR__ . '/lib.php';

$polls = db()->query(
    'SELECT p.id, p.question, p.created_at,
            (SELECT COALESCE(SUM(o.votes), 0) FROM poll_options o WHERE o.poll_id = p.id) AS total
       FROM polls p
      WHERE p.is_open = 1
      ORDER BY p.created_at DESC
      LIMIT 20'
)->fetchAll();

page_start('');
?>
<section class="intro">
  <h1>Ask a question. Share the link. Watch the votes come in.</h1>
  <p class="lede">Live polls on a highly available AWS stack: two servers in two Availability Zones behind a load balancer.</p>
</section>

<section aria-labelledby="open-polls">
  <h2 id="open-polls" class="section-title">Open polls</h2>
  <?php if (!$polls): ?>
    <div class="empty">
      <p>No polls are open right now.</p>
      <?php if (is_admin()): ?>
        <a class="btn btn-primary" href="/admin.php#new">Create a poll</a>
      <?php else: ?>
        <p class="muted">If someone sent you a poll link, open it to vote.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <ul class="poll-list">
      <?php foreach ($polls as $p): $voted = voted_option($p['id']) !== null; ?>
        <li>
          <a class="poll-link" href="/poll.php?id=<?= h($p['id']) ?>">
            <span class="poll-link-q"><?= h($p['question']) ?></span>
            <span class="poll-link-meta">
              <?= (int) $p['total'] ?> <?= (int) $p['total'] === 1 ? 'vote' : 'votes' ?>
              <?php if ($voted): ?><span class="tag tag-voted">You voted</span><?php endif; ?>
            </span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php
page_end();
