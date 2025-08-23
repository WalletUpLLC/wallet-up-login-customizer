/**
 * WULCA - Wallet Up Login Customizer Animation
 * A lightweight animation library for WordPress
 * Version: 2.0.0
 * License: GPL v2 or later
 */

(function(window) {
    'use strict';
    
    // Object.assign polyfill for older browsers
    if (typeof Object.assign !== 'function') {
        Object.assign = function(target) {
            if (target == null) {
                throw new TypeError('Cannot convert undefined or null to object');
            }
            var to = Object(target);
            for (var index = 1; index < arguments.length; index++) {
                var nextSource = arguments[index];
                if (nextSource != null) {
                    for (var nextKey in nextSource) {
                        if (Object.prototype.hasOwnProperty.call(nextSource, nextKey)) {
                            to[nextKey] = nextSource[nextKey];
                        }
                    }
                }
            }
            return to;
        };
    }

    // Store active animations by element
    var activeAnimations = new WeakMap();
    var animationCounter = 0;

    // Easing functions
    var easings = {
        linear: function(t) { return t; },
        'power2.out': function(t) { return 1 - Math.pow(1 - t, 2); },
        'power2.in': function(t) { return t * t; },
        'power2.inOut': function(t) { return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; },
        'sine.inOut': function(t) { return -(Math.cos(Math.PI * t) - 1) / 2; },
        'sine.in': function(t) { return 1 - Math.cos((t * Math.PI) / 2); },
        'sine.out': function(t) { return Math.sin((t * Math.PI) / 2); },
        'back.out': function(t, magnitude) {
            magnitude = magnitude || 1.7;
            return 1 + magnitude * Math.pow(t - 1, 3) + magnitude * Math.pow(t - 1, 2);
        },
        'back.in': function(t, magnitude) {
            magnitude = magnitude || 1.7;
            return magnitude * t * t * t - magnitude * t * t;
        },
        'back.inOut': function(t, magnitude) {
            magnitude = magnitude || 1.7;
            return t < 0.5
                ? (Math.pow(2 * t, 2) * ((magnitude + 1) * 2 * t - magnitude)) / 2
                : (Math.pow(2 * t - 2, 2) * ((magnitude + 1) * (t * 2 - 2) + magnitude) + 2) / 2;
        }
    };

    // Parse easing string (e.g., "back.out(1.7)")
    function parseEasing(easeStr) {
        if (typeof easeStr === 'function') return easeStr;
        if (!easeStr || easeStr === 'none') return easings.linear;
        
        var match = easeStr.match(/^(.+?)\((.+?)\)$/);
        if (match) {
            var easeName = match[1];
            var param = parseFloat(match[2]);
            return function(t) {
                return easings[easeName] ? easings[easeName](t, param) : easings.linear(t);
            };
        }
        
        return easings[easeStr] || easings.linear;
    }

    // Convert jQuery object to DOM element
    function getElement(element) {
        if (!element) return null;
        if (element.jquery) return element[0];
        if (typeof element === 'string') return document.querySelector(element);
        return element;
    }

    // Get current transform values
    function getCurrentTransform(element) {
        var style = window.getComputedStyle(element);
        var matrix = style.transform;
        var values = {
            x: 0,
            y: 0,
            scaleX: 1,
            scaleY: 1,
            scale: 1,
            rotate: 0
        };

        if (matrix && matrix !== 'none') {
            var matrixValues = matrix.match(/matrix\((.+)\)/);
            if (matrixValues) {
                var vals = matrixValues[1].split(', ');
                values.scaleX = parseFloat(vals[0]);
                values.scaleY = parseFloat(vals[3]);
                values.x = parseFloat(vals[4]);
                values.y = parseFloat(vals[5]);
            }
        }

        return values;
    }

    // Parse box-shadow string
    function parseBoxShadow(shadow) {
        if (!shadow || shadow === 'none') return null;
        var match = shadow.match(/(\d+)px\s+(\d+)px\s+(\d+)px\s+(\d+)px\s+rgba?\((.+?)\)/);
        if (match) {
            return {
                x: parseFloat(match[1]),
                y: parseFloat(match[2]),
                blur: parseFloat(match[3]),
                spread: parseFloat(match[4]),
                color: 'rgba(' + match[5] + ')'
            };
        }
        return null;
    }

    // Animate a single property
    function animateProperty(element, prop, from, to, progress, easedProgress) {
        var value = from + (to - from) * easedProgress;
        
        switch(prop) {
            case 'opacity':
                element.style.opacity = value;
                break;
            case 'scale':
                element.style.transform = element.style.transform.replace(/scale\([^)]*\)/, '') + ' scale(' + value + ')';
                break;
            case 'scaleX':
                element.style.transform = element.style.transform.replace(/scaleX\([^)]*\)/, '') + ' scaleX(' + value + ')';
                break;
            case 'scaleY':
                element.style.transform = element.style.transform.replace(/scaleY\([^)]*\)/, '') + ' scaleY(' + value + ')';
                break;
            case 'x':
                element.style.transform = element.style.transform.replace(/translateX\([^)]*\)/, '') + ' translateX(' + value + 'px)';
                break;
            case 'y':
                element.style.transform = element.style.transform.replace(/translateY\([^)]*\)/, '') + ' translateY(' + value + 'px)';
                break;
            case 'rotate':
                element.style.transform = element.style.transform.replace(/rotate\([^)]*\)/, '') + ' rotate(' + value + 'deg)';
                break;
            case 'borderColor':
                element.style.borderColor = to; // For simplicity, snap to target color
                break;
            case 'boxShadow':
                if (typeof to === 'string') {
                    var shadowProgress = easedProgress;
                    if (to === '0 0 0px rgba(16, 185, 129, 0)' && shadowProgress > 0.5) {
                        element.style.boxShadow = to;
                    } else if (shadowProgress <= 0.5) {
                        element.style.boxShadow = to.replace('0)', (0.5 * shadowProgress) + ')');
                    } else {
                        element.style.boxShadow = to;
                    }
                }
                break;
            default:
                if (typeof value === 'number') {
                    element.style[prop] = value + 'px';
                } else {
                    element.style[prop] = value;
                }
        }
    }

    // Parse random values (e.g., "random(-40, 40)")
    function parseRandomValue(value) {
        if (typeof value === 'string' && value.indexOf('random(') === 0) {
            var match = value.match(/random\(([^,]+),\s*([^)]+)\)/);
            if (match) {
                var min = parseFloat(match[1]);
                var max = parseFloat(match[2]);
                return min + Math.random() * (max - min);
            }
        }
        return value;
    }

    // Main animation function
    function animate(element, vars, isFromTo, fromVars) {
        element = getElement(element);
        if (!element) return;

        // Parse animation variables, handling random values
        var duration = parseRandomValue(vars.duration) || 0.4;
        duration = duration * 1000; // Convert to milliseconds
        var ease = parseEasing(vars.ease || 'power2.out');
        var delay = (vars.delay || 0) * 1000;
        var repeat = vars.repeat || 0;
        var yoyo = vars.yoyo || false;
        var onComplete = vars.onComplete;
        var onUpdate = vars.onUpdate;
        var onStart = vars.onStart;

        // Get animation properties (exclude control props)
        var props = {};
        var controlProps = ['duration', 'ease', 'delay', 'repeat', 'yoyo', 'onComplete', 'onUpdate', 'onStart', 'stagger'];
        
        for (var key in vars) {
            if (controlProps.indexOf(key) === -1) {
                // Parse random values for each property
                props[key] = parseRandomValue(vars[key]);
            }
        }

        // Get starting values
        var startValues = {};
        var currentTransform = getCurrentTransform(element);
        
        for (var prop in props) {
            if (isFromTo && fromVars && fromVars[prop] !== undefined) {
                startValues[prop] = fromVars[prop];
                // Set initial value immediately
                animateProperty(element, prop, fromVars[prop], fromVars[prop], 0, 0);
            } else {
                switch(prop) {
                    case 'opacity':
                        startValues[prop] = parseFloat(window.getComputedStyle(element).opacity) || 1;
                        break;
                    case 'scale':
                        startValues[prop] = currentTransform.scale;
                        break;
                    case 'scaleX':
                        startValues[prop] = currentTransform.scaleX;
                        break;
                    case 'x':
                        startValues[prop] = currentTransform.x;
                        break;
                    case 'y':
                        startValues[prop] = currentTransform.y;
                        break;
                    case 'rotate':
                    case 'rotation':
                        startValues[prop] = currentTransform.rotate;
                        break;
                    case 'borderColor':
                        startValues[prop] = window.getComputedStyle(element).borderColor;
                        break;
                    case 'boxShadow':
                        startValues[prop] = window.getComputedStyle(element).boxShadow;
                        break;
                    default:
                        startValues[prop] = parseFloat(window.getComputedStyle(element)[prop]) || 0;
                }
            }
        }

        // Kill existing animations on this element
        killTweensOf(element);

        // Create animation
        var animationId = ++animationCounter;
        var startTime = null;
        var repeatCount = 0;
        var isReversed = false;

        function tick(timestamp) {
            if (!startTime) {
                startTime = timestamp + delay;
                if (onStart) onStart();
            }

            if (timestamp < startTime) {
                activeAnimations.set(element, requestAnimationFrame(tick));
                return;
            }

            var elapsed = timestamp - startTime;
            var rawProgress = Math.min(elapsed / duration, 1);
            var progress = isReversed ? 1 - rawProgress : rawProgress;
            var easedProgress = ease(progress);

            // Apply all property animations
            var transforms = [];
            for (var prop in props) {
                var from = startValues[prop];
                var to = props[prop];
                
                if (prop === 'x' || prop === 'y' || prop === 'scale' || prop === 'scaleX' || prop === 'scaleY' || prop === 'rotate' || prop === 'rotation') {
                    var value = from + (to - from) * easedProgress;
                    switch(prop) {
                        case 'x':
                            transforms.push('translateX(' + value + 'px)');
                            break;
                        case 'y':
                            transforms.push('translateY(' + value + 'px)');
                            break;
                        case 'scale':
                            transforms.push('scale(' + value + ')');
                            break;
                        case 'scaleX':
                            transforms.push('scaleX(' + value + ')');
                            break;
                        case 'scaleY':
                            transforms.push('scaleY(' + value + ')');
                            break;
                        case 'rotate':
                        case 'rotation':
                            transforms.push('rotate(' + value + 'deg)');
                            break;
                    }
                } else {
                    animateProperty(element, prop, from, to, progress, easedProgress);
                }
            }
            
            if (transforms.length > 0) {
                element.style.transform = transforms.join(' ');
            }

            if (onUpdate) onUpdate(easedProgress);

            // Check if animation is complete
            if (rawProgress >= 1) {
                if (repeat === -1 || repeatCount < repeat) {
                    // Handle repeat
                    repeatCount++;
                    if (yoyo) {
                        isReversed = !isReversed;
                    }
                    startTime = timestamp;
                    activeAnimations.set(element, requestAnimationFrame(tick));
                } else {
                    // Animation complete
                    activeAnimations.delete(element);
                    if (onComplete) onComplete();
                }
            } else {
                activeAnimations.set(element, requestAnimationFrame(tick));
            }
        }

        activeAnimations.set(element, requestAnimationFrame(tick));
    }

    // Kill all animations on an element
    function killTweensOf(element) {
        element = getElement(element);
        if (!element) return;
        
        var animId = activeAnimations.get(element);
        if (animId) {
            cancelAnimationFrame(animId);
            activeAnimations.delete(element);
        }
    }

    // Set properties immediately
    function set(element, vars) {
        element = getElement(element);
        if (!element) return;
        
        for (var prop in vars) {
            animateProperty(element, prop, vars[prop], vars[prop], 1, 1);
        }
    }

    // Timeline implementation with from() support and position parameter
    function timeline() {
        var tl = {
            _animations: [],
            _currentIndex: 0,
            _isPlaying: false,
            _startTime: 0,
            _totalDuration: 0,

            to: function(element, vars, position) {
                this._addAnimation({ element: element, vars: vars, type: 'to' }, position);
                return this;
            },

            from: function(element, vars, position) {
                // from() is just fromTo() where current state is the target
                var toVars = {};
                for (var key in vars) {
                    if (key !== 'stagger' && key !== 'duration' && key !== 'ease' && key !== 'delay') {
                        toVars[key] = null; // Will be set to current value
                    }
                }
                toVars.duration = vars.duration;
                toVars.ease = vars.ease;
                toVars.delay = vars.delay;
                
                this._addAnimation({ element: element, fromVars: vars, toVars: toVars, type: 'from' }, position);
                return this;
            },

            fromTo: function(element, fromVars, toVars, position) {
                this._addAnimation({ element: element, fromVars: fromVars, toVars: toVars, type: 'fromTo' }, position);
                return this;
            },

            _addAnimation: function(anim, position) {
                // Parse position parameter (e.g., "-=0.2" or "+=0.5")
                anim.position = position || this._totalDuration;
                if (typeof position === 'string') {
                    var match = position.match(/^([+-]=)?(.+)$/);
                    if (match) {
                        var offset = parseFloat(match[2]);
                        if (match[1] === '-=') {
                            anim.position = Math.max(0, this._totalDuration - offset);
                        } else if (match[1] === '+=') {
                            anim.position = this._totalDuration + offset;
                        } else {
                            anim.position = offset;
                        }
                    }
                }
                
                // Update total duration
                var animDuration = (anim.vars ? anim.vars.duration : anim.toVars.duration) || 0.4;
                this._totalDuration = Math.max(this._totalDuration, anim.position + animDuration);
                
                this._animations.push(anim);
            },

            play: function() {
                if (this._isPlaying) return;
                this._isPlaying = true;
                this._currentIndex = 0;
                this._playNext();
                return this;
            },

            _playNext: function() {
                if (this._currentIndex >= this._animations.length) {
                    this._isPlaying = false;
                    return;
                }

                var anim = this._animations[this._currentIndex];
                var self = this;
                
                // Handle stagger for multiple elements
                var elements = getElements(anim.element);
                var stagger = (anim.vars && anim.vars.stagger) || (anim.fromVars && anim.fromVars.stagger) || 0;
                var completed = 0;
                var total = elements.length;
                
                function onAnimComplete() {
                    completed++;
                    if (completed >= total) {
                        self._currentIndex++;
                        self._playNext();
                    }
                }
                
                elements.forEach(function(el, index) {
                    setTimeout(function() {
                        if (anim.type === 'to') {
                            var vars = Object.assign({}, anim.vars);
                            vars.onComplete = onAnimComplete;
                            delete vars.stagger;
                            animate(el, vars);
                        } else if (anim.type === 'from') {
                            // Get current values as target
                            var currentVals = {};
                            var style = window.getComputedStyle(el);
                            for (var prop in anim.fromVars) {
                                if (prop !== 'stagger' && prop !== 'duration' && prop !== 'ease' && prop !== 'delay' && prop !== 'onComplete') {
                                    if (prop === 'opacity') {
                                        currentVals[prop] = parseFloat(style.opacity) || 1;
                                    } else if (prop === 'y') {
                                        currentVals[prop] = 0;
                                    } else if (prop === 'x') {
                                        currentVals[prop] = 0;
                                    }
                                }
                            }
                            
                            var toVars = Object.assign({}, anim.toVars, currentVals);
                            toVars.onComplete = onAnimComplete;
                            delete toVars.stagger;
                            
                            var fromVars = Object.assign({}, anim.fromVars);
                            delete fromVars.stagger;
                            
                            animate(el, toVars, true, fromVars);
                        } else if (anim.type === 'fromTo') {
                            var toVars = Object.assign({}, anim.toVars);
                            toVars.onComplete = onAnimComplete;
                            delete toVars.stagger;
                            
                            var fromVars = Object.assign({}, anim.fromVars);
                            delete fromVars.stagger;
                            
                            animate(el, toVars, true, fromVars);
                        }
                    }, index * stagger * 1000);
                });
            },

            invalidate: function() {
                this._animations = [];
                this._currentIndex = 0;
                this._isPlaying = false;
                this._totalDuration = 0;
                return this;
            }
        };
        
        // Auto-play timeline when created
        setTimeout(function() { tl.play(); }, 0);
        
        return tl;
    }
    
    // Helper to get elements (handles jQuery selectors and NodeLists)
    function getElements(selector) {
        if (!selector) return [];
        
        // Handle jQuery objects
        if (selector.jquery) {
            var els = [];
            selector.each(function() { els.push(this); });
            return els;
        }
        
        // Handle NodeList
        if (selector instanceof NodeList) {
            return Array.prototype.slice.call(selector);
        }
        
        // Handle single element
        if (selector instanceof Element) {
            return [selector];
        }
        
        // Handle selector string
        if (typeof selector === 'string') {
            return Array.prototype.slice.call(document.querySelectorAll(selector));
        }
        
        return [selector];
    }

    // Create the main API object
    var wulca = {
        to: function(element, vars) {
            animate(element, vars);
        },

        fromTo: function(element, fromVars, toVars) {
            // Handle case where toVars might contain spread operator results
            // In JavaScript, if toVars is { ...animation.to, duration: 0.4, ease: 'power2.out' }
            // it's already merged when passed here
            animate(element, toVars, true, fromVars);
        },

        set: set,
        killTweensOf: killTweensOf,
        timeline: timeline
    };

    // Expose to global scope
    window.wulca = wulca;
    
    // Also expose as WULCA (uppercase)
    window.WULCA = wulca;

})(window);