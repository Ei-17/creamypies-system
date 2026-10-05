    (function () {
        var modal = document.getElementById('addModal');
        var known = JSON.parse(modal.dataset.known || '{}');

        function open()  { modal.classList.add('open'); }
        function close() { modal.classList.remove('open'); }

        document.getElementById('openAdd').addEventListener('click', open);
        document.getElementById('closeAdd').addEventListener('click', close);
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

        document.getElementById('fItem').addEventListener('input', function () {
            var hit = known[this.value.trim().toLowerCase()];
            if (hit) {
                var sel = document.getElementById('fCategory');

                if (![].some.call(sel.options, function (o) { return o.value === hit.category; })) {
                    sel.add(new Option(hit.category, hit.category));
                }
                sel.value = hit.category;

                var unitSel = document.getElementById('fUnit');

                if (![].some.call(unitSel.options, function (o) { return o.value === hit.unit; })) {
                    unitSel.add(new Option(hit.unit, hit.unit));
                }
                unitSel.value = hit.unit;
            }
        });
    })();