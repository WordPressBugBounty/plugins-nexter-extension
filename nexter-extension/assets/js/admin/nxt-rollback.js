document.addEventListener('DOMContentLoaded', () => {
	const form = document.querySelector('.nxt-rb-form');
	if (!form) return;

	const formLabels = form.querySelectorAll('label');
	const formSubmitBtn = document.querySelector('.nxt-ext-rb-popup');
	const rollbackSelect = document.getElementById('nxt_rb_selected_version');
	const selectedVerInput = document.getElementById('nxt_selected_ver');

	if (formSubmitBtn && rollbackSelect && selectedVerInput) {
		formSubmitBtn.classList.remove('nxt-rb-disabled');

		// Set selected version
		selectedVerInput.value = rollbackSelect.value;

		// Handle submit button click
		formSubmitBtn.addEventListener('click', () => {
			const rollbackVersion = selectedVerInput.value;
			const installedVersion = form.querySelector('input[name="installed_version"]')?.value || '';
			const newVersion = form.querySelector('input[name="new_version"]')?.value || '';
			const rollbackName = form.querySelector('input[name="rollback_name"]')?.value || '';

			if (!rollbackVersion) {
				hideModal();
				return;
			}

			//showModal();
			document.querySelector('.nxt-br-plugin-name').textContent = rollbackName;
			document.querySelector('.nxt-br-current-version').textContent = installedVersion;
			document.querySelector('.nxt-br-new-version').textContent = rollbackVersion;
		});

		// Handle version change
		rollbackSelect.addEventListener('change', () => {
			selectedVerInput.value = rollbackSelect.value;
			formLabels.forEach(label => label.classList.remove('nxt-selected'));
			formSubmitBtn.classList.remove('nxt-rb-disabled');
			rollbackSelect.classList.add('nxt-selected');
		});
	}

	// Modal open/close helpers
	function showModal() {
		document.querySelector('.nxt-ext-rb-submit-wrap')?.classList.add('active-rb');
		document.querySelector('.nxt-rb-confirm-popup')?.setAttribute('style', 'display: flex;');
	}

	function hideModal() {
		document.querySelector('.nxt-ext-rb-submit-wrap')?.classList.remove('active-rb');
		document.querySelector('.nxt-rb-confirm-popup')?.setAttribute('style', 'display: none;');
	}

	// Handle modal close
	document.querySelector('.nxtext_close_icon')?.addEventListener('click', (e) => {
		e.preventDefault();
		hideModal();
	});

	document.querySelector('.nxt-br-close')?.addEventListener('click', (e) => {
		e.preventDefault();
		hideModal();
	});

	// Handle submit/go button
	document.querySelector('.nxt-br-submit')?.addEventListener('click', (e) => {
		e.preventDefault();
		form.submit();
	});

	// Handle external open modal button
	document.querySelector('.nxt-ext-rb-popup')?.addEventListener('click', (e) => {
		showModal();
	});
});

/* Theme Rollback */
(function () {
	let watchContentChange = [];
	const contentChange = (elements, callback) => {
	  elements.forEach((el) => {
		el.dataset.lastContents = el.innerHTML;
		watchContentChange.push({ element: el, callback });
	  });
	};
  
	setInterval(() => {
	  watchContentChange.forEach((entry) => {
		if (entry.element.dataset.lastContents !== entry.element.innerHTML) {
		  entry.callback.call(entry.element);
		  entry.element.dataset.lastContents = entry.element.innerHTML;
		}
	  });
	}, 150);
  
	document.addEventListener('DOMContentLoaded', () => {
	  const themes = window.wp.themes || {};
	  themes.data = typeof _wpThemeSettings !== 'undefined' ? _wpThemeSettings : '';
  
	  if (themes?.data?.themes?.length === 1) {
		nxtThemeRollback(themes.data.themes[0].id);
	  }
  
	  contentChange(Array.from(document.querySelectorAll('.theme-overlay')), () => {
		const clickedTheme = getParameterByName('theme');
		if (!isRollbackBtnThere()) {
		  nxtThemeRollback(clickedTheme);
		}
	  });
  
	  function isRollbackBtnThere() {
		return document.querySelector('.nxt-theme-rollback') !== null;
	  }
  
	  function nxtThemeRollback(theme) {
		const themeData = getThemeData(theme);
		if (themeData && themeData.hasRollback !== false) {
		  const activeTheme = document.querySelector('.theme-overlay.active');
		  const positionRight = activeTheme ? '200px' : '200px';
		  const rollbackBtn = document.createElement('a');
  
		  rollbackBtn.href = encodeURI(
			`admin.php?page=nxt-rollback&type=theme&theme_file=${theme}&current_version=${themeData.version}&rollback_name=${themeData.name}&_wpnonce=${nxt_rb_vars.nonce}`
		  );
		  rollbackBtn.className = 'button-secondary nxt-theme-rollback';
		  rollbackBtn.style.position = 'absolute';
		  rollbackBtn.style.right = positionRight;
		  rollbackBtn.style.bottom = '10px';
		  rollbackBtn.textContent = nxt_rb_vars.btn_label;
  
		  document.querySelector('.theme-wrap .theme-actions').appendChild(rollbackBtn);
		} else {
		  const span = document.createElement('span');
		  span.className = 'no-rollback';
		  span.style.cssText = 'position: absolute;left: 23px;bottom: 16px;font-size: 12px;font-style: italic;color: rgb(181, 181, 181);';
		  span.textContent = nxt_rb_vars.non_rollbackable;
		  document.querySelector('.theme-wrap .theme-actions').appendChild(span);
		}
	  }
  
	  function getThemeData(theme) {
		const themeData = wp.themes.data.themes;
		return themeData.find(t => t.id === theme) || null;
	  }
  
	  function getParameterByName(name) {
		const regex = new RegExp(`[?&]${name}=([^&#]*)`);
		const results = regex.exec(location.search);
		return results ? decodeURIComponent(results[1].replace(/\+/g, ' ')) : '';
	  }
  
	  document.body.addEventListener('click', (e) => {
		if (e.target.matches('.nxt-theme-rollback')) {
		  window.location.href = e.target.getAttribute('href');
		}
	  });
	});
})();