    (function () {
        var modal = document.getElementById('orderModal');

        function close() {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
        }

        function cell(text, cls) {
            var td = document.createElement('td');
            td.textContent = text;
            if (cls) td.className = cls;
            return td;
        }

        function openOrder(o) {
            document.getElementById('mNumber').textContent = 'Order ' + o.number;
            document.getElementById('mDate').textContent = o.date;
            document.getElementById('mStatus').textContent = o.status;
            document.getElementById('mTotal').textContent = '\u20B1 ' + o.total;
            document.getElementById('mSalesId').value = o.id;
            document.getElementById('mComplete').style.display =
                (o.status === 'Completed') ? 'none' : 'flex';

            var body = document.getElementById('mItems');
            body.textContent = '';
            o.items.forEach(function (it) {
                var tr = document.createElement('tr');
                tr.appendChild(cell(it.name));
                tr.appendChild(cell(it.container));
                tr.appendChild(cell(it.qty + 'x', 'num'));
                tr.appendChild(cell('\u20B1 ' + it.price, 'num'));
                tr.appendChild(cell('\u20B1 ' + it.line, 'num'));
                body.appendChild(tr);
            });

            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
        }

        document.querySelectorAll('.oc-view').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openOrder(JSON.parse(btn.dataset.order));
            });
        });

        document.getElementById('closeOrder').addEventListener('click', close);
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    })();