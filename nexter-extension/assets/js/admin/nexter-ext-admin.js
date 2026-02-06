"use strict";
document.addEventListener('DOMContentLoaded', (event) => {
	if(document.body.classList.contains('post-type-nxt_builder')){
		var e = document.querySelector('#nxt-import-template-button'),
		    header_right = document.querySelector('.nxt-theme-builder-right-header'),
		    header_left = document.querySelector('.nxt-theme-builder-left-header'),
			t = document.querySelector('#nxt-import-template-form'),
			formanchor = document.querySelector("h1.wp-heading-inline");
		var ele = document.querySelector("#wpbody-content .page-title-action");
		if(ele){
            formanchor.parentNode.insertBefore(t, formanchor.nextSibling);
            formanchor.remove();
            // Create a wrapper div
            const wrapper = document.createElement('div');
                wrapper.className = 'nxt-theme-builder-wrap';

                // Append in the desired order
                wrapper.appendChild(header_left);
                header_left.appendChild(ele);
                const importBtn = header_left.querySelector("#nxt-import-template-button");

                if (header_left && importBtn) {
                    header_left.appendChild(importBtn);
                }
                wrapper.appendChild(header_right);
			    // Insert wrapper into DOM
                const wpBody = document.querySelector('#wpbody-content .wrap');
                if (wpBody) {
                    wpBody.insertBefore(wrapper, wpBody.firstChild);
                }
			e.addEventListener("click", function() {
				var this_item = document.getElementById('nxt-import-template-form'); 
				if( this_item.style.display == '' || this_item.style.display == 'block' ) {
					this_item.style.display = 'none';
				}else {
					this_item.style.display = 'block';
				}
			})
		}
	}
	var nexterProNotice = document.querySelector('.nxt-notice-wrap[data-notice-id="nexter_block_show_pro"]');
    if (nexterProNotice) {
        setTimeout(function () {
        var dismissBtn = nexterProNotice.querySelector('.notice-dismiss');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', function () {
                var request = new XMLHttpRequest();
                request.open('POST', ajaxurl, true);
                request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                request.setRequestHeader('Accept', 'application/json');
                request.send('action=nexter_ext_pro_dismiss_notice');
            });
        }
        }, 500);
    }

	setTimeout(function () {
    	const notices = document.querySelectorAll('.nxt-notice-wrap');
        notices.forEach(function (notice) {
            const dismissBtn = notice.querySelector('.notice-dismiss');
            const noticeId = notice.getAttribute('data-notice-id');
            if (dismissBtn && noticeId) {
                dismissBtn.addEventListener('click', function () {
                    const xhr = new XMLHttpRequest();
                    const formData = new FormData();
                    formData.append('action', 'nexter_ext_dismiss_notice');
                    formData.append('notice_id', noticeId);
                    xhr.open('POST', ajaxurl, true);
                    xhr.send(formData);
                });
            }
        });
    }, 500);
    if(document.body.classList.contains('post-type-nxt_builder')){
        const toggle = document.getElementById('nxt_thtgl_sw');
        if (!toggle) return;

        toggle.addEventListener('change', function(e) {
            const checked = e.target.checked;

            const data = new URLSearchParams({
                action: 'nexter_ext_builder_update',
                nexter_nonce: nxtext_ajax_object.ajax_nonce,
                checked: checked,
                updated: 'switcher',
            });

            fetch(nxtext_ajax_object.ajax_url, {
                method: 'POST',
                body: data
            }).then(res => res.json())
            .then((response) => {
                if (response.success && checked) {
                    window.location.href = nxtext_ajax_object.adminUrl + 'admin.php?page=nxt_builder';
                }
            });
        });
    }
});