/**
 * Easy Cloudflare R2 Storage 批量同步页面 JavaScript
 * 
 * 处理批量同步的AJAX请求和进度显示
 * 
 * @package Easy_R2_Storage
 */

jQuery(document).ready(function($) {
    'use strict';
    
    // ====================
    // 变量初始化
    // ====================
    var isSyncing = false;           // 是否正在同步
    var isStopped = false;           // 是否用户停止了同步
    var syncMode = 'full';           // 同步模式
    var totalProcessed = 0;          // 已处理的文件数
    var successCount = 0;            // 成功数
    var errorCount = 0;              // 失败数
    var currentOffset = 0;           // 当前偏移量
    var batchSize = 10;              // 批次大小
    var delay = 500;                 // 批次间延迟（毫秒）
    
    // ====================
    // 工具函数
    // ====================
    
    /**
     * 添加日志条目
     * 
     * @param {string} message 日志消息
     * @param {string} type 日志类型 (info, success, error, warning)
     */
    function addLog(message, type) {
        type = type || 'info';
        var timestamp = new Date().toLocaleTimeString('zh-CN');
        
        // 创建日志条目
        var logEntry = $('<div>')
            .addClass('log-entry log-' + type)
            .html(
                '<span class="log-time">[' + timestamp + ']</span> ' +
                '<span class="log-message">' + message + '</span>'
            );
        
        // 添加到日志容器
        $('#sync-log').append(logEntry);
        
        // 滚动到底部
        $('#sync-log').scrollTop($('#sync-log')[0].scrollHeight);
    }
    
    /**
     * 更新进度条
     * 
     * @param {number} current 已处理数量
     * @param {number} total 总数量
     */
    function updateProgress(current, total) {
        totalProcessed = current;
        
        var percentage = total > 0 ? Math.round((current / total) * 100) : 0;
        
        // 更新进度条
        $('.progress-bar .progress-fill').css('width', percentage + '%');
        
        // 更新进度文本
        $('.progress-percentage').text(percentage + '%');
        $('.progress-count').text(current + ' / ' + total);
        
        // 更新状态详情
        $('#processed-count').text(current);
        $('#success-count').text(successCount);
        $('#error-count').text(errorCount);
    }
    
    /**
     * 更新同步状态文本
     * 
     * @param {string} status 状态文本
     */
    function updateStatus(status) {
        $('#sync-status .status-text').text(status);
    }
    
    /**
     * 重置UI状态
     */
    function resetUI() {
        isSyncing = false;
        isStopped = false;
        
        $('#start-sync-btn').prop('disabled', false).show();
        $('#stop-sync-btn').prop('disabled', false).hide();
        $('#sync-status').hide();
        
        updateStatus('准备就绪');
    }
    
    /**
     * 显示同步状态
     */
    function showSyncStatus() {
        $('#sync-status').show();
    }
    
    /**
     * 隐藏同步状态
     */
    function hideSyncStatus() {
        $('#sync-status').hide();
    }
    
    /**
     * 延迟函数
     * 
     * @param {number} ms 延迟毫秒数
     * @return {Promise} Promise对象
     */
    function delay(ms) {
        return new Promise(function(resolve) {
            setTimeout(resolve, ms);
        });
    }
    
    // ====================
    // 事件处理
    // ====================
    
    /**
     * 开始同步按钮点击事件
     */
    $('#start-sync-btn').on('click', function() {
        // 获取同步模式
        syncMode = $('input[name="sync_mode"]:checked').val() || 'full';
        
        // 获取批次大小
        batchSize = parseInt($('#batch-size').val()) || 10;
        batchSize = Math.max(1, Math.min(50, batchSize)); // 限制在1-50之间
        
        // 获取延迟
        delay = parseInt($('#delay').val()) || 500;
        delay = Math.max(100, Math.min(5000, delay)); // 限制在100-5000毫秒之间
        
        // 开始同步
        startSync();
    });
    
    /**
     * 停止同步按钮点击事件
     */
    $('#stop-sync-btn').on('click', function() {
        isStopped = true;
        
        var button = $(this);
        button.prop('disabled', true).html(
            '<span class="dashicons dashicons-update-alt spin"></span> 正在停止...'
        );
        
        updateStatus('正在停止...');
        addLog('用户停止了同步', 'warning');
    });
    
    /**
     * 清除日志按钮点击事件
     */
    $('#clear-log-btn').on('click', function() {
        $('#sync-log').empty();
        addLog('日志已清除', 'info');
    });
    
    // ====================
    // 同步逻辑
    // ====================
    
    /**
     * 开始同步
     */
    function startSync() {
        // 检查是否已经在同步
        if (isSyncing) {
            addLog('同步已经在进行中', 'warning');
            return;
        }
        
        // 初始化变量
        isSyncing = true;
        isStopped = false;
        currentOffset = 0;
        totalProcessed = 0;
        successCount = 0;
        errorCount = 0;
        
        // 更新UI
        $('#start-sync-btn').prop('disabled', true).hide();
        $('#stop-sync-btn').prop('disabled', false).show();
        showSyncStatus();
        
        // 添加开始日志
        addLog('='.repeat(60), 'info');
        addLog('开始批量同步', 'info');
        addLog('同步模式: ' + (syncMode === 'full' ? '完整同步' : '增量同步'), 'info');
        addLog('批次大小: ' + batchSize, 'info');
        addLog('批次延迟: ' + delay + 'ms', 'info');
        addLog('='.repeat(60), 'info');
        
        updateStatus('正在初始化...');
        
        // 开始处理第一批
        processBatch();
    }
    
    /**
     * 处理一批文件
     */
    function processBatch() {
        // 检查是否停止
        if (isStopped) {
            handleSyncStopped();
            return;
        }
        
        // 更新状态
        var batchNumber = Math.floor(currentOffset / batchSize) + 1;
        updateStatus('正在处理第 ' + batchNumber + ' 批...');
        
        // 发送AJAX请求
        $.ajax({
            url: easy_r2_storage_bulk.ajax_url,
            type: 'POST',
            data: {
                action: 'easy_r2_storage_bulk_sync_batch',
                nonce: easy_r2_storage_bulk.nonce,
                mode: syncMode,
                offset: currentOffset,
                batch_size: batchSize
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    
                    // 更新计数
                    currentOffset += data.processed;
                    totalProcessed += data.processed;
                    
                    // 处理消息
                    if (data.messages && data.messages.length > 0) {
                        data.messages.forEach(function(msg) {
                            if (msg.type === 'success') {
                                successCount++;
                                addLog(msg.message, 'success');
                            } else if (msg.type === 'error') {
                                errorCount++;
                                addLog(msg.message, 'error');
                            } else if (msg.type === 'warning') {
                                addLog(msg.message, 'warning');
                            } else {
                                addLog(msg.message, 'info');
                            }
                        });
                    }
                    
                    // 更新进度
                    updateProgress(totalProcessed, data.total);
                    
                    // 检查是否还有更多文件
                    if (data.processed > 0 && data.processed === batchSize && !isStopped) {
                        // 延迟后继续下一批
                        delay(delay).then(function() {
                            processBatch();
                        });
                    } else {
                        // 同步完成
                        handleSyncComplete(data.total);
                    }
                } else {
                    handleSyncError(response.data || '未知错误');
                }
            },
            error: function(xhr, status, errorThrown) {
                var errorMessage = '网络错误';
                if (xhr.responseJSON && xhr.responseJSON.data) {
                    errorMessage = xhr.responseJSON.data;
                } else if (errorThrown) {
                    errorMessage = errorThrown;
                }
                handleSyncError(errorMessage);
            },
            complete: function() {
                // 重置停止按钮状态
                if (!isStopped) {
                    $('#stop-sync-btn').prop('disabled', false).html(
                        '<span class="dashicons dashicons-no"></span> 停止同步'
                    );
                }
            }
        });
    }
    
    /**
     * 处理同步完成
     * 
     * @param {number} total 总文件数
     */
    function handleSyncComplete(total) {
        // 更新进度到100%
        updateProgress(totalProcessed, totalProcessed);
        
        // 添加完成日志
        addLog('='.repeat(60), 'info');
        addLog('✨ 同步完成！', 'success');
        addLog('总计处理: ' + totalProcessed + ' 个文件', 'info');
        addLog('成功: ' + successCount + ' 个', 'success');
        addLog('失败: ' + errorCount + ' 个', errorCount > 0 ? 'error' : 'info');
        addLog('='.repeat(60), 'info');
        
        // 更新状态
        updateStatus('同步完成！');
        
        // 重置UI
        resetUI();
    }
    
    /**
     * 处理同步停止
     */
    function handleSyncStopped() {
        // 添加停止日志
        addLog('='.repeat(60), 'info');
        addLog('同步已停止', 'warning');
        addLog('总计处理: ' + totalProcessed + ' 个文件', 'info');
        addLog('成功: ' + successCount + ' 个', 'success');
        addLog('失败: ' + errorCount + ' 个', 'info');
        addLog('='.repeat(60), 'info');
        
        // 更新状态
        updateStatus('同步已停止');
        
        // 重置UI
        resetUI();
    }
    
    /**
     * 处理同步错误
     * 
     * @param {string} errorMessage 错误消息
     */
    function handleSyncError(errorMessage) {
        // 添加错误日志
        addLog('同步出错: ' + errorMessage, 'error');
        errorCount++;
        
        // 更新状态
        updateStatus('同步出错');
        
        // 重置UI
        resetUI();
    }
    
    // ====================
    // 页面加载初始化
    // ====================
    
    // 添加旋转动画样式
    $('<style>')
        .text(
            '.spin { animation: spin 1s linear infinite; } ' +
            '@keyframes spin { 100% { transform: rotate(360deg); } }'
        )
        .appendTo('head');
    
    // 添加初始日志
    addLog('批量同步页面已加载', 'info');
    addLog('点击"开始批量同步"按钮开始上传文件', 'info');
});