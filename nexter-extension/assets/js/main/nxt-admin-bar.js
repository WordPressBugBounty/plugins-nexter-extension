"use strict";
document.addEventListener('DOMContentLoaded', function () {
	var nxt_adminBar = {
		init: function () {
			this.createMenu(NexterAdminBar);

			const menuItems = ['nxt_edit_template', 'nxt_edit_snippets'];
			menuItems.forEach(id => {
				const adminItem = document.querySelector('#wp-admin-bar-' + id);
				if (adminItem) {
					adminItem.classList.add('menupop');
					adminItem.addEventListener('mouseenter', e => e.currentTarget.classList.add('hover'));
					adminItem.addEventListener('mouseleave', e => e.currentTarget.classList.remove('hover'));
				}
			});
		},

		createMenu: function (admnBar) {
			let nexterList = '', otherList = '', snippetList = '';

			// Templates
			if (admnBar?.nxt_edit_template) {
				admnBar.nxt_edit_template.forEach(item => {
					const type = (item.post_type === 'nxt_builder') ? item.nexter_type : item.post_type_name;
					const templateHTML = `
						<li id="wp-admin-bar-${item.id}" class="nxt-admin-submenu nxt-admin-${item.id}">
							<a class="ab-item nxt-admin-sub-item" href="${item.edit_url}">
								<span class="nxt-admin-item-title">${item.title}</span>
								${type ? `<span class="nxt-admin-item-type">${type}</span>` : ''}
							</a>
						</li>`;
					
					if (item.post_type === 'nxt_builder') {
						nexterList += templateHTML;
					} else {
						otherList += templateHTML;
					}
				});
			}

			// Snippets: Nested by type
			if (admnBar?.nxt_edit_snippet) {
				Object.entries(admnBar.nxt_edit_snippet).forEach(([type, snippets]) => {
					if (!snippets || Object.keys(snippets).length === 0) return;

					let innerList = '';
					Object.values(snippets).forEach(snippet => {
						innerList += `
							<li id="wp-admin-bar-snippet-${snippet.id}" class="nxt-admin-submenu">
								<a class="ab-item nxt-admin-sub-item" href="${snippet.edit_url}">
									<span class="nxt-admin-item-title">${snippet.title}</span>
								</a>
							</li>`;
					});

					snippetList += `
						<li class="nxt-admin-snippet-type">
							<a class="ab-item" tabindex="-1">${type.toUpperCase()}</a>
							<ul class="ab-submenu">
								${innerList}
							</ul>
						</li>`;
				});
			}

			// Insert template items
			if (nexterList || otherList) {
				const templateWrap = `
					<div class="ab-sub-wrapper">
						${otherList ? `<ul class="ab-submenu">${otherList}</ul>` : ''}
						${nexterList ? `<ul class="ab-submenu nxt-edit-nexter">${nexterList}</ul>` : ''}
					</div>`;
				const editTemplate = document.querySelector('.nxt_edit_template');
				if (editTemplate) {
					editTemplate.insertAdjacentHTML('beforeend', templateWrap);
				}
			} else {
				const el = document.querySelector('#wp-admin-bar-nxt_edit_template');
				if (el) el.style.display = "none";
			}

			// Insert snippet items with nested grouping
			if (snippetList) {
				const snippetWrap = `
					<div class="ab-sub-wrapper">
						<ul class="ab-submenu nxt-edit-snippets">${snippetList}</ul>
					</div>`;
				const editSnippets = document.querySelector('.nxt_edit_snippets');
				if (editSnippets) {
					editSnippets.insertAdjacentHTML('beforeend', snippetWrap);
				}
			} else {
				const el = document.querySelector('#wp-admin-bar-nxt_edit_snippets');
				if (el) el.style.display = "none";
			}
		},
	};
	nxt_adminBar.init();
});
