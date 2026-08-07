/**
 * Wave Workspace UI — lifecycle + Wave Scan Mode (extends M7 sink patterns).
 *
 * @package MPCommerceFulfillment
 */

import { api } from './api.js';

(function () {
	const root = document.querySelector('[data-mpcf-wave-workspace]');
	if (!root) {
		return;
	}

	const waveId = parseInt(root.getAttribute('data-mpcf-wave-id') || '0', 10);
	if (!waveId) {
		return;
	}

	let wave = null;
	let scanActive = false;
	let lastFocus = null;

	const els = {
		status: root.querySelector('[data-mpcf-wave-status]'),
		progress: root.querySelector('[data-mpcf-wave-progress]'),
		walk: root.querySelector('[data-mpcf-wave-walk]'),
		members: root.querySelector('[data-mpcf-wave-members]'),
		exceptions: root.querySelector('[data-mpcf-wave-exceptions]'),
		scanPanel: root.querySelector('[data-mpcf-wave-scan]'),
		scanStatus: root.querySelector('[data-mpcf-wave-scan-status]'),
		scanResult: root.querySelector('[data-mpcf-wave-scan-result]'),
		scanProgress: root.querySelector('[data-mpcf-wave-scan-progress]'),
	};

	async function load() {
		const data = await api(`waves/${waveId}`);
		wave = data;
		render();
	}

	function render() {
		if (!wave) {
			return;
		}
		els.status.textContent = `Wave #${wave.id} · ${wave.state} · v${wave.version} · ${wave.member_count} members`;
		const p = wave.progress || {};
		els.progress.textContent = `Remaining lines: ${p.remaining_lines ?? '—'} · Remaining qty: ${p.remaining_qty ?? '—'} · Completed: ${p.completed_fulfillments ?? '—'} · Remaining fulfillments: ${p.remaining_fulfillments ?? '—'}`;

		const rows = (wave.walk && wave.walk.rows) || [];
		els.walk.innerHTML = '<h2>Walk</h2><ol>' + rows.map((r) => {
			const loc = r.location_snapshot || '∅';
			return `<li>${escapeHtml(loc)} · ${escapeHtml(r.sku_snapshot || '')} × ${r.required_qty}${r.complete ? ' ✓' : ''}</li>`;
		}).join('') + '</ol>';

		els.members.innerHTML = '<h2>Members</h2><ul>' + (wave.members || []).map((m) => {
			const openUrl = `admin.php?page=mpcf-workspace&fulfillment_id=${m.fulfillment_id}`;
			return `<li>Fulfillment #${m.fulfillment_id}${m.picked_at ? ' (picked)' : ''} — <a href="${openUrl}">Open</a></li>`;
		}).join('') + '</ul>';

		const unfinished = (wave.members || []).filter((m) => !m.picked_at);
		els.exceptions.innerHTML = unfinished.length
			? `<h2>Remaining</h2><p>${unfinished.length} fulfillment(s) still open in this wave.</p>`
			: '<h2>Handoff</h2><p>All members picked — continue packing per order in Workspace.</p>';
	}

	async function mutate(path, body = {}) {
		body.version = wave.version;
		const data = await api(`waves/${waveId}/${path}`, { method: 'POST', body });
		wave = Object.assign({}, wave, data);
		if (data.progress) {
			wave.progress = data.progress;
		}
		await load();
		return data;
	}

	root.querySelector('[data-mpcf-wave-activate]')?.addEventListener('click', () => mutate('activate'));
	root.querySelector('[data-mpcf-wave-pause]')?.addEventListener('click', () => mutate('pause'));
	root.querySelector('[data-mpcf-wave-resume]')?.addEventListener('click', () => mutate('resume'));
	root.querySelector('[data-mpcf-wave-complete]')?.addEventListener('click', () => mutate('complete', { force: false }));
	root.querySelector('[data-mpcf-wave-abandon]')?.addEventListener('click', () => {
		if (window.confirm('Abandon this wave and release membership?')) {
			mutate('abandon');
		}
	});

	root.querySelector('[data-mpcf-wave-print]')?.addEventListener('click', async () => {
		const data = await api(`waves/${waveId}/documents`, { method: 'POST', body: {} });
		const w = window.open('', '_blank');
		if (w && data.html) {
			w.document.write(data.html);
			w.document.close();
			w.focus();
			w.print();
		}
	});

	function setScan(active) {
		scanActive = active;
		if (els.scanPanel) {
			els.scanPanel.hidden = !active;
		}
		if (els.scanStatus) {
			els.scanStatus.textContent = active ? 'Wave Scan Mode active — scan SKUs or MPCF item barcodes.' : '';
		}
		if (active) {
			const sink = root.querySelector('[data-mpcf-scan-sink]');
			if (sink) {
				sink.focus();
			}
		}
	}

	root.querySelector('[data-mpcf-wave-enter-scan]')?.addEventListener('click', () => setScan(true));
	root.querySelector('[data-mpcf-wave-exit-scan]')?.addEventListener('click', () => setScan(false));
	root.querySelector('[data-mpcf-wave-scan-undo]')?.addEventListener('click', async () => {
		const data = await api(`waves/${waveId}/scan`, {
			method: 'POST',
			body: { action: 'undo', version: wave.version },
		});
		if (els.scanResult) {
			els.scanResult.textContent = data.message || data.result || 'Undone';
		}
		await load();
	});

	document.addEventListener('data-mpcf-scan', async (event) => {
		if (!scanActive || !wave) {
			return;
		}
		const payload = event.detail && event.detail.value;
		if (!payload) {
			return;
		}
		try {
			const data = await api(`waves/${waveId}/scan`, {
				method: 'POST',
				body: { action: 'pick', payload, version: wave.version },
			});
			if (els.scanResult) {
				els.scanResult.textContent = `${data.message || data.result} (F#${(data.data && data.data.fulfillment_id) || ''})`;
			}
			if (els.scanProgress && data.data && data.data.progress) {
				const p = data.data.progress;
				els.scanProgress.textContent = `Remaining lines ${p.remaining_lines} · qty ${p.remaining_qty}`;
			}
			await load();
		} catch (err) {
			if (els.scanResult) {
				els.scanResult.textContent = err.message || 'Scan failed';
			}
		}
	});

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	}

	load().catch((err) => {
		if (els.status) {
			els.status.textContent = err.message || 'Failed to load wave';
		}
	});
})();
