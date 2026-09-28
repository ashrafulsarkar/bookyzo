/*
 * "Change the time" on the guest manage-booking page
 * (templates/manage-booking.php): lists the item's other open times and
 * moves the booking to the one picked. The REST route checks the signed
 * token and the cancellation window again; nothing here is trusted.
 * Config comes from window.byzoManageBooking (BYZO_Booking_Cancel).
 */
(function () {
	var config = window.byzoManageBooking;
	var openBtn = document.getElementById('byzo-reschedule-open');
	var panel = document.getElementById('byzo-reschedule-panel');
	var status = document.getElementById('byzo-reschedule-status');
	var times = document.getElementById('byzo-reschedule-times');
	var loaded = false;

	if (!config || !openBtn || !panel || !status || !times) {
		return;
	}

	function label(slot) {
		var date = new Date((slot.local_start_datetime || slot.start_datetime).replace(' ', 'T'));
		return date.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
	}

	function move(slot) {
		status.textContent = config.i18n.saving;
		times.innerHTML = '';
		fetch(config.rest + '/bookings/' + config.bookingId + '/reschedule', {
			method: 'PUT',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ slot_id: slot.id, token: config.token })
		}).then(function (response) {
			return response.json().then(function (data) { return { ok: response.ok, data: data }; });
		}).then(function (result) {
			if (!result.ok) { throw new Error(result.data && result.data.message); }
			status.textContent = config.i18n.done;
			window.location.reload();
		}).catch(function (error) {
			status.textContent = error.message || config.i18n.failed;
		});
	}

	openBtn.addEventListener('click', function () {
		panel.hidden = false;
		openBtn.hidden = true;
		if (loaded) { return; }
		loaded = true;
		fetch(config.rest + '/slots?bookable_item_id=' + config.itemId)
			.then(function (response) { return response.json(); })
			.then(function (slots) {
				var open = (slots || []).filter(function (slot) { return slot.id !== config.currentId; }).slice(0, 30);
				if (!open.length) { status.textContent = config.i18n.none; return; }
				status.textContent = config.i18n.pick;
				open.forEach(function (slot) {
					var button = document.createElement('button');
					button.type = 'button';
					button.className = 'byzo-manage-time';
					button.textContent = label(slot);
					button.addEventListener('click', function () { move(slot); });
					times.appendChild(button);
				});
			})
			.catch(function () { status.textContent = config.i18n.failed; });
	});
})();
