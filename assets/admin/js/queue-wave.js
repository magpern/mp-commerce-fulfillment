/**
 * Queue helpers for creating a wave from selected fulfillments.
 *
 * @package MPCommerceFulfillment
 */

import { api } from './api.js';

(function () {
	const form = document.querySelector('form.mpcf-queue-form, form[method="post"]');
	const host = document.querySelector('.mpcf-queue-bulk-actions');
	if (!host) {
		return;
	}

	const btn = document.createElement('button');
	btn.type = 'button';
	btn.className = 'button';
	btn.textContent = 'Create wave from selection';
	btn.setAttribute('data-mpcf-create-wave', '1');
	host.appendChild(document.createTextNode(' '));
	host.appendChild(btn);

	btn.addEventListener('click', async () => {
		const checked = Array.from(document.querySelectorAll('input[name="fulfillment_ids[]"]:checked, input[name="ids[]"]:checked'));
		const ids = checked.map((el) => parseInt(el.value, 10)).filter((n) => n > 0);
		if (!ids.length) {
			window.alert('Select at least one fulfillment.');
			return;
		}
		try {
			const data = await api('waves', {
				method: 'POST',
				body: { warehouse_id: 1, fulfillment_ids: ids },
			});
			const waveId = data.id;
			const base = window.mpcfWavePage || 'admin.php?page=mpcf-wave';
			window.location.href = `${base}&wave_id=${waveId}`;
		} catch (err) {
			window.alert(err.message || 'Could not create wave');
		}
	});
})();
