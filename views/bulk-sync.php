<?php
/**
 * 批量同步页面视图
 * 
 * 显示批量同步界面
 * 
 * @package Easy_R2_Storage
 * 
 * 创作者声明
 * 
 * 本插件由 quanyixia 创建
 * 作者：quanyixia
 * 邮箱：junjunai2009@gmail.com
 * Telegram：t.me/junjunai2009
 * 许可证：GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

// 获取统计数据
$total = $this->bulk_sync->get_total_media();
$synced = $this->bulk_sync->get_synced_media();
$remaining = $total - $synced;

// 获取最近同步的附件
$recent_synced = get_posts([
    'post_type' => 'attachment',
    'numberposts' => 10,
    'orderby' => 'ID',
    'order' => 'DESC',
    'meta_query' => [
        [
            'key' => '_r2_url',
            'compare' => 'EXISTS'
        ]
    ]
]);

// 获取待同步的附件
$unsynced_attachments = $this->bulk_sync->get_unsynced_attachments();
$unsynced_count = count($unsynced_attachments);
?>

<div class="wrap easy-r2-storage-bulk-sync">
    <h1>批量同步媒体文件到 R2</h1>
    
    <div class="easy-r2-sync-container">
        <div class="easy-r2-sync-main">
            <!-- 同步统计卡片 -->
            <div class="easy-r2-stats-grid">
                <div class="easy-r2-stat-card">
                    <div class="stat-icon total">
                        <span class="dashicons dashicons-admin-media"></span>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">总媒体文件</div>
                        <div class="stat-value"><?php echo number_format($total); ?></div>
                    </div>
                </div>
                
                <div class="easy-r2-stat-card">
                    <div class="stat-icon synced">
                        <span class="dashicons dashicons-yes"></span>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">已同步</div>
                        <div class="stat-value"><?php echo number_format($synced); ?></div>
                    </div>
                </div>
                
                <div class="easy-r2-stat-card">
                    <div class="stat-icon remaining">
                        <span class="dashicons dashicons-clock"></span>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">待同步</div>
                        <div class="stat-value"><?php echo number_format($remaining); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- 同步进度 -->
            <div class="easy-r2-sync-progress-section">
                <h2>同步进度</h2>
                
                <div class="progress-container">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo $total > 0 ? round(($synced / $total) * 100) : 0; ?>%;"></div>
                    </div>
                    <div class="progress-stats">
                        <span class="progress-percentage">
                            <?php echo $total > 0 ? round(($synced / $total) * 100) : 0; ?>%
                        </span>
                        <span class="progress-count">
                            <?php echo number_format($synced); ?> / <?php echo number_format($total); ?>
                        </span>
                    </div>
                </div>
                
                <div class="sync-actions">
                    <button type="button" 
                            class="button button-primary button-large" 
                            id="start-sync-btn">
                        <span class="dashicons dashicons-upload"></span>
                        开始批量同步
                    </button>
                    <button type="button" 
                            class="button button-secondary" 
                            id="stop-sync-btn" 
                            style="display: none;">
                        <span class="dashicons dashicons-no"></span>
                        停止同步
                    </button>
                </div>
                
                <div class="sync-status" id="sync-status" style="display: none;">
                    <div class="status-header">
                        <span class="status-icon">
                            <span class="dashicons dashicons-update-alt spin"></span>
                        </span>
                        <span class="status-text">正在同步中...</span>
                    </div>
                    <div class="status-details">
                        <span class="detail-item">
                            <strong>已处理：</strong>
                            <span id="processed-count">0</span>
                        </span>
                        <span class="detail-item">
                            <strong>成功：</strong>
                            <span id="success-count">0</span>
                        </span>
                        <span class="detail-item">
                            <strong>失败：</strong>
                            <span id="error-count">0</span>
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- 同步设置 -->
            <div class="easy-r2-sync-settings">
                <h2>同步设置</h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">同步模式</th>
                        <td>
                            <label>
                                <input type="radio" 
                                       name="sync_mode" 
                                       value="full" 
                                       checked>
                                完整同步（所有未同步文件）
                            </label>
                            <br>
                            <label>
                                <input type="radio" 
                                       name="sync_mode" 
                                       value="incremental">
                                增量同步（仅缺失尺寸）
                            </label>
                            <p class="description">
                                完整同步会处理所有未同步的文件。增量同步只处理已同步但缺失某些尺寸的文件。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">批次大小</th>
                        <td>
                            <input type="number" 
                                   id="batch-size" 
                                   name="batch_size" 
                                   value="10" 
                                   min="1" 
                                   max="50"
                                   class="small-text">
                            <p class="description">
                                每批处理的文件数量。较小的值可以减少服务器负载。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">延迟（毫秒）</th>
                        <td>
                            <input type="number" 
                                   id="delay" 
                                   name="delay" 
                                   value="500" 
                                   min="100" 
                                   max="5000"
                                   step="100"
                                   class="small-text">
                            <p class="description">
                                每批之间的延迟时间（毫秒）。较大的值可以避免服务器过载。
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- 同步日志 -->
            <div class="easy-r2-sync-log">
                <div class="log-header">
                    <h2>同步日志</h2>
                    <button type="button" 
                            class="button button-small" 
                            id="clear-log-btn">
                        <span class="dashicons dashicons-trash"></span>
                        清除日志
                    </button>
                </div>
                <div class="log-container" id="sync-log">
                    <div class="log-entry log-info">
                        <span class="log-time"><?php echo date('H:i:s'); ?></span>
                        <span class="log-message">准备就绪。点击"开始批量同步"开始上传文件。</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 侧边栏 -->
        <div class="easy-r2-sync-sidebar">
            <!-- 最近同步 -->
            <div class="easy-r2-card">
                <h3>📤 最近同步</h3>
                <div class="recent-sync-list">
                    <?php if (empty($recent_synced)): ?>
                        <p class="no-items">暂无已同步的文件</p>
                    <?php else: ?>
                        <ul>
                            <?php foreach ($recent_synced as $attachment): ?>
                                <li>
                                    <div class="item-title">
                                        <?php echo esc_html(get_the_title($attachment->ID)); ?>
                                    </div>
                                    <div class="item-meta">
                                        <span class="item-id">#<?php echo $attachment->ID; ?></span>
                                        <a href="<?php echo get_edit_post_link($attachment->ID); ?>" 
                                           target="_blank">
                                            查看
                                        </a>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p>
                            <a href="<?php echo admin_url('options-general.php?page=easy-r2-storage'); ?>" 
                               class="button button-secondary button-small button-block">
                                查看设置
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- 待同步列表预览 -->
            <div class="easy-r2-card">
                <h3>⏳ 待同步文件</h3>
                <div class="unsynced-list">
                    <?php if (empty($unsynced_attachments)): ?>
                        <p class="no-items">🎉 所有文件已同步！</p>
                    <?php else: ?>
                        <p>
                            共有 <strong><?php echo number_format($unsynced_count); ?></strong> 个文件待同步
                        </p>
                        <ul>
                            <?php 
                            $preview_count = min(5, count($unsynced_attachments));
                            for ($i = 0; $i < $preview_count; $i++): 
                                $attachment = $unsynced_attachments[$i];
                            ?>
                                <li>
                                    <div class="item-title">
                                        <?php echo esc_html(get_the_title($attachment->ID)); ?>
                                    </div>
                                    <div class="item-meta">
                                        <span class="item-id">#<?php echo $attachment->ID; ?></span>
                                    </div>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($unsynced_count > 5): ?>
                                <li class="more-items">
                                    还有 <?php echo number_format($unsynced_count - 5); ?> 个文件...
                                </li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- 帮助信息 -->
            <div class="easy-r2-card">
                <h3>❓ 帮助</h3>
                <div class="help-content">
                    <h4>什么是批量同步？</h4>
                    <p>批量同步可以将您现有的媒体文件上传到 R2 存储，而无需手动逐个上传。</p>
                    
                    <h4>同步模式说明</h4>
                    <ul>
                        <li><strong>完整同步</strong>：上传所有未同步到 R2 的文件</li>
                        <li><strong>增量同步</strong>：仅上传已同步但缺失某些尺寸的文件</li>
                    </ul>
                    
                    <h4>注意事项</h4>
                    <ul>
                        <li>首次同步可能需要较长时间</li>
                        <li>建议在低峰期运行批量同步</li>
                        <li>同步过程中可以随时停止</li>
                        <li>已同步的文件不会重复上传</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>