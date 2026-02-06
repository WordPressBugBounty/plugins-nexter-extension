// Utility: Get URL parameter
function getUrlParameter(sParam) {
    const sPageURL = decodeURIComponent(window.location.search.substring(1));
    const sURLVariables = sPageURL.split('&');

    for (let param of sURLVariables) {
        const [key, value] = param.split('=');
        if (key === sParam) {
            return value === undefined ? true : value;
        }
    }
    return null;
}

document.addEventListener('DOMContentLoaded', function () {
    const reorderMode = window.nxtContentPostOrder?.reorder ?? 'default';
    const isHierarchical = window.nxtContentPostOrder?.hierarchical === '1';
    const postType = window.nxtContentPostOrder?.post_type;
    const nonce = window.nxtContentPostOrder?.nonce;
    const currentPage = parseInt(window.nxtContentPostOrder?.current_page || 1);
    const perPage = parseInt(window.nxtContentPostOrder?.per_page || 20);

    // MEDIA or POST Table Sorting
    const tableEl = document.querySelector('table.wp-list-table #the-list');
    if (tableEl && reorderMode === 'default' && postType=='attachment') {
        tableEl.classList.add('nxt-drag-post-order');

        new Sortable(tableEl, {
            handle: '.check-column',
            animation: 150,
            ghostClass: 'sortable-ghost',
            onEnd: function () {
                const paged = getUrlParameter('paged') || 1;

                const order = Array.from(tableEl.querySelectorAll('tr')).map(tr => {
                    const input = tr.querySelector('input[name="post[]"], input[name="media[]"]');
                    return input ? `post[]=${input.value}` : '';
                }).filter(Boolean).join('&');

                const queryString = {
                    action: 'nxt_save_post_order',
                    post_type: postType,
                    order: order,
                    paged: paged,
                    nonce: nonce
                };

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(queryString).toString()
                }).then(response => response.text())
                  .then(data => {
                      console.log('Flat order saved.');
                  }).catch(err => {
                      console.error('Error saving flat order:', err);
                  });
            }
        });
    }

    // CPT Hierarchical View Sorting
    if (reorderMode === 'sortable') {
        function createTree(posts) {
            const map = new Map();
            const tree = [];

            posts.forEach(post => {
                post.children = [];
                map.set(post.ID, post);
            });

            posts.forEach(post => {
                if (isHierarchical && post.post_parent && map.has(post.post_parent)) {
                    map.get(post.post_parent).children.push(post);
                } else {
                    tree.push(post);
                }
            });

            return tree;
        }

        function renderTreeHTML(tree, level = 0) {
            let html = `<ul class="nxt-cpt-sortable" data-level="${level}">`;
            tree.forEach(post => {
                html += `<li data-id="${post.ID}" data-parent="${post.post_parent}" data-order="${post.menu_order}">
                    <div class="nxt-cpt-page-item">
                        <div class="nxt-cpt-handle">☰</div>
                        <div class="nxt-cpt-title">
                            ${post.post_title}
                            <span class="nxt-cpt-status">${post.post_status !== 'publish' ? post.post_status : ''}</span>
                        </div>
                    </div>`;
                html += isHierarchical && post.children.length > 0
                    ? renderTreeHTML(post.children, level + 1)
                    : `<ul class="nxt-cpt-sortable" data-level="${level + 1}"></ul>`;
                html += '</li>';
            });
            html += '</ul>';
            return html;
        }

        function saveTreeOrder() {
            const items = document.querySelectorAll('.nxt-cpt-sortable li');
            const orderData = [];

            items.forEach(item => {
                let parentId = 0;
                const parentLi = item.closest('ul').closest('li');
                if (isHierarchical && parentLi) {
                    parentId = parentLi.getAttribute('data-id') || 0;
                }

                orderData.push({
                    id: item.getAttribute('data-id'),
                    parent_id: parentId,
                    order: item.getAttribute('data-order')
                });
            });

            let i = (currentPage - 1) * perPage;
            const updatedOrder = orderData.map(item => {
                i++;
                item.order = i;
                return item;
            });

            const payload = {
                action: 'nxt_save_post_order',
                post_type: postType,
                order_data: JSON.stringify(updatedOrder),
                paged: currentPage,
                nonce: nonce
            };

            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(payload).toString()
            }).then(res => res.text())
              .then(data => {
                  console.log('Tree order saved.');
              }).catch(err => {
                  console.error('Error saving tree order:', err);
              });
        }

        function initSortableTreeView() {
            const table = document.querySelector('.wp-list-table');
            if (!table) return;

            const tree = createTree(window.nxtContentPostOrder.posts);
            table.innerHTML = renderTreeHTML(tree);

            document.querySelectorAll('.nxt-cpt-sortable').forEach(ul => {
                new Sortable(ul, {
                    group: isHierarchical ? 'nested-posts' : 'flat-posts',
                    animation: 150,
                    handle: '.nxt-cpt-page-item',
                    fallbackOnBody: true,
                    forceFallback: true,
                    onEnd: saveTreeOrder
                });
            });
        }

        initSortableTreeView();
    }
});
