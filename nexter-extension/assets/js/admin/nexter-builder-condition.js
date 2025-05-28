class NexterBuilder {
    constructor() {
        if (document.body.classList.contains('post-type-nxt_builder')) {
            this.init();
        }
    }

    init() {
        this.actionBtn = document.querySelectorAll('.page-title-action');
        if(this.actionBtn){
            this.actionBtn.forEach((btn) => {
                if(!btn.classList.contains('nxt-btn-action')){
                    // Disable left-click default and run custom function
                    btn.addEventListener('click', (e) => {
                        e.preventDefault();
                        let action = 'action=nexter_ext_temp_popup&nexter_nonce=' + nexter_admin_config.ajax_nonce;
                        this.nxtBuilderCommon(action, true);
                    });
                    // Disable right-click context menu
                    btn.addEventListener('contextmenu', (event) => {
                        event.preventDefault();
                    });

                    // Disable middle-click (mouse scroll click)
                    btn.addEventListener('auxclick', (e) => {
                        if (e.button === 1) {
                            e.preventDefault();
                        }
                    });
                }
            });
        }

        this.copySCodeBtn = document.querySelectorAll('.nxt-shortcode-copy-btn');
        this.copySCodeBtn.forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                let crt = e.currentTarget;
                let getIcon = crt.querySelectorAll('.nexter-input-box-button-svg');
                let getInput = crt.previousElementSibling.value;                
                if(navigator.clipboard && navigator.clipboard.writeText){
                    navigator.clipboard.writeText(getInput);
                }else{
                    let tempTextarea = document.createElement('textarea');
                    tempTextarea.value = getInput;
                    document.body.appendChild(tempTextarea);
                    tempTextarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(tempTextarea);
                }
                setTimeout(()=>{
                    getIcon[0].classList.remove('active');
                    getIcon[1].classList.add('active');
                    crt.classList.add('active');
                }, 100)
                setTimeout(()=>{
                    getIcon[1].classList.remove('active');
                    getIcon[0].classList.add('active');
                    crt.classList.remove('active');
                }, 1100)
            });
        });

        this.displayBtn = document.querySelectorAll('.nexter-conditions-action');
        this.displayBtn.forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                this.nextOpenCondition(e, '', 'edit')
            });
        });

        this.statusBtn = document.querySelectorAll('.status.column-status');
        this.statusBtn.forEach((btn) => {
            let statusInput = btn.querySelector('.nxt-post-status');
            if(statusInput){
                statusInput.addEventListener('change', (e) => {
                    e.preventDefault();
                    let post_id = e.currentTarget.value;
                    let check = (e.currentTarget.checked) ? 1 : 0;
                    let action = 'action=nexter_ext_status&nexter_nonce=' + nexter_admin_config.ajax_nonce+'&post_id='+post_id+'&check='+check;
                    this.nxtBuilderCommon(action, false);
                });
            }
        });
    }

    nextOpenCondition(nxt, formVal, type){
        // nxt.preventDefault();
        let post_id = nxt.currentTarget.getAttribute('data-post');
        let temp_type = nxt.currentTarget.getAttribute('data-type');
        let temp_stype = nxt.currentTarget.getAttribute('data-subtype');
        let action = '';
        if(temp_type == 'sections'){
            action = 'action=nexter_ext_sections_condition_popup&nexter_nonce=' + nexter_admin_config.ajax_nonce+'&post_id='+post_id+'&type='+type+'&'+formVal;
        }else if(temp_type == 'pages'){
            if(temp_stype == 'page-404'){
                action = 'action=nexter_ext_pages_404_condition_popup&nexter_nonce=' + nexter_admin_config.ajax_nonce+'&post_id='+post_id+'&type='+type+'&'+formVal;
            }else{
                action = 'action=nexter_ext_pages_condition_popup&nexter_nonce=' + nexter_admin_config.ajax_nonce+'&post_id='+post_id+'&type='+type+'&layout_type='+temp_stype+'&'+formVal;
            }
        }else if(temp_type == 'code_snippet'){
            action = 'action=nexter_ext_sections_condition_popup&nexter_nonce=' + nexter_admin_config.ajax_nonce+'&post_id='+post_id+'&type='+type+'&'+formVal;
        }
        this.nxtBuilderCommon(action, true);
    }

    nxtBuilderCommon(action, popupcheck) {
        let popEle = document.querySelector('.nxt-ext-pop-inner');
        if(popupcheck){
            if (!popEle) {
                document.body.insertAdjacentHTML('beforeend', this.getPopupHTML());
                popEle = document.querySelector('.nxt-ext-pop-inner');
            }
        }
        if(action){
            this.sendRequest(popEle, action);
        }

        if(popupcheck){
            let $this = this;
            document.addEventListener("click", e =>{
                const isClosest = e.target;
                const isClosestMedia = e.target.closest('.supports-drag-drop');
    
                if (isClosest && isClosest.classList.contains('nxt-ext-setting-pop') && !isClosestMedia) {
                    var pop = document.querySelector('.nxt-ext-setting-pop');
                    if(pop){
                        $this.closePopupAction(pop);
                    }
                }
            })
            document.addEventListener("keydown", e =>{
                if (e.keyCode == 27) {
                    var pop = document.querySelector('.nxt-ext-setting-pop');
                    if(pop){
                        pop.remove()
                    }
                }
            });
        }

    }

    getPopupHTML() {
        return `<div class="nxt-ext-setting-pop nxt-builder-settings-popup"><div class="nxt-ext-pop-inner"><button class="ext-close-button">×</button><div class="spinner"></div></div></div>`;
    }

    nxt_ext_close_pop(ell){
		var close_pop_setting = ell.querySelector('.ext-close-button');
		if(close_pop_setting){
            let $this = this;
			close_pop_setting.addEventListener('click', e => {
				e.preventDefault();
                $this.closePopupAction(ell)
			})
		}
	}

    sendRequest(popEle, action) {
        const request = new XMLHttpRequest();
        request.open('POST', nexter_admin_config.ajaxurl, true);
        request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        request.setRequestHeader('Accept', 'application/json');
        // request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded;');
        request.onload = () => {

            if(popEle){
                popEle.querySelector('.spinner').remove();
                if (request.status >= 200 && request.status < 400) {
                    const response = JSON.parse(request.response);
                    if (response.success === true && response.data.content !== '') {
                        popEle.insertAdjacentHTML('beforeend', response.data.content);
    
                        let getSel = popEle.querySelector('.nxt-temp-layout');
                        let headerSec = popEle.querySelector('.nxt-header-type-wrap');
                        let footerSec = popEle.querySelector('.nxt-footer-type-wrap');
                        let hooksSec = popEle.querySelector('.nxt-hooks-type-wrap');
                        let tempName = popEle.querySelector('.nxt-temp-name');
                        let backBtn = popEle.querySelector('.nxt-action-back');
                        let nextBtn = popEle.querySelector('.temp-next-button');
                        let footerType = popEle.querySelector('.nxt-footer-type');
                        let footerBG = popEle.querySelector('.nxt-footer-smart-bgcolor');

                        let saveBtn = popEle.querySelector('.temp-edit-btn-save');

                        let addToggle = popEle.querySelector('.nxt-addition-toggle');

                        let nxtOpen = (addToggle) ? addToggle.querySelectorAll('.nxt-open') : '';
                        let nxtClose = (addToggle) ? addToggle.querySelectorAll('.nxt-close') : '';

                        if(hooksSec){
                            jQuery('.nxt-hooks-action-type').select2({dropdownCssClass: 'nxt-builder-select'});
                        }

                        let form = popEle.querySelector('form');

                        /* Set old form data in window */
                        let gFormData = new FormData(form);
                        let gParams = new URLSearchParams();
                        gFormData.forEach((value, key) => {
                            let gElement = form.querySelector(`[name="${key}"]`);
                            const isVisible = window.getComputedStyle(gElement.parentElement).display !== 'none';

                            if (key !== 'action' && isVisible) {
                                gParams.append(key, value);
                            }
                        });
                        let gQueryString = gParams.toString();
                        window.nxtBuilderOldData = gQueryString;
                        
                        if(saveBtn){
                            saveBtn.addEventListener('click', (eee)=>{
                                eee.preventDefault();
                                let btnCrt = eee.currentTarget;
                                btnCrt.textContent = 'Saving..';
                                const formData = new FormData(form);
                                let params = new URLSearchParams();
                                let lParams = new URLSearchParams();

                                formData.forEach((value, key) => {
                                    const element = form.querySelector(`[name="${key}"]`);
                                    const isVisible = window.getComputedStyle(element.parentElement).display !== 'none';

                                    if (isVisible) {
                                        params.append(key, value);
                                    }

                                    if (key !== 'action' && isVisible) {
                                        lParams.append(key, value);
                                    }
                                });
                                const queryString = params.toString();
                                const lQueryString = lParams.toString();

                                fetch(nexter_admin_config.ajaxurl, {
                                    method: 'POST',
                                    body: queryString,
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded',
                                    }
                                })
                                .then(response => response.json())
                                .then(data => {
                                    if(data && data.success){
                                        btnCrt.textContent = 'Saved';
                                        window.nxtBuilderOldData = lQueryString;
                                        if(document.body.classList.contains('post-php')){
                                            setTimeout(()=>{
                                                btnCrt.textContent = 'Save';
                                            }, 2000)
                                        }else{
                                            btnCrt.textContent = 'Save';
                                            location.reload();
                                        }
                                    }
                                })
                                .catch(error => console.error('Error:', error));
                            })
                        }

                        if(backBtn){
                            backBtn.addEventListener('click', (eee)=>{
                                eee.preventDefault();
                                popEle.parentElement.remove();
                                let post_id = form.querySelector('[name="post_id"]').value;
                                let action = 'action=nexter_ext_temp_popup&nexter_nonce=' + nexter_admin_config.ajax_nonce+'&post_id='+post_id;
                                this.nxtBuilderCommon(action, true);
                            })
                        }
                        if(nextBtn){
                            nextBtn.addEventListener('click', (eee)=>{
                                eee.preventDefault();
                                const formData = new FormData(form);
                                let params = new URLSearchParams();

                                formData.forEach((value, key) => {
                                    const element = form.querySelector(`[name="${key}"]`);
                                    const isVisible = window.getComputedStyle(element.parentElement).display !== 'none';

                                    if (key !== 'action' && isVisible) {
                                        params.append(key, value);
                                    }
                                });
                                const queryString = params.toString();
                                popEle.parentElement.remove();
                                this.nextOpenCondition(eee, queryString, 'update');                                
                            })
                        }
                        if(addToggle){
                            addToggle.addEventListener('click', (at)=>{
                                at.preventDefault();
                                if(at.target.classList.contains('nxt-header-note')){
                                    return;
                                }

                                if(getSel.value == 'header' || getSel.value == 'footer' || getSel.value == 'hooks'){
                                    if(at.currentTarget.classList.contains('active')){
                                        at.currentTarget.classList.remove('active');
                                        if(getSel.value == 'header' && headerSec){
                                            headerSec.classList.remove('active');
                                        }else if(getSel.value == 'footer' && footerSec){
                                            footerSec.classList.remove('active');
                                        }else if(getSel.value == 'hooks' && hooksSec){
                                            hooksSec.classList.remove('active');
                                        }

                                        nxtOpen.forEach((op)=>{
                                            op.classList.add('active')
                                        })
                                        nxtClose.forEach((cl)=>{
                                            cl.classList.remove('active')
                                        })

                                    }else{
                                        at.currentTarget.classList.add('active')
                                        if(getSel.value == 'header' && headerSec){
                                            headerSec.classList.add('active');
                                        }else if(getSel.value == 'footer' && footerSec){
                                            footerSec.classList.add('active');
                                        }else if(getSel.value == 'hooks' && hooksSec){
                                            hooksSec.classList.add('active');
                                        }

                                        nxtOpen.forEach((op)=>{
                                            op.classList.remove('active')
                                        })
                                        nxtClose.forEach((cl)=>{
                                            if(cl.classList.contains('nxt-header-note')){
                                                if(getSel.value == 'header'){
                                                    cl.classList.add('active');
                                                }
                                            }else{
                                                cl.classList.add('active');
                                            }
                                            // cl.classList.add('active')
                                        })
                                    }
                                }
                            })
                        }

                        jQuery('#nxt-hooks-footer-smart-bgcolor').wpColorPicker({
                            change: function(event, ui) {
                                // Update the color preview background and input value
                                var color = ui.color.toString();
                                // jQuery('.nxt-color-preview').css('background-color', color);
                            }
                        });
                    
                        // Show color picker when the icon is clicked
                        jQuery('.nxt-footer-picker-icon').on('click', function(e) {
                            jQuery('#nxt-hooks-footer-smart-bgcolor').wpColorPicker('open');
                            // jQuery('.wp-picker-container').trigger('click');
                            jQuery('.wp-color-result').trigger('click');
                            // jQuery('.wp-picker-input-wrap').trigger('click');
                            // jQuery('#nxt-hooks-footer-smart-bgcolor	').trigger('click');
                        });
                        
                        if(getSel){
                            getSel.addEventListener('change', (e)=>{
                                getSel.style = "";
                                if(e.currentTarget.value == 'section'){
                                    /* const hiddenField = document.createElement('input');
                                        hiddenField.type = 'hidden';
                                        hiddenField.name = 'action';
                                        hiddenField.id = 'nxt_section_action';
                                        hiddenField.value = 'nexter_ext_save_template'; 
                                        form.appendChild(hiddenField); */
                                        let getAction = popEle.querySelector('.temp-action-btn');
                                        if(getAction){
                                            getAction.classList.add('nxt-hide');
                                        }
                                        let getbtnAction = popEle.querySelector('.nxt-temp-action');
                                        if(getbtnAction){
                                            getbtnAction.insertAdjacentHTML('beforeend', '<div class="nxt-action-btn-wrap"><input type="hidden" name="action" value="nexter_ext_save_template" id="nxt_section_action" /><input type="hidden" name="nonce" value="'+NexterConfig.hiddennonce+'" /><button type="submit" class="temp-create-btn">Create</button></div>');
                                        }
                                        form.setAttribute('action', NexterConfig.adminPostUrl);
                                }else{
                                    let btn_wrap = document.querySelector('.nxt-action-btn-wrap');
                                    if(btn_wrap){
                                        btn_wrap.remove()
                                    }
                                    let getAction = popEle.querySelector('.temp-action-btn');
                                    if(getAction){
                                        getAction.classList.remove('nxt-hide');
                                    }
                                    form.removeAttribute('action', NexterConfig.adminPostUrl);
                                }
                                
                                if(e.currentTarget.value == 'header' || e.currentTarget.value == 'footer' || e.currentTarget.value == 'hooks'){
                                    if((e.currentTarget.value == 'header' && headerSec) || (e.currentTarget.value == 'footer' && footerSec) || (e.currentTarget.value == 'hooks' && hooksSec)){
                                        addToggle.parentElement.classList.add('visible');
                                    }else if(e.currentTarget.value == 'header' && headerSec==null || (e.currentTarget.value == 'footer' && footerSec==null) || (e.currentTarget.value == 'hooks' && hooksSec==null)){
                                        addToggle.parentElement.classList.remove('visible')
                                    }

                                    if(e.currentTarget.value == 'header' && nxtClose){
                                        let acc = false;
                                        nxtClose.forEach((cl, index)=>{
                                            if(index == 0){
                                                acc = (cl.classList.contains('active')) ? true : false;
                                            }
                                            if(cl.classList.contains('nxt-header-note') && acc){
                                                cl.classList.add('active');
                                            }
                                        })
                                    }else if(nxtClose){
                                        nxtClose.forEach((cl)=>{
                                            if(cl.classList.contains('nxt-header-note')){
                                                cl.classList.remove('active');
                                            }
                                        })
                                    }
                                }else if((e.currentTarget.value == 'header' && headerSec) || (e.currentTarget.value == 'footer' && footerSec) || (e.currentTarget.value == 'hooks' && hooksSec)){
                                    addToggle.parentElement.classList.remove('visible')
                                }
                                if(headerSec){
                                    if(e.currentTarget.value == 'header' && addToggle.classList.contains('active')){
                                        headerSec.classList.add('active');
                                    }else{
                                        headerSec.classList.remove('active');
                                    }
                                }
                                if(footerSec){
                                    if(e.currentTarget.value == 'footer' && addToggle.classList.contains('active')){
                                        footerSec.classList.add('active');
                                    }else{
                                        footerSec.classList.remove('active');
                                    }
                                }
                                if(hooksSec){
                                    if(e.currentTarget.value == 'hooks' && addToggle.classList.contains('active')){
                                        hooksSec.classList.add('active');
                                    }else{
                                        hooksSec.classList.remove('active');
                                    }
                                }
                            })
                        }
                        if(footerType){
                            footerType.addEventListener('change', (es)=>{
                                if(es.currentTarget.value == 'smart'){
                                    footerBG.classList.add('visible');
                                }else{
                                    footerBG.classList.remove('visible');
                                }
                            })
                        }
                        let includeRules = popEle.querySelector('.nxt-condition-include')
                        if(includeRules){
                            let includeClass = includeRules.querySelector('.nxt-add-display-rule');
                            this.includeExcludeRules(includeRules, includeClass, 'include')
                        }
                        let excludeRules = popEle.querySelector('.nxt-condition-exclude')
                        if(excludeRules){
                            let excludeClass = excludeRules.querySelector('.nxt-exclude-display-rule');
                            this.includeExcludeRules(excludeRules, excludeClass, 'exclude')
                        }

                        let container = popEle.querySelector('#accordion-container');
                        if(container){
                            let $this = this;
                            jQuery('.nxt-single-archive-post').select2({dropdownCssClass: 'nxt-builder-select'});

                            let getItemOld = container.querySelectorAll('.accordion-item');
                            var nxt_configold = JSON.parse(JSON.stringify(NexterConfig));
                            if(getItemOld){
                                getItemOld.forEach((ele)=>{
                                    $this.hideSinArcDropdown(ele, nxt_configold)
                                })
                            }
                            
                            popEle.querySelector('.nxt-add-accordion').addEventListener('click', function(e) {
                                let getItem = container.querySelectorAll('.accordion-item');
                                let getType = e.currentTarget.getAttribute('data-type');
                                let total = 0;
                                if(getItem){
                                    getItem.forEach((gi)=>{
                                        let aTotal = gi.getAttribute('data-id');
                                        total = Number(aTotal) + 1
                                        
                                    })
                                }
                                e.preventDefault();
                                const requestNew = new XMLHttpRequest();
                                requestNew.open('POST', nexter_admin_config.ajaxurl, true);
                                requestNew.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded;');
                                requestNew.onload = () => {
                                    if (requestNew.status >= 200 && requestNew.status < 400) {
                                        const responsenew = JSON.parse(requestNew.response);
                                        e.target.insertAdjacentHTML('beforebegin', responsenew.data.content);

                                        jQuery('.nxt-single-archive-post').select2({dropdownCssClass: 'nxt-builder-select'});

                                        setTimeout(()=>{
                                            let getAllItems = container.querySelectorAll('.accordion-item');
                                            if(getAllItems.length >= 2){
                                                getAllItems.forEach((itm)=>{
                                                    let getIcn = itm.querySelector('.accordion-header');
                                                    if(getIcn && getIcn.classList.contains('hide-remove-btn')){
                                                        getIcn.classList.remove('hide-remove-btn')
                                                    }
                                                    $this.hideSinArcDropdown(itm, nxt_configold)

                                                })
                                            }else{
                                                getAllItems.forEach((itm)=>{
                                                    let getIcn = itm.querySelector('.accordion-header');
                                                    if(getIcn){
                                                        getIcn.classList.add('hide-remove-btn')
                                                    }
                                                })
                                            }
                                        }, 10)
                                    }
                                };
                                let newAction = 'action=nexter_ext_repeater_custom_structure&nexter_nonce=' + nexter_admin_config.ajax_nonce+'&nextId='+total+'&type='+getType;
                                requestNew.send(newAction);

                            });
                        
                            container.addEventListener('click', function(e) {                                
                                if (e.target.classList.contains('remove-accordion') || e.target.classList.contains('remove-accordion-path')) {
                                    e.preventDefault();
                                    const item = e.target.closest('.accordion-item');
                                    if (container.children.length > 2) {
                                        item.remove();
                                    }
                                    setTimeout(()=>{
                                        let getAllItems = container.querySelectorAll('.accordion-item');                                    
                                        if(getAllItems.length >= 2){
                                            getAllItems.forEach((itm)=>{
                                                let getIcn = itm.querySelector('.accordion-header');
                                                if(getIcn && getIcn.classList.contains('hide-remove-btn')){
                                                    getIcn.classList.remove('hide-remove-btn')
                                                }
                                            })
                                        }else{
                                            getAllItems.forEach((itm)=>{
                                                let getIcn = itm.querySelector('.accordion-header');
                                                if(getIcn){
                                                    getIcn.classList.add('hide-remove-btn')
                                                }
                                            })
                                        }
                                    }, 10)
                                }
                            });
                        }

                        // jQuery(document).on('change', '.' + singular_cond_rule + ' .archive-select', function(e) {
                        jQuery(document).on('change', '.nxt-singular-group-select', function(e) {
                            var cond_type = e.currentTarget.closest(".accordion-item").querySelector('.nxt-single-archive-post');
                                var data = {
                                action: "nxt_singular_archives_filters_ajax"
                            };
                            data.data = '';
                            data.rules = this.value;

                            var nxt_config = JSON.parse(JSON.stringify(NexterConfig));
                            data.data = JSON.stringify(nxt_config[data.rules]);
                            
                            if (this.value != undefined && this.value != 'front_page') {
                                jQuery.ajax({
                                    type: "POST",
                                    url: nexter_admin_config.ajaxurl,
                                    data: data,
                                    beforeSend: function() {
                                        cond_type.style.display = 'block';
                                        cond_type.nextElementSibling.style.display = 'block';
                                    },
                                    success: function(result_data) {
                                        var result_data = JSON.parse(result_data);
                                        if (result_data.response != 'undefined' && result_data.response == true && result_data.results != 'undefined') {
                                            var data = result_data.results;
                                            while(cond_type.firstChild) cond_type.removeChild(cond_type.firstChild)
                
                                            var all_data = {
                                                id: 'all',
                                                text: 'All'
                                            };
                                            var option = new Option(all_data.text, all_data.id, true, true);
                                            cond_type.append(option);
                                            cond_type.dispatchEvent(new Event('change'));
                                            
                                            data.forEach(function(value) {
                                                var option = new Option(value.text, value.id, false, false);
                                                cond_type.append(option);
                                                cond_type.dispatchEvent(new Event('change'));
                                            });
                
                                        } else {
                                            var data = {
                                                id: 'all',
                                                text: 'All'
                                            };
                                            while(cond_type.firstChild) cond_type.removeChild(cond_type.firstChild)
                                            var option = new Option(data.text, data.id, true, true);
                                            cond_type.append(option);
                                            cond_type.dispatchEvent(new Event('change'));
                                        }
                                    }
                                });
                            } else {
                                cond_type.style.display = 'none';
                                cond_type.nextElementSibling.style.display = 'none';
                            }
                        });

                        jQuery(document).on('change', '.nxt-archive-group-select', function(e) {
                            var $this = this.value;        
                            var cond_type = e.currentTarget.closest(".accordion-item").querySelector('.nxt-single-archive-post');
                            cond_type.style.display = 'block';
                            cond_type.nextElementSibling.style.display = 'block';
                            var data = {
                                action: "nxt_singular_archives_filters_ajax"
                            };
                            data.data = '';
                            data.rules = $this;
                            var nxt_config = JSON.parse(JSON.stringify(NexterConfig));
                            data.data = JSON.stringify(nxt_config.nxt_archives[data.rules]);
                            
                            if ($this != undefined && nxt_config.nxt_archives[$this].condition_type!='' && nxt_config.nxt_archives[$this].condition_type!=undefined && nxt_config.nxt_archives[$this].condition_type == 'yes') {
                                jQuery.ajax({
                                    type: "POST",
                                    url: ajaxurl,
                                    data: data,
                                    beforeSend: function() {
                                        cond_type.style.display = 'block';
                                        cond_type.nextElementSibling.style.display = 'block';
                                    },
                                    success: function(result_data) {
                
                                        var result_data = JSON.parse(result_data);
                
                                        if (result_data.response != 'undefined' && result_data.response == true && result_data.results != 'undefined') {
                
                                            var data = result_data.results;
                                            jQuery(cond_type).select2('close');
                                            while(cond_type.firstChild) cond_type.removeChild(cond_type.firstChild)
                
                                            var all_data = {
                                                id: 'all',
                                                text: 'All'
                                            };
                                            var option = new Option(all_data.text, all_data.id, true, true);
                                            cond_type.append(option);
                                            cond_type.dispatchEvent(new Event('change'));
                
                                            data.forEach(function(value) {
                                                var option = new Option(value.text, value.id, false, false);
                                                cond_type.append(option);
                                                cond_type.dispatchEvent(new Event('change'));
                                            });
                
                                        } else {
                                            var data = {
                                                id: 'all',
                                                text: 'All'
                                            };
                                            jQuery(cond_type).select2('close');
                                            while(cond_type.firstChild) cond_type.removeChild(cond_type.firstChild)
                                            var option = new Option(data.text, data.id, true, true);
                                            cond_type.append(option);
                                            cond_type.dispatchEvent(new Event('change'));
                                        }
                                    }
                                    });
                            } else {
                                cond_type.style.display = 'none';
                                cond_type.nextElementSibling.style.display = 'none';
                            }
                        });

                        jQuery(document).on('change', '.nxt-singular-preview-type-select', function(e) {
                                var preview_id = e.currentTarget.closest('.nxt-pages-preview-wrap').querySelector('.nxt-singular-preview-id-select');
                            var data = {
                                action: "nxt_singular_preview_type_ajax"
                            };
                            data.rules = this.value;
                            if (this.value != undefined && this.value != 'front_page') {
                                jQuery.ajax({
                                    type: "POST",
                                    url: ajaxurl,
                                    data: data,
                                    success: function(result_data) {
                
                                        var result_data = JSON.parse(result_data);
                
                                        if (result_data.response != 'undefined' && result_data.response == true && result_data.results != 'undefined') {
                
                                            var data = result_data.results;
                                            // jQuery(preview_id).select2('close');
                                            while(preview_id.firstChild) preview_id.removeChild(preview_id.firstChild)
                
                                            data.forEach(function(value) {
                                                var option = new Option(value.text, value.id, false, false);
                                                preview_id.append(option);
                                                preview_id.dispatchEvent(new Event('change'));
                                            });
                
                                        } else {
                                            var data = {
                                                id: 'all',
                                                text: 'All'
                                            };
                                            // jQuery(preview_id).select2('close');
                                            while(preview_id.firstChild) preview_id.removeChild(preview_id.firstChild)
                                            var option = new Option(data.text, data.id, true, true);
                                            preview_id.append(option);
                                            preview_id.dispatchEvent(new Event('change'));
                                        }
                                    }
                                });
                            }
                        });

                        jQuery(document).on('change', '.nxt-archive-preview-type-select', function(e) {
                                var $this = this.value;           
                                var preview_id = e.currentTarget.closest('.nxt-pages-preview-wrap').querySelector('.nxt-archive-preview-id-select');
                            
                            var data = {
                                action:  "nxt_archive_preview_taxonomy_ajax"
                            };
                            data.data = '';
                            data.rules = $this;
                            var nxt_config = JSON.parse(JSON.stringify(NexterConfig));
                            data.data = JSON.stringify(nxt_config.nxt_archives[data.rules]);
                            
                            if ($this != undefined && nxt_config.nxt_archives[$this].condition_type!='' && nxt_config.nxt_archives[$this].condition_type!=undefined && nxt_config.nxt_archives[$this].condition_type == 'yes') {
                                jQuery.ajax({
                                    type: "POST",
                                    url: ajaxurl,
                                    data: data,
                                    success: function(result_data) {
                
                                        var result_data = JSON.parse(result_data);
                
                                        if (result_data.response != 'undefined' && result_data.response == true && result_data.results != 'undefined') {
                
                                            var data = result_data.results;
                                            // jQuery(preview_id).select2('close');
                                            while(preview_id.firstChild) preview_id.removeChild(preview_id.firstChild)
                
                                            data.forEach(function(value) {
                                                var option = new Option(value.text, value.id, false, false);
                                                preview_id.append(option);
                                                preview_id.dispatchEvent(new Event('change'));
                                            });
                
                                        } else {
                                            var data = {
                                                id: 'all',
                                                text: 'All'
                                            };
                                            // jQuery(preview_id).select2('close');
                                            while(preview_id.firstChild) preview_id.removeChild(preview_id.firstChild)
                                            var option = new Option(data.text, data.id, true, true);
                                            preview_id.append(option);
                                            preview_id.dispatchEvent(new Event('change'));
                                        }
                                    }
                                });
                            }
                        });

                        if(tempName){
                            tempName.addEventListener('input', (tn)=>{
                                if(tn.currentTarget.value){
                                    tempName.style = "";
                                }else{
                                    tempName.style.borderColor = "red";
                                }
                            })
                        }
    
                        let getAction = popEle.querySelector('.temp-action-btn');
                        
                        if(getAction){
                            getAction.addEventListener('click', (ee) => {
                                ee.preventDefault();
                                const formData = new FormData(form);
                                let params = new URLSearchParams();

                                if(getSel.value && tempName.value){
                                    formData.forEach((value, key) => {
                                        const element = form.querySelector(`[name="${key}"]`);
                                        const isVisible = window.getComputedStyle(element.parentElement).display !== 'none';

                                        if (key !== 'action' && isVisible) {
                                            params.append(key, value);
                                        }
                                    });
                                    popEle.parentElement.remove();
                                    const queryString = params.toString();
                                    let action = '';
                                    if(getSel.value == 'header' || getSel.value == 'footer' || getSel.value == 'breadcrumb' || getSel.value == 'hooks'){
                                        action = 'action=nexter_ext_sections_condition_popup&nexter_nonce=' + nexter_admin_config.ajax_nonce+'&'+queryString+'&type=new';
                                    }else if(getSel.value == 'singular' || getSel.value == 'archives' || getSel.value == 'page-404'){
                                        if( getSel.value == 'page-404' ){
                                            action = 'action=nexter_ext_pages_404_condition_popup&nexter_nonce=' + nexter_admin_config.ajax_nonce+'&'+queryString+'&type=new';
                                        }else{
                                            action = 'action=nexter_ext_pages_condition_popup&nexter_nonce=' + nexter_admin_config.ajax_nonce+'&'+queryString+'&type=new';
                                        }
                                    }else if(getSel.value == 'section'){
                                        action = 'action=nexter_ext_sections_condition_popup&nexter_nonce=' + nexter_admin_config.ajax_nonce+'&'+queryString+'&type=new';
                                    }
                                    this.nxtBuilderCommon(action, true);
                                }else{
                                    if(!getSel.value){
                                        getSel.style.borderColor = "red";
                                    }else{
                                        getSel.style = "";
                                    }
                                    if(!tempName.value){
                                        tempName.style.borderColor = "red";
                                    }else{
                                        tempName.style = "";
                                    }
                                }
                            })
                        }
                    }
                }
            }

        };
        request.send(action);
        if(popEle){
            this.nxt_ext_close_pop(popEle.parentElement)
        }
    }




    nxtShow(elem) {
        if(elem) {
            elem.style.display = 'block';
        }
    };
    // Hide an element
    nxtHide(elem) {
        if(elem) {
            elem.style.display = 'none';
        }
    };

    includeExcludeRules(popEle, inexrule, type){
        let setDayWrap = popEle.querySelector('.nxt-set-day-wrap');
        let osWrap = popEle.querySelector('.nxt-layout-os-wrap');
        let browserWrap = popEle.querySelector('.nxt-layout-browser-wrap');
        let loginStatusWrap = popEle.querySelector('.nxt-layout-login-status-wrap');
        let userRoleWrap = popEle.querySelector('.nxt-layout-login-user-roles-wrap');
        let specificWrap = popEle.querySelector('.nxt-layout-specific-post-wrap');
        let typeVal = (type=='include') ? 'show' : 'hide'; 
        jQuery(inexrule).select2({ dropdownCssClass: 'nxt-builder-select', value: '', placeholder: "Select locations where you want to "+typeVal+" your template.", allowClear: true});
        jQuery('.nxt-set-day').select2({ dropdownCssClass: 'nxt-builder-select', value: '', placeholder: '', allowClear: true});
        jQuery('.nxt-layout-os').select2({ dropdownCssClass: 'nxt-builder-select', value: '', placeholder: '', allowClear: true});
        jQuery('.nxt-layout-browser').select2({ dropdownCssClass: 'nxt-builder-select', value: '', placeholder: '', allowClear: true});
        jQuery('.nxt-layout-login-status').select2({ dropdownCssClass: 'nxt-builder-select', value: '', placeholder: '', allowClear: true});
        jQuery('.nxt-layout-user-roles').select2({ dropdownCssClass: 'nxt-builder-select', value: '', placeholder: '', allowClear: true});

        function updateActiveStates() {
            var selectedValue = jQuery(inexrule).val();
        
            if (setDayWrap && selectedValue && selectedValue.includes("set-day")) {
                setDayWrap.classList.add('active');
            } else if(setDayWrap) {
                setDayWrap.classList.remove('active');
            }
        
            if (osWrap &&selectedValue && selectedValue.includes("os")) {
                osWrap.classList.add('active');
            } else if(osWrap) {
                osWrap.classList.remove('active');
            }
        
            if (browserWrap && selectedValue && selectedValue.includes("browser")) {
                browserWrap.classList.add('active');
            } else if(browserWrap){
                browserWrap.classList.remove('active');
            }
        
            if (loginStatusWrap && selectedValue && selectedValue.includes("login-status")) {
                loginStatusWrap.classList.add('active');
            } else if(loginStatusWrap) {
                loginStatusWrap.classList.remove('active');
            }
            if (specificWrap && selectedValue && selectedValue.includes("particular-post")) {
                specificWrap.classList.add('active');
            } else if(specificWrap) {
                specificWrap.classList.remove('active');
            }
        
            if (userRoleWrap && selectedValue && selectedValue.includes("user-roles")) {
                userRoleWrap.classList.add('active');
            } else if(userRoleWrap){
                userRoleWrap.classList.remove('active');
            }

            var init_target_rule_select2  = function( selector ) {

                jQuery(selector).select2({
                    dropdownCssClass: 'nxt-builder-select',			
                    ajax: {
                        url: ajaxurl,
                        dataType: 'json',
                        method: 'post',
                        delay: 250,
                        data: function (params) {
                            return {
                                q: params.term, // search term
                                page: params.page,
                                action: 'nexter_get_particular_posts_query'
                            };
                        },
                        processResults: function (data) {
                            return {
                                results: data
                            };
                        },
                        cache: true
                    },
                    minimumInputLength: 2,			
                    language: ""
                });
        
            };
            
            var IncSpacific = document.getElementById('nxt-hooks-layout-specific');
            if(IncSpacific){
                init_target_rule_select2( IncSpacific );
            }
            var ExcSpacific = document.getElementById('nxt-hooks-layout-exclude-specific');
            if(ExcSpacific){
                init_target_rule_select2( ExcSpacific );
            }
        }
        updateActiveStates();
        
        // Call the function on change
        jQuery(inexrule).on('change', updateActiveStates);
        
    }

    hideSinArcDropdown(ele, nxt_configold){
        let getArchiveRule = ele.querySelector('.nxt-archive-group-select');
        if(getArchiveRule){
            let getArchiveType = ele.querySelector('.nxt-single-archive-post')
            let selVal = getArchiveRule.value;
            if (selVal != undefined && nxt_configold.nxt_archives[selVal].condition_type!='' && nxt_configold.nxt_archives[selVal].condition_type!=undefined && nxt_configold.nxt_archives[selVal].condition_type == 'yes') {
                /* Do Nothing */
            }else{
                getArchiveType.style.display = 'none';
                getArchiveType.nextElementSibling.style.display = 'none';
            }
        }
        let getSingularRule = ele.querySelector('.nxt-singular-group-select');
        if(getSingularRule){
            let getSingularType = ele.querySelector('.nxt-single-archive-post')
            let selVal = getSingularRule.value;
            if (selVal != undefined && selVal == 'front_page') {
                getSingularType.style.display = 'none';
                getSingularType.nextElementSibling.style.display = 'none';
            }
        }
    }

    closePopupAction(ell){
        let nxtBuilderOldData = window.nxtBuilderOldData;

        let getTempPop = ell.querySelector('.nxt-bul-temp');
        let warnPopup = ell.querySelector('.nxt-close-warning-popup');
        let getInner = ell.querySelector('.nxt-ext-pop-inner');

        let form = getInner.querySelector('form');

        /* Get Latest form data */
        let gFormData = new FormData(form);
        let gParams = new URLSearchParams();
        gFormData.forEach((value, key) => {
            let gElement = form.querySelector(`[name="${key}"]`);
            const isVisible = window.getComputedStyle(gElement.parentElement).display !== 'none';

            if (key !== 'action' && isVisible) {
                gParams.append(key, value);
            }
        });
        let gQueryString = gParams.toString();
        
        if(nxtBuilderOldData == gQueryString){
            ell.remove();
        }else{
            if(warnPopup){
                let leaveBtn = warnPopup.querySelector('.popup-leave-btn');

                if(leaveBtn){
                    if(warnPopup.style.display == 'block'){
                        warnPopup.style.display = 'none';
                        getTempPop.style.display = 'block';
                    }else{
                        getTempPop.style.display = 'none';
                        warnPopup.style.display = 'block';
                    }
    
                    if(leaveBtn){
                        leaveBtn.addEventListener('click', ()=>{
                            ell.remove();
                        })
                    }
                }
            }
        }
    }
}

new NexterBuilder();
window.NexterBuilder = NexterBuilder;