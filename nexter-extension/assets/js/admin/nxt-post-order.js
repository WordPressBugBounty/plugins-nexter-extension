// Get URL parameter utility
function getUrlParameter(sParam) {
    var sPageURL = decodeURIComponent(window.location.search.substring(1)),
        sURLVariables = sPageURL.split('&'),
        sParameterName;

    for (let i = 0; i < sURLVariables.length; i++) {
        sParameterName = sURLVariables[i].split('=');

        if (sParameterName[0] === sParam) {
            return sParameterName[1] === undefined ? true : sParameterName[1];
        }
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var el = document.querySelector('table.wp-list-table #the-list');

    if (el) {
        el.classList.add('nxt-drag-post-order');
        new Sortable(el, {
            handle: '.check-column',
            animation: 150,
            ghostClass: 'sortable-ghost',
            onEnd: function (evt) {
                var post_type = nxtContentPostOrder.post_type;
                var paged = getUrlParameter('paged') || 1;

                // Serialize rows using input[name="post[]"]
                var order = Array.from(el.querySelectorAll('tr')).map(tr => {
                    let input = tr.querySelector('input[name="post[]"]') || tr.querySelector('input[name="media[]"]');
                    return input ? `post[]=${input.value}` : '';
                }).filter(Boolean).join('&');

                var queryString = {
                    action: 'nxt_save_post_order',
                    post_type: post_type,
                    order: order,
                    paged: paged,
                    nonce: nxtContentPostOrder.nonce
                };
                console.log(queryString);
                
 
                // Send AJAX request
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(queryString).toString()
                }).then(response => response.text())
                  .then(data => {
                      // Optional: handle success
                  }).catch(err => {
                      // Optional: handle error
                  });
            }
        });
    }
});
