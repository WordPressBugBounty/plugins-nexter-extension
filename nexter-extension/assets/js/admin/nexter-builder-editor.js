"use strict";
document.addEventListener('DOMContentLoaded', (event) => {
		var cmb = '.cmb2',
            nxt = 'nxt',
			singular_group = document.querySelector(cmb + '-id-nxt-singular-group'),
			singular_preview = document.querySelector('.nxt-preview-singular'),
			archive_group = document.querySelector(cmb + '-id-nxt-archive-group'),
			archive_preview = document.querySelector('.nxt-preview-archive'),
			
			sections_group = document.querySelector('.nxt-sections-wrap-fields'),
			sections_inner_group = document.querySelector('.nxt-sections-inner-fields'),
			sections_rules_group = document.querySelector('.nxt-sections-rules-fields'),
			
			code_group = document.querySelector('.nxt-code-wrap-fields'),
			
			pages_group = document.querySelector('.nxt-pages-rules-fields'),
			hooks_layout_pages = document.getElementById('nxt-hooks-layout-pages'),
			ele_hidden = document.getElementById('_elementor_template_type');
		// Show an element
		var nxtShow = function (elem) {
			if(elem) {
				elem.style.display = 'block';
			}
		};
		// Hide an element
		var nxtHide = function (elem) {
			if(elem) {
				elem.style.display = 'none';
			}
		};
		
		var nxtRulesField = function(val = 'show'){
			if(sections_rules_group){
				var add_display = document.querySelector('.nxt-sections-rules-fields > .cmb2-id-nxt-add-display-rule');
				if(add_display){
					setTimeout(function(){ 
						if(val=='show'){
							nxtShow(add_display);
						}else{
							nxtHide(add_display);
						}
					}, 10);
				}
				var exclude_display = document.querySelector('.nxt-sections-rules-fields > .cmb2-id-nxt-exclude-display-rule');
				if(exclude_display){
					setTimeout(function(){ 
						if(val=='show'){
							nxtShow(exclude_display);
						}else{
							nxtHide(exclude_display);
						}
					}, 10);
				}
			}
		}
		
		var checked_section = function (value) {
			if ( value && value === 'pages' ) {
				nxtHide(sections_group);
				nxtShow(hooks_layout_pages);
				nxtShow(pages_group);
			var pages_change = document.querySelector('.cmb2-id-' + nxt + '-hooks-layout-pages input[name=nxt-hooks-layout-pages]:checked');
				if(pages_change.value == 'singular' && ele_hidden){
					ele_hidden.value = "nxt_builder";
				}else if(pages_change.value == 'archives' && ele_hidden){
					ele_hidden.value = "nxt_builder-archives";
				}else if(ele_hidden){
					ele_hidden.value = "wp-post";
				}
			}else if (value && value === 'sections') {
				nxtHide(pages_group);
				nxtHide(code_group);
				nxtShow(sections_group);
				nxtShow(sections_inner_group);
				
				var section_change = document.querySelector('.cmb2-id-' + nxt + '-hooks-layout-sections input[name=nxt-hooks-layout-sections]:checked');
				if(section_change && section_change.value == 'none'){
					nxtRulesField('hide');
					nxtHide(sections_rules_group);
				}else{
					nxtRulesField('show');
					nxtShow(sections_rules_group);
				}
				if(ele_hidden){
					ele_hidden.value = "wp-post";
				}
			}else if (value && value === 'code_snippet') {
				nxtHide(pages_group);
				nxtHide(sections_inner_group);
				nxtShow(sections_group);
				nxtShow(code_group);
				nxtShow(sections_rules_group);
				nxtRulesField('show');
				var check_Code = document.querySelector('.cmb2-id-' + nxt + '-hooks-layout-code-snippet input[name=nxt-hooks-layout-code-snippet]:checked');
				if(check_Code && check_Code.value){
					checked_codes(check_Code.value);
				}
				if(ele_hidden){
					ele_hidden.value = "wp-post";
				}
			} else {
				nxtHide(sections_group);
				nxtHide(pages_group);
				nxtRulesField('hide');
				nxtHide(sections_rules_group);
				if(ele_hidden){
					ele_hidden.value = "wp-post";
				}
			}
		}
		
		var checked_codes = function (value) {
			if (value && value === 'php') {
				nxtHide(sections_rules_group);
				nxtRulesField('hide');
			}else if(value && (value === 'html' || value === 'css' || value === 'javascript')){
				nxtShow(sections_rules_group);
				nxtRulesField('show');
			}
		}
		var checked_sections = function (value) {
			var footer_style = document.getElementById("nxt-hooks-footer-style");
			var f_smartbg = document.querySelector('.cmb2-id-nxt-hooks-footer-smart-bgcolor');
			
			if(value && value=='footer'){
				nxtShow(sections_rules_group);
				nxtRulesField('show');
				
				if(footer_style && footer_style.value =='smart'){
					nxtShow(f_smartbg);
				}else{
					nxtHide(f_smartbg);
				}
			}else if (value && value != 'none') {
				nxtShow(sections_rules_group);
				nxtRulesField('show');
				nxtHide(f_smartbg);
			}else{
				nxtHide(sections_rules_group);
				nxtRulesField('hide');
				nxtHide(f_smartbg);
			}
		}
		
		var checked_pages = function (value) {
			if (value && value === 'singular') {
				nxtShow(singular_group);
				nxtShow(singular_preview);
				nxtHide(archive_group);
				nxtHide(archive_preview);
				if(ele_hidden){
					ele_hidden.value = "nxt_builder";
				}
			} else if (value && value === 'archives') {
				nxtShow(archive_group);
				nxtShow(archive_preview);
				nxtHide(singular_group);
				nxtHide(singular_preview);
				if(ele_hidden){
					ele_hidden.value = "nxt_builder-archives";
				}
			} else {
				nxtHide(singular_group);
				nxtHide(singular_preview);
				nxtHide(archive_group);
				nxtHide(archive_preview);
				if(ele_hidden){
					ele_hidden.value = "wp-post";
				}
			}
		}
		
		
		if (document.getElementById(nxt + '-hooks-layout')) {
            var sec_layout = document.querySelectorAll('.cmb2-id-' + nxt + '-hooks-layout input[name=nxt-hooks-layout]'),
				checkLayout = document.querySelector('.cmb2-id-' + nxt + '-hooks-layout input[name=nxt-hooks-layout]:checked'),
				sec_section = document.querySelectorAll('.cmb2-id-' + nxt + '-hooks-layout-sections input[name=nxt-hooks-layout-sections]'),
				checkSection = document.querySelector('.cmb2-id-' + nxt + '-hooks-layout-sections input[name=nxt-hooks-layout-sections]:checked'),
				sec_pages = document.querySelectorAll('.cmb2-id-' + nxt + '-hooks-layout-pages input[name=nxt-hooks-layout-pages]'),
				checkPages = document.querySelector('.cmb2-id-' + nxt + '-hooks-layout-pages input[name=nxt-hooks-layout-pages]:checked'),
				code_layout = document.querySelectorAll('.cmb2-id-' + nxt + '-hooks-layout-code-snippet input[name=nxt-hooks-layout-code-snippet]'),
				checkCode = document.querySelector('.cmb2-id-' + nxt + '-hooks-layout-code-snippet input[name=nxt-hooks-layout-code-snippet]:checked');

            if (sec_layout!=null) {
				var section_val = checkLayout.value;
				checked_section(section_val);
				
				Array.prototype.forEach.call(sec_layout, function(el, i){
					el.addEventListener("change", function(ele) {
						checked_section(this.value);
					});
				});
				if(section_val === 'sections'){
					var section_val = checkSection.value;
					checked_sections(section_val);
				}
				
				Array.prototype.forEach.call(sec_section, function(el, i){
					el.addEventListener("change", function(ele) {
						checked_sections(this.value);
					});
				});
				
				if(section_val === 'code_snippet'){
					var code_val = checkCode.value;
					checked_codes(code_val);
				}
				Array.prototype.forEach.call(code_layout, function(el, i){
					el.addEventListener("change", function(ele) {
						checked_codes(this.value);
					});
				});
				var pages_val = checkPages.value;
				checked_pages(pages_val);
				
				Array.prototype.forEach.call(sec_pages, function(el, i){
					el.addEventListener("change", function(ele) {
						checked_pages(this.value);
					});
				});
            }
        }
		
		var singular = 'singular',
            singular_container = nxt + '-' + singular + '-group',
            singular_cond_rule = nxt + '-' + singular + '-cond-rule',
			singular_cond_type = nxt + '-' + singular + '-cond-type',
			singularTable = jQuery(document.getElementById(singular_container + '_repeat')),
			singular_preview_type = nxt + '-' + singular + '-preview-type',
			singular_preview_id = nxt + '-' + singular + '-preview-id';
		var archive = 'archive',
			archive_container = nxt + '-' + archive + '-group',
            archive_cond_rule = nxt + '-' + archive + '-cond-rule',
			archive_cond_type = nxt + '-' + archive + '-cond-type',
			archiveTable = jQuery(document.getElementById(archive_container + '_repeat')),
			archive_preview_type = nxt + '-' + archive + '-preview-type',
			archive_preview_id = nxt + '-' + archive + '-preview-id';
		
		var nxt_config = JSON.parse(JSON.stringify(NexterConfig));
		
		//add new row set value 
        singularTable.on('cmb2_add_row', function(evt, row) {
            var field_id = row.data("iterator"),
				group_id = document.getElementById('cmb-group-' + nxt + '-' + singular + '-group-' + field_id);
            if (group_id) {
			var el = group_id.querySelector('.' + singular_cond_rule + ' .pw_select');
				jQuery(el).select2();
				el.value = "post";
				el.dispatchEvent(new Event('change'));
			var elem = group_id.querySelector('.' + singular_cond_type + ' .pw_multiselect');
				jQuery(elem).select2();
				elem.value = "all";
				elem.dispatchEvent(new Event('change'));
            }
        });
		
		//Archives add new row set value 
        archiveTable.on('cmb2_add_row', function(evt, row) {
            var field_id = row.data("iterator");
            var group_id = document.getElementById('cmb-group-' + nxt + '-' + archive + '-group-' + field_id);
            if ( group_id ) {
                nxtHide(group_id.querySelector('.' + archive_cond_type));
				var el = group_id.querySelector('.' + archive_cond_rule + ' .pw_select');
				jQuery(el).select2();
				el.value = "all";
				el.dispatchEvent(new Event('change'));
				var elem = group_id.querySelector('.' + archive_cond_type + ' .pw_multiselect');
				jQuery(elem).select2();
				elem.value = "all";
				elem.dispatchEvent(new Event('change'));
            }
        });
		
		//Singular On load data Field
        if ( document.getElementById(singular_container + '_repeat') ) {

            var ruleSelect = document.querySelectorAll('#' + singular_container + '_repeat .cmb-repeatable-grouping .' + singular_cond_rule + ' .pw_select')
			Array.prototype.forEach.call(ruleSelect, function(el, i){
                var value = el.value;
                var type_field = el.closest(".cmb-repeatable-grouping").querySelector( '.' + singular_cond_type );
                if (value != undefined && value != 'front_page') {
                    nxtShow(type_field);
                } else {
                    nxtHide(type_field);
                }

            });

        }
		
		//Archives On load data Field
        if (document.getElementById(archive_container + '_repeat')) {
			
			var ruleSelect = document.querySelectorAll('#' + archive_container + '_repeat .cmb-repeatable-grouping .' + archive_cond_rule + ' .pw_select');
			Array.prototype.forEach.call(ruleSelect, function(el, i){

                var value = el.value;
                var type_field = el.closest(".cmb-repeatable-grouping").querySelector('.' + archive_cond_type);
                if (value != undefined && nxt_config.nxt_archives[value].condition_type!='' && nxt_config.nxt_archives[value].condition_type!=undefined && nxt_config.nxt_archives[value].condition_type == 'yes') {
					nxtShow(type_field);
                } else {
                    nxtHide(type_field);
                }

            });
        }
		
		//Change value Singular Preview Type
		jQuery(document).on('change', '.' + singular_preview_type + ' .pw_select', function(e) {
			var target = e.currentTarget.id,
				preview_id = document.querySelector('.' + singular_preview_id + ' .pw_select');
			var data = {
                action: nxt + "_singular_preview_type_ajax"
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
                            jQuery(preview_id).select2('close');
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
                            jQuery(preview_id).select2('close');
                            while(preview_id.firstChild) preview_id.removeChild(preview_id.firstChild)
                            var option = new Option(data.text, data.id, true, true);
                            preview_id.append(option);
							preview_id.dispatchEvent(new Event('change'));
                        }
                    }
                });
			}
		});
		
		//Change value Archives Preview Field
        jQuery(document).on('change', '.' + archive_preview_type + ' .pw_select', function(e) {
            var target = e.currentTarget.id,
				$this = this.value,           
				preview_id = document.querySelector('.' + archive_preview_id + ' .pw_select');;
            
			var data = {
                action: nxt + "_archive_preview_taxonomy_ajax"
            };
            data.data = '';
            data.rules = $this;
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
                            jQuery(preview_id).select2('close');
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
                            jQuery(preview_id).select2('close');
                            while(preview_id.firstChild) preview_id.removeChild(preview_id.firstChild)
                            var option = new Option(data.text, data.id, true, true);
                            preview_id.append(option);
							preview_id.dispatchEvent(new Event('change'));
                        }
                    }
				});
            }
        });
		
		//Change value Singular Options Field
		jQuery(document).on('change', '.' + singular_cond_rule + ' .pw_select', function(e) {
		var target = e.currentTarget.id;
			var cond_type = document.querySelector("#" + target).closest(".cmb-repeatable-grouping").querySelector('.' + singular_cond_type + ' .pw_multiselect');
			 var data = {
                action: nxt + "_singular_archives_filters_ajax"
            };
            data.data = '';
            data.rules = this.value;
            data.data = JSON.stringify(nxt_config[data.rules]);
			
			if (this.value != undefined && this.value != 'front_page') {
				jQuery.ajax({
                    type: "POST",
                    url: ajaxurl,
                    data: data,
                    beforeSend: function() {
						nxtShow(cond_type.closest( '.' + singular_cond_type ));
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
				nxtHide(cond_type.closest( '.' + singular_cond_type ));
			}
		});
		
		//Change value Archives Options Field
        jQuery(document).on('change', '.' + archive_cond_rule + ' .pw_select', function(e) {
            var target = e.currentTarget.id,
				$this = this.value,           
				cond_type = document.querySelector("#" + target).closest(".cmb-repeatable-grouping").querySelector('.' + archive_cond_type + ' .pw_multiselect');
            
			var data = {
                action: nxt + "_singular_archives_filters_ajax"
            };
            data.data = '';
            data.rules = $this;
            data.data = JSON.stringify(nxt_config.nxt_archives[data.rules]);
			
			if ($this != undefined && nxt_config.nxt_archives[$this].condition_type!='' && nxt_config.nxt_archives[$this].condition_type!=undefined && nxt_config.nxt_archives[$this].condition_type == 'yes') {
				nxtShow(cond_type.closest( '.' + archive_cond_type ));
				jQuery.ajax({
                    type: "POST",
                    url: ajaxurl,
                    data: data,
                    beforeSend: function() {
                        nxtShow(cond_type.closest( '.' + archive_cond_type ));
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
				nxtHide(cond_type.closest( '.' + archive_cond_type ));
            }
        });
		
		//Display Sections Dropdown Header/Footer
		// var checkSectionFunc = function (value){
			// if(value!='none'){
				// nxtShow(document.querySelector('.nxt-sections-rules-fields'));
			// }else{
				// nxtHide(document.querySelector('.nxt-sections-rules-fields'));
			// }
		// }
		
		// var layoutSections = document.querySelectorAll('#nxt-hooks-layout-sections input[name=nxt-hooks-layout-sections]');
		// if( layoutSections.length ){
			// var checkSection = document.querySelector('.cmb2-id-' + nxt + '-hooks-layout input[name=nxt-hooks-layout]:checked');
			// checkSectionFunc(checkSection.value);
			// Array.prototype.forEach.call(layoutSections, function(el, i){
				// el.addEventListener("change", function(ele) {
					// checkSectionFunc(this.value);
				// });
			// });
		// }
		
		var field_arr = ['set-day','os','browser','login-status','user-roles'];
		//Display Rule Add Change Event
		var nxtDisplayRule = document.getElementById('nxt-add-display-rule');
		if( nxtDisplayRule ){
		var prefix_hooks = '.cmb2-id-nxt-hooks-layout';

			jQuery(nxtDisplayRule).on('change',function(e) {
				var value = jQuery(nxtDisplayRule).select2("val");
				value = value + '';
				var arr = value.split(",");
				if(arr !=undefined && arr!=''){
					if( arr.includes('particular-post')) {
						jQuery(prefix_hooks+'-specific').slideDown(400);
					}else{
						jQuery(prefix_hooks+'-specific').slideUp(400);
					}
					field_arr.forEach(function(val){
						if( arr.includes( val ) ) {
							jQuery(prefix_hooks+'-'+val).slideDown(400);
						}else{
							jQuery(prefix_hooks+'-'+val).slideUp(400);
						}
					})
				}else{
					jQuery(prefix_hooks+'-specific').slideUp(400);
					field_arr.forEach(function(val){
						jQuery(prefix_hooks+'-'+val).slideUp(400);
					})
				}
			}).change()
		}
		
	//Exclude Display Rule Add Change Event
	var ExcludeDisplayRule = document.getElementById('nxt-exclude-display-rule');
	if( ExcludeDisplayRule ){
		var exclude_hooks = '.cmb2-id-nxt-hooks-layout-exclude';
		jQuery( ExcludeDisplayRule ).on('change',function(e) {
			var value = jQuery( ExcludeDisplayRule ).select2("val");
			value = value + '';
			var arr = value.split(",");
			
			if(arr !=undefined && arr!=''){
				if( arr.includes( 'particular-post' ) ) {
					jQuery(exclude_hooks+'-specific').slideDown(400);
				}else{
					jQuery(exclude_hooks+'-specific').slideUp(400);
				}
				field_arr.forEach(function(val){
					if( arr.includes( val ) ) {
						jQuery(exclude_hooks+'-'+val).slideDown(400);
					}else{
						jQuery(exclude_hooks+'-'+val).slideUp(400);
					}
				})
			}else{
				jQuery(exclude_hooks+'-specific').slideUp(400);
				field_arr.forEach(function(val){
					jQuery(exclude_hooks+'-'+val).slideUp(400);
				})
			}
		}).change();
	}
	
	var init_target_rule_select2  = function( selector ) {

		jQuery(selector).select2({			
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
});