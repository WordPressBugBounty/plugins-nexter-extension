"use strict";
document.addEventListener('DOMContentLoaded', (event) => {
	var headerSticky = document.querySelector( '#nxt-header.nxt-sticky' );
	if(headerSticky){
		nexterHeaderSticky( headerSticky );
	}
});

window.addEventListener('scroll', () => {
	var headerSticky = document.querySelector( '#nxt-header.nxt-sticky' );
	if(headerSticky){
		nexterHeaderSticky( headerSticky );
	}
});

window.addEventListener('resize', () => {
	var headerSticky = document.querySelector( '#nxt-header.nxt-sticky' );
	if(headerSticky){
		nexterHeaderSticky( headerSticky );
	}
});

var nexterHeaderSticky = function(elem){
	var headerHeight= elem.querySelector('.nxt-normal-header');
		headerHeight = headerHeight ? headerHeight.offsetHeight : 0;
	if(elem){
		var stickHeight = elem.querySelector(".nxt-stick-header-height");
		if( stickHeight ) {
			var offset_top = ( elem )? elem.offsetTop : 0;
			if( window.scrollY > offset_top) {
				stickHeight.style.minHeight = headerHeight+'px';
				elem.classList.add("normal-fixed-sticky");
			}else {
				stickHeight.style.minHeight = 0;
				elem.classList.remove("normal-fixed-sticky");
			}
		}else {
			headerHeight += 40;
			window.scrollY >= headerHeight ? elem.classList.add("fixed-sticky"): elem.classList.remove("fixed-sticky");
		}
	}
}