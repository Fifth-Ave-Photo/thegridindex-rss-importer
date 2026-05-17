/**
 * TheGridIndex RSS Importer — admin page behaviors.
 *
 * v1.0.63 — Extracted from the inline <script> block previously emitted by
 * render_admin_page() into a standalone script enqueued via wp_enqueue_script().
 * Reads its dynamic config (AJAX URL, nonces, action names, i18n strings) from
 * window.gipRssCfg, populated by wp_localize_script(). Contains:
 *   - Feed list add/remove (top-of-list insertion, focus, reindex).
 *   - Tab switching with URL-hash sync.
 *   - AJAX auto-save (debounced 800ms after typing).
 *   - Toast notifications (success / error, auto-dismiss).
 *   - Long-action button feedback (spinner, disabled state).
 *   - Import progress polling (writes step-by-step state from a transient).
 *   - Single-feed fetch (per-row button).
 *   - Status-banner one-click "Switch to Publish & fix existing drafts."
 */

(function(){
	/* ---------- Feed list add/remove ---------- */
	var list   = document.getElementById('gip-rss-feeds-list');
	var addBtn = document.getElementById('gip-rss-add-row-top');
	if ( list && addBtn ) {
		var reindex = function() {
			var rows = list.querySelectorAll('.gip-rss-feed-row');
			rows.forEach(function(row, i){
				row.querySelectorAll('input,select').forEach(function(el){
					if ( el.name ) {
						el.name = el.name.replace(/feeds\[\d+\]/, 'feeds[' + i + ']');
					}
				});
			});
		};

		addBtn.addEventListener('click', function(){
			var rows = list.querySelectorAll('.gip-rss-feed-row');
			if ( rows.length === 0 ) return;
			var newRow = rows[0].cloneNode(true);
			newRow.querySelectorAll('input').forEach(function(el){ el.value = ''; });
			newRow.querySelectorAll('select').forEach(function(el){ el.selectedIndex = 0; });
			// Reset status dot to 'never' on the cloned row.
			newRow.querySelectorAll('.gip-dot').forEach(function(el){
				el.className = 'gip-dot gip-dot--never';
				el.setAttribute('title', 'Never fetched');
				el.setAttribute('aria-label', 'Never fetched');
			});
			// v1.0.28 — reset the Last Fetched cell to "Never" on cloned row.
			newRow.querySelectorAll('.gip-rss-feed-row__fetched').forEach(function(el){
				el.className = 'gip-rss-feed-row__fetched gip-rss-feed-row__fetched--never';
				el.removeAttribute('title');
				el.textContent = 'Never';
			});
			newRow.classList.remove('is-detail-open');
			// v1.0.20 — insert at the top of the list (right after the
			// header row) so the new row is visible without scrolling.
			// Then focus the URL input so the user can start typing.
			var header = list.querySelector('.gip-rss-feeds-header');
			if ( header && header.nextSibling ) {
				list.insertBefore(newRow, header.nextSibling);
			} else {
				list.appendChild(newRow);
			}
			reindex();
			var firstInput = newRow.querySelector('input[type="url"], input');
			if ( firstInput ) firstInput.focus();
		});

		list.addEventListener('click', function(e){
			var t = e.target;
			if ( t && t.classList.contains('gip-rss-remove-row') ) {
				/* Click a status dot to toggle the detail row beneath it. */
				var rows = list.querySelectorAll('.gip-rss-feed-row');
				var row  = t.closest('.gip-rss-feed-row');

				// v1.0.21 — Persist the delete. Previously clicking × only
				// removed the row from the DOM; the deletion wasn't saved
				// until the user remembered to click "Save Feeds". If they
				// switched tabs or refreshed first, the row came back.
				// Now we remove the row AND submit the surrounding form so
				// the delete is committed server-side immediately.
				if ( rows.length <= 1 ) {
					// Last row — empty it instead of removing so the form
					// still has at least one row to display after save.
					row.querySelectorAll('input').forEach(function(el){ el.value = ''; });
					row.querySelectorAll('select').forEach(function(el){ el.selectedIndex = 0; });
				} else {
					row.remove();
				}
				reindex();

				// Submit the parent form, if any, to persist.
				var form = list.closest('form');
				if ( form ) form.submit();
			}
			/* Click a status dot to toggle the detail row beneath it. */
			if ( t && t.classList.contains('gip-dot') ) {
				var row = t.closest('.gip-rss-feed-row');
				if ( row ) {
					row.classList.toggle('is-detail-open');
					var detail = row.querySelector('.gip-rss-feed-row__detail');
					if ( detail ) detail.hidden = ! row.classList.contains('is-detail-open');
				}
			}
		});
	}

	/* ---------- Tabs ---------- */
	var tabs   = document.querySelectorAll('.gip-tab');
	var panels = document.querySelectorAll('.gip-tab-panel');
	if ( tabs.length && panels.length ) {
		var activate = function(name) {
			tabs.forEach(function(t){
				var on = t.getAttribute('data-tab') === name;
				t.classList.toggle('is-active', on);
				t.setAttribute('aria-selected', on ? 'true' : 'false');
			});
			panels.forEach(function(p){
				var on = p.getAttribute('data-panel') === name;
				p.classList.toggle('is-active', on);
				p.hidden = ! on;
			});
			// Keep URL hash in sync so refresh/share stays on the same tab.
			if ( history.replaceState ) {
				history.replaceState(null, '', '#' + name);
			}
		};
		tabs.forEach(function(t){
			t.addEventListener('click', function(){
				activate(t.getAttribute('data-tab'));
			});
		});
		/* Honor the URL hash on first load. */
		var initial = (window.location.hash || '').replace('#', '');
		var valid   = ['feeds','catalog','settings','diagnostics','support'];
		if ( valid.indexOf(initial) !== -1 ) {
			activate(initial);
		}

		/* v1.0.42 — Banner click handler. Clicking "Review on Diagnostics"
		   from the Feeds-tab duplicate banner switches to the Diagnostics
		   tab AND scrolls to the duplicate-detector card. Native <a href>
		   would only set the hash; we additionally need to activate the
		   panel before scrolling (the panel is hidden until then). */
		var jumpLinks = document.querySelectorAll('[data-jump-to-dup]');
		jumpLinks.forEach(function(link){
			link.addEventListener('click', function(e){
				e.preventDefault();
				activate('diagnostics');
				// Wait one tick for the panel to unhide before scrolling
				// — scrollIntoView on a hidden element is a no-op.
				setTimeout(function(){
					var target = document.getElementById('gip-dup-detector');
					if ( target ) {
						target.scrollIntoView({ behavior: 'smooth', block: 'start' });
					}
				}, 30);
			});
		});
	}

	/* ---------- FAQ search filter (v1.0.43) ---------- */
	/* Live client-side filter over the support tab's FAQ entries.
	   Reads data-faq-q and data-faq-a (set by PHP), case-insensitive
	   substring match, hides non-matching entries AND empty sections.
	   No external dependencies. */
	var faqInput = document.getElementById('gip-faq-search');
	var faqHint  = document.getElementById('gip-faq-search-hint');
	if ( faqInput ) {
		var faqItems    = Array.prototype.slice.call(document.querySelectorAll('.gip-faq-item'));
		var faqSections = Array.prototype.slice.call(document.querySelectorAll('.gip-faq-section'));
		var filterFaq = function() {
			var q = (faqInput.value || '').trim().toLowerCase();
			var visible = 0;
			faqItems.forEach(function(it){
				if ( q === '' ) {
					it.classList.remove('is-hidden');
					it.open = false;
					visible++;
					return;
				}
				var hay = ((it.getAttribute('data-faq-q') || '') + ' ' + (it.getAttribute('data-faq-a') || '')).toLowerCase();
				if ( hay.indexOf(q) !== -1 ) {
					it.classList.remove('is-hidden');
					it.open = true; // auto-open matched answers
					visible++;
				} else {
					it.classList.add('is-hidden');
				}
			});
			// Hide entire sections whose items are all hidden.
			faqSections.forEach(function(sec){
				var any = sec.querySelectorAll('.gip-faq-item:not(.is-hidden)').length > 0;
				sec.classList.toggle('is-hidden', !any);
			});
			if ( faqHint ) {
				faqHint.textContent = q === ''
					? ''
					: (visible + ' match' + (visible === 1 ? '' : 'es'));
			}
		};
		faqInput.addEventListener('input', filterFaq);
	}

	/* ---------- Auto-save (debounced) v1.0.22 ---------- */
	/* Listens for input/change on the main settings form. After 800ms of
	   idle, POSTs the whole form to admin-ajax.php and updates the inline
	   "Saved ✓ / Saving… / Save failed" indicator. No page reload. */
	var cfg    = window.gipRssCfg;
	var form   = document.querySelector('form input[name="action"][value$="_save"]');
	form       = form ? form.closest('form') : null;
	var ind    = document.getElementById('gip-save-indicator');
	if ( form && cfg && cfg.ajaxUrl ) {
		var saveTimer = null;
		var setIndicator = function(state, label) {
			if ( ! ind ) return;
			ind.textContent = label || '';
			ind.className   = 'gip-save-indicator' + (state ? ' is-' + state : '');
		};
		var doSave = function() {
			setIndicator('saving', cfg.i18n.saving);
			var fd = new FormData(form);
			// Swap the action to our AJAX endpoint + add the AJAX nonce.
			fd.set('action', cfg.saveAction);
			fd.set('_ajax_nonce', cfg.nonce);
			fetch(cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: fd
			}).then(function(r){
				if ( ! r.ok ) throw new Error('http ' + r.status);
				return r.json();
			}).then(function(json){
				if ( json && json.success ) {
					setIndicator('saved', '✓ ' + cfg.i18n.saved);
					// Auto-fade the "Saved" label after a couple seconds.
					setTimeout(function(){ setIndicator('', ''); }, 2200);
				} else {
					setIndicator('error', cfg.i18n.error);
				}
			}).catch(function(){
				setIndicator('error', cfg.i18n.error);
			});
		};
		var schedule = function() {
			clearTimeout(saveTimer);
			saveTimer = setTimeout(doSave, 800);
		};
		form.addEventListener('input',  schedule);
		form.addEventListener('change', schedule);
	}

	/* ---------- Toast notifications v1.0.22 + Fetch now action v1.0.36 ---------- */
	/* The plugin already passes status messages back via the
	   gip_rss_msg URL param. Render the existing notice div as a
	   slide-in toast that auto-dismisses, instead of a static
	   banner the user has to find.
	   
	   v1.0.36: If the redirect carried data-added-idx (a catalog ADD
	   just happened), don't auto-dismiss — inject a "Fetch now"
	   action button so the user can immediately verify the new feed
	   works without leaving the Catalog tab. */
	var notice = document.querySelector('.gi-notice');
	if ( notice && notice.textContent.trim() ) {
		notice.classList.add('gip-toast');

		var addedIdx  = notice.getAttribute('data-added-idx');
		var addedName = notice.getAttribute('data-added-name') || '';
		var isAddToast = ( addedIdx !== null && addedIdx !== '' );

		if ( isAddToast ) {
			// Wrap the existing message text + add action buttons.
			var msg = notice.textContent;
			notice.textContent = '';
			var msgEl = document.createElement('div');
			msgEl.className = 'gip-toast__msg';
			msgEl.textContent = msg;
			notice.appendChild(msgEl);

			var actions = document.createElement('div');
			actions.className = 'gip-toast__actions';

			var fetchBtn = document.createElement('button');
			fetchBtn.type = 'button';
			fetchBtn.className = 'gi-btn gi-btn--primary gip-toast__btn';
			fetchBtn.textContent = '↻ Fetch now';

			var dismissBtn = document.createElement('button');
			dismissBtn.type = 'button';
			dismissBtn.className = 'gi-btn gi-btn--ghost gip-toast__btn';
			dismissBtn.textContent = 'Dismiss';

			actions.appendChild(fetchBtn);
			actions.appendChild(dismissBtn);
			notice.appendChild(actions);

			dismissBtn.addEventListener('click', function(){
				notice.classList.add('is-leaving');
				setTimeout(function(){ notice.style.display = 'none'; }, 400);
			});

			fetchBtn.addEventListener('click', function(){
				if ( fetchBtn.disabled ) return;
				fetchBtn.disabled = true;
				dismissBtn.disabled = true;
				fetchBtn.innerHTML = '<span class="gip-spin"></span> ' +
					(addedName ? 'Fetching: ' + escapeHtml(addedName) + '…' : 'Fetching…');
				// Use the existing progress poller — it'll pick up
				// transient writes from run_import() and update the
				// button text live ("Fetching 1 of 1 — Name…").
				startPolling(fetchBtn);

				var fd = new FormData();
				fd.set('action', cfg.fetchOneAction);
				fd.set('_ajax_nonce', cfg.nonce);
				fd.set('feed_index', addedIdx);

				fetch(cfg.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: fd
				}).then(function(r){
					return r.ok ? r.json() : Promise.reject(new Error('http ' + r.status));
				}).then(function(json){
					// Stop progress polling immediately on success.
					if ( progressTimer ) { clearInterval(progressTimer); progressTimer = null; }
					if ( ! json || ! json.success || ! json.data ) {
						fetchBtn.innerHTML = '⚠ Fetch failed';
						return;
					}
					var d = json.data;
					var n = parseInt(d.last_imported, 10) || 0;
					var s = parseInt(d.last_skipped,  10) || 0;
					var e = parseInt(d.last_errors,   10) || 0;
					fetchBtn.innerHTML = '✓ ' + n + ' new, ' + s + ' already imported' + (e ? ', ' + e + ' errors' : '');
					fetchBtn.classList.remove('gi-btn--primary');
					fetchBtn.classList.add('gi-btn--ghost');
					// Auto-dismiss after a short read-time so user can move on.
					setTimeout(function(){
						notice.classList.add('is-leaving');
						setTimeout(function(){ notice.style.display = 'none'; }, 400);
					}, 4500);
				}).catch(function(){
					if ( progressTimer ) { clearInterval(progressTimer); progressTimer = null; }
					fetchBtn.innerHTML = '⚠ Fetch failed';
				});
			});

			// No auto-dismiss while a Fetch now button is offered.
		} else {
			// Normal toast: auto-dismiss after 5 seconds (errors stay visible longer).
			var ttl = notice.classList.contains('gi-notice--reset') ? 9000 : 5000;
			setTimeout(function(){ notice.classList.add('is-leaving'); }, ttl - 400);
			setTimeout(function(){ notice.style.display = 'none'; }, ttl);
		}
	}

	/* ---------- Long-action loading state v1.0.23 + progress polling v1.0.26 ---------- */
	/* Import Now, Force re-import, and per-row Fetch are synchronous
	   requests that can take 30+ seconds (often 2+ minutes). Disable
	   the button on submit, swap the label to a working state, pulse
	   a spinner, AND start polling the progress endpoint so the
	   label updates live with "Fetching 3 of 11 — TechCrunch". */
	var labelFor = {
		import: 'Importing feeds — this can take 30-60 seconds…',
		force:  'Re-importing last 24 hours — this can take a minute…',
		fetch:  'Fetching…'
	};

	var progressTimer = null;
	var progressLabel = null; // The button element we're updating.
	var pollProgress = function() {
		if ( ! cfg || ! cfg.ajaxUrl || ! progressLabel ) return;
		var url = cfg.ajaxUrl + '?action=' + encodeURIComponent(cfg.progressAction) + '&_ajax_nonce=' + encodeURIComponent(cfg.nonce);
		fetch(url, { credentials: 'same-origin' })
			.then(function(r){ return r.ok ? r.json() : null; })
			.then(function(json){
				if ( ! json || ! json.success || ! json.data ) return;
				var data = json.data;
				if ( data.idle ) return; // Run hasn't started writing progress yet.
				if ( data.state === 'done' ) {
					// Page reload is imminent; show 100% briefly.
					progressLabel.innerHTML = '<span class="gip-spin"></span> Finishing up…';
					return;
				}
				// Running: show "Fetching N of M — Name"
				var current = data.current || '';
				var step    = parseInt(data.step, 10)  || 0;
				var total   = parseInt(data.total, 10) || 0;
				if ( total > 0 ) {
					progressLabel.innerHTML = '<span class="gip-spin"></span> Fetching ' + step + ' of ' + total +
						(current ? ' — ' + escapeHtml(current) : '') + '…';
				}
			})
			.catch(function(){ /* swallow polling errors */ });
	};
	var escapeHtml = function(s){
		return String(s).replace(/[&<>"']/g, function(c){
			return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
		});
	};
	var startPolling = function(btn) {
		progressLabel = btn;
		if ( progressTimer ) clearInterval(progressTimer);
		// First poll right away, then every 1s.
		pollProgress();
		progressTimer = setInterval(pollProgress, 1000);
	};

	// Form-based actions (Import Now, Force re-import).
	document.querySelectorAll('form[data-gip-long-action]').forEach(function(form){
		form.addEventListener('submit', function(){
			var kind = form.getAttribute('data-gip-long-action');
			// The triggering button is usually inside the form. But for
			// hidden forms submitted via HTML5 `form="..."` attribute
			// (the Feeds-toolbar Import/Force buttons), the button lives
			// outside the form. Fall back to looking up buttons that
			// declare form="<this form's id>" in that case.
			var btn = form.querySelector('button[type="submit"]');
			if ( ! btn && form.id ) {
				btn = document.querySelector('button[form="' + form.id + '"]');
			}
			if ( ! btn ) return;
			btn.disabled = true;
			btn.classList.add('is-loading');
			btn.dataset.originalLabel = btn.textContent;
			btn.innerHTML = '<span class="gip-spin"></span> ' + (labelFor[kind] || 'Working…');
			// Also dim any other long-action submit buttons (form=… or in-form).
			document.querySelectorAll('button[type="submit"][form^="gip-"], form[data-gip-long-action] button[type="submit"]').forEach(function(otherBtn){
				if ( otherBtn !== btn ) otherBtn.disabled = true;
			});
			// Start live progress polling for full-import runs.
			if ( kind === 'import' || kind === 'force' ) {
				startPolling(btn);
			}
		});
	});
	// Link-based action (per-row Fetch).
	document.querySelectorAll('a.gip-fetch-link').forEach(function(link){
		link.addEventListener('click', function(){
			if ( link.classList.contains('is-loading') ) {
				// Prevent double-click queuing another fetch.
				return;
			}
			link.classList.add('is-loading');
			link.dataset.originalLabel = link.textContent;
			link.innerHTML = '<span class="gip-spin gip-spin--small"></span>';
			link.style.pointerEvents = 'none';
		});
	});
})();
