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
      let text;
      try{
        return await res.json();
      }catch(e){
        try{
          text = await res.text();
        }catch(_){}
        throw new Error(text || 'Invalid response');
      }
    };

    const openBtn   = q('[data-rbw-open]');
    const backdrop  = q('[data-rbw-backdrop]');
    const closeBtn  = q('[data-rbw-close]');
    const inEl      = q('[data-rbw-in]');
    const outEl     = q('[data-rbw-out]');
    const searchBtn = q('[data-rbw-search]');
    const alertEl   = q('[data-rbw-alert]');
    const listEl    = q('[data-rbw-list]');

    if (!openBtn || !backdrop || !searchBtn || !alertEl || !listEl) return;

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
      <h4>বুকিং তথ্য দিন</h4>
      <div class="rbw-form-grid">
        <label>আপনার নাম*<input type="text" name="rbw_name" required></label>
        <label>মোবাইল নম্বর*<input type="number" inputmode="numeric" pattern="[0-9]*" name="rbw_phone" required></label>
        <label>অতিথি সংখ্যা*<input type="number" min="1" value="1" name="rbw_guests" required></label>
        <label>NID / ID (ঐচ্ছিক, ছবি তুলুন)<input type="file" accept="image/*" capture="environment" name="rbw_nid"></label>
      </div>
      <div class="rbw-form-actions">
        <button type="button" class="rbw-cancel">Cancel</button>
        <button type="button" class="rbw-submit">Confirm & Pay Deposit</button>
      </div>
    `;
    const modalBody = q('.rbw-body');
    if (modalBody) modalBody.appendChild(bookingForm);
    bookingForm.style.display = 'none';

    let bookingRoom = null;

    const openBookingForm = (room) => {
      bookingRoom = room;
      bookingForm.style.display = 'block';
      bookingForm.scrollIntoView({behavior:'smooth', block:'center'});
    };

    bookingForm.querySelector('.rbw-cancel').addEventListener('click', () => {
      bookingForm.style.display = 'none';
      bookingRoom = null;
    });

    bookingForm.querySelector('.rbw-submit').addEventListener('click', async () => {
      if(!bookingRoom){
        showAlert('err','কোনো রুম সিলেক্ট করা হয়নি');
        return;
      }
      const name   = bookingForm.querySelector('[name="rbw_name"]').value.trim();
      const phone  = bookingForm.querySelector('[name="rbw_phone"]').value.trim();
      const guests = bookingForm.querySelector('[name="rbw_guests"]').value;
      const nidFile= bookingForm.querySelector('[name="rbw_nid"]').files[0];
      if(!name || !phone || !guests){
        showAlert('err','সব বাধ্যতামূলক তথ্য পূরণ করুন');
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
      fd.append('total', bookingRoom.total);
      fd.append('deposit', bookingRoom.deposit);
      fd.append('balance', bookingRoom.balance);
      fd.append('price_per_night', bookingRoom.price_per_night);
      fd.append('customer_name', name);
      fd.append('customer_phone', phone);
      fd.append('guests', guests);
      if (nidFile) fd.append('nid', nidFile);

      const submitBtn = bookingForm.querySelector('.rbw-submit');
      submitBtn.disabled = true;
      submitBtn.textContent = 'Processing...';

      try{
        const res = await fetch(RBW.ajaxUrl, { method:'POST', credentials:'same-origin', body: fd });
        const json = await parseResponse(res);
        if(!json.success){
          submitBtn.disabled = false;
          submitBtn.textContent = 'Confirm & Pay Deposit';
          showAlert('err', json.data?.message || 'Error');
          return;
        }
        window.location.href = json.data.checkout_url;
      }catch(e){
        submitBtn.disabled = false;
        submitBtn.textContent = 'Confirm & Pay Deposit';
        showAlert('err','Network error');
      }
    });

    const renderRooms = (rooms, checkIn, checkOut) => {
      const displayIn = formatDisplay(checkIn);
      const displayOut = formatDisplay(checkOut);
      listEl.innerHTML = rooms.map(r => `
        <div class="rbw-room">
          <div class="rbw-room-top">
            <h4>${r.room_name}</h4>
            <span class="rbw-badge">ফাঁকা: ${r.units_left}</span>
          </div>
          <div class="rbw-meta">
            <div>প্রতি রাত: <b>${Number(r.price_per_night).toLocaleString()}</b></div>
            <div>নাইট: <b>${Number(r.nights).toLocaleString()}</b></div>
            <div>মোট: <b>${Number(r.total).toLocaleString()}</b></div>
            <div>ডিপোজিট: <b>${Number(r.deposit).toLocaleString()}</b></div>
            <div>বাকি: <b>${Number(r.balance).toLocaleString()}</b></div>
            <div>তারিখ: <b>${displayIn} → ${displayOut}</b></div>
          </div>
          <button class="rbw-pay" data-room="${r.room_id}">Next</button>
        </div>
      `).join('');

      listEl.querySelectorAll('button[data-room]').forEach(btn => {
        btn.addEventListener('click', () => {
          clearAlert();
          const roomId = btn.getAttribute('data-room');
          const match = rooms.find(x => String(x.room_id) === String(roomId)) || null;
          if (!match) {
            showAlert('err','রুম খুঁজে পাওয়া যায়নি');
            return;
          }
          openBookingForm(match);
        });
      });
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

      if(!checkInISO || !checkOutISO){ showAlert('err','তারিখ সিলেক্ট করুন'); return; }
      if(nights(checkInRaw, checkOutRaw) <= 0){ showAlert('err','Check-out অবশ্যই check-in এর পরে হবে'); return; }

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
          showAlert('err', 'এই তারিখে কোনো রুম ফাঁকা নেই');
          return;
        }

        renderRooms(rooms, checkInISO, checkOutISO);
        showAlert('ok', 'ফাঁকা রুম পাওয়া গেছে, রুম সিলেক্ট করে তথ্য পূরণ করুন');
      }catch(e){
        showAlert('err','Network error');
      }
    });
  });
})();
