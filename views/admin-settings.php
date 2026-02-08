<?php
/**
 * 管理设置页面视图
 * 
 * 显示插件的主要设置界面
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

// 获取当前设置
$settings = $this->settings;
$auto_sync_enabled = get_option('easy_r2_storage_auto_sync_enabled', false);
$auto_sync_batch_size = get_option('easy_r2_storage_auto_sync_batch_size', 10);
$auto_sync_interval = get_option('easy_r2_storage_auto_sync_interval', 'hourly');

// 获取可用的同步间隔
$schedules = wp_get_schedules();
$interval_options = [];
foreach ($schedules as $key => $schedule) {
    $interval_options[$key] = $schedule['display'];
}

// 检查是否已配置
$is_configured = !empty($settings['account_id']) && 
                !empty($settings['access_key_id']) && 
                !empty($settings['secret_access_key']) && 
                !empty($settings['bucket_name']);
?>
<div class="wrap easy-r2-storage-admin">
    <h1>Easy Cloudflare R2 Storage 设置</h1>
    
    <div class="easy-r2-storage-container">
        <div class="easy-r2-storage-main">
            
            <!-- 1. R2凭据配置（独立表单） -->
            <form method="post" action="" id="easy-r2-storage-credentials-form">
                <?php wp_nonce_field('easy_r2_storage_credentials_nonce', 'credentials_nonce'); ?>
                <input type="hidden" name="action" value="save_credentials">
                
                <div class="easy-r2-storage-section">
                    <h2>🔑 R2 凭据配置</h2>
                    <p class="description">请输入您的 Cloudflare R2 存储凭据。这些信息可以在 Cloudflare 控制台的 R2 存储桶设置中找到。</p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="account_id">账户 ID</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="account_id" 
                                       name="easy_r2_storage_settings[account_id]" 
                                       value="<?php echo esc_attr($settings['account_id']); ?>" 
                                       class="regular-text"
                                       placeholder="例如：1234567890abcdef1234567890abcdef">
                                <p class="description">您的 Cloudflare 账户 ID，可以在 Cloudflare 控制台的右侧找到。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="access_key_id">访问密钥 ID</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="access_key_id" 
                                       name="easy_r2_storage_settings[access_key_id]" 
                                       value="<?php echo esc_attr($settings['access_key_id']); ?>" 
                                       class="regular-text"
                                       placeholder="例如：abc123def456">
                                <p class="description">在 R2 控制台中创建的 API 令牌的访问密钥 ID。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="secret_access_key">秘密访问密钥</label>
                            </th>
                            <td>
                                <input type="password" 
                                       id="secret_access_key" 
                                       name="easy_r2_storage_settings[secret_access_key]" 
                                       value="<?php echo esc_attr($settings['secret_access_key']); ?>" 
                                       class="regular-text"
                                       placeholder="••••••••••••••••">
                                <p class="description">与访问密钥 ID 配套的秘密密钥，请妥善保管。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="bucket_name">存储桶名称</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="bucket_name" 
                                       name="easy_r2_storage_settings[bucket_name]" 
                                       value="<?php echo esc_attr($settings['bucket_name']); ?>" 
                                       class="regular-text"
                                       placeholder="例如：my-wordpress-media">
                                <p class="description">您在 R2 中创建的存储桶名称。</p>
                            </td>
                        </tr>
                    </table>
                    
                    <!-- R2凭据保存按钮 -->
                    <p class="submit">
                        <input type="submit" 
                               name="submit_credentials" 
                               id="submit-credentials" 
                               class="button button-primary" 
                               value="保存凭据">
                        <button type="button" 
                                class="button button-secondary" 
                                id="test-connection-btn">
                            <span class="dashicons dashicons-admin-network"></span>
                            测试连接
                        </button>
                        <span id="credentials-result" class="save-result"></span>
                        <span id="test-connection-result" class="test-result"></span>
                    </p>
                </div>
            </form>
            
            <!-- 2. 工具设置（独立表单） -->
            <form method="post" action="" id="easy-r2-storage-tool-form">
                <?php wp_nonce_field('easy_r2_storage_tool_nonce', 'tool_nonce'); ?>
                <input type="hidden" name="action" value="save_tool_settings">
                
                <div class="easy-r2-storage-section">
                    <h2>🛠️ 工具设置</h2>
                    <p class="description">配置媒体文件的处理方式和存储选项。</p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">自动上传</th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           id="auto_offload" 
                                           name="easy_r2_storage_settings[auto_offload]" 
                                           value="1" 
                                           <?php checked($settings['auto_offload']); ?>>
                                    自动将新上传的媒体文件上传到 R2 存储
                                </label>
                                <p class="description">启用后，每次上传媒体文件时都会自动上传到 R2。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">上传模式</th>
                            <td>
                                <label>
                                    <input type="radio" 
                                           name="easy_r2_storage_settings[upload_mode]" 
                                           value="full_only" 
                                           <?php checked($settings['upload_mode'], 'full_only'); ?>>
                                    仅上传原始文件（快速）
                                </label>
                                <br>
                                <label>
                                    <input type="radio" 
                                           name="easy_r2_storage_settings[upload_mode]" 
                                           value="all_sizes" 
                                           <?php checked($settings['upload_mode'], 'all_sizes'); ?>>
                                    上传所有尺寸（完整）
                                </label>
                                <p class="description">选择"仅上传原始文件"可以加快上传速度，但可能需要手动同步缩略图。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">删除本地文件</th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           id="delete_local_files" 
                                           name="easy_r2_storage_settings[delete_local_files]" 
                                           value="1" 
                                           <?php checked($settings['delete_local_files']); ?>>
                                    上传成功后删除本地文件
                                </label>
                                <p class="description">启用后，文件上传到 R2 后会从服务器删除。建议先确保 URL 重写正常工作。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">保留本地副本</th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           id="keep_local_copy" 
                                           name="easy_r2_storage_settings[keep_local_copy]" 
                                           value="1" 
                                           <?php checked($settings['keep_local_copy']); ?>>
                                    保留本地副本作为备份
                                </label>
                                <p class="description">即使启用了"删除本地文件"，也可以选择保留原始文件作为备份。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="file_path_pattern">文件路径模式</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="file_path_pattern" 
                                       name="easy_r2_storage_settings[file_path_pattern]" 
                                       value="<?php echo esc_attr($settings['file_path_pattern']); ?>" 
                                       class="regular-text"
                                       placeholder="uploads/{year}/{month}/{filename}">
                                <p class="description">
                                    可用变量：<code>{year}</code>（年份）、<code>{month}</code>（月份）、<code>{day}</code>（日期）、<code>{filename}</code>（文件名）
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <!-- 工具设置保存按钮 -->
                    <p class="submit">
                        <input type="submit" 
                               name="submit_tool_settings" 
                               id="submit-tool-settings" 
                               class="button button-primary" 
                               value="保存工具设置">
                        <span id="save-tool-result" class="save-result"></span>
                    </p>
                </div>
            </form>
            
            <!-- 3. URL重写与域名设置（独立表单） -->
            <form method="post" action="" id="easy-r2-storage-url-form">
                <?php wp_nonce_field('easy_r2_storage_url_nonce', 'url_nonce'); ?>
                <input type="hidden" name="action" value="save_url_settings">
                
                <div class="easy-r2-storage-section">
                    <h2>🌐 URL 重写与域名设置</h2>
                    <p class="description">配置媒体文件的 URL 重写和自定义域名设置。</p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">启用 URL 重写</th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           id="enable_url_rewrite" 
                                           name="easy_r2_storage_settings[enable_url_rewrite]" 
                                           value="1" 
                                           <?php checked($settings['enable_url_rewrite']); ?>>
                                    自动将媒体 URL 重写为 R2 URL
                                </label>
                                <p class="description">启用后，所有媒体 URL 都会自动指向 R2 存储而不是本地服务器。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="public_url">自定义绑定域名</label>
                            </th>
                            <td>
                                <input type="url" 
                                       id="public_url" 
                                       name="easy_r2_storage_settings[public_url]" 
                                       value="<?php echo esc_attr($settings['public_url']); ?>" 
                                       class="regular-text"
                                       placeholder="https://media.yourdomain.com">
                                <p class="description">如果您为 R2 存储桶配置了自定义域名，请在此输入完整的域名（包含协议）。</p>
                                <p class="description"><strong>使用说明：</strong></p>
                                <ul class="description" style="margin-left: 20px; margin-top: 5px;">
                                    <li>• 确保域名已正确解析到 R2 存储桶</li>
                                    <li>• 推荐使用 HTTPS 域名提升安全性</li>
                                    <li>• 如果不使用自定义域名，将自动使用 R2 默认 URL</li>
                                    <li>• 示例：https://media.example.com 或 https://cdn.yoursite.com</li>
                                </ul>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">自动修复缩略图</th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           id="auto_fix_thumbnails" 
                                           name="easy_r2_storage_settings[auto_fix_thumbnails]" 
                                           value="1" 
                                           <?php checked($settings['auto_fix_thumbnails']); ?>>
                                    自动修复媒体库中的缩略图显示
                                </label>
                                <p class="description">如果媒体库中的缩略图无法显示，启用此选项会尝试自动修复。</p>
                            </td>
                        </tr>
                    </table>
                    
                    <!-- URL设置保存按钮 -->
                    <p class="submit">
                        <input type="submit" 
                               name="submit_url_settings" 
                               id="submit-url-settings" 
                               class="button button-primary" 
                               value="保存 URL 设置">
                        <span id="save-url-result" class="save-result"></span>
                    </p>
                </div>
            </form>
            
            <!-- 4. 自动同步设置（独立表单） -->
            <form method="post" action="" id="easy-r2-storage-sync-form">
                <?php wp_nonce_field('easy_r2_storage_sync_nonce', 'sync_nonce'); ?>
                <input type="hidden" name="action" value="save_sync_settings">
                
                <div class="easy-r2-storage-section">
                    <h2>⏱️ 自动同步设置</h2>
                    <p class="description">配置自动将现有媒体文件同步到 R2 存储。</p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">启用自动同步</th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           id="auto_sync_enabled" 
                                           name="easy_r2_storage_auto_sync_enabled" 
                                           value="1" 
                                           <?php checked($auto_sync_enabled); ?>>
                                    定期自动同步未上传的媒体文件
                                </label>
                                <p class="description">启用后，插件会定期检查并上传尚未同步到 R2 的媒体文件。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="auto_sync_interval">同步频率</label>
                            </th>
                            <td>
                                <select id="auto_sync_interval" name="easy_r2_storage_auto_sync_interval">
                                    <?php foreach ($interval_options as $key => $label): ?>
                                        <option value="<?php echo esc_attr($key); ?>" 
                                                <?php selected($auto_sync_interval, $key); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">选择自动同步任务的执行频率。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="auto_sync_batch_size">每次同步数量</label>
                            </th>
                            <td>
                                <input type="number" 
                                       id="auto_sync_batch_size" 
                                       name="easy_r2_storage_auto_sync_batch_size" 
                                       value="<?php echo esc_attr($auto_sync_batch_size); ?>" 
                                       min="1" 
                                       max="50"
                                       class="small-text">
                                <p class="description">每次同步任务处理的最大文件数量（1-50）。设置较小的值可以减少服务器负载。</p>
                            </td>
                        </tr>
                    </table>
                    
                    <!-- 同步设置保存按钮和立即运行 -->
                    <p class="submit">
                        <input type="submit" 
                               name="submit_sync_settings" 
                               id="submit-sync-settings" 
                               class="button button-primary" 
                               value="保存同步设置">
                        <button type="button" 
                                class="button button-secondary" 
                                id="run-sync-btn">
                            <span class="dashicons dashicons-update-alt"></span>
                            立即运行同步
                        </button>
                        <span id="run-sync-result" class="test-result"></span>
                    </p>
                </div>
            </form>
            
            <!-- 5. 调试设置（独立表单） -->
            <form method="post" action="" id="easy-r2-storage-debug-form">
                <?php wp_nonce_field('easy_r2_storage_debug_nonce', 'debug_nonce'); ?>
                <input type="hidden" name="action" value="save_debug_settings">
                
                <div class="easy-r2-storage-section">
                    <h2>🐛 调试设置</h2>
                    <p class="description">用于故障排除和问题诊断。</p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">启用调试日志</th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           id="enable_debug_logging" 
                                           name="easy_r2_storage_settings[enable_debug_logging]" 
                                           value="1" 
                                           <?php checked($settings['enable_debug_logging']); ?>>
                                    记录详细的调试信息到 WordPress 日志
                                </label>
                                <p class="description">启用后，所有操作都会记录到 WordPress 调试日志文件中。仅在需要诊断问题时启用。</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" 
                               name="submit_debug_settings" 
                               id="submit-debug-settings" 
                               class="button button-primary" 
                               value="保存调试设置">
                        <button type="button" 
                                class="button button-secondary" 
                                id="get-debug-info-btn">
                            <span class="dashicons dashicons-info"></span>
                            获取调试信息
                        </button>
                    </p>
                    
                    <div id="debug-info-container" class="debug-info-container" style="display: none;"></div>
                </div>
            </form>
            
        </div>
        
        <!-- 侧边栏 -->
        <div class="easy-r2-storage-sidebar">
            <div class="easy-r2-storage-card">
                <h3>📊 同步状态</h3>
                <div class="sync-status">
                    <?php
                    $total = wp_count_posts('attachment');
                    $total_count = $total->inherit ?? 0;
                    $synced_count = 0;
                    
                    // 获取最近同步的附件数量
                    $synced_attachments = get_posts([
                        'post_type' => 'attachment',
                        'numberposts' => -1,
                        'meta_query' => [
                            [
                                'key' => '_r2_url',
                                'compare' => 'EXISTS'
                            ]
                        ]
                    ]);
                    $synced_count = count($synced_attachments);
                    $remaining_count = $total_count - $synced_count;
                    
                    $percentage = $total_count > 0 ? round(($synced_count / $total_count) * 100) : 0;
                    ?>
                    <div class="sync-stat">
                        <span class="stat-label">总计</span>
                        <span class="stat-value"><?php echo number_format($total_count); ?></span>
                    </div>
                    <div class="sync-stat">
                        <span class="stat-label">已同步</span>
                        <span class="stat-value synced"><?php echo number_format($synced_count); ?></span>
                    </div>
                    <div class="sync-stat">
                        <span class="stat-label">待同步</span>
                        <span class="stat-value remaining"><?php echo number_format($remaining_count); ?></span>
                    </div>
                    <div class="sync-progress">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $percentage; ?>%;"></div>
                        </div>
                        <span class="progress-text"><?php echo $percentage; ?>%</span>
                    </div>
                </div>
                <p>
                    <a href="<?php echo admin_url('options-general.php?page=easy-r2-storage-bulk-sync'); ?>" 
                       class="button button-secondary button-block">
                        管理批量同步
                    </a>
                </p>
            </div>
            
            <div class="easy-r2-storage-card">
                <h3>📚 快速链接</h3>
                <ul>
                    <li>
                        <a href="https://developers.cloudflare.com/r2/" target="_blank" rel="noopener">
                            Cloudflare R2 文档
                        </a>
                    </li>
                    <li>
                        <a href="https://dash.cloudflare.com/" target="_blank" rel="noopener">
                            Cloudflare 控制台
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo admin_url('upload.php'); ?>">
                            媒体库
                        </a>
                    </li>
                </ul>
            </div>
            
            <div class="easy-r2-storage-card">
                <h3>💡 提示</h3>
                <ul>
                    <li>首次使用建议先测试连接</li>
                    <li>确保存储桶已设置为公共读取</li>
                    <li>建议先上传少量文件测试</li>
                    <li>启用自动同步可逐步迁移现有文件</li>
                </ul>
            </div>
        </div>
    </div>
</div>