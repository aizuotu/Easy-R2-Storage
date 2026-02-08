/**
 * Easy Cloudflare R2 Storage 管理设置页面 JavaScript
 * 
 * 处理设置页面的AJAX请求和用户交互
 * 
 * @package Easy_R2_Storage
 */

jQuery(document).ready(function($) {
    'use strict';
    
    // ====================
    // 1. R2凭据配置表单
    // ====================
    $('#easy-r2-storage-credentials-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var button = $('#submit-credentials');
        var resultDiv = $('#credentials-result');
        
        button.prop('disabled', true).val('保存中...');
        resultDiv.empty();
        
        $.post(ajaxurl, {
            action: 'save_credentials',
            nonce: easy_r2_storage_admin.credentials_nonce,
            easy_r2_storage_settings: form.serializeObject()
        }, function(response) {
            console.log('凭据保存响应:', response);
            
            if (response.success) {
                showNotice('success', 'R2凭据已保存！', resultDiv);
                
                if (response.data && response.data.warning) {
                    showNotice('warning', response.data.warning, resultDiv);
                }
            } else {
                var errorMsg = response.data || '保存凭据失败';
                showNotice('error', errorMsg, resultDiv);
            }
        }).fail(function(xhr, status, errorThrown) {
            console.error('保存凭据错误:', errorThrown);
            showNotice('error', '网络错误，请稍后重试', resultDiv);
        }).always(function() {
            button.prop('disabled', false).val('保存凭据');
        });
    });
    
    // ====================
    // 2. 工具设置表单
    // ====================
    $('#easy-r2-storage-tool-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var button = $('#submit-tool-settings');
        var resultDiv = $('#save-tool-result');
        
        button.prop('disabled', true).val('保存中...');
        resultDiv.empty();
        
        $.post(ajaxurl, {
            action: 'save_tool_settings',
            nonce: easy_r2_storage_admin.tool_nonce,
            easy_r2_storage_settings: form.serializeObject()
        }, function(response) {
            console.log('工具设置保存响应:', response);
            
            if (response.success) {
                showNotice('success', '工具设置已保存！', resultDiv);
            } else {
                var errorMsg = response.data || '保存工具设置失败';
                showNotice('error', errorMsg, resultDiv);
            }
        }).fail(function(xhr, status, errorThrown) {
            console.error('保存工具设置错误:', errorThrown);
            showNotice('error', '网络错误，请稍后重试', resultDiv);
        }).always(function() {
            button.prop('disabled', false).val('保存工具设置');
        });
    });
    
    // ====================
    // 3. URL设置表单
    // ====================
    $('#easy-r2-storage-url-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var button = $('#submit-url-settings');
        var resultDiv = $('#save-url-result');
        
        button.prop('disabled', true).val('保存中...');
        resultDiv.empty();
        
        $.post(ajaxurl, {
            action: 'save_url_settings',
            nonce: easy_r2_storage_admin.url_nonce,
            easy_r2_storage_settings: form.serializeObject()
        }, function(response) {
            console.log('URL设置保存响应:', response);
            
            if (response.success) {
                showNotice('success', 'URL设置已保存！', resultDiv);
            } else {
                var errorMsg = response.data || '保存URL设置失败';
                showNotice('error', errorMsg, resultDiv);
            }
        }).fail(function(xhr, status, errorThrown) {
            console.error('保存URL设置错误:', errorThrown);
            showNotice('error', '网络错误，请稍后重试', resultDiv);
        }).always(function() {
            button.prop('disabled', false).val('保存 URL 设置');
        });
    });
    
    // ====================
    // 4. 同步设置表单
    // ====================
    $('#easy-r2-storage-sync-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var button = $('#submit-sync-settings');
        var resultDiv = $('#run-sync-result');
        
        button.prop('disabled', true).val('保存中...');
        resultDiv.empty();
        
        $.post(ajaxurl, {
            action: 'save_sync_settings',
            nonce: easy_r2_storage_admin.sync_nonce,
            easy_r2_storage_auto_sync_enabled: form.find('[name="easy_r2_storage_auto_sync_enabled"]').is(':checked') ? 1 : 0,
            easy_r2_storage_auto_sync_interval: form.find('[name="easy_r2_storage_auto_sync_interval"]').val(),
            easy_r2_storage_auto_sync_batch_size: form.find('[name="easy_r2_storage_auto_sync_batch_size"]').val()
        }, function(response) {
            console.log('同步设置保存响应:', response);
            
            if (response.success) {
                showNotice('success', '同步设置已保存！', resultDiv);
            } else {
                var errorMsg = response.data || '保存同步设置失败';
                showNotice('error', errorMsg, resultDiv);
            }
        }).fail(function(xhr, status, errorThrown) {
            console.error('保存同步设置错误:', errorThrown);
            showNotice('error', '网络错误，请稍后重试', resultDiv);
        }).always(function() {
            button.prop('disabled', false).val('保存同步设置');
        });
    });
    
    // ====================
    // 5. 调试设置表单
    // ====================
    $('#easy-r2-storage-debug-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var button = $('#submit-debug-settings');
        var resultDiv = button.next('.save-result');
        
        button.prop('disabled', true).val('保存中...');
        
        $.post(ajaxurl, {
            action: 'save_debug_settings',
            nonce: easy_r2_storage_admin.debug_nonce,
            easy_r2_storage_settings: form.serializeObject()
        }, function(response) {
            console.log('调试设置保存响应:', response);
            
            if (response.success) {
                showNotice('success', '调试设置已保存！', resultDiv);
            } else {
                var errorMsg = response.data || '保存调试设置失败';
                showNotice('error', errorMsg, resultDiv);
            }
        }).fail(function(xhr, status, errorThrown) {
            console.error('保存调试设置错误:', errorThrown);
            showNotice('error', '网络错误，请稍后重试', resultDiv);
        }).always(function() {
            button.prop('disabled', false).val('保存调试设置');
        });
    });
    
    // ====================
    // 测试连接（保持不变）
    // ====================
    $('#test-connection-btn').on('click', function() {
        var button = $(this);
        var resultDiv = $('#test-connection-result');
        
        button.prop('disabled', true).html(
            '<span class="dashicons dashicons-update-alt spin"></span> 测试中...'
        );
        resultDiv.empty();
        
        $.ajax({
            url: easy_r2_storage_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'easy_r2_storage_test_connection',
                nonce: easy_r2_storage_admin.test_connection_nonce
            },
            success: function(response) {
                if (response.success) {
                    resultDiv.html('<div class="notice notice-success inline"><p>✅ ' + response.data + '</p></div>');
                } else {
                    resultDiv.html('<div class="notice notice-error inline"><p>❌ ' + response.data + '</p></div>');
                }
            },
            error: function() {
                resultDiv.html('<div class="notice notice-error inline"><p>❌ 网络错误，请检查连接</p></div>');
            },
            complete: function() {
                button.prop('disabled', false).html(
                    '<span class="dashicons dashicons-admin-network"></span> ' + 
                    easy_r2_storage_admin.test_connection_text
                );
            }
        });
    });
    
    // ====================
    // 测试连接
    // ====================
    $('#test-connection-btn').on('click', function() {
        var button = $(this);
        var resultDiv = $('#test-connection-result');
        
        // 禁用按钮并显示加载状态
        button.prop('disabled', true).html(
            '<span class="dashicons dashicons-update-alt spin"></span> 测试中...'
        );
        resultDiv.empty();
        
        // 发送AJAX请求
        $.ajax({
            url: easy_r2_storage_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'easy_r2_storage_test_connection',
                nonce: easy_r2_storage_admin.test_connection_nonce
            },
            success: function(response) {
                if (response.success) {
                    resultDiv.html('<div class="notice notice-success inline"><p>✅ ' + response.data + '</p></div>');
                } else {
                    resultDiv.html('<div class="notice notice-error inline"><p>❌ ' + response.data + '</p></div>');
                }
            },
            error: function() {
                resultDiv.html('<div class="notice notice-error inline"><p>❌ 网络错误，请检查连接</p></div>');
            },
            complete: function() {
                button.prop('disabled', false).html(
                    '<span class="dashicons dashicons-admin-network"></span> ' + 
                    easy_r2_storage_admin.test_connection_text
                );
            }
        });
    });
    
    // ====================
    // 立即运行同步
    // ====================
    $('#run-sync-btn').on('click', function() {
        var button = $(this);
        var resultDiv = $('#run-sync-result');
        
        // 确认操作
        if (!confirm('确定要立即运行自动同步吗？这可能需要一些时间。')) {
            return;
        }
        
        // 禁用按钮并显示加载状态
        button.prop('disabled', true).html(
            '<span class="dashicons dashicons-update-alt spin"></span> 同步中...'
        );
        resultDiv.empty();
        
        // 发送AJAX请求
        $.ajax({
            url: easy_r2_storage_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'easy_r2_storage_run_auto_sync',
                nonce: easy_r2_storage_admin.run_sync_nonce
            },
            success: function(response) {
                if (response.success) {
                    resultDiv.html('<div class="notice notice-success inline"><p>✅ ' + response.data + '</p></div>');
                } else {
                    resultDiv.html('<div class="notice notice-error inline"><p>❌ ' + response.data + '</p></div>');
                }
            },
            error: function() {
                resultDiv.html('<div class="notice notice-error inline"><p>❌ 网络错误，请稍后重试</p></div>');
            },
            complete: function() {
                button.prop('disabled', false).html(
                    '<span class="dashicons dashicons-update-alt"></span> ' + 
                    easy_r2_storage_admin.run_sync_text
                );
            }
        });
    });
    
    // ====================
    // 获取调试信息
    // ====================
    $('#get-debug-info-btn').on('click', function() {
        var button = $(this);
        var container = $('#debug-info-container');
        
        // 禁用按钮并显示加载状态
        button.prop('disabled', true).html(
            '<span class="dashicons dashicons-update-alt spin"></span> 加载中...'
        );
        
        // 发送AJAX请求
        $.ajax({
            url: easy_r2_storage_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'easy_r2_storage_get_debug_info',
                nonce: easy_r2_storage_admin.get_debug_nonce
            },
            success: function(response) {
                if (response.success) {
                    container.html(response.data.debug_info).slideDown();
                } else {
                    container.html('<p class="error">获取调试信息失败: ' + response.data + '</p>').slideDown();
                }
            },
            error: function() {
                container.html('<p class="error">网络错误，无法获取调试信息</p>').slideDown();
            },
            complete: function() {
                button.prop('disabled', false).html(
                    '<span class="dashicons dashicons-info"></span> ' + 
                    easy_r2_storage_admin.refresh_debug_text
                );
            }
        });
    });
    
    // ====================
    // 辅助函数
    // ====================
    
    /**
     * 显示通知消息
     * 
     * @param {string} type 消息类型 (success, error, warning, info)
     * @param {string} message 消息内容
     * @param {jQuery} targetDiv 目标显示容器
     */
    function showNotice(type, message, targetDiv) {
        var noticeClass = 'notice-' + type;
        var icon = '';
        
        switch(type) {
            case 'success':
                icon = '✅';
                break;
            case 'error':
                icon = '❌';
                break;
            case 'warning':
                icon = '⚠️';
                break;
            default:
                icon = 'ℹ️';
        }
        
        var notice = $('<div>')
            .addClass('notice ' + noticeClass + ' inline')
            .css({
                'padding': '10px 15px',
                'margin': '10px 0',
                'display': 'block'
            })
            .html('<p><strong>' + icon + ' ' + message + '</strong></p>');
        
        if (targetDiv && targetDiv.length) {
            targetDiv.empty().append(notice);
        } else {
            $('#save-result').empty().append(notice);
        }
        
        // 5秒后自动隐藏
        setTimeout(function() {
            notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }
    
    // 添加jQuery扩展方法：serializeObject
    $.fn.serializeObject = function() {
        var o = {};
        var a = this.serializeArray();
        $.each(a, function() {
            if (o[this.name] !== undefined) {
                if (!o[this.name].push) {
                    o[this.name] = [o[this.name]];
                }
                o[this.name].push(this.value || '');
            } else {
                o[this.name] = this.value || '';
            }
        });
        return o;
    };
    
    // ====================
    // 页面加载时的初始化
    // ====================
    
    // 添加旋转动画类
    $('<style>')
        .text('.spin { animation: spin 1s linear infinite; } @keyframes spin { 100% { transform: rotate(360deg); } }')
        .appendTo('head');
    
    // 如果调试信息容器有内容，显示它
    if ($('#debug-info-container').find('h3, table, pre').length > 0) {
        $('#debug-info-container').show();
    }
});