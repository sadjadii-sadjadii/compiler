<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \Sadjadii\Component\Copmiler\Administrator\View\Build\HtmlView $this */
if ($this->build === null) : ?>
    <div class="alert alert-warning"><?php echo Text::_('COM_COPMILER_BUILD_NOT_FOUND'); ?></div>
    <?php return; ?>
<?php endif;

$cls      = $this->report['classification'] ?? [];
$analysis = $this->report['analysis'] ?? [];
$files    = $this->report['generatedFiles'] ?? [];
$logs     = $this->report['transformLogs'] ?? [];
$warnings = $analysis['warnings'] ?? [];
$scores   = $cls['scores'] ?? ['component' => 0, 'plugin' => 0, 'module' => 0];
$reasons  = $cls['reasons'] ?? [];

$badgeClass = match ($this->build->detected_type) {
    'component' => 'bg-primary',
    'module'    => 'bg-warning text-dark',
    default     => 'bg-success',
};
?>
<div class="copmiler-wrap">
    <!-- Summary Banner -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="row align-items-center g-3">
                <div class="col-md-8">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <span class="badge fs-6 <?php echo $badgeClass; ?>">
                            <?php echo strtoupper(htmlspecialchars((string) $this->build->detected_type, ENT_QUOTES, 'UTF-8')); ?>
                            <?php if ($this->build->detected_type === 'plugin') : ?>
                                · <?php echo htmlspecialchars((string) $this->build->plugin_group, ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </span>
                        <code class="fs-6"><?php echo htmlspecialchars((string) $this->build->element_name, ENT_QUOTES, 'UTF-8'); ?></code>
                        <span class="badge bg-secondary"><?php echo htmlspecialchars((string) $this->build->compile_mode, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <h2 class="mb-1"><?php echo htmlspecialchars((string) $this->build->wp_name, ENT_QUOTES, 'UTF-8'); ?></h2>
                    <div class="text-muted small">
                        v<?php echo htmlspecialchars((string) $this->build->wp_version, ENT_QUOTES, 'UTF-8'); ?> ·
                        <?php echo htmlspecialchars((string) $this->build->zip_filename, ENT_QUOTES, 'UTF-8'); ?>
                        (<?php echo number_format(((int) $this->build->zip_size) / 1024, 1); ?> KB) ·
                        <?php echo (float) ($this->report['durationSeconds'] ?? 0); ?>s
                    </div>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="<?php echo $this->downloadUrl; ?>" class="btn btn-success btn-lg">
                        <span class="icon-download me-2" aria-hidden="true"></span>
                        <?php echo Text::_('COM_COPMILER_BTN_DOWNLOAD_ZIP'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Classification & Compatibility -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-transparent">
                    <h4 class="mb-0"><?php echo Text::_('COM_COPMILER_REPORT_CLASSIFICATION'); ?></h4>
                </div>
                <div class="card-body">
                    <div class="row text-center mb-3">
                        <div class="col-4">
                            <div class="small text-muted">Component</div>
                            <div class="fs-4 fw-bold text-primary"><?php echo (int) ($scores['component'] ?? 0); ?></div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Plugin</div>
                            <div class="fs-4 fw-bold text-success"><?php echo (int) ($scores['plugin'] ?? 0); ?></div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Module</div>
                            <div class="fs-4 fw-bold text-warning"><?php echo (int) ($scores['module'] ?? 0); ?></div>
                        </div>
                    </div>
                    <p class="small text-muted mb-2">
                        <?php echo Text::sprintf('COM_COPMILER_REPORT_CONFIDENCE', (int) $this->build->confidence); ?>
                    </p>
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($reasons as $reason) : ?>
                            <li class="list-group-item px-0"><?php echo htmlspecialchars((string) $reason, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-transparent">
                    <h4 class="mb-0"><?php echo Text::_('COM_COPMILER_REPORT_ANALYSIS'); ?></h4>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small mb-1">
                            <span><?php echo Text::_('COM_COPMILER_COL_SCORE'); ?></span>
                            <strong><?php echo (int) $this->build->compatibility_score; ?> / 100</strong>
                        </div>
                        <div class="progress" style="height: 12px;">
                            <div class="progress-bar <?php echo (int) $this->build->compatibility_score >= 75 ? 'bg-success' : 'bg-warning'; ?>"
                                 style="width: <?php echo (int) $this->build->compatibility_score; ?>%"></div>
                        </div>
                    </div>
                    <div class="row g-2 small">
                        <div class="col-6">PHP Files: <strong><?php echo count($analysis['phpFiles'] ?? []); ?></strong></div>
                        <div class="col-6">Assets: <strong><?php echo count($analysis['assetFiles'] ?? []); ?></strong></div>
                        <div class="col-6">Actions: <strong><?php echo count($analysis['actions'] ?? []); ?></strong></div>
                        <div class="col-6">Filters: <strong><?php echo count($analysis['filters'] ?? []); ?></strong></div>
                        <div class="col-6">Shortcodes: <strong><?php echo count($analysis['shortcodes'] ?? []); ?></strong></div>
                        <div class="col-6">Widgets: <strong><?php echo count($analysis['widgets'] ?? []); ?></strong></div>
                        <div class="col-6">Admin Menus: <strong><?php echo count($analysis['adminMenus'] ?? []); ?></strong></div>
                        <div class="col-6">Custom Post Types: <strong><?php echo count($analysis['postTypes'] ?? []); ?></strong></div>
                        <div class="col-6">REST Routes: <strong><?php echo count($analysis['restRoutes'] ?? []); ?></strong></div>
                        <div class="col-6">DB Tables: <strong><?php echo count($analysis['dbTables'] ?? []); ?></strong></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Warnings -->
    <?php if ($warnings !== []) : ?>
        <div class="card shadow-sm mb-4 border-warning">
            <div class="card-header bg-warning bg-opacity-10">
                <h4 class="mb-0"><?php echo Text::_('COM_COPMILER_REPORT_WARNINGS'); ?> (<?php echo count($warnings); ?>)</h4>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php foreach ($warnings as $w) : ?>
                        <li class="list-group-item">
                            <span class="badge <?php echo ($w['severity'] ?? '') === 'error' ? 'bg-danger' : 'bg-warning text-dark'; ?> me-2">
                                <?php echo strtoupper(htmlspecialchars((string) ($w['severity'] ?? 'warning'), ENT_QUOTES, 'UTF-8')); ?>
                            </span>
                            <code><?php echo htmlspecialchars((string) ($w['file'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code>:
                            <?php echo htmlspecialchars((string) ($w['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <!-- Transformation Logs & Generated Files -->
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-transparent">
                    <h4 class="mb-0"><?php echo Text::_('COM_COPMILER_REPORT_LOGS'); ?> (<?php echo count($logs); ?>)</h4>
                </div>
                <div class="card-body copmiler-scroll-box">
                    <ul class="list-unstyled small mb-0">
                        <?php foreach ($logs as $log) : ?>
                            <li class="mb-1">• <?php echo htmlspecialchars((string) $log, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-transparent">
                    <h4 class="mb-0"><?php echo Text::_('COM_COPMILER_REPORT_FILES'); ?> (<?php echo count($files); ?>)</h4>
                </div>
                <div class="card-body copmiler-scroll-box">
                    <ul class="list-unstyled small font-monospace mb-0" dir="ltr">
                        <?php foreach ($files as $f) : ?>
                            <li><?php echo htmlspecialchars((string) $f, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
