"use strict";
document.addEventListener('DOMContentLoaded', function () {
	var nxt_adminBar = {
		init : function () {
			this.createMenu(NexterAdminBar)
			var nxtEdit_admin = document.querySelector('#wp-admin-bar-nxt_edit_template');
			if(nxtEdit_admin){
				nxtEdit_admin.classList.add('menupop');
				nxtEdit_admin.addEventListener('mouseenter', e => {
					e.currentTarget.classList.add('hover');
				});
				nxtEdit_admin.addEventListener('mouseleave', e => {
					e.currentTarget.classList.remove('hover');
				});
			}
		},
		createMenu : function(admnBar){
			var nexterList = '',
				otherList = '';
			if(admnBar!=''){
				var data = admnBar.nxt_edit_template
				if(data){
					data.forEach(function (item, i) {
						var type = (data[i].post_type=='nxt_builder') ? data[i].nexter_type : data[i].post_type_name;
						if(data[i].post_type=='nxt_builder'){
							nexterList += '<li id="wp-admin-bar-'+data[i].id+'" class="nxt-admin-submenu nxt-admin-'+data[i].id+'">';
								nexterList += '<a class="ab-item nxt-admin-sub-item" href="'+data[i].edit_url+'" >';
								nexterList += '<span class="nxt-admin-item-title">'+data[i].title+'</span>';
                                if(type){
                                    nexterList += '<span class="nxt-admin-item-type">'+type+'</span>';
                                }
							nexterList += '</a>';
							nexterList += '</li>';
						}else{
							otherList += '<li id="wp-admin-bar-'+data[i].id+'" class="nxt-admin-submenu nxt-admin-'+data[i].id+'">';
								otherList += '<a class="ab-item nxt-admin-sub-item" href="'+data[i].edit_url+'" >';
								otherList += '<span class="nxt-admin-item-title">'+data[i].title+'</span><span class="nxt-admin-item-type">'+type+'</span>';
							otherList += '</a>';
							otherList += '</li>';
						}
					});
				}
			}
			var nxtList = '',
				loopList = '';
			if(otherList){
				loopList = '<ul id="wp-admin-bar-nxt_edit_template" class="ab-submenu">'+otherList+'</ul>';
			}
			if(nexterList){
				nxtList = '<ul id="wp-admin-bar-nxt_edit_template" class="ab-submenu nxt-edit-nexter">'+nexterList+'</ul>';
			}
			if(nexterList || otherList){
				var itemList = '<div class="ab-sub-wrapper">'+loopList+nxtList+'</div>',
					edit_template = document.querySelector('.nxt_edit_template');
				edit_template.insertAdjacentHTML('beforeend', itemList);
			}else{
				document.querySelector('#wp-admin-bar-nxt_edit_template').style.display = "none";
			}
		},
	}
	nxt_adminBar.init();
});