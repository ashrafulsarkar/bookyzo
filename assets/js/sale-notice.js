/*
 * Black Friday admin notice (includes/class-byzo-sale-notice.php): live
 * countdown, copy-coupon button, and a dismiss cookie that hides the notice
 * until tomorrow. Config comes from window.byzoSaleNotice.
 */
(function () {
	var config = window.byzoSaleNotice;
	var notice = document.getElementById('byzo-bf-sale');
	if (!config || !notice) {
		return;
	}

	// Delegation also handles the dismiss button WordPress adds later.
	notice.addEventListener('click', function (event) {
		if (!event.target.closest('.notice-dismiss')) {
			return;
		}
		document.cookie = config.cookieName + '=' + config.cookieValue
			+ '; expires=' + config.cookieExpires
			+ '; path=' + config.cookiePath
			+ '; SameSite=Lax' + (window.location.protocol === 'https:' ? '; Secure' : '');
	});

	var endAt = Date.now() + config.remaining * 1000;
	var units = {};
	Array.prototype.forEach.call(notice.querySelectorAll('.byzo-sale-countdown [data-unit]'), function (el) {
		units[el.getAttribute('data-unit')] = el;
	});
	function pad(n) {
		return n < 10 ? '0' + n : String(n);
	}
	var countdownTimer;
	function tick() {
		var left = Math.max(0, Math.floor((endAt - Date.now()) / 1000));
		if (left <= 0) {
			// Sale is over: drop the banner instead of showing 00:00:00:00.
			clearInterval(countdownTimer);
			if (notice.parentNode) {
				notice.parentNode.removeChild(notice);
			}
			return;
		}
		units.d.textContent = pad(Math.floor(left / 86400));
		units.h.textContent = pad(Math.floor(left % 86400 / 3600));
		units.m.textContent = pad(Math.floor(left % 3600 / 60));
		units.s.textContent = pad(left % 60);
	}
	countdownTimer = setInterval(tick, 1000);
	tick();

	var button = notice.querySelector('.byzo-sale-copy');
	var status = notice.querySelector('.byzo-sale-status');
	var icon = button.querySelector('.dashicons');
	var resetTimer;
	function showResult(success) {
		status.textContent = success ? config.copied : config.copyFailed;
		icon.classList.toggle('dashicons-yes', success);
		icon.classList.toggle('dashicons-admin-page', !success);
		clearTimeout(resetTimer);
		resetTimer = setTimeout(function () {
			icon.classList.remove('dashicons-yes');
			icon.classList.add('dashicons-admin-page');
		}, 2500);
	}
	function fallbackCopy() {
		var field = document.createElement('textarea');
		field.value = config.coupon;
		field.style.position = 'fixed';
		field.style.opacity = '0';
		document.body.appendChild(field);
		field.select();
		var copied = false;
		try { copied = document.execCommand('copy'); } catch (error) {}
		document.body.removeChild(field);
		button.focus();
		showResult(copied);
	}
	button.addEventListener('click', function () {
		status.textContent = '';
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(config.coupon).then(function () { showResult(true); }, fallbackCopy);
		} else {
			fallbackCopy();
		}
	});
}());
