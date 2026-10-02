/* SmartStay ONE — cleaning checklist (port of housekeeping/public/checklist.js).
   3 mandatory photos, compressed in the browser (canvas, max 1920px, JPEG 0.8),
   sent as multipart with the CSRF header. Limits and billing are enforced server-side. */
(function () {
  'use strict';

  const root = document.querySelector('[data-checklist]');
  if (!root) return;
  const form = root.querySelector('[data-checklist-form]');
  const submit = root.querySelector('[data-submit]');
  if (!submit) return; // read-only view

  const apartment = root.dataset.apartment;
  const csrf = root.dataset.csrf;
  const photos = {}; // slot number → Blob

  function toast(m) { window.ONE ? window.ONE.toast(m) : window.alert(m); }

  function compress(file, max, quality) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onerror = reject;
      reader.onload = () => {
        const img = new Image();
        img.onerror = () => reject(new Error('Imaginea nu poate fi citită'));
        img.onload = () => {
          let { width, height } = img;
          const scale = Math.min(1, max / Math.max(width, height));
          width = Math.round(width * scale);
          height = Math.round(height * scale);
          const canvas = document.createElement('canvas');
          canvas.width = width;
          canvas.height = height;
          canvas.getContext('2d').drawImage(img, 0, 0, width, height);
          canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('Compresia a eșuat'))), 'image/jpeg', quality);
        };
        img.src = reader.result;
      };
      reader.readAsDataURL(file);
    });
  }

  function readDataUrl(blob) {
    return new Promise((resolve) => {
      const r = new FileReader();
      r.onload = () => resolve(r.result);
      r.readAsDataURL(blob);
    });
  }

  function syncSubmit() {
    const inputs = root.querySelectorAll('[data-photo]');
    submit.disabled = !Array.from(inputs).every((i) => photos[i.dataset.photo]);
  }

  root.querySelectorAll('[data-photo]').forEach((input) => {
    input.addEventListener('change', async () => {
      const slot = input.closest('[data-photo-slot]');
      const status = slot.querySelector('[data-photo-status]');
      const thumb = slot.querySelector('[data-thumb]');
      const file = input.files && input.files[0];
      delete photos[input.dataset.photo];
      slot.classList.remove('is-done', 'is-error');
      if (!file) { status.textContent = 'Apasă pentru a face poza'; syncSubmit(); return; }
      if (!file.type.startsWith('image/') && file.type !== '') {
        status.textContent = 'Selectează o imagine validă.';
        slot.classList.add('is-error');
        syncSubmit();
        return;
      }
      status.textContent = 'Se procesează imaginea…';
      try {
        const blob = await compress(file, 1920, 0.8);
        if (blob.size > 10 * 1024 * 1024) throw new Error('Imagine prea mare (max. 10 MB)');
        photos[input.dataset.photo] = blob;
        thumb.innerHTML = `<img src="${await readDataUrl(blob)}" alt="">`;
        status.textContent = '✓ Încărcată · ' + Math.round(blob.size / 1024) + ' KB';
        slot.classList.add('is-done');
      } catch (e) {
        status.textContent = (e && e.message) || 'Eroare la procesare. Încearcă din nou.';
        slot.classList.add('is-error');
        input.value = '';
      }
      syncSubmit();
    });
  });

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    if (submit.disabled) return;
    if (!window.confirm('Trimiți acest checklist?')) return;

    const data = new FormData();
    data.append('apartment', apartment);
    const checked = Array.from(form.querySelectorAll('input[name="checked"]:checked')).map((i) => i.value);
    data.append('checked', JSON.stringify(checked));
    const requirements = [];
    root.querySelectorAll('[data-photo]').forEach((input) => {
      const n = input.dataset.photo;
      requirements.push(input.dataset.requirement);
      data.append('photo' + n, photos[n], `photo${n}.jpg`);
    });
    data.append('requirements', JSON.stringify(requirements));

    submit.disabled = true;
    submit.classList.add('is-loading');
    root.querySelector('[data-submit-label]').textContent = 'Se trimite…';

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 90000);

    fetch('/api/housekeeping/checklist', {
      method: 'POST',
      body: data,
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf },
      credentials: 'same-origin',
      cache: 'no-store',
      signal: controller.signal,
    })
      .then((res) => {
        if (res.status === 401) {
          location.href = '/login?expired=1&next=' + encodeURIComponent(location.pathname);
          throw new Error('Sesiune expirată');
        }
        return res.json().then((body) => ({ status: res.status, body }));
      })
      .then(({ status, body }) => {
        clearTimeout(timer);
        if (status === 409) {
          toast(body.error || 'Checklist-ul a fost deja finalizat.');
          setTimeout(() => { location.href = '/housekeeping'; }, 1400);
          return;
        }
        if (!body.ok) throw new Error(body.error || 'Eroare ' + status);
        toast(body.message);
        setTimeout(() => { location.href = '/housekeeping?done=' + encodeURIComponent(apartment); }, 900);
      })
      .catch((e) => {
        clearTimeout(timer);
        toast(e.name === 'AbortError' ? 'Încărcarea durează prea mult. Verifică semnalul și reîncearcă.' : e.message);
        submit.disabled = false;
        submit.classList.remove('is-loading');
        root.querySelector('[data-submit-label]').textContent = 'Trimite checklist';
      });
  });
})();
