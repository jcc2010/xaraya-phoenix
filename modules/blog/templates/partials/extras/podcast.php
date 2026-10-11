<div class="post-podcast">
<?php if ($podcast['cover'] !== null): ?>
<img class="podcast-cover" src="<?= $x->e($podcast['cover']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
<?php endif ?>
<?php if ($podcast['show'] !== null): ?>
<p class="podcast-show"><?= $x->e($podcast['show']) ?></p>
<?php endif ?>
<?php if ($podcast['episode'] !== null): ?>
<p class="podcast-episode"><?= $x->e($podcast['episode']) ?></p>
<?php endif ?>
<?php if ($podcast['audioUrl'] !== null): ?>
<audio controls preload="none" src="<?= $x->e($podcast['audioUrl']) ?>"></audio>
<?php endif ?>
<?php if ($podcast['durationDisplay'] !== null): ?>
<p class="podcast-duration"><?= $x->e($podcast['durationDisplay']) ?></p>
<?php endif ?>
</div>
