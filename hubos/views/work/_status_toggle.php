<?php
use HubOS\Core\Csrf;
use HubOS\Modules\Work\TaskService;

/**
 * Reusable 1-click segmented task status control (with read-only pill fallback for viewers).
 *
 * @var int $taskId
 * @var string $rawStatus
 * @var bool $canEditStatus
 * @var string $returnPath
 * @var bool|null $largePill
 */
$sk = TaskService::statusKey($rawStatus);
$isLarge = !empty($largePill);
?>
<?php if ($canEditStatus): ?>
<form method="post" action="/tasks/<?= e($taskId) ?>/status" class="inline-status-form" data-ajax-status="1">
    <?= Csrf::field() ?>
    <input type="hidden" name="_return" value="<?= e($returnPath) ?>">
    <div class="status-seg<?= $isLarge ? ' status-seg-lg' : '' ?>" role="group" aria-label="סטטוס משימה #<?= e($taskId) ?>">
        <?php foreach (TaskService::STATUSES as $stKey => $stLabel): $activeSt = ($sk === $stKey); ?>
            <button type="submit"
                    name="status"
                    value="<?= e($stKey) ?>"
                    class="status-seg-btn is-<?= e($stKey) ?><?= $activeSt ? ' is-current' : '' ?>"
                    aria-pressed="<?= $activeSt ? 'true' : 'false' ?>"><?= e($stLabel) ?></button>
        <?php endforeach; ?>
    </div>
</form>
<?php elseif ($isLarge): ?>
<span class="tstatus-lg tstatus-<?= e($sk) ?>"><?= $sk === 'done' ? '✓ ' : '' ?><?= e(TaskService::STATUSES[$sk] ?? $rawStatus) ?></span>
<?php else: ?>
<span class="tstatus tstatus-<?= e($sk) ?>"><?= e(TaskService::STATUSES[$sk] ?? $rawStatus) ?></span>
<?php endif; ?>
