// ---------- Container + size popup ----------
(function () {
    var modal = document.getElementById('containerModal');
    var addBtn = document.getElementById('modalAdd');
    var sizeSection = document.getElementById('sizeSection');
    var radios = modal.querySelectorAll('input[name="container"]');
    var sizeRadios = modal.querySelectorAll('input[name="size"]');

    function openModal(id, name) {
        document.getElementById('modalProductID').value = id;
        document.getElementById('modalTitle').textContent = name;
        radios.forEach(function (r) { r.checked = false; });
        sizeRadios.forEach(function (r) { r.checked = false; });
        sizeSection.classList.remove('show');
        addBtn.disabled = true;
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    }
    function closeModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('.product-form .product').forEach(function (card) {
        card.addEventListener('click', function () {
            openModal(card.dataset.id, card.dataset.name);
        });
    });
    radios.forEach(function (r) {
        r.addEventListener('change', function () { sizeSection.classList.add('show'); });
    });
    sizeRadios.forEach(function (r) {
        r.addEventListener('change', function () { addBtn.disabled = false; });
    });
    document.getElementById('modalClose').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });
})();

// ---------- Quantity controls ----------
(function () {
    var form = document.getElementById('orderForm');
    var inputs = document.querySelectorAll('.qty-input');
    if (!form || !inputs.length) return;

    var fmt = function (n) {
        return '₱ ' + n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    };

    function recalc() {
        var total = 0, addons = 0;
        inputs.forEach(function (inp) {
            var q = Math.max(1, parseInt(inp.value, 10) || 1);
            var line = (parseFloat(inp.dataset.price) || 0) * q;
            total += line;
            addons += (parseFloat(inp.dataset.addon) || 0) * q;
            inp.closest('.order-item').querySelector('.line-total').textContent = fmt(line);
        });
        document.getElementById('subtotalAmount').textContent = fmt(total - addons);
        document.getElementById('addonAmount').textContent = fmt(addons);
        document.getElementById('totalAmount').textContent = fmt(total);
    }

    inputs.forEach(function (inp) {
        inp.addEventListener('input', recalc);
        inp.addEventListener('change', function () { form.requestSubmit(); });

        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                form.requestSubmit();
            }
        });
    });

    document.querySelectorAll('.qty-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var inp = btn.parentElement.querySelector('.qty-input');
            var next = (parseInt(inp.value, 10) || 1) + parseInt(btn.dataset.step, 10);
            inp.value = Math.max(1, next);
            recalc();
            form.requestSubmit();
        });
    });
})();

// ---------- Checkout summary popup ----------
(function () {
    var btn = document.getElementById('checkoutBtn');
    var modal = document.getElementById('checkoutModal');
    if (!btn || !modal) return;

    function closeModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    }

    btn.addEventListener('click', function () {
        var cups = 0, cones = 0;
        document.querySelectorAll('.order-item').forEach(function (row) {
            var q = Math.max(1, parseInt(row.querySelector('.qty-input').value, 10) || 1);
            var c = row.querySelector('.order-container').dataset.container;
            if (c === 'Cups') cups += q;
            else if (c === 'Cones') cones += q;
        });
        document.getElementById('cupCount').textContent = cups;
        document.getElementById('coneCount').textContent = cones;
        document.getElementById('checkoutTotal').textContent =
            document.getElementById('totalAmount').textContent;
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    });

    document.getElementById('checkoutClose').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });
})();