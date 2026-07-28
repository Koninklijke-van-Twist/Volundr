(function () {
    'use strict';

    function initFilter(table) {
        var input = document.getElementById('filterInput');
        if (!input || !table) {
            return;
        }

        input.addEventListener('input', function () {
            var query = (input.value || '').trim().toLowerCase();
            var rows = table.tBodies[0] ? table.tBodies[0].rows : [];
            for (var i = 0; i < rows.length; i++) {
                var text = (rows[i].textContent || '').toLowerCase();
                rows[i].style.display = query === '' || text.indexOf(query) !== -1 ? '' : 'none';
            }
        });
    }

    function cellSortValue(cell) {
        if (!cell) {
            return '';
        }
        return (cell.textContent || '').trim().toLowerCase();
    }

    function compareValues(a, b) {
        var na = parseFloat(String(a).replace(',', '.').replace(/[^\d.-]/g, ''));
        var nb = parseFloat(String(b).replace(',', '.').replace(/[^\d.-]/g, ''));
        var aNum = !isNaN(na) && /^-?\d/.test(String(a).trim());
        var bNum = !isNaN(nb) && /^-?\d/.test(String(b).trim());
        if (aNum && bNum) {
            return na - nb;
        }
        return String(a).localeCompare(String(b), 'nl', { numeric: true, sensitivity: 'base' });
    }

    function initSort(table) {
        if (!table || !table.tHead) {
            return;
        }

        var headers = table.tHead.querySelectorAll('th');
        var sortState = { index: -1, asc: true };

        headers.forEach(function (th, colIndex) {
            th.addEventListener('click', function () {
                var tbody = table.tBodies[0];
                if (!tbody) {
                    return;
                }

                if (sortState.index === colIndex) {
                    sortState.asc = !sortState.asc;
                } else {
                    sortState.index = colIndex;
                    sortState.asc = true;
                }

                headers.forEach(function (h) {
                    h.classList.remove('sort-asc', 'sort-desc');
                });
                th.classList.add(sortState.asc ? 'sort-asc' : 'sort-desc');

                var rows = Array.prototype.slice.call(tbody.rows);
                rows.sort(function (ra, rb) {
                    var va = cellSortValue(ra.cells[colIndex]);
                    var vb = cellSortValue(rb.cells[colIndex]);
                    var cmp = compareValues(va, vb);
                    return sortState.asc ? cmp : -cmp;
                });

                rows.forEach(function (row) {
                    tbody.appendChild(row);
                });
            });
        });
    }

    function initLoader() {
        var links = document.querySelectorAll('a.contract-nav');
        if (!links.length) {
            return;
        }

        var overlay = document.createElement('div');
        overlay.className = 'qvt-loader';
        overlay.innerHTML = '<div class="qvt-loader-panel"><div class="qvt-loader-spinner"></div><p>Laden…</p></div>';
        document.body.appendChild(overlay);

        var style = document.createElement('style');
        style.textContent = '.qvt-loader{position:fixed;inset:0;z-index:12000;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.9);opacity:0;visibility:hidden;transition:opacity .2s}.qvt-loader.is-visible{opacity:1;visibility:visible}.qvt-loader-spinner{width:40px;height:40px;border:3px solid rgba(0,153,204,.2);border-top-color:#0099cc;border-radius:50%;animation:qvtspin .8s linear infinite}@keyframes qvtspin{to{transform:rotate(360deg)}}';
        document.head.appendChild(style);

        var timer = null;
        links.forEach(function (link) {
            link.addEventListener('click', function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    overlay.classList.add('is-visible');
                }, 500);
            });
        });
    }

    function initTooltips() {
        var tip = document.createElement('div');
        tip.className = 'qvt-float-tooltip';
        tip.setAttribute('role', 'tooltip');
        document.body.appendChild(tip);

        var active = null;

        function hide() {
            active = null;
            tip.classList.remove('is-visible');
        }

        function place(el) {
            var textEl = el.querySelector('.tooltiptext');
            var text = textEl ? (textEl.textContent || '').trim() : '';
            if (!text) {
                hide();
                return;
            }

            tip.textContent = text;
            tip.classList.add('is-visible');
            tip.style.transform = 'translate(-50%, -100%)';

            var rect = el.getBoundingClientRect();
            var tipRect = tip.getBoundingClientRect();
            var gap = 8;
            var left = rect.left + rect.width / 2;
            var top = rect.top - gap;
            var showBelow = top - tipRect.height < 8;

            if (showBelow) {
                tip.style.transform = 'translate(-50%, 0)';
                top = rect.bottom + gap;
            }

            var minX = tipRect.width / 2 + 8;
            var maxX = window.innerWidth - tipRect.width / 2 - 8;
            left = Math.max(minX, Math.min(maxX, left));

            tip.style.left = left + 'px';
            tip.style.top = top + 'px';
        }

        document.addEventListener('pointerover', function (event) {
            var target = event.target;
            if (!target || !target.closest) {
                return;
            }
            var el = target.closest('imprecise');
            if (!el) {
                return;
            }
            active = el;
            place(el);
        });

        document.addEventListener('pointerout', function (event) {
            var target = event.target;
            if (!target || !target.closest) {
                return;
            }
            var el = target.closest('imprecise');
            if (!el || el !== active) {
                return;
            }
            var related = event.relatedTarget;
            if (related && el.contains(related)) {
                return;
            }
            hide();
        });

        document.addEventListener('scroll', function () {
            if (active) {
                place(active);
            }
        }, true);

        window.addEventListener('resize', hide);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var table = document.getElementById('connect-data-table');
        initFilter(table);
        initSort(table);
        initLoader();
        initTooltips();
    });
})();
