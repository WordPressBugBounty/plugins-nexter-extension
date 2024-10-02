jQuery(document).ready(function($) {
	'use strict';
	$('ul.cmb2-image-select-list li input[type="radio"]').click( function(e) {
	    e.stopPropagation();
	    $(this).closest('ul').find('.cmb2-image-select-selected').removeClass('cmb2-image-select-selected');
	    $(this).parent().closest('li').addClass('cmb2-image-select-selected');
	});
});