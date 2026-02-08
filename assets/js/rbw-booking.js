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

  const SINGLE_ADVANCE = 1000;
  const MULTI_MIN_ADVANCE_RATE = 0.5;

  widgets.forEach(widget => {
    const q = sel => widget.querySelector(sel);

    const formatMoney = (value) => {
      const n = Number(value) || 0;
      const code = RBW?.currencyCode || '';
      if (code && typeof Intl !== 'undefined' && Intl.NumberFormat) {
        try{
          return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: code,
            maximumFractionDigits: 0
          }).format(n);
        }catch(_){}
      }
      const sym = RBW?.currencySymbol || '';
      return `${sym}${n.toLocaleString()}`;
    };

    const setTextWithHighlight = (el, nextValue) => {
      if (!el) return;
      const next = String(nextValue ?? '');
      if (el.textContent === next) return;
      el.textContent = next;
      el.classList.remove('rbw-change-flash');
      // Restart animation each time value changes
      void el.offsetWidth;
      el.classList.add('rbw-change-flash');
    };

    const parseResponse = async (res) => {
      let text = '';
      try{
        text = await res.text();
      }catch(_){}
      const cleaned = (text || '')
        .replace(/^\uFEFF/, '') // strip BOM if present
        .trim();
      if (cleaned === '') {
        const err = new Error('Empty response from server. Please check PHP errors or admin-ajax.php output.');
        err.details = text;
        throw err;
      }
      if (cleaned === '0') {
        const err = new Error('Server returned 0. This usually means the AJAX action is missing, a PHP error occurred, or the request was blocked.');
        err.details = text;
        throw err;
      }
      if (/^\s*</.test(cleaned)) {
        const err = new Error('Unexpected HTML response. This usually indicates a PHP error or login redirect. Check server logs.');
        err.details = text;
        throw err;
      }
      let json;
      try{
        json = cleaned ? JSON.parse(cleaned) : null;
      }catch(e){
        const err = new Error(cleaned || `HTTP ${res.status}`);
        err.details = text;
        throw err;
      }
      if (!json || typeof json !== 'object') {
        const err = new Error('Unexpected response format. Please check server logs.');
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
    const stepsEl = q('[data-rbw-steps]');
    const setStep = (step) => {
      if (stepsEl) stepsEl.setAttribute('data-step', String(step));
    };

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
      if (calState.checkIn) inEl.classList.remove('rbw-input-error');
      if (calState.checkOut) outEl.classList.remove('rbw-input-error');
    };

    const setCalendarVisible = (visible) => {
      if (!calEl) return;
      calEl.style.display = visible ? 'block' : 'none';
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
      setCalendarVisible(true);
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

    const scrollToPoint = (el) => {
      if (!el) return;
      const target = el.closest('label') || el;
      target.scrollIntoView({ behavior: 'smooth', block: 'center' });
      if (typeof el.focus === 'function') {
        try { el.focus({ preventScroll: true }); } catch (_) {}
      }
    };

    const open = () => {
      backdrop.style.display = 'block';
      backdrop.setAttribute('aria-hidden','false');
      setStep(1);
      clearAlert();
      listEl.innerHTML = '';
      bookingForm.style.display = 'none';
      bookingRoom = null;
      currentRooms = [];
      selectedRoomIds = new Set();
      showAllRooms = false;
      selectedGuestType = 'single';
      listGuestsInput = null;
      guestsInput.value = 1;
      clearBookingFormErrors();
      clearDateErrors();
      applyGuestTypeToGuests(selectedGuestType);
      setCalendarVisible(true);
    };

    const close = () => {
      backdrop.style.display = 'none';
      backdrop.setAttribute('aria-hidden','true');
      setStep(1);
    };

    const clearDateErrors = () => {
      inEl.classList.remove('rbw-input-error');
      outEl.classList.remove('rbw-input-error');
    };

    openBtn.addEventListener('click', open);
    if (closeBtn) closeBtn.addEventListener('click', close);
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
    inEl.addEventListener('click', () => { clearDateErrors(); setCalendarVisible(true); });
    outEl.addEventListener('click', () => { clearDateErrors(); setCalendarVisible(true); });

    // Booking form (embedded inside modal)
    const bookingForm = document.createElement('div');
    bookingForm.className = 'rbw-form';
    bookingForm.innerHTML = `
      <h4>Enter Booking Details</h4>
      <div class="rbw-form-grid">
        <label>Your Name*<input type="text" name="rbw_name" required></label>
        <label>Mobile Number*<input type="number" inputmode="numeric" pattern="[0-9]*" name="rbw_phone" required></label>
        <input type="hidden" min="1" value="1" name="rbw_guests" required>
        <label>NID, Driving License, Card*<input type="file" accept="image/*" capture="environment" name="rbw_nid" required></label>
      </div>
      <div class="rbw-paymode">
        <div class="rbw-paymode-title">Payment</div>
        <div class="rbw-paymode-rule">Advance rule: 1 room = pay 1000 now. 2+ rooms = pay at least 50% now.</div>
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
      <div class="rbw-summary" data-rbw-summary>
        <div class="rbw-summary-head">
          <div class="rbw-summary-title">
            <span class="rbw-ico">🧾</span>
            Booking Payment Summary
          </div>
          <div class="rbw-summary-meta">
            <span><span class="rbw-ico">🏠</span> <span data-sum-rooms>0 rooms</span></span>
            <span>•</span>
            <span><span class="rbw-ico">👥</span> <span data-sum-guests>0 guests</span></span>
            <span>•</span>
            <span><span class="rbw-ico">👤</span> <span data-sum-guest-type>Single</span></span>
            <span>•</span>
            <span><span class="rbw-ico">🌙</span> <span data-sum-nights>0 nights</span></span>
          </div>
        </div>

        <div class="rbw-summary-card">
          <div class="rbw-summary-section"><span class="rbw-ico">🧾</span> Charges</div>
          <div class="rbw-summary-row">
            <span>Total Amount</span>
            <b data-sum-total>0</b>
          </div>
          <div class="rbw-summary-row" data-sum-discount-row>
            <span>Discount Applied</span>
            <b data-sum-discount>0</b>
          </div>
        </div>

        <div class="rbw-summary-card">
          <div class="rbw-summary-section"><span class="rbw-ico">💳</span> Payment Details</div>
          <div class="rbw-summary-row">
            <span>Advance Paid (Pay Now)</span>
            <b data-sum-pay>0</b>
          </div>
          <div class="rbw-summary-note" data-sum-note>Select a room to see your total.</div>
        </div>

        <div class="rbw-summary-card rbw-summary-remaining">
          <div class="rbw-summary-section"><span class="rbw-ico">✅</span> Remaining Amount</div>
          <div class="rbw-summary-row">
            <span>Balance Payable at Check-in</span>
            <b data-sum-balance>0</b>
          </div>
        </div>
      </div>
      <div class="rbw-form-actions">
        <button type="button" class="rbw-cancel">Cancel</button>
        <button type="button" class="rbw-submit">Confirm & Pay Advance</button>
      </div>
    `;
    const modalBody = q('.rbw-body');
    if (modalBody) modalBody.appendChild(bookingForm);
    bookingForm.style.display = 'none';

    const nameInput = bookingForm.querySelector('[name="rbw_name"]');
    const phoneInput = bookingForm.querySelector('[name="rbw_phone"]');
    const guestsInput = bookingForm.querySelector('[name="rbw_guests"]');
    const nidInput = bookingForm.querySelector('[name="rbw_nid"]');
    const payModeInputs = bookingForm.querySelectorAll('[name="rbw_pay_mode"]');

    let bookingRoom = null;
    let selectedRoomIds = new Set();
    let showAllRooms = false;
    let currentRooms = [];
    let selectedGuestType = 'single';
    let listGuestsInput = null;
    let updateVisibleCards = () => {};
    let updateSelectedRoomsDisplay = () => {};

    const clearFieldError = (el) => {
      if (!el) return;
      el.classList.remove('rbw-input-error');
      const lbl = el.closest('label');
      if (lbl) lbl.classList.remove('rbw-field-error');
    };

    const setFieldError = (el, message) => {
      if (el) {
        el.classList.add('rbw-input-error');
        const lbl = el.closest('label');
        if (lbl) lbl.classList.add('rbw-field-error');
      }
      showAlert('err', message);
      scrollToPoint(el || bookingForm);
      return false;
    };

    const clearBookingFormErrors = () => {
      [nameInput, phoneInput, nidInput].forEach(clearFieldError);
    };

    const getGuests = () => {
      const v = parseInt(guestsInput.value, 10);
      return Number.isFinite(v) && v >= 0 ? v : 0;
    };

    const getGuestType = () => selectedGuestType;

    const setGuestType = (type) => {
      if (!['single','couple','group'].includes(type)) return;
      selectedGuestType = type;
    };

    const mirrorGuestInputState = () => {
      if (!listGuestsInput) return;
      listGuestsInput.value = guestsInput.value;
      listGuestsInput.min = guestsInput.min || '1';
      listGuestsInput.step = guestsInput.step || '1';
      if (guestsInput.max) {
        listGuestsInput.max = guestsInput.max;
      } else {
        listGuestsInput.removeAttribute('max');
      }
    };

    const applyGuestTypeToGuests = (guestType, normalize = true) => {
      guestsInput.min = 0;
      guestsInput.step = guestType === 'couple' ? 2 : 1;
      guestsInput.removeAttribute('max');
      if (normalize && guestType === 'couple') {
        let g = getGuests();
        if (g < 2) g = 2;
        if (g % 2 !== 0) g += 1;
        guestsInput.value = g;
      }
      mirrorGuestInputState();
    };

    const getGuestTypeAvailability = (guests) => {
      const g = Number(guests) || 0;
      return {
        single: g >= 1,
        couple: g >= 2 && (g % 2 === 0),
        group: g >= 3
      };
    };

    const pickFallbackGuestType = (prevType, availability) => {
      const orderByType = {
        single: ['couple', 'group', 'single'],
        couple: ['group', 'single', 'couple'],
        group: ['couple', 'single', 'group']
      };
      const order = orderByType[prevType] || ['single', 'couple', 'group'];
      for (let i = 0; i < order.length; i++) {
        const type = order[i];
        if (availability[type]) return type;
      }
      return 'single';
    };

    const syncGuestTypeOptions = (resetOnChange = false) => {
      const g = getGuests();
      const availability = getGuestTypeAvailability(g);
      const radios = listEl.querySelectorAll('input[name="rbw_guest_type"]');
      radios.forEach(input => {
        const type = input.value;
        const enabled = !!availability[type];
        input.disabled = !enabled;
        const card = input.closest('.rbw-guest-type-card');
        if (card) card.classList.toggle('disabled', !enabled);
      });

      if (!availability[selectedGuestType]) {
        const fallback = pickFallbackGuestType(selectedGuestType, availability);
        const prev = selectedGuestType;
        selectedGuestType = fallback;
        radios.forEach(input => {
          input.checked = (input.value === selectedGuestType);
        });
        if (resetOnChange && prev !== selectedGuestType && selectedRoomIds.size > 0) {
          resetSelectedRooms();
          clearAlert();
          showAlert('ok', 'Guest type changed by guest count. Please select room(s) again.');
        }
      }
      return availability;
    };

    const resetSelectedRooms = () => {
      selectedRoomIds = new Set();
      bookingRoom = null;
      showAllRooms = false;
      listEl.classList.remove('rbw-need-more');
      listEl.querySelectorAll('.rbw-room[data-room]').forEach(card => card.classList.remove('active'));
      listEl.querySelectorAll('[data-chip]').forEach(chip => chip.classList.remove('active'));
      bookingForm.style.display = 'none';
      updateSelectedRoomsDisplay();
    };


    const getPayMode = () => {
      const checked = bookingForm.querySelector('[name="rbw_pay_mode"]:checked');
      return checked ? checked.value : 'deposit';
    };

    const capacityForGuestType = (guestType) => {
      if (guestType === 'single') return 1;
      if (guestType === 'couple') return 2;
      return 4;
    };

    const roomsNeeded = (guests, guestType) => {
      const g = Number(guests) || 0;
      if (g <= 0) return 0;
      const cap = capacityForGuestType(guestType);
      return Math.max(1, Math.ceil(g / cap));
    };

    const priceForGuestType = (room, guestType) => {
      const single = Number(room.price_single) || 0;
      const couple = Number(room.price_couple) || 0;
      const group = Number(room.price_group) || 0;
      if (guestType === 'single') return single;
      if (guestType === 'couple') return couple > 0 ? couple : single;
      if (guestType === 'group') return group > 0 ? group : single;
      return single;
    };

    const computeTotals = (room, guests, payMode, guestType) => {
      const ppn = priceForGuestType(room, guestType);
      const n = Number(room.nights) || 0;
      const needed = roomsNeeded(guests, guestType);
      const total = guestType === 'group' ? (ppn * n * guests) : (ppn * n * needed);
      const discount = payMode === 'full' ? total * 0.05 : 0;
      const depositSetting = Number(room.deposit) || 0;
      const depositTotal = depositSetting * needed;
      const payNow = payMode === 'full' ? Math.max(0, total - discount) : depositTotal;
      const balance = payMode === 'full' ? 0 : Math.max(0, total - payNow);
      return { total, discount, payNow, balance, rooms_needed: needed, capacity: capacityForGuestType(guestType), deposit_total: depositTotal, booking_type: 'package' };
    };

    const applyAdvancePolicy = (total, payNow, roomsCount, payMode) => {
      if (payMode === 'full') return { payNow, balance: 0 };
      let adjusted = payNow;
      if (roomsCount > 1) {
        adjusted = Math.max(payNow, total * MULTI_MIN_ADVANCE_RATE);
      } else if (roomsCount === 1) {
        adjusted = Math.min(total, SINGLE_ADVANCE);
      }
      const balance = Math.max(0, total - adjusted);
      return { payNow: adjusted, balance };
    };

    const allocateGuests = (rooms, guests, guestType) => {
      const count = rooms.length;
      if (count === 0) return { allocations: [], remaining: guests };
      if (guests < count) return { allocations: [], remaining: guests - count };

      const cap = capacityForGuestType(guestType);
      const capacities = rooms.map(() => cap);
      const base = Math.floor(guests / count);
      let extra = guests % count;

      let allocations = rooms.map((room, idx) => {
        let assigned = base + (extra > 0 ? 1 : 0);
        if (extra > 0) extra -= 1;
        if (assigned > capacities[idx]) {
          assigned = capacities[idx];
        }
        return { room, guests: assigned };
      });

      let assignedTotal = allocations.reduce((s, a) => s + a.guests, 0);
      let remaining = guests - assignedTotal;

      if (remaining > 0) {
        let guard = 0;
        while (remaining > 0 && guard < 1000) {
          let progressed = false;
          for (let i = 0; i < allocations.length && remaining > 0; i++) {
            const cap = capacities[i];
            if (allocations[i].guests < cap) {
              allocations[i].guests += 1;
              remaining -= 1;
              progressed = true;
            }
          }
          if (!progressed) break;
          guard++;
        }
      }

      return { allocations, remaining };
    };

    const totalSelectedCapacity = (rooms, guestType) => {
      const cap = capacityForGuestType(guestType);
      return rooms.length * cap;
    };

    const isMultiSelectMode = () => showAllRooms || listEl.classList.contains('rbw-need-more');

    const updatePricingForGuests = () => {
      const guests = getGuests();
      const availability = syncGuestTypeOptions(false);
      const guestType = getGuestType();
      applyGuestTypeToGuests(guestType, false);
      const payMode = getPayMode();
      const submitBtn = bookingForm.querySelector('.rbw-submit');
      const sumTotalEl = bookingForm.querySelector('[data-sum-total]');
      const sumPayEl = bookingForm.querySelector('[data-sum-pay]');
      const sumBalEl = bookingForm.querySelector('[data-sum-balance]');
      const sumDiscEl = bookingForm.querySelector('[data-sum-discount]');
      const sumDiscRow = bookingForm.querySelector('[data-sum-discount-row]');
      const sumNoteEl = bookingForm.querySelector('[data-sum-note]');
      const sumGuestTypeEl = bookingForm.querySelector('[data-sum-guest-type]');
      listEl.querySelectorAll('.rbw-guest-type-card').forEach(card => {
        const type = card.getAttribute('data-guest-type');
        card.classList.toggle('active', type === guestType && !card.classList.contains('disabled'));
      });
      const detectEl = listEl.querySelector('[data-rbw-rooms-detect]');
      if (detectEl) {
        if (guests <= 0) {
          setTextWithHighlight(detectEl, 'Enter at least 1 guest to enable booking options.');
        } else {
          const needed = roomsNeeded(guests, getGuestType());
          setTextWithHighlight(detectEl, `System detected: ${needed} room${needed === 1 ? '' : 's'} needed for ${guests} guest${guests === 1 ? '' : 's'}.`);
        }
      }
      if (sumGuestTypeEl) {
        sumGuestTypeEl.textContent = guests <= 0
          ? 'N/A'
          : (guestType === 'single'
          ? 'Single'
          : (guestType === 'couple' ? 'Couple' : 'Group'));
      }
      if (submitBtn) {
        submitBtn.textContent = payMode === 'full' ? 'Confirm & Pay Full' : 'Confirm & Pay Advance';
        if (!availability.single && !availability.couple && !availability.group) {
          submitBtn.disabled = true;
        }
      }
      if (guests <= 0 && selectedRoomIds.size > 0) {
        resetSelectedRooms();
      }
      const selectedRooms = currentRooms.filter(r => selectedRoomIds.has(String(r.room_id)));
      updateSelectedRoomsDisplay();
      if (selectedRooms.length) {
        const capSum = totalSelectedCapacity(selectedRooms, getGuestType());
        if (guests > capSum) showAllRooms = true;
      } else {
        const firstAvailable = currentRooms.find(r => (Number(r.units_left) || 0) > 0);
        const firstCap = capacityForGuestType(getGuestType());
        if (guests > Math.max(1, firstCap)) showAllRooms = true;
      }
      const { allocations, remaining } = allocateGuests(selectedRooms, guests, getGuestType());
      const multiMode = isMultiSelectMode() || selectedRooms.length > 1;
      const allocMap = {};
      allocations.forEach(a => { allocMap[String(a.room.room_id)] = a.guests; });

      listEl.querySelectorAll('.rbw-room[data-room]').forEach(card => {
        const roomId = card.getAttribute('data-room');
        const room = currentRooms.find(x => String(x.room_id) === String(roomId));
        if (!room) return;
        let totals;
        if (!multiMode) {
          totals = computeTotals(room, guests, payMode, getGuestType());
          if (payMode !== 'full') {
            const adj = applyAdvancePolicy(totals.total, totals.payNow, 1, payMode);
            totals.payNow = adj.payNow;
            totals.balance = adj.balance;
          }
        } else {
          const assigned = allocMap[String(roomId)] || 0;
          const previewGuests = assigned > 0 ? assigned : Math.min(guests, capacityForGuestType(getGuestType()));
          totals = computeTotals(room, previewGuests, payMode, getGuestType());
        }
        const totalEl = card.querySelector('[data-total]');
        const ppnEl = card.querySelector('[data-ppn]');
        const payNowEl = card.querySelector('[data-pay-now]');
        const balanceEl = card.querySelector('[data-balance]');
        const discountEl = card.querySelector('[data-discount]');
        const discountRow = card.querySelector('[data-discount-row]');
        if (ppnEl) {
          setTextWithHighlight(ppnEl, Number(priceForGuestType(room, getGuestType())).toLocaleString());
        }
        const roomsNeededEl = card.querySelector('[data-rooms-needed]');
        const statusEl = card.querySelector('[data-rooms-status]');
        if (totalEl) setTextWithHighlight(totalEl, Number(totals.total).toLocaleString());
        if (payNowEl) setTextWithHighlight(payNowEl, Number(totals.payNow).toLocaleString());
        if (balanceEl) setTextWithHighlight(balanceEl, Number(totals.balance).toLocaleString());
        if (discountEl) setTextWithHighlight(discountEl, Number(totals.discount).toLocaleString());
        if (discountRow) discountRow.hidden = totals.discount <= 0;
        if (roomsNeededEl) {
          if (!multiMode) {
            setTextWithHighlight(roomsNeededEl, Number(totals.rooms_needed).toLocaleString());
          } else {
            const assigned = allocMap[String(roomId)] || 0;
            setTextWithHighlight(roomsNeededEl, assigned > 0 ? String(assigned) : '-');
          }
        }
        const unitsLeft = Number(room.units_left) || 0;
        const ok = multiMode ? (unitsLeft > 0) : (unitsLeft >= totals.rooms_needed);
        card.classList.toggle('rbw-room-disabled', !ok);
        if (statusEl) {
          if (!multiMode) {
            setTextWithHighlight(statusEl, ok ? `You need ${totals.rooms_needed} room(s)` : `Not enough rooms (need ${totals.rooms_needed})`);
          } else {
            const assigned = allocMap[String(roomId)] || 0;
            setTextWithHighlight(statusEl, assigned > 0 ? `Assigned ${assigned} guest(s)` : 'Select to add rooms.');
          }
        }
      });

      const updatePayCards = (depTotals, fullTotals) => {
        if (!depTotals || !fullTotals) return;
        const advPay = bookingForm.querySelector('[data-adv-pay]');
        const advBal = bookingForm.querySelector('[data-adv-balance]');
        const fullPay = bookingForm.querySelector('[data-full-pay]');
        const fullSave = bookingForm.querySelector('[data-full-save]');
        if (advPay) setTextWithHighlight(advPay, formatMoney(depTotals.payNow));
        if (advBal) setTextWithHighlight(advBal, formatMoney(depTotals.balance));
        if (fullPay) setTextWithHighlight(fullPay, formatMoney(fullTotals.payNow));
        if (fullSave) setTextWithHighlight(fullSave, formatMoney(fullTotals.discount));
        bookingForm.querySelectorAll('.rbw-paycard').forEach(card => {
          const mode = card.getAttribute('data-pay-card');
          card.classList.toggle('active', mode === payMode);
        });
      };

      const resetPayCards = () => {
        const advPay = bookingForm.querySelector('[data-adv-pay]');
        const advBal = bookingForm.querySelector('[data-adv-balance]');
        const fullPay = bookingForm.querySelector('[data-full-pay]');
        const fullSave = bookingForm.querySelector('[data-full-save]');
        if (advPay) advPay.textContent = formatMoney(0);
        if (advBal) advBal.textContent = formatMoney(0);
        if (fullPay) fullPay.textContent = formatMoney(0);
        if (fullSave) fullSave.textContent = formatMoney(0);
      };

      const updateSummary = (total, payNow, balance, discount, roomsCount, mode, guestsCount, nightsCount) => {
        const sumRoomsEl = bookingForm.querySelector('[data-sum-rooms]');
        const sumGuestsEl = bookingForm.querySelector('[data-sum-guests]');
        const sumNightsEl = bookingForm.querySelector('[data-sum-nights]');
        if (sumRoomsEl) setTextWithHighlight(sumRoomsEl, `${Number(roomsCount || 0)} room${Number(roomsCount || 0) === 1 ? '' : 's'}`);
        if (sumGuestsEl) setTextWithHighlight(sumGuestsEl, `${Number(guestsCount || 0)} guest${Number(guestsCount || 0) === 1 ? '' : 's'}`);
        if (sumNightsEl) setTextWithHighlight(sumNightsEl, `${Number(nightsCount || 0)} night${Number(nightsCount || 0) === 1 ? '' : 's'}`);
        if (sumTotalEl) setTextWithHighlight(sumTotalEl, formatMoney(total || 0));
        if (sumPayEl) setTextWithHighlight(sumPayEl, formatMoney(payNow || 0));
        if (sumBalEl) setTextWithHighlight(sumBalEl, formatMoney(balance || 0));
        if (sumDiscEl) setTextWithHighlight(sumDiscEl, formatMoney(discount || 0));
        if (sumDiscRow) sumDiscRow.hidden = !(Number(discount) > 0);
        if (sumNoteEl) {
          if (!roomsCount) {
            sumNoteEl.textContent = 'Select a room to see your total.';
          } else if (mode === 'full') {
            sumNoteEl.textContent = 'Full payment selected (5% discount applied).';
          } else if (roomsCount > 1) {
            sumNoteEl.textContent = 'Advance payment selected (min 50% for multiple rooms).';
          } else {
            sumNoteEl.textContent = 'Advance payment selected (1000 for single room).';
          }
        }
      };

      if (selectedRooms.length) {
        if (remaining !== 0) {
          if (submitBtn) submitBtn.disabled = true;
          showAllRooms = true;
          listEl.classList.add('rbw-need-more');
          if (remaining < 0) {
            showAlert('err', 'You selected more rooms than guests. Reduce rooms or increase guests.');
          } else {
            showAlert('err', `You need to choose more rooms for ${remaining} more guest(s).`);
          }
          setCalendarVisible(false);
          resetPayCards();
          updateSummary(0, 0, 0, 0, 0, payMode, guests, currentRooms[0]?.nights || 0);
          updateVisibleCards();
          return;
        }

        if (submitBtn) submitBtn.disabled = false;
        let totalDep = 0;
        let discountDep = 0;
        let payNowDep = 0;
        let balanceDep = 0;
        let totalFull = 0;
        let discountFull = 0;
        let payNowFull = 0;
        let balanceFull = 0;
        const roomsPayload = [];
        allocations.forEach(({ room, guests: g }) => {
          if (g <= 0) return;
          const totalsDep = computeTotals(room, g, 'deposit', guestType);
          const totalsFull = computeTotals(room, g, 'full', guestType);
          totalDep += totalsDep.total;
          discountDep += totalsDep.discount;
          payNowDep += totalsDep.payNow;
          balanceDep += totalsDep.balance;
          totalFull += totalsFull.total;
          discountFull += totalsFull.discount;
          payNowFull += totalsFull.payNow;
          balanceFull += totalsFull.balance;
          roomsPayload.push({
            room_id: room.room_id,
            room_name: room.room_name,
            guests: g,
            capacity: capacityForGuestType(guestType),
            booking_type: 'package'
          });
        });

        const depAdjusted = applyAdvancePolicy(totalDep, payNowDep, selectedRooms.length, 'deposit');
        payNowDep = depAdjusted.payNow;
        balanceDep = depAdjusted.balance;

       
        showAllRooms = false;
        listEl.classList.remove('rbw-need-more');
        showAlert('ok', `You need ${selectedRooms.length} room(s) for ${guests} guest(s).`);
        setCalendarVisible(selectedRooms.length <= 1);
        bookingRoom = {
          rooms: roomsPayload,
          total: payMode === 'full' ? totalFull : totalDep,
          discount: payMode === 'full' ? discountFull : discountDep,
          deposit: payMode === 'full' ? payNowFull : payNowDep,
          balance: payMode === 'full' ? balanceFull : balanceDep,
          pay_mode: payMode,
          guest_type: guestType
        };
        updatePayCards(
          { payNow: payNowDep, balance: balanceDep },
          { payNow: payNowFull, discount: discountFull }
        );
        updateSummary(
          bookingRoom.total,
          bookingRoom.deposit,
          bookingRoom.balance,
          bookingRoom.discount,
          selectedRooms.length,
          payMode,
          guests,
          currentRooms[0]?.nights || 0
        );
        updateVisibleCards();
      } else {
        showAllRooms = false;
        listEl.classList.remove('rbw-need-more');
        const submitBtn = bookingForm.querySelector('.rbw-submit');
        if (submitBtn) submitBtn.disabled = true;
        setCalendarVisible(true);
        updateVisibleCards();
        resetPayCards();
        updateSummary(0, 0, 0, 0, 0, payMode, guests, currentRooms[0]?.nights || 0);
      }
    };

    const openBookingForm = () => {
      bookingForm.style.display = 'block';
      applyGuestTypeToGuests(getGuestType());
      setStep(3);
      updatePricingForGuests();
    };

    const validateBeforeSubmit = () => {
      clearBookingFormErrors();

      if(!bookingRoom || !Array.isArray(bookingRoom.rooms) || !bookingRoom.rooms.length){
        showAlert('err', 'Please select room(s) first.');
        const selectedWrap = listEl.querySelector('[data-rbw-selected-rooms]') || listEl;
        scrollToPoint(selectedWrap);
        return false;
      }

      const name = (nameInput?.value || '').trim();
      if (!name) return setFieldError(nameInput, 'Please enter your name.');

      const phone = (phoneInput?.value || '').trim();
      const phoneDigits = phone.replace(/\D/g, '');
      if (!phone || phoneDigits.length < 6) {
        return setFieldError(phoneInput, 'Please enter a valid mobile number.');
      }

      const guests = getGuests();
      if (!Number.isFinite(guests) || guests <= 0) {
        showAlert('err', 'Please enter guest number first.');
        const guestBox = listEl.querySelector('[data-rbw-guests]');
        if (guestBox) guestBox.classList.add('rbw-input-error');
        scrollToPoint(guestBox || guestsInput);
        return false;
      }

      const nidFile = nidInput?.files?.[0];
      if (!nidFile) return setFieldError(nidInput, 'Please upload NID, Driving License, or Card.');

      return true;
    };

    bookingForm.querySelector('.rbw-cancel').addEventListener('click', () => {
      bookingForm.style.display = 'none';
      bookingRoom = null;
    });
    if (nameInput) nameInput.addEventListener('input', () => clearFieldError(nameInput));
    if (phoneInput) phoneInput.addEventListener('input', () => clearFieldError(phoneInput));
    if (nidInput) nidInput.addEventListener('change', () => clearFieldError(nidInput));
    guestsInput.addEventListener('input', () => {
      applyGuestTypeToGuests(getGuestType(), false);
      syncGuestTypeOptions(true);
      updatePricingForGuests();
    });
    payModeInputs.forEach(r => r.addEventListener('change', updatePricingForGuests));

    bookingForm.querySelector('.rbw-submit').addEventListener('click', async () => {
      if (!validateBeforeSubmit()) return;
      const name   = (nameInput?.value || '').trim();
      const phone  = (phoneInput?.value || '').trim();
      const guests = getGuests();
      const payMode = getPayMode();
      const guestType = getGuestType();
      const nidFile= nidInput?.files?.[0];

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
      fd.append('guest_type', guestType);
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

    const escAttr = (value) => String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');

    const renderRoomMedia = (room) => {
      const imgs = Array.isArray(room?.images) && room.images.length
        ? room.images.filter(Boolean)
        : (room?.image ? [room.image] : []);

      if (!imgs.length) {
        return `<div class="rbw-room-placeholder">No image</div>`;
      }
      if (imgs.length === 1) {
        return `<img src="${escAttr(imgs[0])}" alt="${escAttr(room?.room_name || 'Room')}">`;
      }

      return `
        <div class="rbw-room-gallery" data-rbw-gallery>
          <div class="rbw-room-gallery-track">
            ${imgs.map((src, idx) => `
              <div class="rbw-room-slide ${idx === 0 ? 'active' : ''}" data-rbw-slide="${idx}">
                <img src="${escAttr(src)}" alt="${escAttr(room?.room_name || 'Room')}">
              </div>
            `).join('')}
          </div>
          <button type="button" class="rbw-gallery-nav prev" data-rbw-prev aria-label="Previous image">&#10094;</button>
          <button type="button" class="rbw-gallery-nav next" data-rbw-next aria-label="Next image">&#10095;</button>
          <div class="rbw-gallery-dots">
            ${imgs.map((_, idx) => `<button type="button" class="rbw-gallery-dot ${idx === 0 ? 'active' : ''}" data-rbw-dot="${idx}" aria-label="Image ${idx + 1}"></button>`).join('')}
          </div>
        </div>
      `;
    };

    const initRoomGalleries = () => {
      listEl.querySelectorAll('[data-rbw-gallery]').forEach(gallery => {
        const track = gallery.querySelector('.rbw-room-gallery-track');
        const slides = Array.from(gallery.querySelectorAll('[data-rbw-slide]'));
        const dots = Array.from(gallery.querySelectorAll('[data-rbw-dot]'));
        if (!track || slides.length <= 1) return;

        let active = 0;
        let touchStartX = 0;
        let startTranslateX = 0;
        let dragging = false;

        const galleryWidth = () => Math.max(1, gallery.clientWidth || 1);
        const setTrackX = (x, withTransition = true) => {
          track.style.transition = withTransition ? 'transform .42s cubic-bezier(.22,.61,.36,1)' : 'none';
          track.style.transform = `translate3d(${x}px, 0, 0)`;
        };
        const setActive = (idx, withTransition = true) => {
          const next = (idx + slides.length) % slides.length;
          active = next;
          slides.forEach((slide, pos) => slide.classList.toggle('active', pos === next));
          dots.forEach((dot, pos) => dot.classList.toggle('active', pos === next));
          setTrackX(-(next * galleryWidth()), withTransition);
        };
        setActive(0, false);

        const prevBtn = gallery.querySelector('[data-rbw-prev]');
        const nextBtn = gallery.querySelector('[data-rbw-next]');
        if (prevBtn) {
          prevBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            setActive(active - 1);
          });
        }
        if (nextBtn) {
          nextBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            setActive(active + 1);
          });
        }
        dots.forEach((dot, pos) => {
          dot.addEventListener('click', (e) => {
            e.stopPropagation();
            setActive(pos);
          });
        });

        gallery.addEventListener('touchstart', (e) => {
          if (!(e.touches && e.touches[0])) return;
          touchStartX = e.touches[0].clientX;
          startTranslateX = -(active * galleryWidth());
          dragging = true;
          setTrackX(startTranslateX, false);
        }, { passive: true });
        gallery.addEventListener('touchmove', (e) => {
          if (!dragging || !(e.touches && e.touches[0])) return;
          const delta = e.touches[0].clientX - touchStartX;
          const maxDrag = galleryWidth() * 0.35;
          const clamped = Math.max(-maxDrag, Math.min(maxDrag, delta));
          setTrackX(startTranslateX + clamped, false);
        }, { passive: true });
        gallery.addEventListener('touchend', (e) => {
          if (!dragging) return;
          dragging = false;
          const endX = e.changedTouches && e.changedTouches[0] ? e.changedTouches[0].clientX : touchStartX;
          const delta = endX - touchStartX;
          const threshold = Math.max(28, galleryWidth() * 0.14);
          if (Math.abs(delta) < threshold) {
            setActive(active);
            return;
          }
          if (delta > 0) setActive(active - 1);
          else setActive(active + 1);
        }, { passive: true });
        gallery.addEventListener('touchcancel', () => {
          if (!dragging) return;
          dragging = false;
          setActive(active);
        }, { passive: true });
      });
    };

    const renderRooms = (rooms, checkIn, checkOut) => {
      const displayIn = formatDisplay(checkIn);
      const displayOut = formatDisplay(checkOut);
      const sortedRooms = rooms.slice().sort((a, b) => {
        const aAvail = (Number(a.units_left) || 0) > 0 ? 0 : 1;
        const bAvail = (Number(b.units_left) || 0) > 0 ? 0 : 1;
        return aAvail - bAvail;
      });
      currentRooms = sortedRooms.slice();
      const availableRooms = sortedRooms.filter(r => (Number(r.units_left) || 0) > 0);
      if (!availableRooms.length) {
        showAlert('err', `Sorry, we are not available from ${displayIn} to ${displayOut}.`);
      }
      const guestSetupHtml = `
        <div class="rbw-guest-type rbw-guest-type-inline" data-rbw-guest-type>
          <div class="rbw-guest-setup-top">
            <div class="rbw-guest-step-copy">
              <div class="rbw-guest-type-title">Step 1: Enter Guest Number</div>
              <div class="rbw-guest-step-sub">Tell us total guests first. System will suggest room count.</div>
            </div>
            <label class="rbw-guest-count">
              <span>Total Guests</span>
              <div class="rbw-guest-input-wrap">
                <button type="button" class="rbw-guest-adjust" data-rbw-guest-adjust="-1" aria-label="Decrease guest count">-</button>
                <input type="number" min="0" step="1" value="${Number(getGuests() || 0)}" data-rbw-guests required>
                <button type="button" class="rbw-guest-adjust" data-rbw-guest-adjust="1" aria-label="Increase guest count">+</button>
              </div>
            </label>
          </div>
          <div class="rbw-guest-type-title">Step 2: Select Guest Type</div>
          <div class="rbw-guest-type-options">
            <label class="rbw-guest-type-card ${selectedGuestType === 'single' ? 'active' : ''}" data-guest-type="single">
              <input type="radio" name="rbw_guest_type" value="single" ${selectedGuestType === 'single' ? 'checked' : ''}>
              <div>
                <div class="rbw-guest-type-name">Single</div>
                <div class="rbw-guest-type-sub">1 guest per room</div>
              </div>
            </label>
            <label class="rbw-guest-type-card ${selectedGuestType === 'couple' ? 'active' : ''}" data-guest-type="couple">
              <input type="radio" name="rbw_guest_type" value="couple" ${selectedGuestType === 'couple' ? 'checked' : ''}>
              <div>
                <div class="rbw-guest-type-name">Couple</div>
                <div class="rbw-guest-type-sub">2 guests per room</div>
              </div>
            </label>
            <label class="rbw-guest-type-card ${selectedGuestType === 'group' ? 'active' : ''}" data-guest-type="group">
              <input type="radio" name="rbw_guest_type" value="group" ${selectedGuestType === 'group' ? 'checked' : ''}>
              <div>
                <div class="rbw-guest-type-name">Group</div>
                <div class="rbw-guest-type-sub">3-4 guests per room (price per person)</div>
              </div>
            </label>
          </div>
          <div class="rbw-guest-type-note" data-rbw-rooms-detect>Enter guest number to detect required rooms. Couple requires even guests.</div>
        </div>
      `;
      listEl.innerHTML = `
        ${guestSetupHtml}
        <div class="rbw-selected-rooms" data-rbw-selected-rooms>Selected rooms: None</div>
        <div class="rbw-room-chips" data-rbw-chips></div>
        <div class="rbw-room-toggle">
          <button type="button" class="rbw-show-all" data-rbw-show-all>Show all rooms</button>
        </div>
      ` + sortedRooms.map((r, idx) => `
        <div class="rbw-room ${idx === 0 ? 'active' : ''} ${r.units_left <= 0 ? 'rbw-room-disabled rbw-room-booked' : ''}" data-room="${r.room_id}">
          <div class="rbw-room-media">
            ${renderRoomMedia(r)}
          </div>
          <div class="rbw-room-content">
            <div class="rbw-room-top">
              <h4>${r.room_name}</h4>
              ${r.units_left > 0 ? `<span class="rbw-badge rbw-badge-available">Available: ${r.units_left}</span>` : `<span class="rbw-badge rbw-badge-full">Fully Booked</span>`}
              <span class="rbw-selected-badge">Selected</span>
            </div>
            <div class="rbw-meta">
              <div>Per night: <b data-ppn>${Number(r.price_per_night).toLocaleString()}</b></div>
              <div>Nights: <b>${Number(r.nights).toLocaleString()}</b></div>
              <div data-rooms-status>Need 1 room(s)</div>
              <div>Total: <b class="rbw-amt rbw-amt-total" data-total>${Number(r.total).toLocaleString()}</b></div>
              <div data-discount-row hidden>Discount: <b class="rbw-amt rbw-amt-discount" data-discount>0</b></div>
              <div>Pay Now: <b class="rbw-amt rbw-amt-pay" data-pay-now>${Number(r.deposit).toLocaleString()}</b></div>
              <div>Balance: <b class="rbw-amt rbw-amt-balance" data-balance>${Number(r.balance).toLocaleString()}</b></div>
              <div>Rooms Needed: <b data-rooms-needed>1</b></div>
              <div>Dates: <b>${displayIn} -> ${displayOut}</b></div>
            </div>
          </div>
        </div>
      `).join('');
      initRoomGalleries();

      const chipsEl = listEl.querySelector('[data-rbw-chips]');
      listGuestsInput = listEl.querySelector('[data-rbw-guests]');
      if (listGuestsInput) {
        listGuestsInput.addEventListener('input', () => {
          listGuestsInput.classList.remove('rbw-input-error');
          guestsInput.value = listGuestsInput.value !== '' ? listGuestsInput.value : '0';
          applyGuestTypeToGuests(getGuestType(), false);
          syncGuestTypeOptions(true);
          updatePricingForGuests();
        });
      }
      const guestAdjustButtons = listEl.querySelectorAll('[data-rbw-guest-adjust]');
      if (guestAdjustButtons.length && listGuestsInput) {
        guestAdjustButtons.forEach((btn) => {
          btn.addEventListener('click', () => {
            const delta = parseInt(btn.getAttribute('data-rbw-guest-adjust') || '0', 10);
            if (!Number.isFinite(delta) || delta === 0) return;
            const current = parseInt(listGuestsInput.value || '0', 10);
            const min = parseInt(listGuestsInput.min || '0', 10);
            const step = Math.max(1, parseInt(listGuestsInput.step || '1', 10));
            const next = Math.max(min, (Number.isFinite(current) ? current : 0) + (delta * step));
            listGuestsInput.value = String(next);
            listGuestsInput.dispatchEvent(new Event('input', { bubbles: true }));
          });
        });
      }
      const guestTypeWrap = listEl.querySelector('[data-rbw-guest-type]');
      if (guestTypeWrap) {
        guestTypeWrap.querySelectorAll('input[name="rbw_guest_type"]').forEach(input => {
          input.addEventListener('change', () => {
            if (input.disabled) return;
            const prev = getGuestType();
            setGuestType(input.value);
            if (prev !== getGuestType()) {
              resetSelectedRooms();
              clearAlert();
              showAlert('ok', 'Guest type changed. Please select room(s) again.');
            }
            applyGuestTypeToGuests(getGuestType(), true);
            syncGuestTypeOptions(false);
            updateVisibleCards();
            updatePricingForGuests();
          });
        });
      }
      if (chipsEl) {
        chipsEl.innerHTML = sortedRooms.map(r => `
          <button type="button" class="rbw-chip ${r.units_left <= 0 ? 'disabled' : ''}" data-chip="${r.room_id}" ${r.units_left <= 0 ? 'disabled' : ''}>${r.room_name}</button>
        `).join('');
      }

      showAllRooms = false;
      updateSelectedRoomsDisplay = () => {
        const selectedWrap = listEl.querySelector('[data-rbw-selected-rooms]');
        if (!selectedWrap) return;
        const selected = currentRooms.filter(r => selectedRoomIds.has(String(r.room_id)));
        if (!selected.length) {
          selectedWrap.textContent = 'Selected rooms: None';
          return;
        }
        const names = selected.map(r => r.room_name).filter(Boolean);
        selectedWrap.textContent = `Selected rooms (${selected.length}): ${names.join(', ')}`;
      };
      updateVisibleCards = () => {
        const rooms = listEl.querySelectorAll('.rbw-room[data-room]');
        const selectedIds = new Set(Array.from(selectedRoomIds).map(String));
        rooms.forEach((card, idx) => {
          const roomId = String(card.getAttribute('data-room') || '');
          if (showAllRooms) {
            card.classList.remove('rbw-room-hidden');
          } else if (selectedIds.size > 0) {
            card.classList.toggle('rbw-room-hidden', !selectedIds.has(roomId));
          } else {
            card.classList.toggle('rbw-room-hidden', idx !== 0);
          }
        });
        const toggleBtn = listEl.querySelector('[data-rbw-show-all]');
        if (toggleBtn) {
          toggleBtn.textContent = showAllRooms ? 'Show less' : 'Show all rooms';
        }
        updateSelectedRoomsDisplay();
      };

      const toggleBtn = listEl.querySelector('[data-rbw-show-all]');
      if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
          showAllRooms = !showAllRooms;
          updateVisibleCards();
        });
      }

      if (chipsEl) {
        chipsEl.querySelectorAll('[data-chip]').forEach(chip => {
          chip.addEventListener('click', () => {
            if (chip.classList.contains('disabled')) {
              showAlert('err', 'Already booked.');
              return;
            }
            if (getGuests() <= 0) {
              showAlert('err', 'Enter guest number first.');
              const guestBox = listEl.querySelector('[data-rbw-guests]');
              if (guestBox) guestBox.classList.add('rbw-input-error');
              scrollToPoint(guestBox || guestsInput);
              return;
            }
            const roomId = chip.getAttribute('data-chip');
            if (!roomId) return;
            const guests = getGuests();
            const needRooms = roomsNeeded(guests, getGuestType());
            const selectedRooms = currentRooms.filter(r => selectedRoomIds.has(String(r.room_id)));
            if (isMultiSelectMode()) {
              if (selectedRoomIds.has(String(roomId))) {
                selectedRoomIds.delete(String(roomId));
                chip.classList.remove('active');
              } else {
                if (selectedRooms.length >= needRooms) {
                  showAlert('err', `You only need ${needRooms} room(s) for this guest setup.`);
                  return;
                }
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
          if (getGuests() <= 0) {
            showAlert('err', 'Enter guest number first.');
            const guestBox = listEl.querySelector('[data-rbw-guests]');
            if (guestBox) guestBox.classList.add('rbw-input-error');
            scrollToPoint(guestBox || guestsInput);
            return;
          }
          const roomId = card.getAttribute('data-room');
            const match = sortedRooms.find(x => String(x.room_id) === String(roomId)) || null;
            if (!match) {
              showAlert('err','Room not found.');
              return;
            }
            const guests = getGuests();
            const needRooms = roomsNeeded(guests, getGuestType());
            const selectedRooms = currentRooms.filter(r => selectedRoomIds.has(String(r.room_id)));
            if (isMultiSelectMode()) {
              if (selectedRoomIds.has(String(roomId))) {
                selectedRoomIds.delete(String(roomId));
                card.classList.remove('active');
              } else {
                if (selectedRooms.length >= needRooms) {
                  showAlert('err', `You only need ${needRooms} room(s) for this guest setup.`);
                  return;
                }
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
      const firstAvailable = sortedRooms.find(r => (Number(r.units_left) || 0) > 0);
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

      if(!checkInISO || !checkOutISO){
        showAlert('err','Please select check-in and check-out dates.');
        if (!checkInISO) inEl.classList.add('rbw-input-error');
        if (!checkOutISO) outEl.classList.add('rbw-input-error');
        scrollToPoint(!checkInISO ? inEl : outEl);
        return;
      }
      if(checkInISO < today || checkOutISO < today){
        showAlert('err','Please select today or a future date.');
        if (checkInISO < today) inEl.classList.add('rbw-input-error');
        if (checkOutISO < today) outEl.classList.add('rbw-input-error');
        scrollToPoint(checkInISO < today ? inEl : outEl);
        return;
      }
      if(nights(checkInRaw, checkOutRaw) <= 0){
        showAlert('err','Check-out must be after check-in.');
        outEl.classList.add('rbw-input-error');
        scrollToPoint(outEl);
        return;
      }

      const fd = new FormData();
      fd.append('action', 'rbw_get_availability');
      fd.append('nonce', RBW.nonce);
      fd.append('check_in', checkInISO);
      fd.append('check_out', checkOutISO);
      const groupFilter = widget.getAttribute('data-rbw-group');
      const roomFilter = widget.getAttribute('data-rbw-room');
      if(roomFilter) {
        fd.append('room', roomFilter);
      } else if(groupFilter) {
        fd.append('group', groupFilter);
      }

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
        setStep(2);
        showAlert('ok', 'Rooms available. Select a room and complete the form.');
      }catch(e){
        handleError(e, 'Availability');
      }
    });
  });
})();
