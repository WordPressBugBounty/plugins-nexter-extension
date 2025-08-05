"use strict";
document.addEventListener('DOMContentLoaded', (event) => {
	if(document.body.classList.contains('post-type-nxt_builder')){
		var e = document.querySelector('#nxt-import-template-button'),
			t = document.querySelector('#nxt-import-template-form'),
			formanchor = document.querySelector("h1.wp-heading-inline");
		var ele = document.querySelector("#wpbody-content .page-title-action");
		if(ele){
			ele.parentNode.insertBefore(e, ele.nextSibling)
			formanchor.parentNode.insertBefore(t, formanchor.nextSibling);
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
	var nexterProNotice = document.querySelector(".nexter-pro-ext-notice");
	if( nexterProNotice ){
		nexterProNotice.addEventListener('click', function(){
			
			var request = new XMLHttpRequest();

			request.open('POST', ajaxurl, true);
			//request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded;');
			request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        	request.setRequestHeader('Accept', 'application/json');
			request.onload = function () {
				
			};
			request.send('action=nexter_ext_pro_dismiss_notice');
		});
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
                    formData.append('nonce', nexter_admin_config.ajax_nonce);
                    xhr.open('POST', ajaxurl, true);
                    xhr.send(formData);
                });
            }
        });
    }, 100);
});