( function( window, wp ){
    window.checkWDKload = true;
    window.tempType = '';
    wp.data.subscribe(function () {
        setTimeout(() => {
            const headerToolBar = document.querySelector(".editor-header__center");
            if(headerToolBar){
                if (!headerToolBar.querySelector('#nexter-edit-condition')) {

                    let svgIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M12.4343 1.5655C12.2526 1.38461 12.0369 1.2415 11.7996 1.14444C11.5623 1.04739 11.3082 0.998309 11.0518 1.00004C10.7954 1.00178 10.5419 1.05429 10.306 1.15455C10.07 1.25481 9.85627 1.40083 9.67707 1.58417L1.94397 9.31727L1 12.9998L4.68255 12.0553L12.4157 4.32222C12.599 4.1431 12.7451 3.9294 12.8454 3.69348C12.9457 3.45756 12.9982 3.2041 13 2.94775C13.0017 2.69141 12.9526 2.43726 12.8555 2.2C12.7584 1.96275 12.6153 1.74709 12.4343 1.5655V1.5655Z" stroke="#1717CC" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>`;

                    const editCondition = document.createElement("div");
                    editCondition.classList.add("nexter-edit-condition-wrap");
                    const htmlData = '<button id="nexter-edit-condition" title="Edit Condition" class="nxt-hide">'+svgIcon+'Edit Condition</button>';
                    if( headerToolBar instanceof HTMLElement ){
                        headerToolBar.insertAdjacentHTML( 'beforeend', htmlData );
                    }
        
                    let editConditionButton = document.querySelector("#nexter-edit-condition");
                    if(editConditionButton){
                        const urlParams = new URLSearchParams(window.location.search);
                        let post_id = urlParams.get('post');

                        const request = new XMLHttpRequest();
                        request.open('POST', nexter_admin_config.ajaxurl, true);
                        request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        		        request.setRequestHeader('Accept', 'application/json');
                        request.onload = () => {
                            if (request.status >= 200 && request.status < 400) {
                                const response = JSON.parse(request.response);
                                if (response) {
                                    if(response.subtype!='section'){
                                        editConditionButton.classList.remove('nxt-hide')
                                        editConditionButton.setAttribute('data-post', post_id);
                                        editConditionButton.setAttribute('data-type', response.type);
                                        editConditionButton.setAttribute('data-subtype', response.subtype);
                                        window.tempType = response.subtype
                                    }
                                }
                            }
                        }

                        request.send('action=nexter_ext_edit_condition_data&nexter_nonce=' + nexter_admin_config.ajax_nonce+'&post_id='+post_id);

                        const builder = new window.NexterBuilder();
                        editConditionButton.addEventListener('click', (e)=>{
                            builder.nextOpenCondition(e, '', 'edit');
                        })
                    }
                }
                
                //elementor-action-wdkit-button
                var wdBtn = document.querySelector(".wkit-gutenber-btn");
                if (window.checkWDKload == true && wdBtn) {
                    const postContent = wp.data.select('core/editor').getEditedPostContent();
                    if (wdBtn !== null && (!postContent || postContent.trim() === '')) {
                        window.checkWDKload = false;
                        wdBtn.click();
                        /* // Get current URL
                        let currentUrl = window.location.href;

                        // Remove any previous '#/theme/builder/<number>' or '#/browse'
                        currentUrl = currentUrl.replace(/#\/theme\/builder\/\d+$/, '');
                        currentUrl = currentUrl.replace(/#\/browse$/, ''); */

                        let wdkID = 1018
                        if(window.tempType== 'header'){
                            wdkID = 1017
                        }else if(window.tempType== 'footer'){
                            wdkID = 1018
                        }else if(window.tempType== 'breadcrumb'){
                            wdkID = 1017
                        }else if(window.tempType== 'section'){
                            wdkID = 1002
                        }else if(window.tempType== 'singular'){
                            wdkID = 1009
                        }else if(window.tempType== 'archives'){
                            wdkID = 1010
                        }else if(window.tempType== 'hooks'){  
                            wdkID = 1002
                        }else if(window.tempType== 'page-404'){  
                            wdkID = 1012
                        }
                        window.WdkitThemeBuilderToggle.open(null, null, wdkID, '1002');
                        /* // Add the new path only once
                        currentUrl += `#/theme/builder/${wdkID}`;

                        history.replaceState(null, "", currentUrl); */
                    }
                }
            }

            
            const changeURL = document.querySelector('.edit-post-fullscreen-mode-close');
            if (
                changeURL &&
                changeURL.getAttribute('data-change') !== 'true' && // ✅ run only once
                changeURL.getAttribute('href') === 'edit.php?post_type=nxt_builder' &&
                nxtext_ajax_object.themebuilderStatus == "true"
            ) {
                changeURL.setAttribute('href', 'admin.php?page=nxt_builder');
                changeURL.setAttribute('data-change', 'true');
            }
        }, 1);
    });

} )( window, wp )