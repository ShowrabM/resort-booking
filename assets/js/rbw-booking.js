(function(){
  if (typeof RBW === 'undefined' || !RBW.ajaxUrl) return;

  const widgets = document.querySelectorAll('.rbw-wrap[data-rbw-widget]');
  if (!widgets.length) return;

  // Parse dd/mm/yyyy or yyyy-mm-dd to UTC Date; return null if invalid
  const parseDate = (str) => {
    if(!str) return null;
    // normalize separators
    let d,m,y;
    if(/^\d{2}\/\d{2}\/\d{4}$/.test(str)){
      [d,m,y] = str.split('/').map(Number);
    } else if(/^\d{4}-\d{2}-\d{2}$/.test(str)){
      [y,m,d] = str.split('-').map(Number);
    } else {
      return null;
    }
    const dt = new Date(Date.UTC(y, m-1, d));
    return isNaN(dt.getTime()) ? null : dt;
  };

  const toISO = (str) => {
    const dt = parseDate(str);
    if(!dt) return '';
    const d = String(dt.getUTCDate()).padStart(2,'0');
    const m = String(dt.getUTCMonth()+1).padStart(2,'0');
    const y = dt.getUTCFullYear();
    return `${y}-${m}-${d}`;
  };

  const formatDisplay = (str) => {
    const dt = parseDate(str);
    if(!dt) return str;
    const d = String(dt.getUTCDate()).padStart(2,'0');
    const m = String(dt.getUTCMonth()+1).padStart(2,'0');
    const y = dt.getUTCFullYear();
    return `${d}/${m}/${y}`;
  };

  const todayISO = () => {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth()+1).padStart(2,'0');
    const day = String(d.getDate()).padStart(2,'0');
    return `${y}-${m}-${day}`;
  };

  const nights = (checkIn, checkOut) => {
    const a = parseDate(checkIn);
    const b = parseDate(checkOut);
    if(!a || !b) return 0;
    const A = Date.UTC(a.getUTCFullYear(), a.getUTCMonth(), a.getUTCDate());
    const B = Date.UTC(b.getUTCFullYear(), b.getUTCMonth(), b.getUTCDate());
    return Math.max(0, Math.round((B - A) / 86400000));
  };

  widgets.forEach(widget => {
    const q = sel => widget.querySelector(sel);

    const parseResponse = async (res) => {
      let text = '';
      try{
        text = await res.text();
      }catch(_){}
      const cleaned = (text || '')
        .replace(/^\uFEFF/, '') // strip BOM if present
        .trim();
      let json;
      try{
        json = cleaned ? JSON.parse(cleaned) : null;
      }catch(e){
        const err = new Error(cleaned || `HTTP ${res.status}`);
        err.details = text;
        throw err;
      }
      if (!res.ok) {
        const msg = json?.data?.message || json?.message || `HTTP ${res.status}`;
        const err = new Error(msg);
        err.details = text;
        throw err;
      }
      return json;
    };

    const handleError = (e, context) => {
      const msg = (e && e.message) ? e.message : 'Network error';
      console.error(`[RBW] ${context} failed`, e, e?.details);
      showAlert('err', msg.length > 200 ? 'Server error. Please try again.' : msg);
    };

    const openBtn   = q('[data-rbw-open]');
    const backdrop  = q('[data-rbw-backdrop]');
    const closeBtn  = q('[data-rbw-close]');
    const inEl      = q('[data-rbw-in]');
    const outEl     = q('[data-rbw-out]');
    const calEl     = q('[data-rbw-calendar]');
    const searchBtn = q('[data-rbw-search]');
    const alertEl   = q('[data-rbw-alert]');
    const listEl    = q('[data-rbw-list]');

    if (!openBtn || !backdrop || !searchBtn || !alertEl || !listEl || !inEl || !outEl) return;

    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const dayNames = ['Su','Mo','Tu','We','Th','Fr','Sa'];

    const calState = {
      view: new Date(),
      checkIn: '',
      checkOut: ''
    };

    const setInputsFromState = () => {
      inEl.value = calState.checkIn || '';
      outEl.value = calState.checkOut || '';
    };

    const isoFromParts = (y, m, d) => {
      const mm = String(m).padStart(2,'0');
      const dd = String(d).padStart(2,'0');
      return `${y}-${mm}-${dd}`;
    };

    const canGoPrev = (y, m) => {
      const today = todayISO();
      const cur = isoFromParts(y, m, 1);
      const t = today.slice(0, 8) + '01';
      return cur > t;
    };

    const renderCalendar = () => {
      if (!calEl) return;
      const y = calState.view.getFullYear();
      const m = calState.view.getMonth() + 1;
      const firstDow = new Date(y, m - 1, 1).getDay();
      const daysInMonth = new Date(y, m, 0).getDate();
      const today = todayISO();

      let cells = '';
      for (let i = 0; i < firstDow; i++) {
        cells += '<div class="rbw-cal-empty"></div>';
      }
      for (let d = 1; d <= daysInMonth; d++) {
        const iso = isoFromParts(y, m, d);
        const isPast = iso < today;
        const isSelected = iso === calState.checkIn || iso === calState.checkOut;
        const inRange = calState.checkIn && calState.checkOut && iso > calState.checkIn && iso < calState.checkOut;
        const cls = [
          'rbw-cal-day',
          isPast ? 'disabled' : '',
          isSelected ? 'selected' : '',
          inRange ? 'range' : ''
        ].filter(Boolean).join(' ');
        cells += `<button type="button" class="${cls}" data-cal-date="${iso}" ${isPast ? 'disabled' : ''}>${d}</button>`;
      }

      const prevDisabled = !canGoPrev(y, m);
      calEl.innerHTML = `
        <div class="rbw-cal-head">
          <button type="button" class="rbw-cal-nav" data-cal-prev ${prevDisabled ? 'disabled' : ''}>&larr;</button>
          <div class="rbw-cal-title">${monthNames[m - 1]} ${y}</div>
          <button type="button" class="rbw-cal-nav" data-cal-next>&rarr;</button>
        </div>
        <div class="rbw-cal-grid">
          ${dayNames.map(d => `<div class="rbw-cal-dow">${d}</div>`).join('')}
          ${cells}
        </div>
      `;

      const prevBtn = calEl.querySelector('[data-cal-prev]');
      const nextBtn = calEl.querySelector('[data-cal-next]');
      if (prevBtn) prevBtn.addEventListener('click', () => {
        if (prevDisabled) return;
        calState.view = new Date(y, m - 2, 1);
        renderCalendar();
      });
      if (nextBtn) nextBtn.addEventListener('click', () => {
        calState.view = new Date(y, m, 1);
        renderCalendar();
      });

      calEl.querySelectorAll('[data-cal-date]').forEach(btn => {
        btn.addEventListener('click', () => {
          const iso = btn.getAttribute('data-cal-date');
          if (!iso) return;
          if (!calState.checkIn || (calState.checkIn && calState.checkOut)) {
            calState.checkIn = iso;
            calState.checkOut = '';
          } else if (iso > calState.checkIn) {
            calState.checkOut = iso;
          } else {
            calState.checkIn = iso;
            calState.checkOut = '';
          }
          setInputsFromState();
          renderCalendar();
        });
      });
    };

    if (calEl) {
      if (inEl.value) calState.checkIn = toISO(inEl.value);
      if (outEl.value) calState.checkOut = toISO(outEl.value);
      if (calState.checkIn) {
        const dt = parseDate(calState.checkIn);
        if (dt) calState.view = new Date(dt.getUTCFullYear(), dt.getUTCMonth(), 1);
      }
      setInputsFromState();
      renderCalendar();
    }

    const showAlert = (type, msg) => {
      alertEl.style.display = 'block';
      alertEl.className = 'rbw-alert ' + (type === 'err' ? 'err' : 'ok');
      alertEl.textContent = msg;
    };

    const clearAlert = () => {
      alertEl.style.display = 'none';
      alertEl.className = 'rbw-alert';
      alertEl.textContent = '';
    };

    const open = () => {
      backdrop.style.display = 'block';
      backdrop.setAttribute('aria-hidden','false');
      clearAlert();
      listEl.innerHTML = '';
      bookingForm.style.display = 'none';
      bookingRoom = null;
      currentRooms = [];
    };

    const close = () => {
      backdrop.style.display = 'none';
      backdrop.setAttribute('aria-hidden','true');
    };

    openBtn.addEventListener('click', open);
    if (closeBtn) closeBtn.addEventListener('click', close);
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });

    // Booking form (embedded inside modal)
    const bookingForm = document.createElement('div');
    bookingForm.className = 'rbw-form';
    bookingForm.innerHTML = `
      <h4>Enter Booking Details</h4>
      <div class="rbw-form-grid">
        <label>Your Name*<input type="text" name="rbw_name" required></label>
        <label>Mobile Number*<input type="number" inputmode="numeric" pattern="[0-9]*" name="rbw_phone" required></label>
        <label>Number of Guests*<input type="number" min="1" value="1" name="rbw_guests" required></label>
        <label>NID(optional, take photo)<input type="file" accept="image/*" capture="environment" name="rbw_nid"></label>
      </div>
      <div class="rbw-paymode">
        <div class="rbw-paymode-title">Payment</div>
        <div class="rbw-paymode-grid">
          <label class="rbw-paycard" data-pay-card="deposit">
            <input type="radio" name="rbw_pay_mode" value="deposit" checked>
            <div class="rbw-paycard-body">
              <div class="rbw-paycard-title">Pay Advance Payment</div>
              <div class="rbw-paycard-amount">Pay <b data-adv-pay>0</b> now</div>
              <div class="rbw-paycard-sub">Pay remaining <span data-adv-balance>0</span></div>
            </div>
          </label>
          <label class="rbw-paycard" data-pay-card="full">
            <input type="radio" name="rbw_pay_mode" value="full">
            <div class="rbw-paycard-body">
              <div class="rbw-paycard-title">Pay in Full <span class="rbw-save">SAVE 5%</span></div>
              <div class="rbw-paycard-amount">Pay <b data-full-pay>0</b> now</div>
              <div class="rbw-paycard-sub">Save <span data-full-save>0</span></div>
            </div>
          </label>
        </div>
        <div class="rbw-paymode-note">Secure payment. Your details are protected with SSL encryption.</div>
      </div>
      <div class="rbw-form-actions">
        <button type="button" class="rbw-cancel">Cancel</button>
        <button type="button" class="rbw-submit">Confirm & Pay Advance</button>
      </div>
    `;
    const modalBody = q('.rbw-body');
    if (modalBody) modalBody.appendChild(bookingForm);
    bookingForm.style.display = 'none';

    const guestsInput = bookingForm.querySelector('[name="rbw_guests"]');
    const payModeInputs = bookingForm.querySelectorAll('[name="rbw_pay_mode"]');

    let bookingRoom = null;
    let currentRooms = [];

    const getGuests = () => {
      const v = parseInt(guestsInput.value, 10);
      return Number.isFinite(v) && v > 0 ? v : 1;
    };

    const getPayMode = () => {
      const checked = bookingForm.querySelector('[name="rbw_pay_mode"]:checked');
      return checked ? checked.value : 'deposit';
    };

    const computeTotals = (room, guests, payMode) => {
      const ppn = Number(room.price_per_night) || 0;
      const n = Number(room.nights) || 0;
      const total = ppn * n * guests;
      const discount = payMode === 'full' ? total * 0.05 : 0;
      const payNow = payMode === 'full' ? Math.max(0, total - discount) : (Number(room.deposit) || 0);
      const balance = payMode === 'full' ? 0 : Math.max(0, total - payNow);
      return { total, discount, payNow, balance };
    };

    const updatePricingForGuests = () => {
      const guests = getGuests();
      const payMode = getPayMode();
      const submitBtn = bookingForm.querySelector('.rbw-submit');
      if (submitBtn) {
        submitBtn.textContent = payMode === 'full' ? 'Confirm & Pay Full' : 'Confirm & Pay Advance';
      }
      listEl.querySelectorAll('.rbw-room[data-room]').forEach(card => {
        const roomId = card.getAttribute('data-room');
        const room = currentRooms.find(x => String(x.room_id) === String(roomId));
        if (!room) return;
        const totals = computeTotals(room, guests, payMode);
        const totalEl = card.querySelector('[data-total]');
        const payNowEl = card.querySelector('[data-pay-now]');
        const balanceEl = card.querySelector('[data-balance]');
        const discountEl = card.querySelector('[data-discount]');
        const discountRow = card.querySelector('[data-discount-row]');
        if (totalEl) totalEl.textContent = Number(totals.total).toLocaleString();
        if (payNowEl) payNowEl.textContent = Number(totals.payNow).toLocaleString();
        if (balanceEl) balanceEl.textContent = Number(totals.balance).toLocaleString();
        if (discountEl) discountEl.textContent = Number(totals.discount).toLocaleString();
        if (discountRow) discountRow.hidden = totals.discount <= 0;
      });

      const updatePayCards = (room) => {
        if (!room) return;
        const dep = computeTotals(room, guests, 'deposit');
        const full = computeTotals(room, guests, 'full');
        const advPay = bookingForm.querySelector('[data-adv-pay]');
        const advBal = bookingForm.querySelector('[data-adv-balance]');
        const fullPay = bookingForm.querySelector('[data-full-pay]');
        const fullSave = bookingForm.querySelector('[data-full-save]');
        if (advPay) advPay.textContent = Number(dep.payNow).toLocaleString();
        if (advBal) advBal.textContent = Number(dep.balance).toLocaleString();
        if (fullPay) fullPay.textContent = Number(full.payNow).toLocaleString();
        if (fullSave) fullSave.textContent = Number(full.discount).toLocaleString();
        bookingForm.querySelectorAll('.rbw-paycard').forEach(card => {
          const mode = card.getAttribute('data-pay-card');
          card.classList.toggle('active', mode === payMode);
        });
      };

      if (bookingRoom) {
        const room = currentRooms.find(x => String(x.room_id) === String(bookingRoom.room_id)) || bookingRoom;
        const totals = computeTotals(room, guests, payMode);
        bookingRoom.total = totals.total;
        bookingRoom.deposit = totals.payNow;
        bookingRoom.balance = totals.balance;
        bookingRoom.discount = totals.discount;
        bookingRoom.pay_mode = payMode;
        updatePayCards(room);
      } else {
        updatePayCards(currentRooms[0]);
      }
    };

    const openBookingForm = (room) => {
      const guests = getGuests();
      const payMode = getPayMode();
      const totals = computeTotals(room, guests, payMode);
      bookingRoom = { ...room, total: totals.total, deposit: totals.payNow, balance: totals.balance, discount: totals.discount, pay_mode: payMode };
      bookingForm.style.display = 'block';
      bookingForm.scrollIntoView({behavior:'smooth', block:'center'});
    };

    bookingForm.querySelector('.rbw-cancel').addEventListener('click', () => {
      bookingForm.style.display = 'none';
      bookingRoom = null;
    });
    guestsInput.addEventListener('input', updatePricingForGuests);
    payModeInputs.forEach(r => r.addEventListener('change', updatePricingForGuests));

    bookingForm.querySelector('.rbw-submit').addEventListener('click', async () => {
      if(!bookingRoom){
        showAlert('err','No room selected.');
        return;
      }
      const name   = bookingForm.querySelector('[name="rbw_name"]').value.trim();
      const phone  = bookingForm.querySelector('[name="rbw_phone"]').value.trim();
      const guests = getGuests();
      const payMode = getPayMode();
      const nidFile= bookingForm.querySelector('[name="rbw_nid"]').files[0];
      if(!name || !phone || !guests){
        showAlert('err','Please fill in all required fields.');
        return;
      }

      const fd = new FormData();
      fd.append('action', 'rbw_create_booking');
      fd.append('nonce', RBW.nonce);
      fd.append('room_id', bookingRoom.room_id);
      fd.append('room_name', bookingRoom.room_name);
      fd.append('check_in', toISO(inEl.value));
      fd.append('check_out', toISO(outEl.value));
      fd.append('nights', bookingRoom.nights);
      const totals = computeTotals(bookingRoom, guests, payMode);
      fd.append('total', totals.total);
      fd.append('deposit', totals.payNow);
      fd.append('balance', totals.balance);
      fd.append('price_per_night', bookingRoom.price_per_night);
      fd.append('customer_name', name);
      fd.append('customer_phone', phone);
      fd.append('guests', guests);
      fd.append('pay_mode', payMode);
      if (nidFile) fd.append('nid', nidFile);

      const submitBtn = bookingForm.querySelector('.rbw-submit');
      submitBtn.disabled = true;
      submitBtn.textContent = 'Processing...';

      try{
        const res = await fetch(RBW.ajaxUrl, { method:'POST', credentials:'same-origin', body: fd });
        const json = await parseResponse(res);
        if(!json.success){
          submitBtn.disabled = false;
          submitBtn.textContent = getPayMode() === 'full' ? 'Confirm & Pay Full' : 'Confirm & Pay Advance';
          showAlert('err', json.data?.message || 'Error');
          return;
        }
        window.location.href = json.data.checkout_url;
      }catch(e){
        submitBtn.disabled = false;
        submitBtn.textContent = getPayMode() === 'full' ? 'Confirm & Pay Full' : 'Confirm & Pay Advance';
        handleError(e, 'Create booking');
      }
    });

    const renderRooms = (rooms, checkIn, checkOut) => {
      const displayIn = formatDisplay(checkIn);
      const displayOut = formatDisplay(checkOut);
      currentRooms = rooms.slice();
      listEl.innerHTML = rooms.map((r, idx) => `
        <div class="rbw-room ${idx === 0 ? 'active' : ''}" data-room="${r.room_id}">
          <div class="rbw-room-top">
            <h4>${r.room_name}</h4>
            <span class="rbw-badge">Available: ${r.units_left}</span>
          </div>
          <div class="rbw-meta">
            <div>Per night: <b>${Number(r.price_per_night).toLocaleString()}</b></div>
            <div>Nights: <b>${Number(r.nights).toLocaleString()}</b></div>
            <div>Total: <b data-total>${Number(r.total).toLocaleString()}</b></div>
            <div data-discount-row hidden>Discount: <b data-discount>0</b></div>
            <div>Pay Now: <b data-pay-now>${Number(r.deposit).toLocaleString()}</b></div>
            <div>Balance: <b data-balance>${Number(r.balance).toLocaleString()}</b></div>
            <div>Dates: <b>${displayIn} -> ${displayOut}</b></div>
          </div>
        </div>
      `).join('');

      const setActive = (roomId) => {
        listEl.querySelectorAll('.rbw-room').forEach(el => el.classList.remove('active'));
        const activeEl = listEl.querySelector(`.rbw-room[data-room="${roomId}"]`);
        if (activeEl) activeEl.classList.add('active');
      };

      listEl.querySelectorAll('.rbw-room[data-room]').forEach(card => {
        card.addEventListener('click', () => {
          clearAlert();
          const roomId = card.getAttribute('data-room');
          const match = rooms.find(x => String(x.room_id) === String(roomId)) || null;
          if (!match) {
            showAlert('err','Room not found.');
            return;
          }
          setActive(roomId);
          openBookingForm(match);
        });
      });

      // Auto-open form with the first room
      if (rooms[0]) {
        openBookingForm(rooms[0]);
      }
      updatePricingForGuests();
    };

    searchBtn.addEventListener('click', async () => {
      clearAlert();
      listEl.innerHTML = '';
      bookingForm.style.display = 'none';
      bookingRoom = null;

      const checkInRaw = inEl.value;
      const checkOutRaw = outEl.value;
      const checkInISO = toISO(checkInRaw);
      const checkOutISO = toISO(checkOutRaw);
      const today = todayISO();

      if(!checkInISO || !checkOutISO){ showAlert('err','Please select dates.'); return; }
      if(checkInISO < today || checkOutISO < today){ showAlert('err','Please select today or a future date.'); return; }
      if(nights(checkInRaw, checkOutRaw) <= 0){ showAlert('err','Check-out must be after check-in.'); return; }

      const fd = new FormData();
      fd.append('action', 'rbw_get_availability');
      fd.append('nonce', RBW.nonce);
      fd.append('check_in', checkInISO);
      fd.append('check_out', checkOutISO);
      const roomFilter = widget.getAttribute('data-rbw-room');
      if(roomFilter) fd.append('room_id', roomFilter);

      try{
        const res = await fetch(RBW.ajaxUrl, { method:'POST', credentials:'same-origin', body: fd });
        const json = await parseResponse(res);

        if(!json.success){
          showAlert('err', json.data?.message || 'Error');
          return;
        }

        const rooms = json.data.rooms || [];
        if(!rooms.length){
          showAlert('err', 'No rooms available for these dates.');
          return;
        }

        renderRooms(rooms, checkInISO, checkOutISO);
        showAlert('ok', 'Rooms available. Select a room and complete the form.');
      }catch(e){
        handleError(e, 'Availability');
      }
    });
  });
})();

