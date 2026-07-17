/**
 * Nexter SEO — admin "SEO Checks" column popover.
 * Toggles the details popover and lazily fetches the checklist from the REST endpoint.
 * Config is provided via wp_localize_script as window.nxtSeoChecks.
 */
(function(){
	var cfg = window.nxtSeoChecks || {};
	function esc(s){ var d=document.createElement('div'); d.textContent = (s==null?'':String(s)); return d.innerHTML; }
	function closeAll(except){
		var open = document.querySelectorAll('.nxt-seo-checks__badge[aria-expanded="true"]');
		for (var i=0;i<open.length;i++){
			if (open[i]===except){ continue; }
			open[i].setAttribute('aria-expanded','false');
			var w = open[i].closest('.nxt-seo-checks');
			var d = w ? w.querySelector('.nxt-seo-checks__detail') : null;
			if (d){ d.hidden = true; }
		}
	}
	function render(data){
		var list = (data && Array.isArray(data.checklist)) ? data.checklist : [];
		var score = (data && typeof data.seo_score !== 'undefined') ? data.seo_score : '';
		var head = '<div class="nxt-seo-checks__detail-head"><span>'+esc(cfg.i18n&&cfg.i18n.title||'SEO Checks')+'</span><span>'+esc(cfg.i18n&&cfg.i18n.score||'Score')+': '+esc(score)+'</span></div>';
		var rows = '';
		for (var i=0;i<list.length;i++){
			var it = list[i]||{};
			var st = it.status || 'warning';
			var ico = st==='pass' ? '✓' : (st==='error' ? '✕' : '!');
			rows += '<li class="nxt-seo-checks__item nxt-seo-checks__item--'+esc(st)+'">'
				+ '<span class="nxt-seo-checks__ico" aria-hidden="true">'+ico+'</span>'
				+ '<span class="nxt-seo-checks__txt"><strong>'+esc(it.label||'')+'</strong>'+(it.text?' — '+esc(it.text):'')+'</span>'
				+ '</li>';
		}
		if (!rows){ rows = '<li class="nxt-seo-checks__item">'+esc(cfg.i18n&&cfg.i18n.error||'')+'</li>'; }
		return head + '<ul class="nxt-seo-checks__items">'+rows+'</ul>';
	}
	document.addEventListener('click', function(e){
		var badge = e.target && e.target.closest ? e.target.closest('.nxt-seo-checks__badge') : null;
		if (!badge){
			// Click outside any open popover closes them.
			if (!e.target || !e.target.closest || !e.target.closest('.nxt-seo-checks__detail')){ closeAll(null); }
			return;
		}
		var wrap = badge.closest('.nxt-seo-checks');
		var detail = wrap ? wrap.querySelector('.nxt-seo-checks__detail') : null;
		if (!detail){ return; }
		var isOpen = badge.getAttribute('aria-expanded') === 'true';
		closeAll(badge);
		if (isOpen){ badge.setAttribute('aria-expanded','false'); detail.hidden = true; return; }
		badge.setAttribute('aria-expanded','true'); detail.hidden = false;
		if (detail.getAttribute('data-loaded') === '1'){ return; }
		detail.innerHTML = '<div class="nxt-seo-checks__loading">'+esc(cfg.i18n&&cfg.i18n.loading||'Analyzing…')+'</div>';
		var url;
		var termId = wrap.getAttribute('data-term');
		if (termId){
			url = cfg.termRoot + termId + '?taxonomy=' + encodeURIComponent(wrap.getAttribute('data-taxonomy')||'');
		} else {
			url = cfg.root + wrap.getAttribute('data-post');
		}
		fetch(url, { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' })
			.then(function(r){ return r.json(); })
			.then(function(res){ detail.innerHTML = render(res && res.data ? res.data : {}); detail.setAttribute('data-loaded','1'); })
			.catch(function(){ detail.innerHTML = '<div class="nxt-seo-checks__err">'+esc(cfg.i18n&&cfg.i18n.error||'')+'</div>'; });
	});
	document.addEventListener('keydown', function(e){ if (e.key === 'Escape'){ closeAll(null); } });
})();
