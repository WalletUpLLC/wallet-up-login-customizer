/**
 * Wallet Up Login Admin JavaScript
 * Script for handling admin settings interface
 * Version: 2.5.1 - Fixed walletUpLogin undefined error
 */

(function($) {
    "use strict";
    
    $(document).ready(function() {
        console.log("Wallet Up Admin JS Loaded");
        
        // Auto-dismiss is now handled inline in PHP for better control
        
        // Button feedback system - show loading state on click
        $('button[type="submit"], input[type="submit"], .button-primary').on('click', function(e) {
            var $button = $(this);
            
            // Don't process if already disabled
            if ($button.prop('disabled') || $button.hasClass('processing')) {
                e.preventDefault();
                return false;
            }
            
            // Store original text
            var originalText = $button.html();
            var originalValue = $button.val();
            
            // Add processing class but DON'T disable yet (let form submit first)
            $button.addClass('processing');
            
            // Use setTimeout to update after the click event completes
            setTimeout(function() {
                // Now disable and update text
                $button.prop('disabled', true);
                
                // Add loading spinner and update text
                if ($button.is('input')) {
                    $button.val('Processing...');
                } else {
                    $button.html('<span class="spinner is-active" style="float: left; margin: 2px 5px 0 0;"></span>Processing...');
                }
                
                // For non-form buttons, re-enable after 3 seconds (safety fallback)
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
            }, 10); // Small delay to allow form submission to start
        });
        
        // Handle form submission feedback
        $('form').on('submit', function() {
            var $form = $(this);
            var $submitButton = $form.find('input[type="submit"], button[type="submit"]');
            
            // Add loading state to submit button
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
                }
            });
        });
        
        // Initialize color pickers
        if ($.fn.wpColorPicker) {
            $('.wallet-up-color-picker').wpColorPicker({
                change: function(event, ui) {
                    // Update preview in real time
                    updateColorPreview();
                }
            });
        }
        
        // Initialize tooltips if available
        if ($.fn.tipTip) {
            $('.wallet-up-tooltip').tipTip({
                attribute: 'data-tip',
                fadeIn: 50,
                fadeOut: 50,
                delay: 200
            });
        }
        
        // Handle dynamic message fields
        var messageContainer = $('#loading-messages-container');
        var messageTemplate = $('#loading-message-template').html();
        var messageCount = messageContainer.find('.loading-message').length;
        
        // Add new message field
        $('#add-loading-message').on('click', function(e) {
            e.preventDefault();
            var newMessage = messageTemplate.replace(/\[index\]/g, messageCount);
            messageContainer.append(newMessage);
            messageCount++;
            
            // Animate the new field
            var newField = messageContainer.find('.loading-message').last();
            newField.hide().slideDown(300);
        });
        
        // Remove message field
        $(document).on('click', '.remove-loading-message', function(e) {
            e.preventDefault();
            var messageField = $(this).closest('.loading-message');
            
            // Animate removal
            messageField.slideUp(300, function() {
                $(this).remove();
                
                // Check if we need to add a default message
                if (messageContainer.find('.loading-message').length === 0) {
                    var defaultMessage = messageTemplate.replace(/\[index\]/g, 0);
                    var defaultMsg = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.verifyingCredentials) ? walletUpLogin.strings.verifyingCredentials : 'Verifying your credentials...';
                    defaultMessage = defaultMessage.replace('value=""', 'value="' + defaultMsg.replace(/"/g, '&quot;') + '"');
                    messageContainer.append(defaultMessage);
                    messageContainer.find('.loading-message').hide().slideDown(300);
                }
            });
        });
        
        // Preview button hover effect
        $('.preview-button').hover(
            function() {
                $(this).find('.preview-icon').css('transform', 'translateX(3px)');
            },
            function() {
                $(this).find('.preview-icon').css('transform', 'translateX(0)');
            }
        );
        
        // Media uploader for logo selection
        initMediaUploader();
        
        // Color scheme presets
        $('.color-preset').on('click', function(e) {
            e.preventDefault();
            
            var preset = $(this).data('preset');
            var colors = getPresetColors(preset);
            
            // Update color pickers
            $('#primary_color').val(colors.primary).trigger('change');
            $('#gradient_start').val(colors.gradientStart).trigger('change');
            $('#gradient_end').val(colors.gradientEnd).trigger('change');
            
            // Update color picker UI
            $('#primary_color').wpColorPicker('color', colors.primary);
            $('#gradient_start').wpColorPicker('color', colors.gradientStart);
            $('#gradient_end').wpColorPicker('color', colors.gradientEnd);
            
            // Show notification
            showNotification('Color scheme applied!', 'success');
        });
        
        // Real-time preview updates
        function updateColorPreview() {
            var primaryColor = $('#primary_color').val() || '#674FBF';
            var gradientStart = $('#gradient_start').val() || '#674FBF';
            var gradientEnd = $('#gradient_end').val() || '#7B68D4';
            
            // Update color preview elements
            $('.color-preview-primary').css('background-color', primaryColor);
            $('.color-preview-gradient').css('background', 'linear-gradient(135deg, ' + gradientStart + ' 0%, ' + gradientEnd + ' 100%)');
            
            // Update button preview
            $('.button-preview').css('background', 'linear-gradient(135deg, ' + gradientStart + ' 0%, ' + gradientEnd + ' 100%)');
        }
        
        // Initialize preview
        updateColorPreview();
        
        // Tab functionality
        initTabs();
        
        // Initialize option validation
        initOptionValidation();
        
        // Fix duplicate menu items
        fixDuplicateMenuItems();
        
        // Fix menu highlighting
        fixMenuHighlighting();
        
        // Export/import settings
        $('#export-settings').on('click', function(e) {
            e.preventDefault();
            
            // Comprehensive settings export
            var exportData = {
                version: '2.0',
                timestamp: new Date().toISOString(),
                plugin_version: '2.5.0', // Match current JS version
                settings: {
                    login_options: {},
                    security_options: {}
                }
            };
            
            // Get all form fields
            $('#wallet-up-settings-form').find('input, select, textarea').each(function() {
                var $field = $(this);
                var name = $field.attr('name');
                if (!name) return;
                
                // Parse field name to get option group and key
                var matches = name.match(/^(wallet_up_(?:login|security)_options)\[([^\]]+)\](?:\[\])?$/);
                if (!matches) return;
                
                var optionGroup = matches[1];
                var fieldKey = matches[2];
                var value;
                
                // Determine which settings object to use
                var targetSettings = optionGroup === 'wallet_up_login_options' 
                    ? exportData.settings.login_options 
                    : exportData.settings.security_options;
                
                // Handle different field types
                if ($field.attr('type') === 'checkbox') {
                    value = $field.is(':checked') ? 1 : 0;
                } else if ($field.attr('type') === 'radio') {
                    if ($field.is(':checked')) {
                        value = $field.val();
                    } else {
                        return; // Skip unchecked radio buttons
                    }
                } else {
                    value = $field.val();
                }
                
                // Handle array fields (like loading_messages)
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
            
            // Add metadata
            exportData.site_url = window.location.hostname;
            exportData.exported_by = (typeof walletUpLogin !== 'undefined' && walletUpLogin.currentUser) ? walletUpLogin.currentUser : 'Admin';
            
            // Convert to JSON with pretty formatting
            var json = JSON.stringify(exportData, null, 2);
            
            // Create downloadable file
            var blob = new Blob([json], {type: 'application/json'});
            var url = URL.createObjectURL(blob);
            
            // Generate filename with timestamp
            var date = new Date();
            var filename = 'wallet-up-settings-' + 
                          date.getFullYear() + 
                          ('0' + (date.getMonth() + 1)).slice(-2) + 
                          ('0' + date.getDate()).slice(-2) + '-' +
                          ('0' + date.getHours()).slice(-2) + 
                          ('0' + date.getMinutes()).slice(-2) + 
                          '.json';
            
            // Create temporary link and click it
            var a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            
            // Show notification
            showNotification('Settings exported successfully!', 'success');
        });
        
        // Import settings button
        $('#import-settings').on('click', function(e) {
            e.preventDefault();
            $('#import-file').click();
        });
        
        // Import settings file change
        $('#import-file').on('change', function(e) {
            var file = e.target.files[0];
            if (!file) return;
            
            // Validate file type
            if (file.type !== 'application/json' && !file.name.endsWith('.json')) {
                showNotification('Please select a valid JSON file', 'error');
                $(this).val(''); // Clear file input
                return;
            }
            
            var reader = new FileReader();
            reader.onload = function(e) {
                try {
                    var importData = JSON.parse(e.target.result);
                    var settings;
                    
                    // Handle both new format (v2.0) and legacy format
                    if (importData.version === '2.0' && importData.settings) {
                        // New comprehensive format
                        console.log('Importing settings v2.0 format');
                        
                        // Import login options
                        if (importData.settings.login_options) {
                            importSettings('wallet_up_login_options', importData.settings.login_options);
                        }
                        
                        // Import security options
                        if (importData.settings.security_options) {
                            importSettings('wallet_up_security_options', importData.settings.security_options);
                        }
                        
                        // Show import info
                        var importInfo = 'Settings imported from ' + importData.site_url;
                        if (importData.timestamp) {
                            importInfo += ' (exported on ' + new Date(importData.timestamp).toLocaleDateString() + ')';
                        }
                        showNotification(importInfo, 'success');
                        
                    } else {
                        // Legacy format - treat entire object as login_options
                        console.log('Importing legacy format settings');
                        importSettings('wallet_up_login_options', importData);
                        showNotification('Settings imported successfully (legacy format)', 'success');
                    }
                    
                    // Update color preview
                    updateColorPreview();
                    
                    // Update logo preview if custom_logo_url was imported
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
            
            // Clear file input for re-selection
            $(this).val('');
        });
        
        // Helper function to import settings
        function importSettings(optionGroup, settings) {
            for (var key in settings) {
                if (settings.hasOwnProperty(key)) {
                    var value = settings[key];
                    var fieldName = optionGroup + '[' + key + ']';
                    
                    // Handle different field types
                    if (key === 'loading_messages' && Array.isArray(value)) {
                        // Clear existing messages
                        $('#loading-messages-container').empty();
                        
                        // Add new messages
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
                        // Find the field
                        var $field = $('[name="' + fieldName + '"]');
                        
                        if ($field.length === 0) {
                            // Try with ID
                            $field = $('#' + key);
                        }
                        
                        if ($field.length > 0) {
                            if ($field.attr('type') === 'checkbox') {
                                $field.prop('checked', value == 1 || value === true || value === 'on');
                            } else if ($field.attr('type') === 'radio') {
                                $('[name="' + fieldName + '"][value="' + value + '"]').prop('checked', true);
                            } else {
                                $field.val(value);
                                
                                // Update color pickers
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
        
        // Helper function to escape HTML
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
        
        // Helper function to update logo preview
        function updateLogoPreview(url) {
            $('.logo-preview').remove();
            if (url) {
                var previewHtml = '<div class="logo-preview" style="margin-top: 10px;">' +
                                 '<img src="' + url + '" alt="Logo Preview" style="max-width: 100px; max-height: 100px; border: 1px solid #ddd; border-radius: 4px; padding: 5px;" />' +
                                 '</div>';
                $('#upload_logo_button').after(previewHtml);
            }
        }
        
        // Reset settings
        $('#reset-settings').on('click', function(e) {
            e.preventDefault();
            
            if (confirm('Are you sure you want to reset all settings to default values?')) {
                // Reset color pickers
                $('#primary_color').wpColorPicker('color', '#674FBF');
                $('#gradient_start').wpColorPicker('color', '#674FBF');
                $('#gradient_end').wpColorPicker('color', '#7B68D4');
                
                // Reset text fields
                $('#custom_logo_url').val('');
                $('#redirect_delay').val(1500);
                
                // Remove any existing logo preview
                $('.logo-preview').remove();
                
                // Reset checkboxes
                $('#enable_ajax_login').prop('checked', true);
                $('#enable_sounds').prop('checked', true);
                $('#dashboard_redirect').prop('checked', true);
                $('#show_remember_me').prop('checked', true);
                
                // Reset loading messages
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
                
                // Update preview
                updateColorPreview();
                
                // Show notification
                showNotification('Settings reset to defaults!', 'success');
            }
        });
        
        /**
         * Initialize WordPress media uploader for logo selection
         */
        function initMediaUploader() {
            $('#upload_logo_button').on('click', function(e) {
                e.preventDefault();
                
                // Create the media frame
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
                
                // When an image is selected, run a callback
                frame.on('select', function() {
                    var state = frame.state();
                    if (!state) return;
                    
                    var selection = state.get('selection');
                    if (!selection) return;
                    
                    var attachment = selection.first().toJSON();
                    
                    // Debug logging
                    console.log('Selected attachment:', attachment);
                    
                    // Get the correct URL - always prefer full size for logo
                    var imageUrl = '';
                    
                    // First try to get full size URL
                    if (attachment.sizes && attachment.sizes.full && attachment.sizes.full.url) {
                        imageUrl = attachment.sizes.full.url;
                    } else if (attachment.url) {
                        // Fallback to main URL
                        imageUrl = attachment.url;
                    } else if (attachment.sizes) {
                        // Try other sizes as last resort
                        if (attachment.sizes.large && attachment.sizes.large.url) {
                            imageUrl = attachment.sizes.large.url;
                        } else if (attachment.sizes.medium && attachment.sizes.medium.url) {
                            imageUrl = attachment.sizes.medium.url;
                        } else if (attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) {
                            imageUrl = attachment.sizes.thumbnail.url;
                        }
                    }
                    
                    // Ensure we have a valid URL
                    if (!imageUrl) {
                        console.error('No valid image URL found in attachment:', attachment);
                        showNotification('Error: Could not get image URL', 'error');
                        return;
                    }
                    
                    // Validate URL format
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
                    
                    // Set the URL in the input field
                    $('#custom_logo_url').val(imageUrl).trigger('change');
                    
                    // Update the preview - find any existing preview in the parent container
                    var $container = $('#upload_logo_button').parent();
                    var $existingPreview = $container.find('.logo-preview');
                    
                    // Remove any existing preview to avoid duplicates
                    $existingPreview.remove();
                    
                    // Create new preview with error handling
                    var $previewDiv = $('<div class="logo-preview" style="margin-top: 10px;"></div>');
                    var $previewImg = $('<img alt="Logo Preview" style="max-width: 100px; max-height: 100px; border: 1px solid #ddd; border-radius: 4px; padding: 5px;" />');
                    
                    // Add error handler for preview image
                    $previewImg.on('error', function() {
                        console.error('Preview image failed to load:', imageUrl);
                        $(this).attr('src', 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwIiBoZWlnaHQ9IjEwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwIiBoZWlnaHQ9IjEwMCIgZmlsbD0iI2Y1ZjVmNSIvPjx0ZXh0IHg9IjUwJSIgeT0iNTAlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjOTk5IiBmb250LWZhbWlseT0ic2Fucy1zZXJpZiIgZm9udC1zaXplPSIxMiIgZHk9Ii4zZW0iPkltYWdlIE5vdCBGb3VuZDwvdGV4dD48L3N2Zz4=');
                    });
                    
                    // Add load handler to confirm success
                    $previewImg.on('load', function() {
                        console.log('Preview image loaded successfully:', imageUrl);
                    });
                    
                    // Set the source
                    $previewImg.attr('src', imageUrl);
                    
                    // Append preview
                    $previewDiv.append($previewImg);
                    $('#upload_logo_button').after($previewDiv);
                    
                    // Don't show success message here - logo hasn't been saved yet
                    // The success message will be shown after clicking Save Settings
                });
                
                // Open the modal
                frame.open();
            });
        }
        
        /**
         * Initialize tabs
         */
        function initTabs() {
            // Immediately hide all panels to prevent flash of unstyled content
            $('.settings-panel').removeClass('active').hide();
            
            // Assign data attribute indexes to tabs and panels for direct correlation
            $('.settings-tab').each(function(index) {
                $(this).attr('data-tab-index', index);
            });
            
            $('.settings-panel').each(function(index) {
                $(this).attr('data-panel-index', index);
            });
            
            // Tab click handler
            $('.settings-tab').on('click', function(e) {
                e.preventDefault();
                
                var clickedIndex = parseInt($(this).attr('data-tab-index'));
                
                // Update tabs
                $('.settings-tab').removeClass('active');
                $(this).addClass('active');
                
                // Hide all panels first
                $('.settings-panel').removeClass('active').hide();
                
                // Show target panel
                var $targetPanel = $('.settings-panel[data-panel-index="' + clickedIndex + '"]');
                if ($targetPanel.length) {
                    $targetPanel.addClass('active').show();
                    // Reinitialize any components in the active tab
                    reinitializeTabComponents($targetPanel);
                }
                
                // Save to localStorage
                localStorage.setItem('walletUpActiveTabIndex', clickedIndex);
            });
            
            // Initial tab selection - determine which tab to show
            var savedTabIndex = localStorage.getItem('walletUpActiveTabIndex');
            var $initialTab;
            
            if (savedTabIndex !== null && $('.settings-tab[data-tab-index="' + savedTabIndex + '"]').length) {
                $initialTab = $('.settings-tab[data-tab-index="' + savedTabIndex + '"]');
            } else {
                $initialTab = $('.settings-tab:first');
            }
            
            // Manually activate the initial tab without animation
            if ($initialTab.length) {
                var initialIndex = parseInt($initialTab.attr('data-tab-index'));
                $('.settings-tab').removeClass('active');
                $initialTab.addClass('active');
                
                // Show only the corresponding panel
                var $initialPanel = $('.settings-panel[data-panel-index="' + initialIndex + '"]');
                if ($initialPanel.length) {
                    $initialPanel.addClass('active').show();
                    reinitializeTabComponents($initialPanel);
                }
            }
        }
        
        /**
         * Reinitialize components when switching tabs
         */
        function reinitializeTabComponents($panel) {
            // Reinitialize color pickers in the active tab
            $panel.find('.wallet-up-color-picker').each(function() {
                if (!$(this).hasClass('wp-color-picker')) {
                    // Initialize if not already initialized
                    $(this).wpColorPicker({
                        change: function(event, ui) {
                            updateColorPreview();
                        }
                    });
                }
            });
            
            // Clear any stale validation messages from other tabs
            $('.wallet-up-option-tip').not($panel.find('.wallet-up-option-tip')).remove();
            
            // Force reflow to ensure proper layout
            $panel[0].offsetHeight;
        }
        
        /**
         * Show notification
         */
        function showNotification(message, type) {
            // Remove existing notifications
            $('.wallet-up-notification').remove();
            
            // Create notification
            var notification = $('<div class="wallet-up-notification ' + type + '">' + message + '</div>');
            $('body').append(notification);
            
            // Position notification
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
            
            // Fade in
            notification.hide().fadeIn(300);
            
            // Fade out after delay
            setTimeout(function() {
                notification.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        }
        
        /**
         * Get preset colors
         */
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
        
        /**
         * Fix duplicate menu items
         */
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
        
        /**
         * Fix menu highlighting
         */
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
        
        /**
         * Initialize option validation
         */
        function initOptionValidation() {
            var $forceReplacement = $('#force_dashboard_replacement');
            var $exemptAdmins = $('#exempt_admin_roles');
            var $landToWalletUp = $('#redirect_to_wallet_up');
            
            $forceReplacement.on('change', function() {
                if ($(this).is(':checked')) {
                    if (!$exemptAdmins.is(':checked')) {
                        showOptionTip($exemptAdmins, 'Consider enabling "Exempt Administrator Roles" for easier management.', 'info');
                    }
                }
            });
            
            $exemptAdmins.on('change', function() {
                clearOptionTips();
            });
        }
        
        /**
         * Show helpful tip near an option
         */
        function showOptionTip($element, message, type) {
            var tipClass = 'wallet-up-option-tip wallet-up-tip-' + type;
            var tipHtml = '<div class="' + tipClass + '" style="margin-top: 8px; padding: 8px; border-radius: 4px; font-size: 12px; ' +
                         (type === 'info' ? 'background: #e7f3ff; border-left: 3px solid #2196F3; color: #1565C0;' : '') +
                         (type === 'success' ? 'background: #f0f9ff; border-left: 3px solid #10b981; color: #065f46;' : '') +
                         '">' + message + '</div>';
            
            $element.closest('td').find('.wallet-up-option-tip').remove();
            $element.closest('td').append(tipHtml);
        }
        
        /**
         * Clear all option tips
         */
        function clearOptionTips() {
            $('.wallet-up-option-tip').fadeOut(function() {
                $(this).remove();
            });
        }
        
    }); // End of document ready
    
})(jQuery);