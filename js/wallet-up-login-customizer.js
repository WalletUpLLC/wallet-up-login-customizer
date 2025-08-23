/**
 * Wallet Up Premium Login JavaScript
 * Enhanced interactive login experience with improved animations and validation
 * Version: 2.2.0
 */

(function($) {
	"use strict";
	
	// Global state variables
	var animations = {}; // Store animation timelines
	var loginState = {
	  isValidating: false,
	  isSubmitting: false,
	  isSuccess: false,
	  isError: false,
	  errorMessage: '',
	  redirectUrl: '', // Store redirect URL for successful login
	  lastErrorShown: '', // Track last error to prevent duplicates
	  errorTimestamp: 0 // Track when error was shown
	};
	
	// Track field values to detect changes
	var fieldValues = {
	  username: '',
	  password: ''
	};
	
	// Configuration object that can be overridden via settings
	var config = {
	  animationSpeed: 0.4,
	  enableAjaxLogin: true,
	  validateOnType: true,
	  redirectDelay: 1500,
	  enableSounds: true,
	  loadingMessages: (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings) ? [
		walletUpLogin.strings.verifyingCredentials,
		walletUpLogin.strings.preparingDashboard,
		walletUpLogin.strings.almostThere
	  ] : [
		walletUpLogin.strings.verifyingCredentials || 'Verifying your credentials...',
		walletUpLogin.strings.preparingDashboard || 'Preparing your dashboard...',
		walletUpLogin.strings.almostThere || 'Almost there...'
	  ]
	};
	
	// Initialize when the DOM is fully loaded
	$(document).ready(function() {
	  // Only log in development mode
	  if (typeof walletUpLogin !== 'undefined' && walletUpLogin.debug) {
		console.log('Wallet Up Login JS initialized');
	  }
	  
	  // Load configuration from global variable if available
	  if (typeof walletUpLoginConfig !== 'undefined') {
		$.extend(config, walletUpLoginConfig);
	  }
	  
	  // Primary initialization functions
	  initializeLoginPage();
	  
	  // Setup event handlers and interactive elements
	  setupEventHandlers();
	  
	  // Set up custom events for extensibility
	  setupCustomEvents();
	});
	
	/**
	 * Initialize login page elements and structure
	 */
	function initializeLoginPage() {
	  // First fix any structural issues
	  fixLoginStructure();
	  
	  // Then enhance the form
	  enhanceLoginForm();
	  
	  // Setup styling and animations
	  setupStylingAndAnimations();
	  
	  // Add enhanced validation
	  setupFormValidation();
	  
	  // Add AJAX login
	  if (config.enableAjaxLogin) {
		setupAjaxLogin();
	  }
	  
	  // Setup action screen
	  createActionScreen();
	  
	  // Add accessibility enhancements
	  enhanceAccessibility();
	  
	  // Enhance password field
	  enhancePasswordField();
	  
	  // Check URL parameters for notices or actions
	  handleUrlParameters();
	}
	
	/**
	 * Handle URL parameters for notices or actions
	 */
	function handleUrlParameters() {
	  const urlParams = new URLSearchParams(window.location.search);
	  
	  // Handle login errors from redirect
	  if (urlParams.has('login') && urlParams.get('login') === 'failed') {
		showAlert('error', 'Login failed. Please check your credentials and try again.');
	  }
	  
	  // Handle logout success
	  if (urlParams.has('loggedout') && urlParams.get('loggedout') === 'true') {
		showAlert('success', 'You have been successfully logged out.');
	  }
	  
	  // Handle password reset success
	  if (urlParams.has('password_reset') && urlParams.get('password_reset') === 'true') {
		showAlert('success', 'Your password has been reset successfully.');
	  }
	  
	  // Show welcome back message if returning from a previous session
	  if (urlParams.has('welcome_back') && urlParams.get('welcome_back') === 'true') {
		showAlert('info', walletUpLogin.strings.welcomeBack || 'Welcome back! Please sign in to continue.');
	  }
	}
	
	/**
	 * Setup custom events for extensibility
	 */
	function setupCustomEvents() {
	  // Define custom events that can be hooked into
	  const events = {
		'wallet-up:login-initialized': {},
		'wallet-up:login-success': { detail: { user: null, redirect: '' } },
		'wallet-up:login-error': { detail: { message: '' } },
		'wallet-up:validation-success': { detail: { field: '' } },
		'wallet-up:validation-error': { detail: { field: '', message: '' } }
	  };
	  
	  // Register all custom events
	  Object.keys(events).forEach(eventName => {
		$(document).on(eventName, function(e, detail) {
		  // Allow third-party code to hook into our events
		  if (typeof detail === 'object') {
			$.extend(events[eventName].detail, detail);
		  }
		});
	  });
	  
	  // Trigger initialized event
	  $(document).trigger('wallet-up:login-initialized');
	}
	
	/**
	 * Fix login page structure issues
	 */
	function fixLoginStructure() {
	  // Fix logo display issues
	  fixLogoDisplay();
	  
	  // Fix input field structure
	  fixInputFieldStructure();
	  
	  // Ensure login button is visible
	  ensureLoginButtonVisible();
	  
	  // Fix password visibility toggle
	  fixPasswordVisibilityToggle();
	  
	  // Convert standard WordPress messages to our format
	  convertStandardMessages();
	  
	  // Fix for any browser-specific issues
	  applyBrowserFixes();
	}
	
	/**
	 * Apply browser-specific fixes
	 */
	function applyBrowserFixes() {
	  // Fix for iOS zooming on input focus
	  if (/iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream) {
		$('meta[name="viewport"]').attr('content', 'width=device-width, initial-scale=1, maximum-scale=1');
	  }
	  
	  // Fix for Firefox autofill styles
	  if (navigator.userAgent.indexOf('Firefox') !== -1) {
		setTimeout(function() {
		  $('input:-webkit-autofill').each(function() {
			var $input = $(this);
			var $wrapper = $input.closest('.user-login-wrap, .user-pass-wrap');
			
			$wrapper.addClass('has-value');
		  });
		}, 500);
	  }
	  
	  // Fix for Edge and IE specific issues
	  if (/Edge\/|Trident\/|MSIE /.test(navigator.userAgent)) {
		$('.login input[type=text], .login input[type=password]').css('padding-top', '18px');
	  }
	}
	
	/**
	 * Fix logo display issues
	 */
	function fixLogoDisplay() {
	  // Check if the logo exists properly
	  var logoUrl = (typeof walletUpLogin !== 'undefined' && walletUpLogin.logoUrl) 
		? walletUpLogin.logoUrl 
		: '../img/walletup-icon.png';
	  
	  // Check if the logo is loaded properly
	  var logoImg = new Image();
	  logoImg.onload = function() {
		// Logo loaded successfully - no need to log in production
	  };
	  
	  logoImg.onerror = function() {
		// Logo failed to load, using fallback - handle silently in production
		// Use inline SVG as fallback
		$('.login h1 a').css('background-image', 'url("data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'24\' height=\'24\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%23674FBF\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'><path d=\'M5 8V5c0-1 1-2 2-2h10c1 0 2 1 2 2v3\'/><path d=\'M19 16v3c0 1-1 2-2 2H7c-1 0-2-1-2-2v-3\'/><line x1=\'12\' x2=\'12\' y1=\'4\' y2=\'20\'/></svg>")');
	  };
	  
	  // Update the logo with correct path
	  logoImg.src = logoUrl;
	  $('.login h1 a').css({
		'background-image': 'url("' + logoUrl + '")',
		'background-size': '80px',
		'width': '100px',
		'height': '100px',
		'margin-bottom': '10px'
	  });
	  
	  // Add space between logo and form
	  $('.login h1').css('margin-bottom', '45px');
	  
	  // Create logo if it doesn't exist
	  if ($('.login h1 a').length === 0) {
		$('.login h1').html('<a href="' + (typeof walletUpLogin !== 'undefined' ? walletUpLogin.homeUrl : '/') + '"></a>');
		$('.login h1 a').css({
		  'background-image': 'url("' + logoUrl + '")',
		  'background-size': '80px',
		  'width': '100px',
		  'height': '100px',
		  'display': 'block',
		  'margin': '0 auto 10px'
		});
	  }
	}
	
	/**
	 * Fix input field structure
	 */
	function fixInputFieldStructure() {
	  // Skip if form is already pre-structured by PHP
	  if ($('#loginform').attr('data-pre-structured') === 'true') {
		return;
	  }
	  
	  // Fix username field structure
	  if ($('#user_login').parent('.user-login-wrap').length === 0) {
		var $userLogin = $('#user_login');
		var $label = $('label[for="user_login"]');
		var $wrapper = $('<div class="user-login-wrap"></div>');
		
		if ($label.length) {
		  $label.after($wrapper);
		} else {
		  $userLogin.before($wrapper);
		}
		
		$userLogin.appendTo($wrapper);
	  }
	  
	  // Fix password field structure
	  if ($('#user_pass').parents('.user-pass-wrap').length === 0) {
		var $userPass = $('#user_pass');
		var $label = $('label[for="user_pass"]');
		var $wrapper = $('<div class="user-pass-wrap"></div>');
		
		if ($label.length) {
		  $label.after($wrapper);
		} else {
		  $userPass.before($wrapper);
		}
		
		$userPass.appendTo($wrapper);
		$('.wp-hide-pw').appendTo($wrapper);
	  }
	  
	  // Add field validation state indicators
	  $('.user-login-wrap, .user-pass-wrap').each(function() {
		var $wrapper = $(this);
		
		// Only add indicators if they don't already exist
		if ($wrapper.find('.input-field-state.success').length === 0) {
		  $wrapper.append('<div class="input-field-state success">' +
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
			  '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>' +
			  '<polyline points="22 4 12 14.01 9 11.01"></polyline>' +
			'</svg>' +
		  '</div>');
		}
		
		if ($wrapper.find('.input-field-state.error').length === 0) {
		  $wrapper.append('<div class="input-field-state error">' +
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
			  '<circle cx="12" cy="12" r="10"></circle>' +
			  '<line x1="12" y1="8" x2="12" y2="12"></line>' +
			  '<line x1="12" y1="16" x2="12.01" y2="16"></line>' +
			'</svg>' +
		  '</div>');
		}
		
		if ($wrapper.find('.input-field-state.loading').length === 0) {
		  $wrapper.append('<div class="input-field-state loading">' +
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
			  '<line x1="12" y1="2" x2="12" y2="6"></line>' +
			  '<line x1="12" y1="18" x2="12" y2="22"></line>' +
			  '<line x1="4.93" y1="4.93" x2="7.76" y2="7.76"></line>' +
			  '<line x1="16.24" y1="16.24" x2="19.07" y2="19.07"></line>' +
			  '<line x1="2" y1="12" x2="6" y2="12"></line>' +
			  '<line x1="18" y1="12" x2="22" y2="12"></line>' +
			  '<line x1="4.93" y1="19.07" x2="7.76" y2="16.24"></line>' +
			  '<line x1="16.24" y1="7.76" x2="19.07" y2="4.93"></line>' +
			'</svg>' +
		  '</div>');
		}
	  });
	  
	  // Add animated labels
	  addAnimatedLabels();
	  
	  // Fix input field styling - only if not pre-structured
	  if ($('#loginform').attr('data-pre-structured') !== 'true') {
		$('#user_login, #user_pass').css({
		  'height': '45px',
		  'padding': '12px 16px',
		  'padding-left': '44px',
		  'box-sizing': 'border-box'
		});
	  }
	  
	  // Check for autofilled fields
	  setTimeout(function() {
		$('#user_login, #user_pass').each(function() {
		  if ($(this).val() !== '') {
			$(this).closest('.user-login-wrap, .user-pass-wrap').addClass('has-value');
		  }
		});
	  }, 100);
	}
	
	/**
	 * Add animated labels to input fields
	 */
	function addAnimatedLabels() {
	  // Username field
	  $('.user-login-wrap').each(function() {
		var $wrapper = $(this);
		var $input = $wrapper.find('input');
		
		if ($wrapper.find('.wallet-up-animated-label').length === 0) {
		  $wrapper.addClass('has-animated-label');
		  var usernameLabel = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.username) ? walletUpLogin.strings.username : 'Username';
		  $wrapper.append('<span class="wallet-up-animated-label">' + usernameLabel + '</span>');
		  
		  // Check if field has a value on load
		  if ($input.val().trim().length > 0) {
			$wrapper.addClass('has-value has-value-on-load');
			fieldValues.username = $input.val().trim();
		  }
		}
	  });
	  
	  // Password field
	  $('.user-pass-wrap').each(function() {
		var $wrapper = $(this);
		var $input = $wrapper.find('input#user_pass');
		
		if ($wrapper.find('.wallet-up-animated-label').length === 0) {
		  $wrapper.addClass('has-animated-label');
		  
		  if ($wrapper.find('.wp-pwd').length) {
			var passwordLabel = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.password) ? walletUpLogin.strings.password : 'Password';
			$wrapper.find('.wp-pwd').before('<span class="wallet-up-animated-label">' + passwordLabel + '</span>');
		  } else {
			var passwordLabel = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.password) ? walletUpLogin.strings.password : 'Password';
			$wrapper.append('<span class="wallet-up-animated-label">' + passwordLabel + '</span>');
		  }
		  
		  // Check if field has a value on load
		  if ($input.val().trim().length > 0) {
			$wrapper.addClass('has-value has-value-on-load');
			fieldValues.password = $input.val().trim();
		  }
		}
	  });
	}
	
	/**
	 * Ensure the login button is visible
	 */
	function ensureLoginButtonVisible() {
	  if ($('#wp-submit').length) {
		$('#wp-submit').css({
		  'display': 'block',
		  'opacity': '1',
		  'visibility': 'visible'
		});
		
		$('.submit').css({
		  'display': 'block',
		  'opacity': '1',
		  'visibility': 'visible',
		  'margin-top': '24px'
		});
	  }
	}
	
	/**
	 * Fix password visibility toggle position
	 */
	function fixPasswordVisibilityToggle() {
	  // Wait for everything to be rendered
	  setTimeout(function() {
		var $passwordField = $('#user_pass');
		
		if ($passwordField.length) {
		  $('.button.wp-hide-pw').css({
			'position': 'absolute',
			'top': '50%',
			'right': '10px',
			'transform': 'translateY(-50%)',
			'background': 'transparent',
			'border': 'none',
			'box-shadow': 'none',
			'z-index': '100'
		  });
		}
	  }, 100);
	}
	
	/**
	 * Enhance the login form with custom elements
	 */
	function enhanceLoginForm() {
	  // Add title to the form if not already added
	  if ($('#loginform .wallet-up-form-title').length === 0) {
		var formTitle = 'Welcome to the Next \'Up';
		
		// Use site name if available
		if (typeof walletUpLogin !== 'undefined' && walletUpLogin.siteName) {
		  var welcomeText = (typeof walletUpLogin.strings !== 'undefined' && walletUpLogin.strings.welcomeTo) ? walletUpLogin.strings.welcomeTo : 'Welcome to';
		  formTitle = welcomeText + ' ' + walletUpLogin.siteName;
		}
		
		$('#loginform, #lostpasswordform, #registerform').prepend(
		  '<div class="wallet-up-login-customizer-logo"></div>' +
		  '<h2 class="wallet-up-form-title">' + formTitle + '</h2>'
		);
	  }
	  
	  // Add loading indicator if not exists
	  if ($('.login-success-animation').length === 0) {
		$('<div class="login-success-animation">' +
		  '<div class="success-checkmark">' +
			'<div class="check-icon">' +
			  '<span class="icon-line line-tip"></span>' +
			  '<span class="icon-line line-long"></span>' +
			  '<div class="icon-circle"></div>' +
			  '<div class="icon-fix"></div>' +
			'</div>' +
		  '</div>' +
		'</div>').appendTo('body');
	  }
	  
	  // Add ripple effect to buttons
	  $('#wp-submit, .button-primary, .button-secondary').addClass('wallet-up-ripple');
	  
	  // Customize button text
	  if ($('#wp-submit').length && $('#wp-submit').val() === 'Log In') {
		$('#wp-submit').val((typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.signInSecurely) ? walletUpLogin.strings.signInSecurely : 'Sign In Securely');
	  }
	  
	  // Enhance navigation links with icons
	  if ($('#nav a').length && !$('#nav a svg').length) {
		var resetText = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.resetPassword) ? walletUpLogin.strings.resetPassword : 'Reset Password';
		$('#nav a').html('<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1" style="display: inline-block; vertical-align: -2px; margin-right: 5px;"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg> ' + resetText);
	  }
	  
	  if ($('#backtoblog a').length && !$('#backtoblog a svg').length) {
		// Retrieve site name from walletUpLogin or fallback to <title> or default
		var siteName = (typeof walletUpLogin !== 'undefined' && walletUpLogin.siteName) 
		  ? walletUpLogin.siteName 
		  : (document.title.split('–')[0] || document.title.split(' - ')[0] || 'Home').trim();
		var backToText = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.backToSite) ? walletUpLogin.strings.backToSite : 'Back to ' + siteName;
		$('#backtoblog a').html('<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1" style="display: inline-block; vertical-align: -2px; margin-right: 5px;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg> ' + backToText);
	  }
	  
	  // Add hidden AJAX nonce field to form if it doesn't exist
	  if ($('#wallet-up-login-customizer-nonce').length === 0) {
		var nonce = '';
		if (typeof walletUpLogin !== 'undefined' && walletUpLogin.nonce) {
		  nonce = walletUpLogin.nonce;
		}
		$('#loginform').append('<input type="hidden" name="security" id="wallet-up-login-customizer-nonce" value="' + nonce + '">');
	  }
	  
	  // Add additional hidden fields for redirect handling
	  if ($('#wallet-up-redirect-to').length === 0) {
		var redirectTo = '';
		
		// Check URL for redirect_to parameter
		var urlParams = new URLSearchParams(window.location.search);
		if (urlParams.has('redirect_to')) {
		  redirectTo = urlParams.get('redirect_to');
		} else if (typeof walletUpLogin !== 'undefined' && walletUpLogin.adminUrl) {
		  redirectTo = walletUpLogin.adminUrl;
		}
		
		$('#loginform').append('<input type="hidden" name="redirect_to" id="wallet-up-redirect-to" value="' + redirectTo + '">');
	  }
	  
	  // Add remember me field if it doesn't exist
	  if ($('.forgetmenot').length === 0) {
		var $submitWrapper = $('#wp-submit').closest('.submit');
		var rememberMeText = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.rememberMe) ? walletUpLogin.strings.rememberMe : 'Remember Me';
		$submitWrapper.before('<p class="forgetmenot"><label><input name="rememberme" type="checkbox" id="rememberme" value="forever"> ' + rememberMeText + '</label></p>');
	  }
	}
	
	/**
	 * Setup styling and animations
	 */
	function setupStylingAndAnimations() {
	  // Apply hover effect to cards and buttons
	  $('.login form, #nav a, #backtoblog a').addClass('wallet-up-hover-effect');
	  
	  // Initialize animations
	  initAnimations();
	}
	
	/**
	 * Initialize animations
	 */
	function initAnimations() {
	  // Animate floating shapes
	  animateFloatingShapes();
	  
	  // Add mouse movement effect
	  addMouseMoveEffect();
	  
	  // Animate form elements on load
	  animateFormElements();
	}
	
	/**
	 * Animate floating shapes in the background
	 */
	function animateFloatingShapes() {
	  if (typeof wulca !== 'undefined') {
		// Create timeline
		animations.floatingShapes = wulca.timeline();
		
		// Animate background shapes
		animations.floatingShapes.to('.animated-shape', {
		  x: 'random(-40, 40)',
		  y: 'random(-40, 40)',
		  scale: 'random(0.95, 1.05)',
		  duration: 'random(20, 30)',
		  ease: 'sine.inOut',
		  repeat: -1,
		  yoyo: true,
		  stagger: 1
		});
		
		// Animate floating shapes
		animations.floatingShapes.to('.floating-shape', {
		  y: 'random(-50, 50)',
		  x: 'random(-20, 20)',
		  rotation: 'random(-15, 15)',
		  duration: 'random(10, 20)',
		  ease: 'sine.inOut',
		  repeat: -1,
		  yoyo: true,
		  stagger: 0.5
		}, 0);
	  }
	}
	
	/**
	 * Add parallax effect on mouse movement
	 */
	function addMouseMoveEffect() {
	  $(document).mousemove(function(e) {
		if (window.requestAnimationFrame) {
		  window.requestAnimationFrame(function() {
			processMouseMovement(e);
		  });
		} else {
		  processMouseMovement(e);
		}
	  });
	  
	  function processMouseMovement(e) {
		var windowWidth = $(window).width();
		var windowHeight = $(window).height();
		
		// Calculate mouse position percentage
		var mouseXpercentage = Math.round((e.pageX / windowWidth) * 100);
		var mouseYpercentage = Math.round((e.pageY / windowHeight) * 100);
		
		// Move the shapes based on mouse position
		$('.animated-shape').css({
		  'transform': 'translate(' + mouseXpercentage / 50 + 'px, ' + mouseYpercentage / 50 + 'px)'
		});
		
		// Move floating shapes with different values for depth
		$('.floating-shape').each(function(index) {
		  var depth = (index + 3) * 10;
		  $(this).css({
			'transform': 'translate(' + mouseXpercentage / depth + 'px, ' + mouseYpercentage / depth + 'px)'
		  });
		});
	  }
	}
	
	/**
	 * Animate form elements on page load
	 */
	function animateFormElements() {
	  if (typeof wulca !== 'undefined') {
		// Create timeline
		animations.formElements = wulca.timeline();
		
		// Animate form fields
		animations.formElements.from('.user-login-wrap, .user-pass-wrap', {
		  y: 20,
		  opacity: 0,
		  duration: 0.5,
		  stagger: 0.1,
		  delay: 0.3,
		  ease: 'power2.out'
		});
		
		// Animate remember me checkbox
		animations.formElements.from('.forgetmenot', {
		  y: 10,
		  opacity: 0,
		  duration: 0.4,
		  ease: 'power2.out'
		}, '-=0.2');
		
		// Animate submit button
		animations.formElements.from('#wp-submit, .submit', {
		  y: 10,
		  opacity: 0,
		  duration: 0.4,
		  ease: 'power2.out'
		}, '-=0.2');
		
		// Animate nav links
		animations.formElements.from('#nav, #backtoblog', {
		  y: 10,
		  opacity: 0,
		  duration: 0.4,
		  stagger: 0.1,
		  ease: 'power2.out'
		}, '-=0.2');
	  } else {
		// Fallback for browsers without WULCA - use CSS animations
		$('.user-login-wrap, .user-pass-wrap').css({
		  'animation': 'fadeInUp 0.5s ease-out forwards',
		  'animation-delay': 'calc(0.3s + var(--i, 0) * 0.1s)',
		  'opacity': '0',
		  'transform': 'translateY(20px)'
		}).each(function(index) {
		  $(this).css('--i', index);
		});
		
		$('.forgetmenot, #wp-submit, .submit, #nav, #backtoblog').css({
		  'animation': 'fadeInUp 0.4s ease-out forwards',
		  'animation-delay': 'calc(0.5s + var(--i, 0) * 0.1s)',
		  'opacity': '0',
		  'transform': 'translateY(10px)'
		}).each(function(index) {
		  $(this).css('--i', index);
		});
	  }
	}
	
	/**
	 * Setup form validation
	 */
	function setupFormValidation() {
	  // Track user interaction
	  var userInteracted = false;
	  
	  // Setup debounced validation
	  var typingTimer;
	  var doneTypingInterval = 300;
	  
	  $('#user_login, #user_pass').on('keyup', function() {
		userInteracted = true;
		var $input = $(this);
		var $wrapper = $input.closest('.user-login-wrap, .user-pass-wrap');
		var fieldName = $input.attr('id') === 'user_login' ? 'username' : 'password';
		var currentValue = $input.val().trim();
		
		// Clear previous validation messages
		$wrapper.next('.wallet-up-input-message').remove();
		
		// Reset validation classes
		$wrapper.removeClass('is-validating has-success has-error');
		
		// Update has-value class
		if (currentValue.length > 0) {
		  $wrapper.addClass('has-value');
		} else {
		  $wrapper.removeClass('has-value');
		}
		
		// Only validate if configured to validate on type
		if (config.validateOnType) {
		  // Show loading state only when not empty
		  if (currentValue.length > 0) {
			$wrapper.addClass('is-validating');
			
			// Only validate if the value has changed
			if (currentValue !== fieldValues[fieldName]) {
			  fieldValues[fieldName] = currentValue;
			  
			  clearTimeout(typingTimer);
			  typingTimer = setTimeout(function() {
				validateField($input);
			  }, doneTypingInterval);
			}
		  }
		}
	  }).on('keydown', function() {
		clearTimeout(typingTimer);
	  });
	  
	  // Validate on blur only if user interacted
	  $('#user_login, #user_pass').on('blur', function() {
		if (userInteracted) {
		  validateField($(this));
		}
	  });
	  
	  // Focus effects
	  $('#user_login, #user_pass').on('focus', function() {
		userInteracted = true;
		var $wrapper = $(this).closest('.user-login-wrap, .user-pass-wrap');
		$wrapper.addClass('is-focused');
		
		// Check if it has a value for animated label
		if ($(this).val().trim().length > 0) {
		  $wrapper.addClass('has-value');
		}
	  }).on('blur', function() {
		var $wrapper = $(this).closest('.user-login-wrap, .user-pass-wrap');
		$wrapper.removeClass('is-focused');
		
		// Check if it has a value for animated label
		if ($(this).val().trim().length > 0) {
		  $wrapper.addClass('has-value');
		} else {
		  $wrapper.removeClass('has-value');
		}
	  });
	  
	  // Validate on form submit
	  $('#loginform').on('submit', function(e) {
		if (!config.enableAjaxLogin) {
		  if (!validateForm()) {
			e.preventDefault();
			return false;
		  }
		}
	  });
	}
	
	/**
	 * Validate a field
	 * @param {jQuery} $field - The field to validate
	 * @returns {boolean} - Whether the field is valid
	 */
	function validateField($field) {
	  var fieldId = $field.attr('id');
	  var value = $field.val().trim();
	  var $wrapper = $field.closest('.user-login-wrap, .user-pass-wrap');
	  var $existingMessage = $wrapper.next('.wallet-up-input-message');
	  var isValid = true;
	  var eventDetail = { field: fieldId, message: '' };
	  
	  // Remove existing validation message
	  if ($existingMessage.length) {
		$existingMessage.remove();
	  }
	  
	  // Reset validation classes
	  $wrapper.removeClass('is-validating has-success has-error');
	  
	  // If field is empty, don't show any validation message
	  if (value === '') {
		return false;
	  }
	  
	  // Validate based on field type
	  if (fieldId === 'user_login') {
		if (value.length < 3) {
		  isValid = false;
		  $wrapper.addClass('has-error');
		  var usernameTooShortMsg = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.usernameTooShort) ? walletUpLogin.strings.usernameTooShort : 'Username must be at least 3 characters';
		  $wrapper.after(createValidationMessage('error', usernameTooShortMsg));
		  eventDetail.message = usernameTooShortMsg;
		} else {
		  // Mark as validated
		  $wrapper.addClass('has-success');
		}
	  } else if (fieldId === 'user_pass') {
		if (value.length < 6) {
		  // We'll mark it as valid but warn about weak password
		  $wrapper.addClass('has-success');
		  var strongerPasswordMsg = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.considerStrongerPassword) ? walletUpLogin.strings.considerStrongerPassword : 'Consider using a stronger password';
		  $wrapper.after(createValidationMessage('info', strongerPasswordMsg));
		  eventDetail.message = strongerPasswordMsg;
		} else {
		  // Mark as validated
		  $wrapper.addClass('has-success');
		}
	  }
	  
	  // Add animation to message
	  var $message = $wrapper.next('.wallet-up-input-message');
	  
	  if ($message.length) {
		animateElement($message, 'fadeInDown', 0.3);
	  }
	  
	  // Trigger custom event
	  if (isValid) {
		$(document).trigger('wallet-up:validation-success', { field: fieldId });
	  } else {
		$(document).trigger('wallet-up:validation-error', eventDetail);
	  }
	  
	  return isValid;
	}
	
	/**
	 * Create a validation message element
	 * @param {string} type - Message type (error, success, info)
	 * @param {string} message - Message text
	 * @returns {jQuery} - The created message element
	 */
	function createValidationMessage(type, message) {
	  var icon = '';
	  
	  // Set icon based on type
	  switch (type) {
		case 'error':
		  icon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
		  break;
		case 'success':
		  icon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';
		  break;
		case 'info':
		  icon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';
		  break;
	  }
	  
	  return $('<div class="wallet-up-input-message ' + type + '">' + icon + message + '</div>');
	}
	
	/**
	 * Validate the entire form
	 * @returns {boolean} - Whether the form is valid
	 */
	function validateForm() {
	  var isValid = true;
	  
	  // Validate each field
	  $('#user_login, #user_pass').each(function() {
		if (!validateField($(this))) {
		  isValid = false;
		}
	  });
	  
	  // Visual feedback if not valid
	  if (!isValid) {
		$('#loginform').addClass('enhanced-error-feedback');
		setTimeout(function() {
		  $('#loginform').removeClass('enhanced-error-feedback');
		}, 600);
		
		showAlert('error', 'Please correct the errors before signing in.');
	  }
	  
	  return isValid;
	}
	
	/**
	 * Setup AJAX login
	 */
	function setupAjaxLogin() {
	  // Handle form submission
	  $('#loginform').on('submit', function(e) {
		// Check if nonce exists for AJAX login
		if ($('#wallet-up-login-customizer-nonce').length || (typeof walletUpLogin !== 'undefined' && walletUpLogin.nonce)) {
		  // Prevent default form submission for AJAX
		  e.preventDefault();
		  
		  // Prevent double submission
		  if (loginState.isSubmitting) {
			return false;
		  }
		  
		  // Reset state
		  loginState = {
			isValidating: true,
			isSubmitting: true,
			isSuccess: false,
			isError: false,
			errorMessage: '',
			redirectUrl: $('#wallet-up-redirect-to').val() || ''
		  };
		  
		  // Remove any existing messages
		  $('.wallet-up-alert').remove();
		  
		  // Show loading action screen first (so it appears on retry)
		  showActionScreen('loading', getRandomLoadingMessage(), 20);
		  
		  // Validate form
		  if (!validateForm()) {
			// Hide the action screen if validation fails
			hideActionScreen();
			// Reset button state
			$('#wp-submit')
			  .removeClass('is-loading')
			  .prop('disabled', false)
			  .attr('aria-disabled', 'false');
			loginState.isValidating = false;
			loginState.isSubmitting = false;
			return false;
		  }
		  
		  // Disable and animate button
		  $('#wp-submit')
			.addClass('is-loading')
			.prop('disabled', true)
			.attr('aria-disabled', 'true');
		  
		  // Add loading animation to button
		  animateButtonLoading($('#wp-submit'));
		  
		  // Get form data
		  var username = $('#user_login').val();
		  var password = $('#user_pass').val();
		  var remember = $('#rememberme').is(':checked');
		  var security = $('#wallet-up-login-customizer-nonce').val() || (walletUpLogin ? walletUpLogin.nonce : '');
		  var redirectTo = $('#wallet-up-redirect-to').val() || '';
		  
		  // Update progress
		  updateProgress(40);
		  
		  // Prepare the data
		  var data = {
			action: 'wallet_up_ajax_login',
			username: username,
			password: password,
			remember: remember,
			security: security,
			redirect_to: redirectTo
		  };
		  
		  // Perform AJAX login
		  $.ajax({
			type: 'POST',
			dataType: 'json',
			url: walletUpLogin ? walletUpLogin.ajaxUrl : ajaxurl,
			data: data,
			beforeSend: function(xhr) {
			  // Request sent
			},
			complete: function(xhr, status) {
			  // Request completed
			},
			success: function(response) {
			  // Update progress
			  updateProgress(70);
			  
			  setTimeout(function() {
				if (response.success) {
				  // Update progress to 100%
				  updateProgress(100);
				  
				  // Store the redirect URL
				  loginState.redirectUrl = response.data.redirect || redirectTo || (walletUpLogin ? walletUpLogin.adminUrl : '/wp-admin/');
				  
				  // Show success state, keep disabled
				  $('#wp-submit')
					.removeClass('is-loading')
					.addClass('is-success')
					.prop('disabled', true) // Ensure remains disabled
					.attr('aria-disabled', 'true');
				  
				  // Add success animation to button
				  animateButtonSuccess($('#wp-submit'));
				  
				  // Get personalized welcome message
				  var successMessage = response.data.message || walletUpLogin.strings.welcomeBackSuccess || 'Welcome back! You have successfully signed in.';
				  
				  // Show success message
				  showActionScreen('success', successMessage, 100);
				  
				  // Update state
				  loginState.isSuccess = true;
				  loginState.isSubmitting = false;
				  loginState.isValidating = false;
				  
				  // Add success pulse to form
				  pulseSuccessEffect($('#loginform'));
				  
				  // Trigger success event
				  $(document).trigger('wallet-up:login-success', { 
					user: username,
					redirect: loginState.redirectUrl 
				  });
				  
				  // Auto redirect after delay
				  setTimeout(function() {
					window.location.href = loginState.redirectUrl;
				  }, config.redirectDelay);
				} else {
				  // Update progress to show error
				  updateProgress(100);
				  
				  // Show error, re-enable button with proper state reset
				  $('#wp-submit')
					.removeClass('is-loading is-success')
					.prop('disabled', false)
					.attr('aria-disabled', 'false');
				  
				  // Add error animation to button
				  animateButtonError($('#wp-submit'));
				  
				  // Reset all form states to prevent animation conflicts
				  $('.user-login-wrap, .user-pass-wrap').removeClass('is-validating has-success has-error');
				  
				  // Clear any existing WordPress error messages first
				  $('#login_error, .message, .notice').remove();
				  
				  // Show error message
				  var errorMessage = response.data.message || 'Invalid username or password. Please try again.';
				  showActionScreen('error', errorMessage, 100);
				  
				  // Update state
				  loginState.isError = true;
				  loginState.errorMessage = errorMessage;
				  loginState.isSubmitting = false;
				  loginState.isValidating = false;
				  
				  // Apply error class to form with enhanced reset
				  $('#loginform').addClass('enhanced-error-feedback');
				  setTimeout(function() {
					$('#loginform').removeClass('enhanced-error-feedback');
					// Force re-initialization of animations if needed
					if (typeof wulca !== 'undefined' && animations.formElements) {
					  animations.formElements.invalidate();
					}
				  }, 600);
				  
				  // Trigger error event
				  $(document).trigger('wallet-up:login-error', { 
					message: errorMessage 
				  });
				}
			  }, 800); // Slight delay for better UX
			},
			error: function(xhr, status, error) {
			  // Update progress to show error
			  updateProgress(100);
			  
			  // Show error, re-enable button with proper state reset
			  $('#wp-submit')
				.removeClass('is-loading is-success')
				.prop('disabled', false)
				.attr('aria-disabled', 'false');
			  
			  // Add error animation to button
			  animateButtonError($('#wp-submit'));
			  
			  // Reset all form states to prevent animation conflicts
			  $('.user-login-wrap, .user-pass-wrap').removeClass('is-validating has-success has-error');
			  
			  // Clear any existing WordPress error messages first
			  $('#login_error, .message, .notice').remove();
			  
			  // Show error message with more details
			  var errorMessage = 'Server error. Please try again later.';
			  
			  // Try to get more specific error information
			  if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
				errorMessage = xhr.responseJSON.data.message;
			  }
			  
			  showActionScreen('error', errorMessage, 100);
			  
			  // Update state
			  loginState.isError = true;
			  loginState.errorMessage = errorMessage;
			  loginState.isSubmitting = false;
			  loginState.isValidating = false;
			  
			  // Show error animation with enhanced reset
			  $('#loginform').addClass('enhanced-error-feedback');
			  setTimeout(function() {
				$('#loginform').removeClass('enhanced-error-feedback');
				// Force re-initialization of animations if needed
				if (typeof wulca !== 'undefined' && animations.formElements) {
				  animations.formElements.invalidate();
				}
			  }, 600);
			  
			  // Log error only in debug mode
			  if (typeof walletUpLogin !== 'undefined' && walletUpLogin.debug) {
				console.error('Login error:', xhr, status, error);
			  }
			  
			  // Trigger error event
			  $(document).trigger('wallet-up:login-error', { 
				message: errorMessage,
				xhr: xhr,
				status: status,
				error: error
			  });
			}
		  });
		}
	  });
	}
	
	/**
	 * Create action screen for showing loading, success, and error states
	 */
	function createActionScreen() {
	  if ($('.wallet-up-action-screen').length === 0) {
		var actionScreen = $('<div class="wallet-up-action-screen" role="dialog" aria-modal="true" aria-labelledby="action-screen-title" aria-describedby="action-screen-message">' +
		  '<div class="action-screen-content">' +
			'<div class="action-screen-icon">' +
			  '<div class="action-loading-spinner"></div>' +
			'</div>' +
			'<h2 id="action-screen-title" class="action-screen-title">' + ((typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.signingIn) ? walletUpLogin.strings.signingIn : 'Signing In') + '</h2>' +
			'<p id="action-screen-message" class="action-screen-message">' + ((typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.verifyingCredentials) ? walletUpLogin.strings.verifyingCredentials : 'Verifying your credentials...') + '</p>' +
			'<div class="action-progress">' +
			  '<div class="action-progress-bar"></div>' +
			'</div>' +
			'<button type="button" class="action-screen-button">Continue</button>' +
		  '</div>' +
		'</div>');
		
		$('body').append(actionScreen);
	  }
	}
	
	/**
	 * Get random loading message
	 * @returns {string} - Random loading message
	 */
	function getRandomLoadingMessage() {
	  if (config.loadingMessages && config.loadingMessages.length > 0) {
		var randomIndex = Math.floor(Math.random() * config.loadingMessages.length);
		return config.loadingMessages[randomIndex];
	  }
	  return (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.verifyingCredentials) ? walletUpLogin.strings.verifyingCredentials : 'Verifying your credentials...';
	}
	
	/**
	 * Show action screen with specific state
	 * @param {string} state - 'loading', 'success', or 'error'
	 * @param {string} message - Message to display
	 * @param {number} progress - Progress percentage (0-100)
	 */
	function showActionScreen(state, message, progress) {
	  // Prevent duplicate error messages within 2 seconds
	  if (state === 'error' && message) {
		var currentTime = Date.now();
		var normalizedMessage = message.toLowerCase().trim();
		
		// Check if same error was shown recently (within 2 seconds)
		if (loginState.lastErrorShown === normalizedMessage && 
			(currentTime - loginState.errorTimestamp) < 2000) {
		  return; // Skip showing duplicate error
		}
		
		// Update error tracking
		loginState.lastErrorShown = normalizedMessage;
		loginState.errorTimestamp = currentTime;
	  }
	  
	  var $screen = $('.wallet-up-action-screen');
	  var $content = $screen.find('.action-screen-content');
	  var $icon = $screen.find('.action-screen-icon');
	  var $title = $screen.find('.action-screen-title');
	  var $message = $screen.find('.action-screen-message');
	  var $progress = $screen.find('.action-progress-bar');
	  var $button = $screen.find('.action-screen-button');
	  
	  // Add active class first to ensure CSS doesn't override our styles
	  $screen.addClass('active');
	  
	  // Make sure the screen is visible and reset all visibility properties
	  $screen.css({
		'display': 'flex',
		'visibility': 'visible',
		'opacity': '1',
		'z-index': '9999'
	  });
	  
	  // Reset content
	  $icon.empty();
	  $button.hide();
	  
	  // Set state-specific content
	  switch (state) {
		case 'loading':
		  $icon.html('<div class="action-loading-spinner"></div>');
		  $title.text((typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.signingIn) ? walletUpLogin.strings.signingIn : 'Signing In');
		  $button.hide();
		  $progress.css('width', (progress || 0) + '%');
		  break;
		  
		case 'success':
		  $icon.html('<svg class="action-success-icon" width="80" height="80" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">' +
			'<circle cx="40" cy="40" r="38" stroke="#10B981" stroke-width="4" fill="none"/>' +
			'<path class="action-success-icon" d="M25 40L35 50L55 30" stroke="#10B981" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>' +
		  '</svg>');
		  var successText = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.success) ? walletUpLogin.strings.success : 'Success!';
	  $title.text(successText);
		  $progress.css('width', '100%');
		  $button.show().text((typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.continueToDashboard) ? walletUpLogin.strings.continueToDashboard : 'Continue to Dashboard');
		  
		  // Add success sound if possible
		  if (config.enableSounds) {
			playSound('success');
		  }
		  break;
		  
		case 'error':
		  $icon.html('<div class="action-error-icon"></div>');
		  var oopsText = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.oops) ? walletUpLogin.strings.oops : 'Oops!';
	  $title.text(oopsText);
		  $progress.css('width', '100%');
		  $button.show().text((typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.tryAgain) ? walletUpLogin.strings.tryAgain : 'Try Again');
		  
		  // Add error sound if possible
		  if (config.enableSounds) {
			playSound('error');
		  }
		  break;
	  }
	  
	  // Set message
	  if (message) {
		// Strip HTML from error messages for security
		var cleanMessage = $('<div>').html(message).text();
		$message.text(cleanMessage);
	  }
	  
	  // Animate content in
	  setTimeout(function() {
		$content.css({
		  'transform': 'translateY(0)',
		  'opacity': '1'
		});
	  }, 50);
	}
	
	/**
	 * Hide action screen
	 */
	function hideActionScreen() {
	  var $screen = $('.wallet-up-action-screen');
	  var $content = $screen.find('.action-screen-content');
	  
	  // Animate out
	  $content.css({
		'transform': 'translateY(20px)',
		'opacity': '0',
		'transition': 'transform 0.3s ease, opacity 0.3s ease'
	  });
	  
	  // Hide screen after animation
	  setTimeout(function() {
		$screen.removeClass('active');
		// Remove inline styles to let CSS handle the hidden state
		$screen.removeAttr('style');
		
		// Reset content and state
		setTimeout(function() {
		  $content.css({
			'transform': '',
			'opacity': '',
			'transition': ''
		  });
		  
		  // Reset modal content to initial loading state
		  resetActionScreen();
		}, 300);
	  }, 300);
	}
	
	/**
	 * Reset action screen to initial loading state
	 */
	function resetActionScreen() {
	  var $screen = $('.wallet-up-action-screen');
	  var $icon = $screen.find('.action-screen-icon');
	  var $title = $screen.find('.action-screen-title');
	  var $message = $screen.find('.action-screen-message');
	  var $button = $screen.find('.action-screen-button');
	  var $progress = $screen.find('.action-progress-bar');
	  
	  // Reset to loading state
	  $icon.html('<div class="action-loading-spinner"></div>');
	  $title.text((typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.signingIn) ? walletUpLogin.strings.signingIn : 'Signing In');
	  $message.text((typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.verifyingCredentials) ? walletUpLogin.strings.verifyingCredentials : 'Verifying your credentials...');
	  $button.text('Continue').hide();
	  $progress.css('width', '0%');
	  
	  // Remove state classes
	  $screen.removeClass('is-success is-error is-loading');
	}
	
	/**
	 * Update progress bar in action screen
	 * @param {number} percentage - Progress percentage (0-100)
	 */
	function updateProgress(percentage) {
	  $('.action-progress-bar').css('width', percentage + '%');
	}
	
	/**
	 * Enhance password field with strength meter and visibility toggle
	 */
	function enhancePasswordField() {
	  var $passwordField = $('#user_pass');
	  var $passwordWrap = $passwordField.closest('.user-pass-wrap');
	  
	  // Only enhance if we have a password field
	  if ($passwordField.length === 0) {
		return;
	  }
	  
	  // Ensure we have show/hide password button
	  if ($('.wp-hide-pw').length === 0) {
		var $hidePwButton = $('<button type="button" class="button wp-hide-pw" aria-label="Show password">' +
		  '<span class="dashicons dashicons-visibility" aria-hidden="true"></span>' +
		'</button>');
		
		$passwordWrap.find('.wp-pwd').length ? 
		  $passwordWrap.find('.wp-pwd').append($hidePwButton) : 
		  $passwordField.after($hidePwButton);
		
		// Attach show/hide password functionality
		$hidePwButton.on('click', function() {
		  var $this = $(this);
		  var $passwordField = $this.prev('input');
		  
		  if ($passwordField.attr('type') === 'password') {
			$passwordField.attr('type', 'text');
			$this.find('.dashicons').removeClass('dashicons-visibility').addClass('dashicons-hidden');
			$this.attr('aria-label', 'Hide password');
		  } else {
			$passwordField.attr('type', 'password');
			$this.find('.dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
			$this.attr('aria-label', 'Show password');
		  }
		  
		  // Re-focus the password field
		  $passwordField.focus();
		});
	  }
	}
	
	/**
	 * Enhance accessibility
	 */
	function enhanceAccessibility() {
	  // Add ARIA labels
	  var usernameLabel = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.username) ? walletUpLogin.strings.username : 'Username';
	  var passwordLabel = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.password) ? walletUpLogin.strings.password : 'Password';
	  $('.user-login-wrap input').attr('aria-label', usernameLabel);
	  $('.user-pass-wrap input').attr('aria-label', passwordLabel);
	  
	  // Add role attributes
	  $('#loginform').attr('role', 'form');
	  $('.wallet-up-alert').attr('role', 'alert');
	  
	  // Ensure alert messages are announced
	  $('.wallet-up-alert').attr('aria-live', 'assertive');
	  
	  // Ensure focus is managed properly
	  var $lastFocused;
	  
	  // Store the last focused element
	  $(document).on('focus', 'button, input, a', function() {
		if (!$('.wallet-up-action-screen').hasClass('active')) {
		  $lastFocused = $(this);
		}
	  });
	  
	  // When action screen is shown, focus the button
	  $('.wallet-up-action-screen').on('transitionend', function() {
		if ($(this).hasClass('active') && $('.action-screen-button').is(':visible')) {
		  $('.action-screen-button').focus();
		}
	  });
	  
	  // When action screen is hidden, restore focus
	  $('.wallet-up-action-screen').on('transitionend', function() {
		if (!$(this).hasClass('active') && $lastFocused && $lastFocused.length) {
		  $lastFocused.focus();
		}
	  });
	  
	  // Add keyboard navigation for forms
	  $('#loginform').on('keydown', function(e) {
		// On enter key in form fields, submit form
		if (e.key === 'Enter' && (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT')) {
		  e.preventDefault();
		  $('#wp-submit').click();
		}
	  });
	}
	
	/**
	 * Convert standard WordPress messages to custom alerts
	 */
	function convertStandardMessages() {
	  $('#login_error, .message').each(function() {
		var $this = $(this);
		var message = $this.text().trim();
		var type = 'info';
		
		if ($this.is('#login_error')) {
		  type = 'error';
		  
		  // Apply error animation to form
		  $('#loginform').addClass('enhanced-error-feedback');
		  setTimeout(function() {
			$('#loginform').removeClass('enhanced-error-feedback');
		  }, 600);
		} else if ($this.hasClass('updated')) {
		  type = 'success';
		} else if (message.toLowerCase().includes('error')) {
		  type = 'error';
		}
		
		// Create new alert
		showAlert(type, message);
		
		// Hide original message
		$this.hide();
	  });
	}
	
	/**
	 * Show a custom alert
	 * @param {string} type - Alert type ('error', 'success', 'info', 'warning')
	 * @param {string} message - Alert message
	 * @param {number} duration - Auto-dismiss duration in ms (0 to disable)
	 * @returns {jQuery} - The created alert element
	 */
	function showAlert(type, message, duration = 5000) {
	  // Remove existing alerts with the same type
	  $('.wallet-up-alert.' + type).remove();
	  
	  // Create icon based on type
	  var icon = '';
	  switch (type) {
		case 'error':
		  icon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="wallet-up-alert-icon" style="color: #EF4444;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
		  break;
		case 'success':
		  icon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="wallet-up-alert-icon" style="color: #10B981;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';
		  break;
		case 'info':
		  icon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="wallet-up-alert-icon" style="color: #3B82F6;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';
		  break;
		case 'warning':
		  icon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="wallet-up-alert-icon" style="color: #F59E0B;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
		  break;
	  }
	  
	  // Create alert
	  var alert = $(
		'<div class="wallet-up-alert ' + type + '" role="alert">' +
		  icon +
		  '<span>' + message + '</span>' +
		  '<button type="button" class="wallet-up-alert-close" aria-label="Close">' +
			'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>' +
		  '</button>' +
		  (duration > 0 ? '<div class="wallet-up-alert-progress"></div>' : '') +
		'</div>'
	  );
	  
	  // Add alert to the page
	  $('#login').prepend(alert);
	  
	  // Animate the alert entrance
	  animateElement(alert, 'fadeInDown', 0.4);
	  
	  // Animate progress bar and auto-dismiss
	  if (duration > 0) {
		var progress = alert.find('.wallet-up-alert-progress');
		
		if (typeof wulca !== 'undefined') {
		  wulca.to(progress, {
			scaleX: 1,
			duration: duration / 1000,
			ease: 'linear',
			onComplete: function() {
			  animateElement(alert, 'fadeOutUp', 0.3, function() {
				alert.remove();
			  });
			}
		  });
		} else {
		  // CSS fallback
		  progress.css({
			'transition': 'transform ' + (duration / 1000) + 's linear',
			'transform': 'scaleX(1)'
		  });
		  
		  setTimeout(function() {
			animateElement(alert, 'fadeOutUp', 0.3, function() {
			  alert.remove();
			});
		  }, duration);
		}
	  }
	  
	  return alert;
	}
	
	/**
	 * Set up event handlers for interactive elements
	 */
	function setupEventHandlers() {
	  // Close alert when clicking close button
	  $(document).on('click', '.wallet-up-alert-close', function() {
		var $alert = $(this).closest('.wallet-up-alert');
		
		animateElement($alert, 'fadeOutUp', 0.3, function() {
		  $alert.remove();
		});
	  });
	  
	  // Add ripple effect to buttons
	  $(document).on('mousedown', '.wallet-up-ripple', function(e) {
		addRippleEffect($(this), e);
	  });
	  
	  // Handle action button click
	  $(document).on('click', '.action-screen-button', function(e) {
		e.preventDefault();
		
		// If in success state and we have a redirect URL, redirect there
		if (loginState.isSuccess && loginState.redirectUrl) {
		  window.location.href = loginState.redirectUrl;
		  return;
		}
		
		hideActionScreen();
		
		// If in error state, clear the error state and reset animations
		if (loginState.isError) {
		  loginState.isError = false;
		  loginState.isSubmitting = false;
		  loginState.isValidating = false;
		  loginState.errorMessage = '';
		  $('#wp-submit').removeClass('is-loading is-success');
		  $('.user-login-wrap, .user-pass-wrap').removeClass('is-validating has-success has-error');
		  
		  // Clear password and focus it for retry
		  $('#user_pass').val('').focus();
		  
		  // Re-initialize animations if available
		  if (typeof wulca !== 'undefined' && animations.formElements) {
			animations.formElements.invalidate();
		  }
		  return;
		}
	  });
	  
	  // Password visibility toggle
	  $(document).on('click', '.wp-hide-pw', function() {
		// Add ripple effect
		addRippleEffect($(this));
	  });
	  
	  // Handle pressing enter key in form fields
	  $('#loginform input').on('keydown', function(e) {
		if (e.key === 'Enter') {
		  e.preventDefault();
		  $('#wp-submit').click();
		}
	  });
	  
	  // Handle browser back button
	  $(window).on('popstate', function() {
		// Close action screen if it's open
		if ($('.wallet-up-action-screen').hasClass('active')) {
		  hideActionScreen();
		}
	  });
	}
	
	/**
	 * Add ripple effect to elements
	 * @param {jQuery} element - Element to add ripple to
	 * @param {Event} e - Click event
	 */
	function addRippleEffect(element, e) {
	  // Remove existing ripple effects
	  element.find('.wallet-up-ripple-effect').remove();
	  
	  var ripple = $('<span class="wallet-up-ripple-effect"></span>');
	  element.append(ripple);
	  
	  var size = Math.max(element.outerWidth(), element.outerHeight());
	  
	  // Get position relative to clicked element
	  var pos = element.offset();
	  var relX = e ? e.pageX - pos.left : element.width() / 2;
	  var relY = e ? e.pageY - pos.top : element.height() / 2;
	  
	  ripple.css({
		width: size + 'px',
		height: size + 'px',
		top: relY - (size / 2) + 'px',
		left: relX - (size / 2) + 'px'
	  });
	  
	  // Remove ripple after animation completes
	  setTimeout(function() {
		ripple.remove();
	  }, 600);
	}
	
	/**
	 * Play a sound effect
	 * @param {string} type - 'success' or 'error'
	 */
	function playSound(type) {
	  // Check if AudioContext is supported
	  if (typeof AudioContext !== 'undefined' || typeof webkitAudioContext !== 'undefined') {
		var AudioContextClass = window.AudioContext || window.webkitAudioContext;
		var audioCtx = new AudioContextClass();
		var oscillator = audioCtx.createOscillator();
		var gainNode = audioCtx.createGain();
		
		oscillator.connect(gainNode);
		gainNode.connect(audioCtx.destination);
		
		if (type === 'success') {
		  // Success sound (rising tone)
		  oscillator.type = 'sine';
		  oscillator.frequency.value = 500;
		  gainNode.gain.value = 0.1;
		  oscillator.frequency.linearRampToValueAtTime(800, audioCtx.currentTime + 0.1);
		  
		  oscillator.start();
		  oscillator.stop(audioCtx.currentTime + 0.15);
		  
		  // Add a second tone for success
		  setTimeout(function() {
			var osc2 = audioCtx.createOscillator();
			osc2.type = 'sine';
			osc2.frequency.value = 800;
			osc2.connect(gainNode);
			osc2.start();
			osc2.stop(audioCtx.currentTime + 0.15);
		  }, 150);
		} else if (type === 'error') {
		  // Error sound (falling tone)
		  oscillator.type = 'sine';
		  oscillator.frequency.value = 400;
		  gainNode.gain.value = 0.1;
		  oscillator.frequency.linearRampToValueAtTime(200, audioCtx.currentTime + 0.1);
		  
		  oscillator.start();
		  oscillator.stop(audioCtx.currentTime + 0.2);
		}
	  }
	}
	
	/**
	 * Animate element with predefined effects
	 * @param {jQuery} element - Element to animate
	 * @param {string} effect - Animation effect name
	 * @param {number} duration - Animation duration in seconds
	 * @param {Function} callback - Callback function after animation
	 */
	function animateElement(element, effect, duration, callback) {
	  // Animation effects
	  var effects = {
		fadeIn: {
		  from: { opacity: 0 },
		  to: { opacity: 1 }
		},
		fadeOut: {
		  from: { opacity: 1 },
		  to: { opacity: 0 }
		},
		fadeInUp: {
		  from: { opacity: 0, y: 20 },
		  to: { opacity: 1, y: 0 }
		},
		fadeOutUp: {
		  from: { opacity: 1, y: 0 },
		  to: { opacity: 0, y: -20 }
		},
		fadeInDown: {
		  from: { opacity: 0, y: -20 },
		  to: { opacity: 1, y: 0 }
		},
		fadeOutDown: {
		  from: { opacity: 1, y: 0 },
		  to: { opacity: 0, y: 20 }
		}
	  };
	  
	  // Get animation params
	  var animation = effects[effect] || effects.fadeIn;
	  
	  if (typeof wulca !== 'undefined') {
		// Use WULCA for animation
		wulca.fromTo(element, 
		  animation.from, 
		  {
			...animation.to,
			duration: duration || 0.4,
			ease: 'power2.out',
			onComplete: function() {
			  if (typeof callback === 'function') {
				callback();
			  }
			}
		  }
		);
	  } else {
		// Use CSS transitions as fallback
		var cssProps = {
		  'transition': 'transform ' + (duration || 0.4) + 's ease-out, opacity ' + (duration || 0.4) + 's ease-out'
		};
		
		// Apply from properties
		if (animation.from.opacity !== undefined) cssProps.opacity = animation.from.opacity;
		if (animation.from.y !== undefined) cssProps.transform = 'translateY(' + animation.from.y + 'px)';
		
		element.css(cssProps);
		
		// Force reflow
		element[0].offsetHeight;
		
		// Apply to properties
		var toProps = {};
		if (animation.to.opacity !== undefined) toProps.opacity = animation.to.opacity;
		if (animation.to.y !== undefined) toProps.transform = 'translateY(' + animation.to.y + 'px)';
		
		element.css(toProps);
		
		// Execute callback after animation
		if (typeof callback === 'function') {
		  setTimeout(callback, (duration || 0.4) * 1000);
		}
	  }
	}
	
	/**
	 * Pulse effect for success
	 * @param {jQuery} element - Element to apply success pulse to
	 */
	function pulseSuccessEffect(element) {
	  if (typeof wulca !== 'undefined') {
		wulca.to(element, {
		  boxShadow: '0 0 0 3px rgba(16, 185, 129, 0.25)',
		  duration: 0.5,
		  repeat: 2,
		  yoyo: true,
		  ease: 'sine.inOut'
		});
	  }
	}
	
	/**
	 * Animate button loading state
	 * @param {jQuery} $button - Button element to animate
	 */
	function animateButtonLoading($button) {
	  // Add loading text and spinner
	  var originalText = $button.val();
	  $button.data('original-text', originalText);
	  
	  // Create loading content
	  var loadingHTML = '<span class="wallet-up-button-spinner"></span><span class="wallet-up-button-text">Signing In...</span>';
	  
	  // For input buttons, we need to handle differently
	  if ($button.is('input')) {
		$button.val('Signing In...');
		
		// Add spinner via CSS pseudo-element
		$button.addClass('wallet-up-loading-button');
	  } else {
		$button.html(loadingHTML);
	  }
	  
	  // Add pulsing animation
	  if (typeof wulca !== 'undefined') {
		wulca.to($button, {
		  scale: 1.02,
		  duration: 0.8,
		  ease: 'sine.inOut',
		  repeat: -1,
		  yoyo: true
		});
	  }
	}
	
	/**
	 * Animate button success state
	 * @param {jQuery} $button - Button element to animate
	 */
	function animateButtonSuccess($button) {
	  // Kill any existing animations
	  if (typeof wulca !== 'undefined') {
		wulca.killTweensOf($button);
	  }
	  
	  // Reset scale
	  $button.css('transform', 'scale(1)');
	  
	  // Update button text
	  if ($button.is('input')) {
		$button.val('Success!').removeClass('wallet-up-loading-button');
	  } else {
		$button.html('<span class="wallet-up-success-icon">✓</span><span class="wallet-up-button-text">Success!</span>');
	  }
	  
	  // Add success animation
	  if (typeof wulca !== 'undefined') {
		// Scale and color animation
		wulca.fromTo($button, 
		  { scale: 1 },
		  { 
			scale: 1.05,
			duration: 0.3,
			ease: 'back.out(1.7)',
			onComplete: function() {
			  wulca.to($button, {
				scale: 1,
				duration: 0.2,
				ease: 'power2.out'
			  });
			}
		  }
		);
		
		// Glow effect
		wulca.to($button, {
		  boxShadow: '0 0 20px rgba(16, 185, 129, 0.5)',
		  duration: 0.5,
		  ease: 'power2.out',
		  onComplete: function() {
			wulca.to($button, {
			  boxShadow: '0 0 0px rgba(16, 185, 129, 0)',
			  duration: 0.5,
			  ease: 'power2.out'
			});
		  }
		});
	  }
	}
	
	/**
	 * Animate button error state
	 * @param {jQuery} $button - Button element to animate
	 */
	function animateButtonError($button) {
	  // Kill any existing animations
	  if (typeof wulca !== 'undefined') {
		wulca.killTweensOf($button);
	  }
	  
	  // Reset scale and remove loading class
	  $button.css('transform', 'scale(1)').removeClass('wallet-up-loading-button');
	  
	  // Restore original text
	  var originalText = $button.data('original-text') || ((typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.signInSecurely) ? walletUpLogin.strings.signInSecurely : 'Sign In Securely');
	  
	  if ($button.is('input')) {
		$button.val(originalText);
	  } else {
		$button.html(originalText);
	  }
	  
	  // Add shake animation
	  if (typeof wulca !== 'undefined') {
		wulca.fromTo($button,
		  { x: 0 },
		  {
			x: -10,
			duration: 0.1,
			ease: 'power2.out',
			repeat: 5,
			yoyo: true,
			onComplete: function() {
			  wulca.set($button, { x: 0 });
			}
		  }
		);
		
		// Flash red border
		wulca.to($button, {
		  borderColor: '#EF4444',
		  duration: 0.3,
		  ease: 'power2.out',
		  repeat: 1,
		  yoyo: true
		});
	  }
	}
	
	/**
	 * Add hover animations to buttons
	 */
	function addButtonHoverAnimations() {
	  $('#wp-submit, .button-primary').on('mouseenter', function() {
		var $this = $(this);
		
		if (!$this.prop('disabled') && typeof wulca !== 'undefined') {
		  wulca.to($this, {
			scale: 1.02,
			duration: 0.2,
			ease: 'power2.out'
		  });
		}
	  }).on('mouseleave', function() {
		var $this = $(this);
		
		if (!$this.prop('disabled') && typeof wulca !== 'undefined') {
		  wulca.to($this, {
			scale: 1,
			duration: 0.2,
			ease: 'power2.out'
		  });
		}
	  });
	}
	
	// Initialize button hover animations when page loads
	$(document).ready(function() {
	  // Add a slight delay to ensure buttons are rendered
	  setTimeout(addButtonHoverAnimations, 100);
	});
	
  })(jQuery);