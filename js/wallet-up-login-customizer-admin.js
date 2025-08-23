/**
 * Wallet Up Premium Login JavaScript - Admin
 * Enhanced interactive login experience with improved animations and validation
 * Version: 2.3.5
 */

(function($) {
    "use strict";
    $(document).ready(function() {
        $('button[type="submit"], input[type="submit"], .button-primary').on('click', function(e) {
            var $button = $(this);
            if ($button.prop('disabled') || $button.hasClass('processing')) {
                e.preventDefault();
                return false;
            }
            var originalText = $button.html();
            var originalValue = $button.val();
            $button.addClass('processing');
            setTimeout(function() {
                $button.prop('disabled', true);
                if ($button.is('input')) {
                    $button.val('Processing...');
                } else {
                    $button.html('<span class="spinner is-active" style="float: left; margin: 2px 5px 0 0;"></span>Processing...');
                }
                if (!$button.closest('form').length) {
                    setTimeout(function() {
                        $button.removeClass('processing');
                        $button.prop('disabled', false);
                        if ($button.is('input')) {
                            $button.val(originalValue);
                        } else {
                            $button.html(originalText);
                        }
                    }, 3000);
                }
            }, 10); 
        });
        $('form').on('submit', function() {
            var $form = $(this);
            var $submitButton = $form.find('input[type="submit"], button[type="submit"]');
            $submitButton.each(function() {
                var $button = $(this);
                if (!$button.hasClass('processing')) {
                    $button.addClass('processing');
                    $button.prop('disabled', true);
                    if ($button.is('input')) {
                        $button.data('original-value', $button.val());
                        $button.val('Saving...');
                    } else {
                        $button.data('original-html', $button.html());
                        $button.html('<span class="spinner is-active" style="float: left; margin: 2px 5px 0 0;"></span>Saving...');
                    }
                    
                    // Reset button after 3-5 seconds if form processing is complete
                    setTimeout(function() {
                        if ($button.hasClass('processing')) {
                            $button.removeClass('processing');
                            $button.prop('disabled', false);
                            if ($button.is('input')) {
                                var originalValue = $button.data('original-value') || 'Save Settings';
                                $button.val(originalValue);
                            } else {
                                var originalHtml = $button.data('original-html') || 'Save Settings';
                                $button.html(originalHtml);
                            }
                        }
                    }, 4000); // Reset after 4 seconds
                }
            });
        });
        if ($.fn.wpColorPicker) {
            $('.wallet-up-color-picker').wpColorPicker({
                change: function(event, ui) {
                    updateColorPreview();
                }
            });
        }
        if ($.fn.tipTip) {
            $('.wallet-up-tooltip').tipTip({
                attribute: 'data-tip',
                fadeIn: 50,
                fadeOut: 50,
                delay: 200
            });
        }
        var messageContainer = $('#loading-messages-container');
        var messageTemplate = $('#loading-message-template').html();
        var messageCount = messageContainer.find('.loading-message').length;
        $('#add-loading-message').on('click', function(e) {
            e.preventDefault();
            var newMessage = messageTemplate.replace(/\[index\]/g, messageCount);
            messageContainer.append(newMessage);
            messageCount++;
            var newField = messageContainer.find('.loading-message').last();
            newField.hide().slideDown(300);
        });
        $(document).on('click', '.remove-loading-message', function(e) {
            e.preventDefault();
            var messageField = $(this).closest('.loading-message');
            messageField.slideUp(300, function() {
                $(this).remove();
                if (messageContainer.find('.loading-message').length === 0) {
                    var defaultMessage = messageTemplate.replace(/\[index\]/g, 0);
                    var defaultMsg = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.verifyingCredentials) ? walletUpLogin.strings.verifyingCredentials : 'Verifying your credentials...';
                    defaultMessage = defaultMessage.replace('value=""', 'value="' + defaultMsg.replace(/"/g, '&quot;') + '"');
                    messageContainer.append(defaultMessage);
                    messageContainer.find('.loading-message').hide().slideDown(300);
                }
            });
        });
        $('.preview-button').hover(
            function() {
                $(this).find('.preview-icon').css('transform', 'translateX(3px)');
            },
            function() {
                $(this).find('.preview-icon').css('transform', 'translateX(0)');
            }
        );
        initMediaUploader();
        $('.color-preset').on('click', function(e) {
            e.preventDefault();
            var preset = $(this).data('preset');
            var colors = getPresetColors(preset);
            $('#primary_color').val(colors.primary).trigger('change');
            $('#gradient_start').val(colors.gradientStart).trigger('change');
            $('#gradient_end').val(colors.gradientEnd).trigger('change');
            $('#primary_color').wpColorPicker('color', colors.primary);
            $('#gradient_start').wpColorPicker('color', colors.gradientStart);
            $('#gradient_end').wpColorPicker('color', colors.gradientEnd);
            showNotification('Color scheme applied!', 'success');
        });
        function updateColorPreview() {
            var primaryColor = $('#primary_color').val() || '#674FBF';
            var gradientStart = $('#gradient_start').val() || '#674FBF';
            var gradientEnd = $('#gradient_end').val() || '#7B68D4';
            $('.color-preview-primary').css('background-color', primaryColor);
            $('.color-preview-gradient').css('background', 'linear-gradient(135deg, ' + gradientStart + ' 0%, ' + gradientEnd + ' 100%)');
            $('.button-preview').css('background', 'linear-gradient(135deg, ' + gradientStart + ' 0%, ' + gradientEnd + ' 100%)');
        }
        updateColorPreview();
        initTabs();
        initOptionValidation();
        fixDuplicateMenuItems();
        fixMenuHighlighting();
        $('#export-settings').on('click', function(e) {
            e.preventDefault();
            var exportData = {
                version: '2.0',
                timestamp: new Date().toISOString(),
                plugin_version: '2.5.0', 
                settings: {
                    login_options: {},
                    security_options: {}
                }
            };
            $('#wallet-up-settings-form').find('input, select, textarea').each(function() {
                var $field = $(this);
                var name = $field.attr('name');
                if (!name) return;
                var matches = name.match(/^(wallet_up_(?:login|security)_options)\[([^\]]+)\](?:\[\])?$/);
                if (!matches) return;
                var optionGroup = matches[1];
                var fieldKey = matches[2];
                var value;
                var targetSettings = optionGroup === 'wallet_up_login_customizer_options' 
                    ? exportData.settings.login_options 
                    : exportData.settings.security_options;
                if ($field.attr('type') === 'checkbox') {
                    value = $field.is(':checked') ? 1 : 0;
                } else if ($field.attr('type') === 'radio') {
                    if ($field.is(':checked')) {
                        value = $field.val();
                    } else {
                        return; 
                    }
                } else {
                    value = $field.val();
                }
                if (name.endsWith('[]')) {
                    if (!targetSettings[fieldKey]) {
                        targetSettings[fieldKey] = [];
                    }
                    if (value) {
                        targetSettings[fieldKey].push(value);
                    }
                } else {
                    targetSettings[fieldKey] = value;
                }
            });
            exportData.site_url = window.location.hostname;
            exportData.exported_by = (typeof walletUpLogin !== 'undefined' && walletUpLogin.currentUser) ? walletUpLogin.currentUser : 'Admin';
            var json = JSON.stringify(exportData, null, 2);
            var blob = new Blob([json], {type: 'application/json'});
            var url = URL.createObjectURL(blob);
            var date = new Date();
            var filename = 'wallet-up-settings-' + 
                          date.getFullYear() + 
                          ('0' + (date.getMonth() + 1)).slice(-2) + 
                          ('0' + date.getDate()).slice(-2) + '-' +
                          ('0' + date.getHours()).slice(-2) + 
                          ('0' + date.getMinutes()).slice(-2) + 
                          '.json';
            var a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            showNotification('Settings exported successfully!', 'success');
        });
        $('#import-settings').on('click', function(e) {
            e.preventDefault();
            $('#import-file').click();
        });
        $('#import-file').on('change', function(e) {
            var file = e.target.files[0];
            if (!file) return;
            if (file.type !== 'application/json' && !file.name.endsWith('.json')) {
                showNotification('Please select a valid JSON file', 'error');
                $(this).val(''); 
                return;
            }
            var reader = new FileReader();
            reader.onload = function(e) {
                try {
                    var importData = JSON.parse(e.target.result);
                    var settings;
                    if (importData.version === '2.0' && importData.settings) {
                        console.log('Importing settings v2.0 format');
                        if (importData.settings.login_options) {
                            importSettings('wallet_up_login_customizer_options', importData.settings.login_options);
                        }
                        if (importData.settings.security_options) {
                            importSettings('wallet_up_login_customizer_security_options', importData.settings.security_options);
                        }
                        var importInfo = 'Settings imported from ' + importData.site_url;
                        if (importData.timestamp) {
                            importInfo += ' (exported on ' + new Date(importData.timestamp).toLocaleDateString() + ')';
                        }
                        showNotification(importInfo, 'success');
                    } else {
                        console.log('Importing legacy format settings');
                        importSettings('wallet_up_login_customizer_options', importData);
                        showNotification('Settings imported successfully (legacy format)', 'success');
                    }
                    updateColorPreview();
                    var $logoInput = $('#custom_logo_url');
                    if ($logoInput.val()) {
                        updateLogoPreview($logoInput.val());
                    }
                } catch (error) {
                    console.error('Import error:', error);
                    showNotification('Error importing settings: ' + error.message, 'error');
                }
            };
            reader.onerror = function() {
                showNotification('Error reading file', 'error');
            };
            reader.readAsText(file);
            $(this).val('');
        });
        function importSettings(optionGroup, settings) {
            for (var key in settings) {
                if (settings.hasOwnProperty(key)) {
                    var value = settings[key];
                    var fieldName = optionGroup + '[' + key + ']';
                    if (key === 'loading_messages' && Array.isArray(value)) {
                        $('#loading-messages-container').empty();
                        value.forEach(function(message, index) {
                            if (message) {
                                var template = $('#loading-message-template').html() || 
                                    '<div class="loading-message"><input type="text" name="' + optionGroup + '[loading_messages][]" value="" /><button type="button" class="remove-loading-message">Remove</button></div>';
                                var newMessage = template.replace(/\[index\]/g, index);
                                newMessage = newMessage.replace('value=""', 'value="' + escapeHtml(message) + '"');
                                $('#loading-messages-container').append(newMessage);
                            }
                        });
                    } else {
                        var $field = $('[name="' + fieldName + '"]');
                        if ($field.length === 0) {
                            $field = $('#' + key);
                        }
                        if ($field.length > 0) {
                            if ($field.attr('type') === 'checkbox') {
                                $field.prop('checked', value == 1 || value === true || value === 'on');
                            } else if ($field.attr('type') === 'radio') {
                                $('[name="' + fieldName + '"][value="' + value + '"]').prop('checked', true);
                            } else {
                                $field.val(value);
                                if ($field.hasClass('wallet-up-color-picker') && $.fn.wpColorPicker) {
                                    $field.wpColorPicker('color', value);
                                }
                            }
                        } else {
                            console.warn('Field not found for key:', key, 'in group:', optionGroup);
                        }
                    }
                }
            }
        }
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
        function updateLogoPreview(url) {
            $('.logo-preview').remove();
            if (url) {
                var previewHtml = '<div class="logo-preview" style="margin-top: 10px;">' +
                                 '<img src="' + url + '" alt="Logo Preview" style="max-width: 100px; max-height: 100px; border: 1px solid #ddd; border-radius: 4px; padding: 5px;" />' +
                                 '</div>';
                $('#upload_logo_button').after(previewHtml);
            }
        }
        $('#reset-settings').on('click', function(e) {
            e.preventDefault();
            if (confirm('Are you sure you want to reset all settings to default values?')) {
                $('#primary_color').wpColorPicker('color', '#674FBF');
                $('#gradient_start').wpColorPicker('color', '#674FBF');
                $('#gradient_end').wpColorPicker('color', '#7B68D4');
                $('#custom_logo_url').val('');
                $('#redirect_delay').val(1500);
                $('.logo-preview').remove();
                $('#enable_ajax_login').prop('checked', true);
                $('#enable_sounds').prop('checked', true);
                $('#dashboard_redirect').prop('checked', true);
                $('#show_remember_me').prop('checked', true);
                $('#loading-messages-container').empty();
                var defaultMessages = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings) ? [
                    walletUpLogin.strings.verifyingCredentials || 'Verifying your credentials...',
                    walletUpLogin.strings.preparingDashboard || 'Preparing your dashboard...',
                    walletUpLogin.strings.almostThere || 'Almost there...'
                ] : [
                    'Verifying your credentials...',
                    'Preparing your dashboard...',
                    'Almost there...'
                ];
                defaultMessages.forEach(function(message, index) {
                    var newMessage = messageTemplate.replace(/\[index\]/g, index);
                    newMessage = newMessage.replace('value=""', 'value="' + message + '"');
                    $('#loading-messages-container').append(newMessage);
                });
                updateColorPreview();
                showNotification('Settings reset to defaults!', 'success');
            }
        });
        function initMediaUploader() {
            $('#upload_logo_button').on('click', function(e) {
                e.preventDefault();
                var frame = wp.media({
                    title: 'Select or Upload Logo Image',
                    button: {
                        text: 'Use This Image'
                    },
                    library: {
                        type: 'image'
                    },
                    multiple: false
                });
                frame.on('select', function() {
                    var state = frame.state();
                    if (!state) return;
                    var selection = state.get('selection');
                    if (!selection) return;
                    var attachment = selection.first().toJSON();
                    console.log('Selected attachment:', attachment);
                    var imageUrl = '';
                    if (attachment.sizes && attachment.sizes.full && attachment.sizes.full.url) {
                        imageUrl = attachment.sizes.full.url;
                    } else if (attachment.url) {
                        imageUrl = attachment.url;
                    } else if (attachment.sizes) {
                        if (attachment.sizes.large && attachment.sizes.large.url) {
                            imageUrl = attachment.sizes.large.url;
                        } else if (attachment.sizes.medium && attachment.sizes.medium.url) {
                            imageUrl = attachment.sizes.medium.url;
                        } else if (attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) {
                            imageUrl = attachment.sizes.thumbnail.url;
                        }
                    }
                    if (!imageUrl) {
                        console.error('No valid image URL found in attachment:', attachment);
                        showNotification('Error: Could not get image URL', 'error');
                        return;
                    }
                    try {
                        var urlObj = new URL(imageUrl);
                        if (!urlObj.protocol.match(/^https?:$/)) {
                            throw new Error('Invalid protocol');
                        }
                    } catch (e) {
                        console.error('Invalid image URL format:', imageUrl, e);
                        showNotification('Error: Invalid image URL format', 'error');
                        return;
                    }
                    console.log('Using image URL:', imageUrl);
                    $('#custom_logo_url').val(imageUrl).trigger('change');
                    var $container = $('#upload_logo_button').parent();
                    var $existingPreview = $container.find('.logo-preview');
                    $existingPreview.remove();
                    var $previewDiv = $('<div class="logo-preview" style="margin-top: 10px;"></div>');
                    var $previewImg = $('<img alt="Logo Preview" style="max-width: 100px; max-height: 100px; border: 1px solid #ddd; border-radius: 4px; padding: 5px;" />');
                    $previewImg.on('error', function() {
                        console.error('Preview image failed to load:', imageUrl);
                        $(this).attr('src', 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwIiBoZWlnaHQ9IjEwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwIiBoZWlnaHQ9IjEwMCIgZmlsbD0iI2Y1ZjVmNSIvPjx0ZXh0IHg9IjUwJSIgeT0iNTAlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjOTk5IiBmb250LWZhbWlseT0ic2Fucy1zZXJpZiIgZm9udC1zaXplPSIxMiIgZHk9Ii4zZW0iPkltYWdlIE5vdCBGb3VuZDwvdGV4dD48L3N2Zz4=');
                    });
                    $previewImg.on('load', function() {
                        console.log('Preview image loaded successfully:', imageUrl);
                    });
                    $previewImg.attr('src', imageUrl);
                    $previewDiv.append($previewImg);
                    $('#upload_logo_button').after($previewDiv);
                });
                frame.open();
            });
        }
        function initTabs() {
            $('.settings-panel').removeClass('active').hide();
            $('.settings-tab').each(function(index) {
                $(this).attr('data-tab-index', index);
            });
            $('.settings-panel').each(function(index) {
                $(this).attr('data-panel-index', index);
            });
            $('.settings-tab').on('click', function(e) {
                e.preventDefault();
                var clickedIndex = parseInt($(this).attr('data-tab-index'));
                $('.settings-tab').removeClass('active');
                $(this).addClass('active');
                $('.settings-panel').removeClass('active').hide();
                var $targetPanel = $('.settings-panel[data-panel-index="' + clickedIndex + '"]');
                if ($targetPanel.length) {
                    $targetPanel.addClass('active').show();
                    reinitializeTabComponents($targetPanel);
                }
                localStorage.setItem('walletUpActiveTabIndex', clickedIndex);
            });
            var savedTabIndex = localStorage.getItem('walletUpActiveTabIndex');
            var $initialTab;
            if (savedTabIndex !== null && $('.settings-tab[data-tab-index="' + savedTabIndex + '"]').length) {
                $initialTab = $('.settings-tab[data-tab-index="' + savedTabIndex + '"]');
            } else {
                $initialTab = $('.settings-tab:first');
            }
            if ($initialTab.length) {
                var initialIndex = parseInt($initialTab.attr('data-tab-index'));
                $('.settings-tab').removeClass('active');
                $initialTab.addClass('active');
                var $initialPanel = $('.settings-panel[data-panel-index="' + initialIndex + '"]');
                if ($initialPanel.length) {
                    $initialPanel.addClass('active').show();
                    reinitializeTabComponents($initialPanel);
                }
            }
        }
        function reinitializeTabComponents($panel) {
            $panel.find('.wallet-up-color-picker').each(function() {
                if (!$(this).hasClass('wp-color-picker')) {
                    $(this).wpColorPicker({
                        change: function(event, ui) {
                            updateColorPreview();
                        }
                    });
                }
            });
            $('.wallet-up-option-tip').not($panel.find('.wallet-up-option-tip')).remove();
            $panel[0].offsetHeight;
        }
        function showNotification(message, type) {
            $('.wallet-up-notification').remove();
            var notification = $('<div class="wallet-up-notification ' + type + '">' + message + '</div>');
            $('body').append(notification);
            notification.css({
                position: 'fixed',
                top: '32px',
                right: '20px',
                background: type === 'success' ? '#10b981' : '#ef4444',
                color: 'white',
                padding: '12px 20px',
                borderRadius: '4px',
                zIndex: 100000,
                boxShadow: '0 2px 8px rgba(0,0,0,0.15)'
            });
            notification.hide().fadeIn(300);
            setTimeout(function() {
                notification.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        }
        function getPresetColors(preset) {
            var presets = {
                'purple': {
                    primary: '#674FBF',
                    gradientStart: '#674FBF',
                    gradientEnd: '#7B68D4'
                },
                'blue': {
                    primary: '#3B82F6',
                    gradientStart: '#3B82F6',
                    gradientEnd: '#60A5FA'
                },
                'green': {
                    primary: '#10B981',
                    gradientStart: '#10B981',
                    gradientEnd: '#34D399'
                },
                'red': {
                    primary: '#EF4444',
                    gradientStart: '#EF4444',
                    gradientEnd: '#F87171'
                },
                'orange': {
                    primary: '#F59E0B',
                    gradientStart: '#F59E0B',
                    gradientEnd: '#FBBF24'
                },
                'dark': {
                    primary: '#1F2937',
                    gradientStart: '#1F2937',
                    gradientEnd: '#4B5563'
                }
            };
            return presets[preset] || presets.purple;
        }
        function fixDuplicateMenuItems() {
            var $dashboardMenuItem = $('#adminmenu li.menu-top.menu-icon-dashboard');
            var $walletUpMenuItem = $('#adminmenu a.menu-top[href*="page=wallet-up"]').parent();
            if ($dashboardMenuItem.length && $walletUpMenuItem.length) {
                if ($dashboardMenuItem.index() < $walletUpMenuItem.index()) {
                    $walletUpMenuItem.attr('data-duplicate', 'true');
                } else {
                    $dashboardMenuItem.attr('data-duplicate', 'true');
                }
                if (window.location.href.indexOf('page=wallet-up') > -1) {
                    $dashboardMenuItem.addClass('wp-has-current-submenu wp-menu-open')
                        .removeClass('wp-not-current-submenu');
                    $walletUpMenuItem.removeClass('wp-has-current-submenu wp-menu-open')
                        .addClass('wp-not-current-submenu');
                }
            }
        }
        function fixMenuHighlighting() {
            if (window.location.href.indexOf('page=wallet-up') > -1) {
                $('#adminmenu li.menu-top.menu-icon-dashboard')
                    .addClass('current wp-has-current-submenu wp-menu-open')
                    .removeClass('wp-not-current-submenu');
                $('#adminmenu a.menu-top[href*="page=wallet-up"]').parent()
                    .removeClass('current wp-has-current-submenu wp-menu-open')
                    .addClass('wp-not-current-submenu');
                $('#adminmenu li.menu-top.menu-icon-dashboard .wp-submenu-head').addClass('current');
            }
        }
        function initOptionValidation() {
            var $forceReplacement = $('#force_dashboard_replacement');
            var $exemptAdmins = $('#exempt_admin_roles');
            var $landToWalletUp = $('#redirect_to_wallet_up');
            $forceReplacement.on('change', function() {
                if ($(this).is(':checked')) {
                    if (!$exemptAdmins.is(':checked')) {
                        showOptionTip($exemptAdmins, walletUpLogin.strings.considerEnabling || 'Consider enabling "Exempt Administrator Roles" for easier management.', 'info');
                    }
                }
            });
            $exemptAdmins.on('change', function() {
                clearOptionTips();
            });
        }
        function showOptionTip($element, message, type) {
            var tipClass = 'wallet-up-option-tip wallet-up-tip-' + type;
            var tipHtml = '<div class="' + tipClass + '" style="margin-top: 8px; padding: 8px; border-radius: 4px; font-size: 12px; ' +
                         (type === 'info' ? 'background: #e7f3ff; border-left: 3px solid #2196F3; color: #1565C0;' : '') +
                         (type === 'success' ? 'background: #f0f9ff; border-left: 3px solid #10b981; color: #065f46;' : '') +
                         '">' + message + '</div>';
            $element.closest('td').find('.wallet-up-option-tip').remove();
            $element.closest('td').append(tipHtml);
        }
        function clearOptionTips() {
            $('.wallet-up-option-tip').fadeOut(function() {
                $(this).remove();
            });
        }
    }); 
})(jQuery);