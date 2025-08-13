(function($) {
	"use strict";
	var animations = {}; 
	var loginState = {
	  isValidating: false,
	  isSubmitting: false,
	  isSuccess: false,
	  isError: false,
	  errorMessage: '',
	  redirectUrl: '' 
	};
	var fieldValues = {
	  username: '',
	  password: ''
	};
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
	$(document).ready(function() {
	  if (typeof walletUpLogin !== 'undefined' && walletUpLogin.debug) {
		console.log('Wallet Up Login JS initialized');
	  }
	  if (typeof walletUpLoginConfig !== 'undefined') {
		$.extend(config, walletUpLoginConfig);
	  }
	  initializeLoginPage();
	  setupEventHandlers();
	  setupCustomEvents();
	});
	function initializeLoginPage() {
	  fixLoginStructure();
	  enhanceLoginForm();
	  setupStylingAndAnimations();
	  setupFormValidation();
	  if (config.enableAjaxLogin) {
		setupAjaxLogin();
	  }
	  createActionScreen();
	  enhanceAccessibility();
	  enhancePasswordField();
	  handleUrlParameters();
	}
	function handleUrlParameters() {
	  const urlParams = new URLSearchParams(window.location.search);
	  if (urlParams.has('login') && urlParams.get('login') === 'failed') {
		showAlert('error', 'Login failed. Please check your credentials and try again.');
	  }
	  if (urlParams.has('loggedout') && urlParams.get('loggedout') === 'true') {
		showAlert('success', 'You have been successfully logged out.');
	  }
	  if (urlParams.has('password_reset') && urlParams.get('password_reset') === 'true') {
		showAlert('success', 'Your password has been reset successfully.');
	  }
	  if (urlParams.has('welcome_back') && urlParams.get('welcome_back') === 'true') {
		showAlert('info', walletUpLogin.strings.welcomeBack || 'Welcome back! Please sign in to continue.');
	  }
	}
	function setupCustomEvents() {
	  const events = {
		'wallet-up:login-initialized': {},
		'wallet-up:login-success': { detail: { user: null, redirect: '' } },
		'wallet-up:login-error': { detail: { message: '' } },
		'wallet-up:validation-success': { detail: { field: '' } },
		'wallet-up:validation-error': { detail: { field: '', message: '' } }
	  };
	  Object.keys(events).forEach(eventName => {
		$(document).on(eventName, function(e, detail) {
		  if (typeof detail === 'object') {
			$.extend(events[eventName].detail, detail);
		  }
		});
	  });
	  $(document).trigger('wallet-up:login-initialized');
	}
	function fixLoginStructure() {
	  fixLogoDisplay();
	  fixInputFieldStructure();
	  ensureLoginButtonVisible();
	  fixPasswordVisibilityToggle();
	  convertStandardMessages();
	  applyBrowserFixes();
	}
	function applyBrowserFixes() {
	  if (/iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream) {
		$('meta[name="viewport"]').attr('content', 'width=device-width, initial-scale=1, maximum-scale=1');
	  }
	  if (navigator.userAgent.indexOf('Firefox') !== -1) {
		setTimeout(function() {
		  $('input:-webkit-autofill').each(function() {
			var $input = $(this);
			var $wrapper = $input.closest('.user-login-wrap, .user-pass-wrap');
			$wrapper.addClass('has-value');
		  });
		}, 500);
	  }
	  if (/Edge\/|Trident\/|MSIE /.test(navigator.userAgent)) {
		$('.login input[type=text], .login input[type=password]').css('padding-top', '18px');
	  }
	}
	function fixLogoDisplay() {
	  var logoUrl = (typeof walletUpLogin !== 'undefined' && walletUpLogin.logoUrl) 
		? walletUpLogin.logoUrl 
		: '../img/walletup-icon.png';
	  var logoImg = new Image();
	  logoImg.onload = function() {
	  };
	  logoImg.onerror = function() {
		$('.login h1 a').css('background-image', 'url("data:image/svg+xml;utf8,<svg xmlns=\'http:
	  };
	  logoImg.src = logoUrl;
	  $('.login h1 a').css({
		'background-image': 'url("' + logoUrl + '")',
		'background-size': '80px',
		'width': '100px',
		'height': '100px',
		'margin-bottom': '10px'
	  });
	  $('.login h1').css('margin-bottom', '45px');
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
	function fixInputFieldStructure() {
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
	  $('.user-login-wrap, .user-pass-wrap').each(function() {
		var $wrapper = $(this);
		if ($wrapper.find('.input-field-state.success').length === 0) {
		  $wrapper.append('<div class="input-field-state success">' +
			'<svg xmlns="http:
			  '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>' +
			  '<polyline points="22 4 12 14.01 9 11.01"></polyline>' +
			'</svg>' +
		  '</div>');
		}
		if ($wrapper.find('.input-field-state.error').length === 0) {
		  $wrapper.append('<div class="input-field-state error">' +
			'<svg xmlns="http:
			  '<circle cx="12" cy="12" r="10"></circle>' +
			  '<line x1="12" y1="8" x2="12" y2="12"></line>' +
			  '<line x1="12" y1="16" x2="12.01" y2="16"></line>' +
			'</svg>' +
		  '</div>');
		}
		if ($wrapper.find('.input-field-state.loading').length === 0) {
		  $wrapper.append('<div class="input-field-state loading">' +
			'<svg xmlns="http:
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
	  addAnimatedLabels();
	  $('#user_login, #user_pass').css({
		'height': '45px',
		'padding': '12px 16px',
		'padding-left': '44px',
		'box-sizing': 'border-box'
	  });
	  setTimeout(function() {
		$('#user_login, #user_pass').each(function() {
		  if ($(this).val() !== '') {
			$(this).closest('.user-login-wrap, .user-pass-wrap').addClass('has-value');
		  }
		});
	  }, 100);
	}
	function addAnimatedLabels() {
	  $('.user-login-wrap').each(function() {
		var $wrapper = $(this);
		var $input = $wrapper.find('input');
		if ($wrapper.find('.wallet-up-animated-label').length === 0) {
		  $wrapper.addClass('has-animated-label');
		  var usernameLabel = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.username) ? walletUpLogin.strings.username : 'Username';
		  $wrapper.append('<span class="wallet-up-animated-label">' + usernameLabel + '</span>');
		  if ($input.val().trim().length > 0) {
			$wrapper.addClass('has-value has-value-on-load');
			fieldValues.username = $input.val().trim();
		  }
		}
	  });
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
		  if ($input.val().trim().length > 0) {
			$wrapper.addClass('has-value has-value-on-load');
			fieldValues.password = $input.val().trim();
		  }
		}
	  });
	}
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
	function fixPasswordVisibilityToggle() {
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
	function enhanceLoginForm() {
	  if ($('#loginform .wallet-up-form-title').length === 0) {
		var formTitle = 'Welcome to the Next \'Up';
		if (typeof walletUpLogin !== 'undefined' && walletUpLogin.siteName) {
		  var welcomeText = (typeof walletUpLogin.strings !== 'undefined' && walletUpLogin.strings.welcomeTo) ? walletUpLogin.strings.welcomeTo : 'Welcome to';
		  formTitle = welcomeText + ' ' + walletUpLogin.siteName;
		}
		$('#loginform, #lostpasswordform, #registerform').prepend(
		  '<div class="wallet-up-login-customizer-logo"></div>' +
		  '<h2 class="wallet-up-form-title">' + formTitle + '</h2>'
		);
	  }
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
	  $('#wp-submit, .button-primary, .button-secondary').addClass('wallet-up-ripple');
	  if ($('#wp-submit').length && $('#wp-submit').val() === 'Log In') {
		$('#wp-submit').val((typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.signInSecurely) ? walletUpLogin.strings.signInSecurely : 'Sign In Securely');
	  }
	  if ($('#nav a').length && !$('#nav a svg').length) {
		var resetText = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.resetPassword) ? walletUpLogin.strings.resetPassword : 'Reset Password';
		$('#nav a').html('<svg xmlns="http:
	  }
	  if ($('#backtoblog a').length && !$('#backtoblog a svg').length) {
		var siteName = (typeof walletUpLogin !== 'undefined' && walletUpLogin.siteName) 
		  ? walletUpLogin.siteName 
		  : (document.title.split('–')[0] || document.title.split(' - ')[0] || 'Home').trim();
		var backToText = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.backToSite) ? walletUpLogin.strings.backToSite : 'Back to ' + siteName;
		$('#backtoblog a').html('<svg xmlns="http:
	  }
	  if ($('#wallet-up-login-customizer-nonce').length === 0) {
		var nonce = '';
		if (typeof walletUpLogin !== 'undefined' && walletUpLogin.nonce) {
		  nonce = walletUpLogin.nonce;
		}
		$('#loginform').append('<input type="hidden" name="security" id="wallet-up-login-customizer-nonce" value="' + nonce + '">');
	  }
	  if ($('#wallet-up-redirect-to').length === 0) {
		var redirectTo = '';
		var urlParams = new URLSearchParams(window.location.search);
		if (urlParams.has('redirect_to')) {
		  redirectTo = urlParams.get('redirect_to');
		} else if (typeof walletUpLogin !== 'undefined' && walletUpLogin.adminUrl) {
		  redirectTo = walletUpLogin.adminUrl;
		}
		$('#loginform').append('<input type="hidden" name="redirect_to" id="wallet-up-redirect-to" value="' + redirectTo + '">');
	  }
	  if ($('.forgetmenot').length === 0) {
		var $submitWrapper = $('#wp-submit').closest('.submit');
		var rememberMeText = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.rememberMe) ? walletUpLogin.strings.rememberMe : 'Remember Me';
		$submitWrapper.before('<p class="forgetmenot"><label><input name="rememberme" type="checkbox" id="rememberme" value="forever"> ' + rememberMeText + '</label></p>');
	  }
	}
	function setupStylingAndAnimations() {
	  $('.login form, #nav a, #backtoblog a').addClass('wallet-up-hover-effect');
	  initAnimations();
	}
	function initAnimations() {
	  animateFloatingShapes();
	  addMouseMoveEffect();
	  animateFormElements();
	}
	function animateFloatingShapes() {
	  if (typeof gsap !== 'undefined') {
		animations.floatingShapes = gsap.timeline();
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
		var mouseXpercentage = Math.round((e.pageX / windowWidth) * 100);
		var mouseYpercentage = Math.round((e.pageY / windowHeight) * 100);
		$('.animated-shape').css({
		  'transform': 'translate(' + mouseXpercentage / 50 + 'px, ' + mouseYpercentage / 50 + 'px)'
		});
		$('.floating-shape').each(function(index) {
		  var depth = (index + 3) * 10;
		  $(this).css({
			'transform': 'translate(' + mouseXpercentage / depth + 'px, ' + mouseYpercentage / depth + 'px)'
		  });
		});
	  }
	}
	function animateFormElements() {
	  if (typeof gsap !== 'undefined') {
		animations.formElements = gsap.timeline();
		animations.formElements.from('.user-login-wrap, .user-pass-wrap', {
		  y: 20,
		  opacity: 0,
		  duration: 0.5,
		  stagger: 0.1,
		  delay: 0.3,
		  ease: 'power2.out'
		});
		animations.formElements.from('.forgetmenot', {
		  y: 10,
		  opacity: 0,
		  duration: 0.4,
		  ease: 'power2.out'
		}, '-=0.2');
		animations.formElements.from('#wp-submit, .submit', {
		  y: 10,
		  opacity: 0,
		  duration: 0.4,
		  ease: 'power2.out'
		}, '-=0.2');
		animations.formElements.from('#nav, #backtoblog', {
		  y: 10,
		  opacity: 0,
		  duration: 0.4,
		  stagger: 0.1,
		  ease: 'power2.out'
		}, '-=0.2');
	  } else {
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
	function setupFormValidation() {
	  var userInteracted = false;
	  var typingTimer;
	  var doneTypingInterval = 300;
	  $('#user_login, #user_pass').on('keyup', function() {
		userInteracted = true;
		var $input = $(this);
		var $wrapper = $input.closest('.user-login-wrap, .user-pass-wrap');
		var fieldName = $input.attr('id') === 'user_login' ? 'username' : 'password';
		var currentValue = $input.val().trim();
		$wrapper.next('.wallet-up-input-message').remove();
		$wrapper.removeClass('is-validating has-success has-error');
		if (currentValue.length > 0) {
		  $wrapper.addClass('has-value');
		} else {
		  $wrapper.removeClass('has-value');
		}
		if (config.validateOnType) {
		  if (currentValue.length > 0) {
			$wrapper.addClass('is-validating');
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
	  $('#user_login, #user_pass').on('blur', function() {
		if (userInteracted) {
		  validateField($(this));
		}
	  });
	  $('#user_login, #user_pass').on('focus', function() {
		userInteracted = true;
		var $wrapper = $(this).closest('.user-login-wrap, .user-pass-wrap');
		$wrapper.addClass('is-focused');
		if ($(this).val().trim().length > 0) {
		  $wrapper.addClass('has-value');
		}
	  }).on('blur', function() {
		var $wrapper = $(this).closest('.user-login-wrap, .user-pass-wrap');
		$wrapper.removeClass('is-focused');
		if ($(this).val().trim().length > 0) {
		  $wrapper.addClass('has-value');
		} else {
		  $wrapper.removeClass('has-value');
		}
	  });
	  $('#loginform').on('submit', function(e) {
		if (!config.enableAjaxLogin) {
		  if (!validateForm()) {
			e.preventDefault();
			return false;
		  }
		}
	  });
	}
	function validateField($field) {
	  var fieldId = $field.attr('id');
	  var value = $field.val().trim();
	  var $wrapper = $field.closest('.user-login-wrap, .user-pass-wrap');
	  var $existingMessage = $wrapper.next('.wallet-up-input-message');
	  var isValid = true;
	  var eventDetail = { field: fieldId, message: '' };
	  if ($existingMessage.length) {
		$existingMessage.remove();
	  }
	  $wrapper.removeClass('is-validating has-success has-error');
	  if (value === '') {
		return false;
	  }
	  if (fieldId === 'user_login') {
		if (value.length < 3) {
		  isValid = false;
		  $wrapper.addClass('has-error');
		  var usernameTooShortMsg = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.usernameTooShort) ? walletUpLogin.strings.usernameTooShort : 'Username must be at least 3 characters';
		  $wrapper.after(createValidationMessage('error', usernameTooShortMsg));
		  eventDetail.message = usernameTooShortMsg;
		} else {
		  $wrapper.addClass('has-success');
		}
	  } else if (fieldId === 'user_pass') {
		if (value.length < 6) {
		  $wrapper.addClass('has-success');
		  var strongerPasswordMsg = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.considerStrongerPassword) ? walletUpLogin.strings.considerStrongerPassword : 'Consider using a stronger password';
		  $wrapper.after(createValidationMessage('info', strongerPasswordMsg));
		  eventDetail.message = strongerPasswordMsg;
		} else {
		  $wrapper.addClass('has-success');
		}
	  }
	  var $message = $wrapper.next('.wallet-up-input-message');
	  if ($message.length) {
		animateElement($message, 'fadeInDown', 0.3);
	  }
	  if (isValid) {
		$(document).trigger('wallet-up:validation-success', { field: fieldId });
	  } else {
		$(document).trigger('wallet-up:validation-error', eventDetail);
	  }
	  return isValid;
	}
	function createValidationMessage(type, message) {
	  var icon = '';
	  switch (type) {
		case 'error':
		  icon = '<svg xmlns="http:
		  break;
		case 'success':
		  icon = '<svg xmlns="http:
		  break;
		case 'info':
		  icon = '<svg xmlns="http:
		  break;
	  }
	  return $('<div class="wallet-up-input-message ' + type + '">' + icon + message + '</div>');
	}
	function validateForm() {
	  var isValid = true;
	  $('#user_login, #user_pass').each(function() {
		if (!validateField($(this))) {
		  isValid = false;
		}
	  });
	  if (!isValid) {
		$('#loginform').addClass('enhanced-error-feedback');
		setTimeout(function() {
		  $('#loginform').removeClass('enhanced-error-feedback');
		}, 600);
		showAlert('error', 'Please correct the errors before signing in.');
	  }
	  return isValid;
	}
	function setupAjaxLogin() {
	  $('#loginform').on('submit', function(e) {
		if ($('#wallet-up-login-customizer-nonce').length || (typeof walletUpLogin !== 'undefined' && walletUpLogin.nonce)) {
		  e.preventDefault();
		  if (loginState.isSubmitting) {
			return false;
		  }
		  loginState = {
			isValidating: true,
			isSubmitting: true,
			isSuccess: false,
			isError: false,
			errorMessage: '',
			redirectUrl: $('#wallet-up-redirect-to').val() || ''
		  };
		  $('.wallet-up-alert').remove();
		  showActionScreen('loading', getRandomLoadingMessage(), 20);
		  if (!validateForm()) {
			hideActionScreen();
			$('#wp-submit')
			  .removeClass('is-loading')
			  .prop('disabled', false)
			  .attr('aria-disabled', 'false');
			loginState.isValidating = false;
			loginState.isSubmitting = false;
			return false;
		  }
		  $('#wp-submit')
			.addClass('is-loading')
			.prop('disabled', true)
			.attr('aria-disabled', 'true');
		  animateButtonLoading($('#wp-submit'));
		  var username = $('#user_login').val();
		  var password = $('#user_pass').val();
		  var remember = $('#rememberme').is(':checked');
		  var security = $('#wallet-up-login-customizer-nonce').val() || (walletUpLogin ? walletUpLogin.nonce : '');
		  var redirectTo = $('#wallet-up-redirect-to').val() || '';
		  updateProgress(40);
		  var data = {
			action: 'wallet_up_ajax_login',
			username: username,
			password: password,
			remember: remember,
			security: security,
			redirect_to: redirectTo
		  };
		  console.log('AJAX Login Request:', {
			url: walletUpLogin ? walletUpLogin.ajaxUrl : ajaxurl,
			data: data,
			nonce: security
		  });
		  $.ajax({
			type: 'POST',
			dataType: 'json',
			url: walletUpLogin ? walletUpLogin.ajaxUrl : ajaxurl,
			data: data,
			beforeSend: function(xhr) {
			  console.log('AJAX Login: Sending request...');
			},
			complete: function(xhr, status) {
			  console.log('AJAX Login Complete:', {
				status: status,
				responseStatus: xhr.status,
				responseText: xhr.responseText ? xhr.responseText.substring(0, 200) + '...' : 'No response'
			  });
			},
			success: function(response) {
			  updateProgress(70);
			  setTimeout(function() {
				if (response.success) {
				  updateProgress(100);
				  loginState.redirectUrl = response.data.redirect || redirectTo || (walletUpLogin ? walletUpLogin.adminUrl : '/wp-admin/');
				  $('#wp-submit')
					.removeClass('is-loading')
					.addClass('is-success')
					.prop('disabled', true) 
					.attr('aria-disabled', 'true');
				  animateButtonSuccess($('#wp-submit'));
				  var successMessage = response.data.message || walletUpLogin.strings.welcomeBackSuccess || 'Welcome back! You have successfully signed in.';
				  showActionScreen('success', successMessage, 100);
				  loginState.isSuccess = true;
				  loginState.isSubmitting = false;
				  loginState.isValidating = false;
				  pulseSuccessEffect($('#loginform'));
				  $(document).trigger('wallet-up:login-success', { 
					user: username,
					redirect: loginState.redirectUrl 
				  });
				  setTimeout(function() {
					window.location.href = loginState.redirectUrl;
				  }, config.redirectDelay);
				} else {
				  updateProgress(100);
				  $('#wp-submit')
					.removeClass('is-loading is-success')
					.prop('disabled', false)
					.attr('aria-disabled', 'false');
				  animateButtonError($('#wp-submit'));
				  $('.user-login-wrap, .user-pass-wrap').removeClass('is-validating has-success has-error');
				  var errorMessage = response.data.message || 'Invalid username or password. Please try again.';
				  showActionScreen('error', errorMessage, 100);
				  loginState.isError = true;
				  loginState.errorMessage = errorMessage;
				  loginState.isSubmitting = false;
				  loginState.isValidating = false;
				  $('#loginform').addClass('enhanced-error-feedback');
				  setTimeout(function() {
					$('#loginform').removeClass('enhanced-error-feedback');
					if (typeof gsap !== 'undefined' && animations.formElements) {
					  animations.formElements.invalidate();
					}
				  }, 600);
				  $(document).trigger('wallet-up:login-error', { 
					message: errorMessage 
				  });
				}
			  }, 800); 
			},
			error: function(xhr, status, error) {
			  updateProgress(100);
			  $('#wp-submit')
				.removeClass('is-loading is-success')
				.prop('disabled', false)
				.attr('aria-disabled', 'false');
			  animateButtonError($('#wp-submit'));
			  $('.user-login-wrap, .user-pass-wrap').removeClass('is-validating has-success has-error');
			  var errorMessage = 'Server error. Please try again later.';
			  if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
				errorMessage = xhr.responseJSON.data.message;
			  } else if (xhr.responseText) {
				console.error('AJAX Login Error Response:', xhr.responseText);
			  }
			  console.error('AJAX Login Error:', {
				status: xhr.status,
				statusText: xhr.statusText,
				error: error,
				responseText: xhr.responseText
			  });
			  showActionScreen('error', errorMessage, 100);
			  loginState.isError = true;
			  loginState.errorMessage = errorMessage;
			  loginState.isSubmitting = false;
			  loginState.isValidating = false;
			  $('#loginform').addClass('enhanced-error-feedback');
			  setTimeout(function() {
				$('#loginform').removeClass('enhanced-error-feedback');
				if (typeof gsap !== 'undefined' && animations.formElements) {
				  animations.formElements.invalidate();
				}
			  }, 600);
			  if (typeof walletUpLogin !== 'undefined' && walletUpLogin.debug) {
				console.error('Login error:', xhr, status, error);
			  }
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
	function getRandomLoadingMessage() {
	  if (config.loadingMessages && config.loadingMessages.length > 0) {
		var randomIndex = Math.floor(Math.random() * config.loadingMessages.length);
		return config.loadingMessages[randomIndex];
	  }
	  return (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.verifyingCredentials) ? walletUpLogin.strings.verifyingCredentials : 'Verifying your credentials...';
	}
	function showActionScreen(state, message, progress) {
	  var $screen = $('.wallet-up-action-screen');
	  var $content = $screen.find('.action-screen-content');
	  var $icon = $screen.find('.action-screen-icon');
	  var $title = $screen.find('.action-screen-title');
	  var $message = $screen.find('.action-screen-message');
	  var $progress = $screen.find('.action-progress-bar');
	  var $button = $screen.find('.action-screen-button');
	  $screen.addClass('active');
	  $screen.css({
		'display': 'flex',
		'visibility': 'visible',
		'opacity': '1',
		'z-index': '9999'
	  });
	  $icon.empty();
	  $button.hide();
	  switch (state) {
		case 'loading':
		  $icon.html('<div class="action-loading-spinner"></div>');
		  $title.text((typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.signingIn) ? walletUpLogin.strings.signingIn : 'Signing In');
		  $button.hide();
		  $progress.css('width', (progress || 0) + '%');
		  break;
		case 'success':
		  $icon.html('<svg class="action-success-icon" width="80" height="80" viewBox="0 0 80 80" fill="none" xmlns="http:
			'<circle cx="40" cy="40" r="38" stroke="#10B981" stroke-width="4" fill="none"/>' +
			'<path class="action-success-icon" d="M25 40L35 50L55 30" stroke="#10B981" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>' +
		  '</svg>');
		  var successText = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.success) ? walletUpLogin.strings.success : 'Success!';
	  $title.text(successText);
		  $progress.css('width', '100%');
		  $button.show().text((typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.continueToDashboard) ? walletUpLogin.strings.continueToDashboard : 'Continue to Dashboard');
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
		  if (config.enableSounds) {
			playSound('error');
		  }
		  break;
	  }
	  if (message) {
		var cleanMessage = $('<div>').html(message).text();
		$message.text(cleanMessage);
	  }
	  setTimeout(function() {
		$content.css({
		  'transform': 'translateY(0)',
		  'opacity': '1'
		});
	  }, 50);
	}
	function hideActionScreen() {
	  var $screen = $('.wallet-up-action-screen');
	  var $content = $screen.find('.action-screen-content');
	  $content.css({
		'transform': 'translateY(20px)',
		'opacity': '0',
		'transition': 'transform 0.3s ease, opacity 0.3s ease'
	  });
	  setTimeout(function() {
		$screen.removeClass('active');
		$screen.removeAttr('style');
		setTimeout(function() {
		  $content.css({
			'transform': '',
			'opacity': '',
			'transition': ''
		  });
		  resetActionScreen();
		}, 300);
	  }, 300);
	}
	function resetActionScreen() {
	  var $screen = $('.wallet-up-action-screen');
	  var $icon = $screen.find('.action-screen-icon');
	  var $title = $screen.find('.action-screen-title');
	  var $message = $screen.find('.action-screen-message');
	  var $button = $screen.find('.action-screen-button');
	  var $progress = $screen.find('.action-progress-bar');
	  $icon.html('<div class="action-loading-spinner"></div>');
	  $title.text((typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.signingIn) ? walletUpLogin.strings.signingIn : 'Signing In');
	  $message.text((typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.verifyingCredentials) ? walletUpLogin.strings.verifyingCredentials : 'Verifying your credentials...');
	  $button.text('Continue').hide();
	  $progress.css('width', '0%');
	  $screen.removeClass('is-success is-error is-loading');
	}
	function updateProgress(percentage) {
	  $('.action-progress-bar').css('width', percentage + '%');
	}
	function enhancePasswordField() {
	  var $passwordField = $('#user_pass');
	  var $passwordWrap = $passwordField.closest('.user-pass-wrap');
	  if ($passwordField.length === 0) {
		return;
	  }
	  if ($('.wp-hide-pw').length === 0) {
		var $hidePwButton = $('<button type="button" class="button wp-hide-pw" aria-label="Show password">' +
		  '<span class="dashicons dashicons-visibility" aria-hidden="true"></span>' +
		'</button>');
		$passwordWrap.find('.wp-pwd').length ? 
		  $passwordWrap.find('.wp-pwd').append($hidePwButton) : 
		  $passwordField.after($hidePwButton);
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
		  $passwordField.focus();
		});
	  }
	}
	function enhanceAccessibility() {
	  var usernameLabel = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.username) ? walletUpLogin.strings.username : 'Username';
	  var passwordLabel = (typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.password) ? walletUpLogin.strings.password : 'Password';
	  $('.user-login-wrap input').attr('aria-label', usernameLabel);
	  $('.user-pass-wrap input').attr('aria-label', passwordLabel);
	  $('#loginform').attr('role', 'form');
	  $('.wallet-up-alert').attr('role', 'alert');
	  $('.wallet-up-alert').attr('aria-live', 'assertive');
	  var $lastFocused;
	  $(document).on('focus', 'button, input, a', function() {
		if (!$('.wallet-up-action-screen').hasClass('active')) {
		  $lastFocused = $(this);
		}
	  });
	  $('.wallet-up-action-screen').on('transitionend', function() {
		if ($(this).hasClass('active') && $('.action-screen-button').is(':visible')) {
		  $('.action-screen-button').focus();
		}
	  });
	  $('.wallet-up-action-screen').on('transitionend', function() {
		if (!$(this).hasClass('active') && $lastFocused && $lastFocused.length) {
		  $lastFocused.focus();
		}
	  });
	  $('#loginform').on('keydown', function(e) {
		if (e.key === 'Enter' && (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT')) {
		  e.preventDefault();
		  $('#wp-submit').click();
		}
	  });
	}
	function convertStandardMessages() {
	  $('#login_error, .message').each(function() {
		var $this = $(this);
		var message = $this.text().trim();
		var type = 'info';
		if ($this.is('#login_error')) {
		  type = 'error';
		  $('#loginform').addClass('enhanced-error-feedback');
		  setTimeout(function() {
			$('#loginform').removeClass('enhanced-error-feedback');
		  }, 600);
		} else if ($this.hasClass('updated')) {
		  type = 'success';
		} else if (message.toLowerCase().includes('error')) {
		  type = 'error';
		}
		showAlert(type, message);
		$this.hide();
	  });
	}
	function showAlert(type, message, duration = 5000) {
	  $('.wallet-up-alert.' + type).remove();
	  var icon = '';
	  switch (type) {
		case 'error':
		  icon = '<svg xmlns="http:
		  break;
		case 'success':
		  icon = '<svg xmlns="http:
		  break;
		case 'info':
		  icon = '<svg xmlns="http:
		  break;
		case 'warning':
		  icon = '<svg xmlns="http:
		  break;
	  }
	  var alert = $(
		'<div class="wallet-up-alert ' + type + '" role="alert">' +
		  icon +
		  '<span>' + message + '</span>' +
		  '<button type="button" class="wallet-up-alert-close" aria-label="Close">' +
			'<svg xmlns="http:
		  '</button>' +
		  (duration > 0 ? '<div class="wallet-up-alert-progress"></div>' : '') +
		'</div>'
	  );
	  $('#login').prepend(alert);
	  animateElement(alert, 'fadeInDown', 0.4);
	  if (duration > 0) {
		var progress = alert.find('.wallet-up-alert-progress');
		if (typeof gsap !== 'undefined') {
		  gsap.to(progress, {
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
	function setupEventHandlers() {
	  $(document).on('click', '.wallet-up-alert-close', function() {
		var $alert = $(this).closest('.wallet-up-alert');
		animateElement($alert, 'fadeOutUp', 0.3, function() {
		  $alert.remove();
		});
	  });
	  $(document).on('mousedown', '.wallet-up-ripple', function(e) {
		addRippleEffect($(this), e);
	  });
	  $(document).on('click', '.action-screen-button', function(e) {
		e.preventDefault();
		if (loginState.isSuccess && loginState.redirectUrl) {
		  window.location.href = loginState.redirectUrl;
		  return;
		}
		hideActionScreen();
		if (loginState.isError) {
		  loginState.isError = false;
		  loginState.isSubmitting = false;
		  loginState.isValidating = false;
		  loginState.errorMessage = '';
		  $('#wp-submit').removeClass('is-loading is-success');
		  $('.user-login-wrap, .user-pass-wrap').removeClass('is-validating has-success has-error');
		  $('#user_pass').val('').focus();
		  if (typeof gsap !== 'undefined' && animations.formElements) {
			animations.formElements.invalidate();
		  }
		  return;
		}
	  });
	  $(document).on('click', '.wp-hide-pw', function() {
		addRippleEffect($(this));
	  });
	  $('#loginform input').on('keydown', function(e) {
		if (e.key === 'Enter') {
		  e.preventDefault();
		  $('#wp-submit').click();
		}
	  });
	  $(window).on('popstate', function() {
		if ($('.wallet-up-action-screen').hasClass('active')) {
		  hideActionScreen();
		}
	  });
	}
	function addRippleEffect(element, e) {
	  element.find('.wallet-up-ripple-effect').remove();
	  var ripple = $('<span class="wallet-up-ripple-effect"></span>');
	  element.append(ripple);
	  var size = Math.max(element.outerWidth(), element.outerHeight());
	  var pos = element.offset();
	  var relX = e ? e.pageX - pos.left : element.width() / 2;
	  var relY = e ? e.pageY - pos.top : element.height() / 2;
	  ripple.css({
		width: size + 'px',
		height: size + 'px',
		top: relY - (size / 2) + 'px',
		left: relX - (size / 2) + 'px'
	  });
	  setTimeout(function() {
		ripple.remove();
	  }, 600);
	}
	function playSound(type) {
	  if (typeof AudioContext !== 'undefined' || typeof webkitAudioContext !== 'undefined') {
		var AudioContextClass = window.AudioContext || window.webkitAudioContext;
		var audioCtx = new AudioContextClass();
		var oscillator = audioCtx.createOscillator();
		var gainNode = audioCtx.createGain();
		oscillator.connect(gainNode);
		gainNode.connect(audioCtx.destination);
		if (type === 'success') {
		  oscillator.type = 'sine';
		  oscillator.frequency.value = 500;
		  gainNode.gain.value = 0.1;
		  oscillator.frequency.linearRampToValueAtTime(800, audioCtx.currentTime + 0.1);
		  oscillator.start();
		  oscillator.stop(audioCtx.currentTime + 0.15);
		  setTimeout(function() {
			var osc2 = audioCtx.createOscillator();
			osc2.type = 'sine';
			osc2.frequency.value = 800;
			osc2.connect(gainNode);
			osc2.start();
			osc2.stop(audioCtx.currentTime + 0.15);
		  }, 150);
		} else if (type === 'error') {
		  oscillator.type = 'sine';
		  oscillator.frequency.value = 400;
		  gainNode.gain.value = 0.1;
		  oscillator.frequency.linearRampToValueAtTime(200, audioCtx.currentTime + 0.1);
		  oscillator.start();
		  oscillator.stop(audioCtx.currentTime + 0.2);
		}
	  }
	}
	function animateElement(element, effect, duration, callback) {
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
	  var animation = effects[effect] || effects.fadeIn;
	  if (typeof gsap !== 'undefined') {
		gsap.fromTo(element, 
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
		var cssProps = {
		  'transition': 'transform ' + (duration || 0.4) + 's ease-out, opacity ' + (duration || 0.4) + 's ease-out'
		};
		if (animation.from.opacity !== undefined) cssProps.opacity = animation.from.opacity;
		if (animation.from.y !== undefined) cssProps.transform = 'translateY(' + animation.from.y + 'px)';
		element.css(cssProps);
		element[0].offsetHeight;
		var toProps = {};
		if (animation.to.opacity !== undefined) toProps.opacity = animation.to.opacity;
		if (animation.to.y !== undefined) toProps.transform = 'translateY(' + animation.to.y + 'px)';
		element.css(toProps);
		if (typeof callback === 'function') {
		  setTimeout(callback, (duration || 0.4) * 1000);
		}
	  }
	}
	function pulseSuccessEffect(element) {
	  if (typeof gsap !== 'undefined') {
		gsap.to(element, {
		  boxShadow: '0 0 0 3px rgba(16, 185, 129, 0.25)',
		  duration: 0.5,
		  repeat: 2,
		  yoyo: true,
		  ease: 'sine.inOut'
		});
	  }
	}
	function animateButtonLoading($button) {
	  var originalText = $button.val();
	  $button.data('original-text', originalText);
	  var loadingHTML = '<span class="wallet-up-button-spinner"></span><span class="wallet-up-button-text">Signing In...</span>';
	  if ($button.is('input')) {
		$button.val('Signing In...');
		$button.addClass('wallet-up-loading-button');
	  } else {
		$button.html(loadingHTML);
	  }
	  if (typeof gsap !== 'undefined') {
		gsap.to($button, {
		  scale: 1.02,
		  duration: 0.8,
		  ease: 'sine.inOut',
		  repeat: -1,
		  yoyo: true
		});
	  }
	}
	function animateButtonSuccess($button) {
	  if (typeof gsap !== 'undefined') {
		gsap.killTweensOf($button);
	  }
	  $button.css('transform', 'scale(1)');
	  if ($button.is('input')) {
		$button.val('Success!').removeClass('wallet-up-loading-button');
	  } else {
		$button.html('<span class="wallet-up-success-icon">✓</span><span class="wallet-up-button-text">Success!</span>');
	  }
	  if (typeof gsap !== 'undefined') {
		gsap.fromTo($button, 
		  { scale: 1 },
		  { 
			scale: 1.05,
			duration: 0.3,
			ease: 'back.out(1.7)',
			onComplete: function() {
			  gsap.to($button, {
				scale: 1,
				duration: 0.2,
				ease: 'power2.out'
			  });
			}
		  }
		);
		gsap.to($button, {
		  boxShadow: '0 0 20px rgba(16, 185, 129, 0.5)',
		  duration: 0.5,
		  ease: 'power2.out',
		  onComplete: function() {
			gsap.to($button, {
			  boxShadow: '0 0 0px rgba(16, 185, 129, 0)',
			  duration: 0.5,
			  ease: 'power2.out'
			});
		  }
		});
	  }
	}
	function animateButtonError($button) {
	  if (typeof gsap !== 'undefined') {
		gsap.killTweensOf($button);
	  }
	  $button.css('transform', 'scale(1)').removeClass('wallet-up-loading-button');
	  var originalText = $button.data('original-text') || ((typeof walletUpLogin !== 'undefined' && walletUpLogin.strings && walletUpLogin.strings.signInSecurely) ? walletUpLogin.strings.signInSecurely : 'Sign In Securely');
	  if ($button.is('input')) {
		$button.val(originalText);
	  } else {
		$button.html(originalText);
	  }
	  if (typeof gsap !== 'undefined') {
		gsap.fromTo($button,
		  { x: 0 },
		  {
			x: -10,
			duration: 0.1,
			ease: 'power2.out',
			repeat: 5,
			yoyo: true,
			onComplete: function() {
			  gsap.set($button, { x: 0 });
			}
		  }
		);
		gsap.to($button, {
		  borderColor: '#EF4444',
		  duration: 0.3,
		  ease: 'power2.out',
		  repeat: 1,
		  yoyo: true
		});
	  }
	}
	function addButtonHoverAnimations() {
	  $('#wp-submit, .button-primary').on('mouseenter', function() {
		var $this = $(this);
		if (!$this.prop('disabled') && typeof gsap !== 'undefined') {
		  gsap.to($this, {
			scale: 1.02,
			duration: 0.2,
			ease: 'power2.out'
		  });
		}
	  }).on('mouseleave', function() {
		var $this = $(this);
		if (!$this.prop('disabled') && typeof gsap !== 'undefined') {
		  gsap.to($this, {
			scale: 1,
			duration: 0.2,
			ease: 'power2.out'
		  });
		}
	  });
	}
	$(document).ready(function() {
	  setTimeout(addButtonHoverAnimations, 100);
	});
  })(jQuery);