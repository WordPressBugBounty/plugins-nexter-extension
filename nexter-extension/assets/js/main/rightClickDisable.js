
var show_msg = true;
if (show_msg) {
	var options = {
		view_src: "View Source is disabled",
		inspect_e: "Inspect Element is disabled",
		right_click: "Right click is disabled",
		copy_cut_paste: "Cut/Copy/Paste is disabled",
		img_drop: "Image Drag & Drop is disabled",
		find: "Find is disabled",
		link: "Select Link is disabled"
	}
} else {
	var options = '';
}


document.oncontextmenu = function() { 
	if (show_msg) {show_toast('right_click');}
	return false;
}
document.ondragstart = function() { 
	if (show_msg) {show_toast('img_drop');}
	return false; 
}

document.onmousedown = function (event) {
	event = (event || window.event);
	if (event.keyCode === 123) {
		if (show_msg) {show_toast('inspect_e');}
		return false;
	}
}
document.onkeydown = function (event) {
	event = (event || window.event);
	if (event.keyCode === 116 || event.keyCode === 117 || event.keyCode === 118 || event.keyCode === 123 || (event.ctrlKey && event.shiftKey && event.keyCode === 73)|| (event.ctrlKey && event.shiftKey && event.keyCode === 74) || (event.ctrlKey && event.shiftKey && event.keyCode === 75) || (event.ctrlKey && event.shiftKey && event.keyCode === 69)) {
		if (show_msg !== '0') {show_toast('inspect_e');}
		return false;
	}
	if ((event.ctrlKey && event.keyCode === 85) || (event.ctrlKey && event.keyCode === 83) || (event.ctrlKey && event.keyCode === 80) || (event.ctrlKey && event.keyCode === 72) || (event.ctrlKey && event.keyCode === 76) || (event.ctrlKey && event.keyCode === 75)) {
		if (show_msg) {show_toast('view_src');}
		return false;
	}
	if(event.altKey && event.keyCode === 68){
		if (show_msg) {show_toast('link');}
		return false;
	}
	
	if(event.ctrlKey && event.keyCode === 70){
		if (show_msg) {show_toast('find');}
		return false;
	}
}

function addMultiEventListener(element, eventNames, listener) {
	var events = eventNames.split(' ');
	for (var i = 0, iLen = events.length; i < iLen; i++) {
		element.addEventListener(events[i], function (e) {
			e.preventDefault();
			if (show_msg) {
				show_toast(listener);
			}
		});
	}
}
addMultiEventListener(document, 'contextmenu', 'right_click');
addMultiEventListener(document, 'cut copy paste print', 'copy_cut_paste');

function show_toast(text) {
	var nxt_alert = document.getElementById("nxt-right-click-disable-alert");
	if(nxt_alert){
		
		let cus_alert = nxt_alert.getAttribute("data-customText");
		if(cus_alert && cus_alert!=undefined){
			nxt_alert.innerHTML = cus_alert;
		}else{
			nxt_alert.innerHTML = eval('options.' + text);
		}
		nxt_alert.className = "active";
		setTimeout(function () {
			nxt_alert.className = nxt_alert.className.replace("active", "")
		}, 3000);
	}
}



