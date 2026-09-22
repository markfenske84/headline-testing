(function () {
	'use strict';

	var config = window.ahtConfig || {};
	var REST_BASE = (config.restUrl || '').replace(/\/$/, '');
	var COOKIE_NAME = config.cookieName || 'aht_vid';
	var COOKIE_DAYS = parseInt(config.cookieDays, 10) || 30;

	function getCookie(name) {
		var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[$()*+./?[\\\]^{|}-]/g, '\\$&') + '=([^;]*)'));
		return match ? decodeURIComponent(match[1]) : '';
	}

	function setCookie(name, value, days) {
		var maxAge = days * 86400;
		var secure = location.protocol === 'https:' ? '; Secure' : '';
		document.cookie =
			name +
			'=' +
			encodeURIComponent(value) +
			'; path=/; max-age=' +
			maxAge +
			'; SameSite=Lax' +
			secure;
	}

	function randomId() {
		if (window.crypto && crypto.getRandomValues) {
			var arr = new Uint8Array(16);
			crypto.getRandomValues(arr);
			return Array.prototype.map
				.call(arr, function (b) {
					return ('0' + b.toString(16)).slice(-2);
				})
				.join('');
		}
		return String(Date.now()) + Math.random().toString(16).slice(2);
	}

	function getVisitorId() {
		var id = getCookie(COOKIE_NAME);
		if (!id || id.length < 8) {
			id = randomId();
			setCookie(COOKIE_NAME, id, COOKIE_DAYS);
		}
		return id;
	}

	function hashPick(visitorId, testId, variantCount) {
		var str = visitorId + ':' + testId;
		var hash = 0;
		for (var i = 0; i < str.length; i++) {
			hash = (hash << 5) - hash + str.charCodeAt(i);
			hash |= 0;
		}
		return Math.abs(hash) % variantCount;
	}

	function collectPostIds() {
		var nodes = document.querySelectorAll('[data-aht-headline][data-aht-post-id]');
		var ids = {};
		nodes.forEach(function (el) {
			var id = parseInt(el.getAttribute('data-aht-post-id'), 10);
			if (id) {
				ids[id] = true;
			}
		});
		return Object.keys(ids).map(function (k) {
			return parseInt(k, 10);
		});
	}

	function setHeadlineText(el, text) {
		var link = el.querySelector('a');
		if (link) {
			var span = link.querySelector('span');
			if (span) {
				span.textContent = text;
			} else {
				link.textContent = text;
			}
		} else {
			el.textContent = text;
		}
	}

	function fetchActiveTests(postIds) {
		if (!REST_BASE || !postIds.length) {
			return Promise.resolve({});
		}
		var url = REST_BASE + '/active?post_ids=' + encodeURIComponent(postIds.join(','));
		return fetch(url, { credentials: 'same-origin' })
			.then(function (res) {
				return res.ok ? res.json() : { tests: {} };
			})
			.catch(function () {
				return { tests: {} };
			});
	}

	function sendEvents(visitorId, events) {
		if (!events.length || !REST_BASE) {
			return;
		}
		var payload = JSON.stringify({ visitor_id: visitorId, events: events });
		if (navigator.sendBeacon) {
			var blob = new Blob([payload], { type: 'application/json' });
			navigator.sendBeacon(REST_BASE + '/events', blob);
			return;
		}
		fetch(REST_BASE + '/events', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: payload,
			keepalive: true,
		});
	}

	function init() {
		var postIds = collectPostIds();
		if (!postIds.length) {
			return;
		}

		var visitorId = getVisitorId();

		fetchActiveTests(postIds).then(function (data) {
			var tests = (data && data.tests) || {};
			var assignments = {};
			var nodesByPost = {};

			document.querySelectorAll('[data-aht-headline][data-aht-post-id]').forEach(function (el) {
				var postId = parseInt(el.getAttribute('data-aht-post-id'), 10);
				if (!postId) {
					return;
				}
				if (!nodesByPost[postId]) {
					nodesByPost[postId] = [];
				}
				nodesByPost[postId].push(el);
			});

			Object.keys(tests).forEach(function (key) {
				var test = tests[key];
				if (!test || !test.variants || !test.variants.length) {
					return;
				}
				var idx = hashPick(visitorId, test.test_id, test.variants.length);
				var variant = test.variants[idx];
				assignments[test.post_id] = {
					testId: test.test_id,
					variantId: variant.id,
					headline: variant.headline,
					scrollThreshold: parseFloat(test.scroll_threshold) || 30,
					timeThreshold: parseInt(test.time_threshold, 10) || 78,
				};
			});

			Object.keys(assignments).forEach(function (postIdKey) {
				var postId = parseInt(postIdKey, 10);
				var assignment = assignments[postId];
				var nodes = nodesByPost[postId] || [];
				nodes.forEach(function (el) {
					el.setAttribute('data-aht-test-id', String(assignment.testId));
					el.setAttribute('data-aht-variant-id', String(assignment.variantId));
					setHeadlineText(el, assignment.headline);
				});
			});

			setupTracking(visitorId, assignments, nodesByPost);
		});
	}

	function setupTracking(visitorId, assignments, nodesByPost) {
		var pending = [];
		var flushTimer = null;

		function queueEvent(testId, variantId, type) {
			pending.push({ test_id: testId, variant_id: variantId, type: type });
			if (flushTimer) {
				clearTimeout(flushTimer);
			}
			flushTimer = setTimeout(function () {
				var batch = pending.splice(0, pending.length);
				sendEvents(visitorId, batch);
			}, 400);
		}

		var impressed = {};

		if ('IntersectionObserver' in window) {
			var observer = new IntersectionObserver(
				function (entries) {
					entries.forEach(function (entry) {
						if (!entry.isIntersecting) {
							return;
						}
						var el = entry.target;
						var testId = parseInt(el.getAttribute('data-aht-test-id'), 10);
						var variantId = parseInt(el.getAttribute('data-aht-variant-id'), 10);
						if (!testId || !variantId) {
							return;
						}
						var key = testId + ':' + variantId;
						if (impressed[key]) {
							return;
						}
						impressed[key] = true;
						queueEvent(testId, variantId, 'impression');
					});
				},
				{ threshold: 0.25 }
			);

			document.querySelectorAll('[data-aht-test-id][data-aht-variant-id]').forEach(function (el) {
				observer.observe(el);
			});
		}

		document.querySelectorAll('[data-aht-test-id][data-aht-variant-id]').forEach(function (el) {
			el.addEventListener(
				'click',
				function () {
					var testId = parseInt(el.getAttribute('data-aht-test-id'), 10);
					var variantId = parseInt(el.getAttribute('data-aht-variant-id'), 10);
					if (testId && variantId) {
						queueEvent(testId, variantId, 'click');
					}
				},
				true
			);
		});

		var article = document.querySelector('.single-article, article.single-article, #singular-template article');
		if (article) {
			var postId = null;
			Object.keys(assignments).some(function (pid) {
				var nodes = nodesByPost[parseInt(pid, 10)] || [];
				if (nodes.some(function (n) {
					return article.contains(n);
				})) {
					postId = parseInt(pid, 10);
					return true;
				}
				return false;
			});

			if (postId && assignments[postId]) {
				var a = assignments[postId];
				var scrollFired = false;
				var timeFired = false;
				var threshold = a.scrollThreshold / 100;

				function onScroll() {
					if (scrollFired) {
						return;
					}
					var rect = article.getBoundingClientRect();
					var total = article.scrollHeight - window.innerHeight;
					if (total <= 0) {
						return;
					}
					var scrolled = window.scrollY - (article.offsetTop || 0);
					if (scrolled / total >= threshold) {
						scrollFired = true;
						queueEvent(a.testId, a.variantId, 'scroll');
					}
				}

				window.addEventListener('scroll', onScroll, { passive: true });

				setTimeout(function () {
					if (!timeFired) {
						timeFired = true;
						queueEvent(a.testId, a.variantId, 'time');
					}
				}, a.timeThreshold * 1000);
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
