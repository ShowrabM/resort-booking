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

    const fetchWithTimeout = async (url, options = {}, timeoutMs = 25000) => {
      const controller = new AbortController();
      const t = setTimeout(() => controller.abort(), timeoutMs);
      try{
        return await fetch(url, { ...options, signal: controller.signal });
      }finally{
        clearTimeout(t);
      }
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
      selectedRoomIds = new Set();
      showAllRooms = false;
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
    let selectedRoomIds = new Set();
    let showAllRooms = false;
    let currentRooms = [];
    let updateVisibleCards = () => {};

    const getGuests = () => {
      const v = parseInt(guestsInput.value, 10);
      return Number.isFinite(v) && v > 0 ? v : 1;
    };

    const getPayMode = () => {
      const checked = bookingForm.querySelector('[name="rbw_pay_mode"]:checked');
      return checked ? checked.value : 'deposit';
    };

    const roomsNeeded = (guests, capacity) => {
      const g = Number(guests) || 1;
      const cap = Number(capacity) || 1;
      return Math.max(1, Math.ceil(g / Math.max(1, cap)));
    };

    const computeTotals = (room, guests, payMode) => {
      const ppn = Number(room.price_per_night) || 0;
      const n = Number(room.nights) || 0;
      const capacity = Number(room.capacity) || 1;
      const bookingType = room.booking_type === 'entire_room' ? 'entire_room' : 'per_person';
      const needed = roomsNeeded(guests, capacity);
      const total = bookingType === 'entire_room' ? (ppn * n * needed) : (ppn * n * guests);
      const discount = payMode === 'full' ? total * 0.05 : 0;
      const depositSetting = Number(room.deposit) || 0;
      const depositTotal = depositSetting * needed;
      const payNow = payMode === 'full' ? Math.max(0, total - discount) : depositTotal;
      const balance = payMode === 'full' ? 0 : Math.max(0, total - payNow);
      return { total, discount, payNow, balance, rooms_needed: needed, capacity, deposit_total: depositTotal, booking_type: bookingType };
    };

    const allocateGuests = (rooms, guests) => {
      let remaining = guests;
      const allocations = rooms.map(r => {
        const cap = Number(r.capacity) || 1;
        const assigned = Math.min(cap, remaining);
        remaining -= assigned;
        return { room: r, guests: assigned };
      });
      return { allocations, remaining };
    };

    const isMultiSelectMode = () => showAllRooms || listEl.classList.contains('rbw-need-more');

    const updatePricingForGuests = () => {
      const guests = getGuests();
      const payMode = getPayMode();
      const submitBtn = bookingForm.querySelector('.rbw-submit');
      if (submitBtn) {
        submitBtn.textContent = payMode === 'full' ? 'Confirm & Pay Full' : 'Confirm & Pay Advance';
      }
      const selectedRooms = currentRooms.filter(r => selectedRoomIds.has(String(r.room_id)));
      const { allocations, remaining } = allocateGuests(selectedRooms, guests);
      const multiMode = isMultiSelectMode() || selectedRooms.length > 1;
      const allocMap = {};
      allocations.forEach(a => { allocMap[String(a.room.room_id)] = a.guests; });

      listEl.querySelectorAll('.rbw-room[data-room]').forEach(card => {
        const roomId = card.getAttribute('data-room');
        const room = currentRooms.find(x => String(x.room_id) === String(roomId));
        if (!room) return;
        let totals;
        if (!multiMode) {
          totals = computeTotals(room, guests, payMode);
        } else {
          const assigned = allocMap[String(roomId)] || 0;
          const previewGuests = assigned > 0 ? assigned : Math.min(guests, Number(room.capacity) || 1);
          totals = computeTotals(room, previewGuests, payMode);
        }
        const totalEl = card.querySelector('[data-total]');
        const payNowEl = card.querySelector('[data-pay-now]');
        const balanceEl = card.querySelector('[data-balance]');
        const discountEl = card.querySelector('[data-discount]');
        const discountRow = card.querySelector('[data-discount-row]');
        const roomsNeededEl = card.querySelector('[data-rooms-needed]');
        const statusEl = card.querySelector('[data-rooms-status]');
        if (totalEl) totalEl.textContent = Number(totals.total).toLocaleString();
        if (payNowEl) payNowEl.textContent = Number(totals.payNow).toLocaleString();
        if (balanceEl) balanceEl.textContent = Number(totals.balance).toLocaleString();
        if (discountEl) discountEl.textContent = Number(totals.discount).toLocaleString();
        if (discountRow) discountRow.hidden = totals.discount <= 0;
        if (roomsNeededEl) {
          if (!multiMode) {
            roomsNeededEl.textContent = Number(totals.rooms_needed).toLocaleString();
          } else {
            const assigned = allocMap[String(roomId)] || 0;
            roomsNeededEl.textContent = assigned > 0 ? String(assigned) : '-';
          }
        }
        const unitsLeft = Number(room.units_left) || 0;
        const ok = unitsLeft >= totals.rooms_needed;
        card.classList.toggle('rbw-room-disabled', !ok);
        if (statusEl) {
          if (!multiMode) {
            statusEl.textContent = ok ? `You need ${totals.rooms_needed} room(s)` : `Not enough rooms (need ${totals.rooms_needed})`;
          } else {
            const assigned = allocMap[String(roomId)] || 0;
            statusEl.textContent = assigned > 0 ? `Assigned ${assigned} guest(s)` : 'Select to add capacity';
          }
        }
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

      if (selectedRooms.length) {
        if (remaining > 0) {
          if (submitBtn) submitBtn.disabled = true;
          showAllRooms = true;
          listEl.classList.add('rbw-need-more');
          showAlert('err', `You need to choose more rooms for ${remaining} more guest(s).`);
          updateVisibleCards();
          return;
        }

        let total = 0;
        let discount = 0;
        let payNow = 0;
        let balance = 0;
        const roomsPayload = [];
        allocations.forEach(({ room, guests: g }) => {
          const totals = computeTotals(room, g, payMode);
          total += totals.total;
          discount += totals.discount;
          payNow += totals.payNow;
          balance += totals.balance;
          roomsPayload.push({
            room_id: room.room_id,
            room_name: room.room_name,
            guests: g,
            capacity: room.capacity,
            booking_type: totals.booking_type
          });
        });

       
        showAllRooms = false;
        listEl.classList.remove('rbw-need-more');
        showAlert('ok', `You need ${selectedRooms.length} room(s) for ${guests} guest(s).`);
        bookingRoom = {
          rooms: roomsPayload,
          total,
          discount,
          deposit: payNow,
          balance,
          pay_mode: payMode
        };
        updatePayCards(selectedRooms[0]);
        updateVisibleCards();
      } else {
        showAllRooms = false;
        listEl.classList.remove('rbw-need-more');
        const submitBtn = bookingForm.querySelector('.rbw-submit');
        if (submitBtn) submitBtn.disabled = true;
        if (selectedRoomIds.size === 0) {
          showAlert('err', 'Please select a room.');
        }
        updateVisibleCards();
        updatePayCards(currentRooms[0]);
      }
    };

    const openBookingForm = () => {
      bookingForm.style.display = 'block';
      bookingForm.scrollIntoView({behavior:'smooth', block:'center'});
      updatePricingForGuests();
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
      fd.append('rooms', JSON.stringify(bookingRoom.rooms || []));
      fd.append('check_in', toISO(inEl.value));
      fd.append('check_out', toISO(outEl.value));
      fd.append('nights', currentRooms[0]?.nights || 0);
      fd.append('total', bookingRoom.total || 0);
      fd.append('deposit', bookingRoom.deposit || 0);
      fd.append('balance', bookingRoom.balance || 0);
      fd.append('customer_name', name);
      fd.append('customer_phone', phone);
      fd.append('guests', guests);
      fd.append('pay_mode', payMode);
      if (nidFile) fd.append('nid', nidFile);

      const submitBtn = bookingForm.querySelector('.rbw-submit');
      submitBtn.disabled = true;
      submitBtn.textContent = 'Processing...';

      try{
        const res = await fetchWithTimeout(RBW.ajaxUrl, { method:'POST', credentials:'same-origin', body: fd });
        const json = await parseResponse(res);
        if(!json.success){
          submitBtn.disabled = false;
          submitBtn.textContent = getPayMode() === 'full' ? 'Confirm & Pay Full' : 'Confirm & Pay Advance';
          showAlert('err', json.data?.message || 'Error');
          return;
        }
        const checkoutUrl = json?.data?.checkout_url;
        if (!checkoutUrl) {
          submitBtn.disabled = false;
          submitBtn.textContent = getPayMode() === 'full' ? 'Confirm & Pay Full' : 'Confirm & Pay Advance';
          showAlert('err', 'Checkout URL missing. Please try again.');
          return;
        }
        window.location.href = checkoutUrl;
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
      const availableRooms = rooms.filter(r => (Number(r.units_left) || 0) > 0);
      if (!availableRooms.length) {
        listEl.innerHTML = '';
        showAlert('err', `Sorry, we are not available from ${displayIn} to ${displayOut}.`);
        return;
      }
      listEl.innerHTML = `
        <div class="rbw-room-chips" data-rbw-chips></div>
      ` + rooms.map((r, idx) => `
        <div class="rbw-room ${idx === 0 ? 'active' : ''} ${r.units_left <= 0 ? 'rbw-room-disabled' : ''}" data-room="${r.room_id}">
          <div class="rbw-room-top">
            <h4>${r.room_name}</h4>
            ${r.units_left > 0 ? `<span class="rbw-badge">Available: ${r.units_left}</span>` : `<span class="rbw-badge rbw-badge-full">Fully Booked</span>`}
          </div>
          <div class="rbw-meta">
            <div>Per night: <b>${Number(r.price_per_night).toLocaleString()}</b></div>
            <div>Nights: <b>${Number(r.nights).toLocaleString()}</b></div>
            <div>Capacity: <b>${Number(r.capacity || 1).toLocaleString()}</b></div>
            <div>Booking Type: <b>${r.booking_type === 'entire_room' ? 'Entire Room' : 'Per Person'}</b></div>
            <div data-rooms-status>Need 1 room(s)</div>
            <div>Total: <b data-total>${Number(r.total).toLocaleString()}</b></div>
            <div data-discount-row hidden>Discount: <b data-discount>0</b></div>
            <div>Pay Now: <b data-pay-now>${Number(r.deposit).toLocaleString()}</b></div>
            <div>Balance: <b data-balance>${Number(r.balance).toLocaleString()}</b></div>
            <div>Rooms Needed: <b data-rooms-needed>1</b></div>
            <div>Dates: <b>${displayIn} -> ${displayOut}</b></div>
          </div>
        </div>
      `).join('');

      const chipsEl = listEl.querySelector('[data-rbw-chips]');
      if (chipsEl) {
        chipsEl.innerHTML = rooms.map(r => `
          <button type="button" class="rbw-chip ${r.units_left <= 0 ? 'disabled' : ''}" data-chip="${r.room_id}" ${r.units_left <= 0 ? 'disabled' : ''}>${r.room_name}</button>
        `).join('');
      }

      updateVisibleCards = () => {
        const noneSelected = selectedRoomIds.size === 0;
        listEl.querySelectorAll('.rbw-room[data-room]').forEach(card => {
          const id = card.getAttribute('data-room');
          if (noneSelected) {
            card.classList.remove('rbw-room-hidden');
            return;
          }
          const shouldHide = !showAllRooms && !selectedRoomIds.has(String(id));
          card.classList.toggle('rbw-room-hidden', shouldHide);
        });
      };

      if (chipsEl) {
        chipsEl.querySelectorAll('[data-chip]').forEach(chip => {
          chip.addEventListener('click', () => {
            if (chip.classList.contains('disabled')) {
              showAlert('err', 'Already booked.');
              return;
            }
            const roomId = chip.getAttribute('data-chip');
            if (!roomId) return;
            if (isMultiSelectMode()) {
              if (selectedRoomIds.has(String(roomId))) {
                selectedRoomIds.delete(String(roomId));
                chip.classList.remove('active');
              } else {
                selectedRoomIds.add(String(roomId));
                chip.classList.add('active');
              }
            } else {
              selectedRoomIds = new Set([String(roomId)]);
              chipsEl.querySelectorAll('[data-chip]').forEach(c => c.classList.remove('active'));
              chip.classList.add('active');
            }
            updateVisibleCards();
            openBookingForm();
          });
        });
      }

      listEl.querySelectorAll('.rbw-room[data-room]').forEach(card => {
        card.addEventListener('click', () => {
          clearAlert();
          if (card.classList.contains('rbw-room-disabled')) {
            showAlert('err', 'Already booked.');
            return;
          }
          const roomId = card.getAttribute('data-room');
          const match = rooms.find(x => String(x.room_id) === String(roomId)) || null;
          if (!match) {
            showAlert('err','Room not found.');
            return;
          }
          if (isMultiSelectMode()) {
            if (selectedRoomIds.has(String(roomId))) {
              selectedRoomIds.delete(String(roomId));
              card.classList.remove('active');
            } else {
              selectedRoomIds.add(String(roomId));
              card.classList.add('active');
            }
          } else {
            selectedRoomIds = new Set([String(roomId)]);
            listEl.querySelectorAll('.rbw-room[data-room]').forEach(el => el.classList.remove('active'));
            card.classList.add('active');
          }
          if (chipsEl) {
            chipsEl.querySelectorAll('[data-chip]').forEach(chip => {
              const id = chip.getAttribute('data-chip');
              chip.classList.toggle('active', selectedRoomIds.has(String(id)));
            });
          }
          updateVisibleCards();
          openBookingForm();
        });
      });

      // Auto-select first room
      const firstAvailable = rooms.find(r => (Number(r.units_left) || 0) > 0);
      if (firstAvailable) {
        selectedRoomIds = new Set([String(firstAvailable.room_id)]);
        const firstCard = listEl.querySelector(`.rbw-room[data-room="${firstAvailable.room_id}"]`);
        if (firstCard) firstCard.classList.add('active');
        if (chipsEl) {
          const firstChip = chipsEl.querySelector(`[data-chip="${firstAvailable.room_id}"]`);
          if (firstChip) firstChip.classList.add('active');
        }
        updateVisibleCards();
      }
      bookingForm.style.display = 'block';
      updatePricingForGuests();
    };

    searchBtn.addEventListener('click', async () => {
      clearAlert();
      listEl.innerHTML = '';
      bookingForm.style.display = 'none';
      bookingRoom = null;
      selectedRoomIds = new Set();
      showAllRooms = false;

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
      const groupFilter = widget.getAttribute('data-rbw-group');
      if(groupFilter) fd.append('group', groupFilter);

      try{
        const res = await fetchWithTimeout(RBW.ajaxUrl, { method:'POST', credentials:'same-origin', body: fd });
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
