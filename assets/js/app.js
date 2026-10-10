/* SIPBJ - interaksi umum */
document.addEventListener('DOMContentLoaded', function () {
  // Hamburger (mobile)
  const hb = document.getElementById('btnNav');
  if (hb) hb.addEventListener('click', () => document.body.classList.toggle('nav-open'));
  document.addEventListener('click', (ev) => {
    if (document.body.classList.contains('nav-open') &&
        !ev.target.closest('.sidebar') && !ev.target.closest('#btnNav')) {
      document.body.classList.remove('nav-open');
    }
  });

  // Dropdown user
  const chip = document.getElementById('userChip');
  if (chip) {
    const menu = chip.querySelector('.dropdown-menu');
    chip.addEventListener('click', function (ev) {
      ev.stopPropagation();
      menu.classList.toggle('open');
    });
    document.addEventListener('click', () => menu.classList.remove('open'));
  }

  // Konfirmasi aksi berbahaya / persetujuan
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', function (ev) {
      if (!confirm(el.getAttribute('data-confirm'))) ev.preventDefault();
    });
  });

  // Auto-submit filter
  document.querySelectorAll('[data-autosubmit]').forEach(el => {
    el.addEventListener('change', () => el.form.submit());
  });

  // Baris dinamis item (kebutuhan/rencana/ATK)
  document.querySelectorAll('[data-itemtable]').forEach(tbl => {
    const tbody = tbl.querySelector('tbody');
    const addBtn = tbl.parentElement.querySelector('[data-addrow]');
    const tplName = tbl.getAttribute('data-itemtable');
    let idx = tbody.querySelectorAll('tr').length;
    function renum() {
      tbody.querySelectorAll('tr').forEach((tr, i) => {
        tr.querySelector('.rowno') && (tr.querySelector('.rowno').textContent = i + 1);
      });
      calcTotals(tbl);
    }
    if (addBtn) addBtn.addEventListener('click', () => {
      const tr = document.createElement('tr');
      tr.innerHTML = rowTemplate(tplName, idx);
      tbody.appendChild(tr);
      idx++; renum();
    });
    tbody.addEventListener('input', (ev) => {
      const tr = ev.target.closest('tr');
      if (tr) {
        const qty = tr.querySelector('[data-qty]'), price = tr.querySelector('[data-price]'), tot = tr.querySelector('[data-rowtotal]');
        if (qty && price && tot) tot.textContent = fmtRp((+qty.value || 0) * (+price.value || 0));
      }
      calcTotals(tbl);
    });
    tbody.addEventListener('click', (ev) => {
      if (ev.target.closest('[data-delrow]')) {
        const tr = ev.target.closest('tr');
        if (tbody.querySelectorAll('tr').length > 1) { tr.remove(); renum(); }
        else alert('Minimal satu baris item.');
      }
    });
    tbody.querySelectorAll('[data-delrow]').forEach(b => b.addEventListener('click', () => {}));
    renum();
  });

  function calcTotals(tbl) {
    let sum = 0;
    tbl.querySelectorAll('tbody [data-rowtotal]').forEach(td => {
      const tr = td.closest('tr');
      const qty = tr.querySelector('[data-qty]'), price = tr.querySelector('[data-price]');
      if (qty && price) sum += (+qty.value || 0) * (+price.value || 0);
    });
    const targetId = tbl.getAttribute('data-total-target');
    if (targetId) {
      const el = document.getElementById(targetId);
      if (el) el.textContent = fmtRp(sum);
    }
    const inp = tbl.closest('form') && tbl.closest('form').querySelector('[data-grandtotal]');
    if (inp) inp.value = sum;
  }

  function fmtRp(n) { return 'Rp ' + (n || 0).toLocaleString('id-ID'); }

  function rowTemplate(name, i) {
    const del = '<button type="button" class="btn btn-xs btn-ghost" data-delrow title="Hapus baris">✕</button>';
    if (name === 'item') {
      return `<td class="rowno"></td>
        <td><input class="input" name="item_nama[]" required placeholder="Nama barang/jasa"></td>
        <td><input class="input" name="item_spesifikasi[]" placeholder="Spesifikasi"></td>
        <td><input class="input" name="item_satuan[]" value="unit" style="width:90px"></td>
        <td><input class="input" type="number" min="1" name="item_jumlah[]" data-qty value="1" required style="width:90px"></td>
        <td><input class="input" type="number" min="0" step="100" name="item_harga[]" data-price value="0" style="width:140px;text-align:right"></td>
        <td class="num" data-rowtotal>Rp 0</td>
        <td>${del}</td>`;
    }
    if (name === 'atk') {
      return '';
    }
    return '';
  }

  // Modal sederhana
  window.openModal = function (id) { const m = document.getElementById(id); if (m) m.classList.add('open'); };
  window.closeModal = function (id) { const m = document.getElementById(id); if (m) m.classList.remove('open'); };
  document.querySelectorAll('.modal-backdrop').forEach(m => {
    m.addEventListener('click', ev => { if (ev.target === m) m.classList.remove('open'); });
  });

  // Scan opname: enter pada input kode -> submit & fokus kembali
  const scan = document.getElementById('scanInput');
  if (scan) {
    scan.focus();
    scan.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); scan.closest('form').submit(); setTimeout(()=>scan.select(),50);} });
    const p = new URLSearchParams(location.search);
    if (p.get('scan') !== null) { scan.select(); }
  }

  // Print helper
  window.printArea = function () { window.print(); };
});
