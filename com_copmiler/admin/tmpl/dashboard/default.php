<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/** @var \Sadjadii\Component\Copmiler\Administrator\View\Dashboard\HtmlView $this */
$token = Session::getFormToken();
?>
<div class="copmiler-wrap">
    <!-- Top Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card copmiler-stat-card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small"><?php echo Text::_('COM_COPMILER_STAT_TOTAL'); ?></div>
                        <div class="fs-2 fw-bold"><?php echo (int) $this->stats['total']; ?></div>
                    </div>
                    <span class="badge bg-secondary rounded-pill p-3"><span class="icon-cogs" aria-hidden="true"></span></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card copmiler-stat-card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small"><?php echo Text::_('COM_COPMILER_STAT_COMPONENTS'); ?></div>
                        <div class="fs-2 fw-bold text-primary"><?php echo (int) $this->stats['components']; ?></div>
                    </div>
                    <span class="badge bg-primary rounded-pill p-3">COM</span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card copmiler-stat-card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small"><?php echo Text::_('COM_COPMILER_STAT_PLUGINS'); ?></div>
                        <div class="fs-2 fw-bold text-success"><?php echo (int) $this->stats['plugins']; ?></div>
                    </div>
                    <span class="badge bg-success rounded-pill p-3">PLG</span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card copmiler-stat-card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small"><?php echo Text::_('COM_COPMILER_STAT_MODULES'); ?></div>
                        <div class="fs-2 fw-bold text-warning"><?php echo (int) $this->stats['modules']; ?></div>
                    </div>
                    <span class="badge bg-warning text-dark rounded-pill p-3">MOD</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload & Compile Form -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
            <h3 class="card-title mb-0">
                <span class="icon-upload me-2" aria-hidden="true"></span>
                <?php echo Text::_('COM_COPMILER_UPLOAD_HEADING'); ?>
            </h3>
            <span class="badge bg-info text-dark">Joomla 6 · PHP 8.3+</span>
        </div>
        <div class="card-body">
            <form
                action="<?php echo Route::_('index.php?option=com_copmiler&task=compile.compile'); ?>"
                method="post"
                enctype="multipart/form-data"
                id="copmiler-upload-form"
            >
                <div class="row g-4">
                    <div class="col-lg-6">
                        <label for="wp_plugin_zip" class="form-label fw-semibold">
                            <?php echo Text::_('COM_COPMILER_FIELD_ZIP_LABEL'); ?>
                        </label>
                        <div class="copmiler-dropzone" id="copmiler-dropzone">
                            <input
                                type="file"
                                name="wp_plugin_zip"
                                id="wp_plugin_zip"
                                accept=".zip,application/zip"
                                class="form-control copmiler-file-input"
                                required
                            >
                            <div class="copmiler-dropzone-content text-center py-4">
                                <div class="fs-1 mb-2">📦</div>
                                <div class="fw-semibold" id="copmiler-file-label">
                                    <?php echo Text::_('COM_COPMILER_DROPZONE_TEXT'); ?>
                                </div>
                                <div class="text-muted small mt-1">
                                    <?php echo Text::sprintf('COM_COPMILER_DROPZONE_HINT', $this->maxUploadMb); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="mb-3">
                            <label for="compile_mode" class="form-label fw-semibold">
                                <?php echo Text::_('COM_COPMILER_FIELD_MODE_LABEL'); ?>
                            </label>
                            <select name="compile_mode" id="compile_mode" class="form-select">
                                <option value="hybrid" <?php echo $this->defaultMode === 'hybrid' ? 'selected' : ''; ?>>
                                    <?php echo Text::_('COM_COPMILER_MODE_HYBRID'); ?>
                                </option>
                                <option value="native" <?php echo $this->defaultMode === 'native' ? 'selected' : ''; ?>>
                                    <?php echo Text::_('COM_COPMILER_MODE_NATIVE'); ?>
                                </option>
                            </select>
                            <div class="form-text" id="copmiler-mode-help">
                                <?php echo Text::_('COM_COPMILER_MODE_HYBRID_DESC'); ?>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="target_override" class="form-label fw-semibold">
                                    <?php echo Text::_('COM_COPMILER_FIELD_TARGET_LABEL'); ?>
                                </label>
                                <select name="target_override" id="target_override" class="form-select">
                                    <option value="auto" selected><?php echo Text::_('COM_COPMILER_TARGET_AUTO'); ?></option>
                                    <option value="component"><?php echo Text::_('COM_COPMILER_TARGET_COMPONENT'); ?></option>
                                    <option value="plugin"><?php echo Text::_('COM_COPMILER_TARGET_PLUGIN'); ?></option>
                                    <option value="module"><?php echo Text::_('COM_COPMILER_TARGET_MODULE'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="plugin_group_override" class="form-label fw-semibold">
                                    <?php echo Text::_('COM_COPMILER_FIELD_PLUGIN_GROUP_LABEL'); ?>
                                </label>
                                <select name="plugin_group_override" id="plugin_group_override" class="form-select">
                                    <option value="auto" selected><?php echo Text::_('COM_COPMILER_GROUP_AUTO'); ?></option>
                                    <option value="system">system</option>
                                    <option value="content">content</option>
                                    <option value="user">user</option>
                                    <option value="authentication">authentication</option>
                                    <option value="webservices">webservices</option>
                                    <option value="task">task</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="vendor_namespace" class="form-label fw-semibold">
                                <?php echo Text::_('COM_COPMILER_FIELD_VENDOR_LABEL'); ?>
                            </label>
                            <input
                                type="text"
                                name="vendor_namespace"
                                id="vendor_namespace"
                                class="form-control"
                                value="<?php echo htmlspecialchars($this->vendorNamespace, ENT_QUOTES, 'UTF-8'); ?>"
                                pattern="[A-Za-z][A-Za-z0-9]*"
                            >
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100" id="copmiler-submit-btn">
                            <span class="icon-cogs me-2" aria-hidden="true"></span>
                            <?php echo Text::_('COM_COPMILER_BTN_COMPILE_NOW'); ?>
                        </button>
                    </div>
                </div>

                <?php echo HTMLHelper::_('form.token'); ?>
            </form>
        </div>
    </div>

    <!-- Build History Table -->
    <div class="card shadow-sm">
        <div class="card-header bg-transparent">
            <h3 class="card-title mb-0"><?php echo Text::_('COM_COPMILER_HISTORY_HEADING'); ?></h3>
        </div>
        <div class="card-body p-0">
            <?php if ($this->builds === []) : ?>
                <div class="p-4 text-center text-muted">
                    <?php echo Text::_('COM_COPMILER_HISTORY_EMPTY'); ?>
                </div>
            <?php else : ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th><?php echo Text::_('COM_COPMILER_COL_WP_PLUGIN'); ?></th>
                                <th><?php echo Text::_('COM_COPMILER_COL_DETECTED_TYPE'); ?></th>
                                <th><?php echo Text::_('COM_COPMILER_COL_ELEMENT'); ?></th>
                                <th><?php echo Text::_('COM_COPMILER_COL_MODE'); ?></th>
                                <th><?php echo Text::_('COM_COPMILER_COL_SCORE'); ?></th>
                                <th><?php echo Text::_('COM_COPMILER_COL_DATE'); ?></th>
                                <th class="text-end"><?php echo Text::_('COM_COPMILER_COL_ACTIONS'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->builds as $item) : ?>
                                <?php
                                $badgeClass = match ($item->detected_type) {
                                    'component' => 'bg-primary',
                                    'module'    => 'bg-warning text-dark',
                                    default     => 'bg-success',
                                };
                                $reportUrl   = Route::_('index.php?option=com_copmiler&view=build&id=' . (int) $item->id);
                                $downloadUrl = Route::_('index.php?option=com_copmiler&task=compile.download&id=' . (int) $item->id . '&' . $token . '=1');
                                $deleteUrl   = Route::_('index.php?option=com_copmiler&task=compile.delete&id=' . (int) $item->id . '&' . $token . '=1');
                                ?>
                                <tr>
                                    <td><?php echo (int) $item->id; ?></td>
                                    <td>
                                        <a href="<?php echo $reportUrl; ?>" class="fw-semibold text-decoration-none">
                                            <?php echo htmlspecialchars((string) $item->wp_name, ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                        <div class="small text-muted">v<?php echo htmlspecialchars((string) $item->wp_version, ENT_QUOTES, 'UTF-8'); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $badgeClass; ?>">
                                            <?php echo strtoupper(htmlspecialchars((string) $item->detected_type, ENT_QUOTES, 'UTF-8')); ?>
                                            <?php if ($item->detected_type === 'plugin') : ?>
                                                (<?php echo htmlspecialchars((string) $item->plugin_group, ENT_QUOTES, 'UTF-8'); ?>)
                                            <?php endif; ?>
                                        </span>
                                        <span class="small text-muted ms-1"><?php echo (int) $item->confidence; ?>%</span>
                                    </td>
                                    <td><code><?php echo htmlspecialchars((string) $item->element_name, ENT_QUOTES, 'UTF-8'); ?></code></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars((string) $item->compile_mode, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="height: 8px; min-width: 60px;">
                                                <div class="progress-bar <?php echo (int) $item->compatibility_score >= 75 ? 'bg-success' : ((int) $item->compatibility_score >= 50 ? 'bg-warning' : 'bg-danger'); ?>"
                                                     style="width: <?php echo (int) $item->compatibility_score; ?>%"></div>
                                            </div>
                                            <span class="small fw-semibold"><?php echo (int) $item->compatibility_score; ?>%</span>
                                        </div>
                                    </td>
                                    <td class="small text-muted"><?php echo htmlspecialchars((string) $item->created, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="text-end text-nowrap">
                                        <a href="<?php echo $reportUrl; ?>" class="btn btn-sm btn-outline-primary">
                                            <?php echo Text::_('COM_COPMILER_BTN_REPORT'); ?>
                                        </a>
                                        <a href="<?php echo $downloadUrl; ?>" class="btn btn-sm btn-success">
                                            <?php echo Text::_('COM_COPMILER_BTN_DOWNLOAD'); ?>
                                        </a>
                                        <a href="<?php echo $deleteUrl; ?>" class="btn btn-sm btn-outline-danger"
                                           onclick="return confirm('<?php echo Text::_('COM_COPMILER_CONFIRM_DELETE', true); ?>');">
                                            <?php echo Text::_('JACTION_DELETE'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
