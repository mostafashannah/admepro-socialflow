/* admepro.com: all site behaviour, no dependencies (~8 KB). Every feature is progressive:
   pages work without JS, this only adds motion and convenience. */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const lang = document.documentElement.lang || 'en';
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

  // header shadow on scroll
  const header = $('[data-header]');
  const onScroll = () => header && header.classList.toggle('scrolled', scrollY > (document.body.classList.contains('page-home') ? innerHeight * 0.8 : 24));
  addEventListener('scroll', onScroll, { passive: true }); onScroll();

  // full-screen menu (same as the SocialFlow careers page)
  const burger = $('[data-burger]'); const menu = $('[data-menu]');
  if (burger && menu) {
    let closing;
    const set = (open) => {
      burger.setAttribute('aria-expanded', open); document.body.classList.toggle('menu-open', open); clearTimeout(closing);
      if (open) { menu.hidden = false; void menu.offsetWidth; menu.classList.add('open'); menu.setAttribute('tabindex', '-1'); menu.focus({ preventScroll: true }); }
      else { menu.classList.remove('open'); closing = setTimeout(() => { menu.hidden = true; }, reduced ? 0 : 520); burger.focus(); }
    };
    burger.addEventListener('click', () => set(true));
    $('[data-menu-close]', menu).addEventListener('click', () => set(false));
    addEventListener('keydown', (e) => { if (e.key === 'Escape' && menu.classList.contains('open')) set(false); });
    $$('.flip', menu).forEach((a) => a.addEventListener('touchstart', () => {}, { passive: true })); // iOS :active
  }

  // dark / light switch on the right rail. Follows the device setting until the visitor picks one.
  const root = document.documentElement;
  const paint = () => $$('[data-theme-set]').forEach((b) => b.setAttribute('aria-pressed', b.dataset.themeSet === root.dataset.theme));
  $$('[data-theme-set]').forEach((b) => b.addEventListener('click', () => {
    root.dataset.theme = b.dataset.themeSet; try { localStorage.setItem('theme', b.dataset.themeSet); } catch (e) {}
    paint();
  }));
  try {
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
      if (!localStorage.getItem('theme')) { root.dataset.theme = e.matches ? 'dark' : 'light'; paint(); }
    });
  } catch (e) {}
  paint();

  // reveal + count-up when visible
  const count = (el) => {
    const to = +el.dataset.count; if (!to || reduced) return;
    const t0 = performance.now(), d = 1400;
    const fmt = (n) => n.toLocaleString(lang === 'ar' ? 'ar-EG' : 'en-US');
    const step = (t) => { const p = Math.min(1, (t - t0) / d); el.textContent = fmt(Math.round(to * (1 - Math.pow(1 - p, 3)))); if (p < 1) requestAnimationFrame(step); };
    requestAnimationFrame(step);
  };
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => entries.forEach((e) => {
      if (!e.isIntersecting) return;
      e.target.classList.add('in');
      $$('[data-count]', e.target).forEach(count);
      if (e.target.matches('[data-count]')) count(e.target);
      io.unobserve(e.target);
    }), { rootMargin: '0px 0px -8% 0px' });
    $$('.reveal').forEach((el) => io.observe(el));
    $$('[data-count]').forEach((el) => { if (!el.closest('.reveal')) io.observe(el); });
  } else $$('.reveal').forEach((el) => el.classList.add('in'));

  // progress bars (Client Satisfaction, conversion rate): fill from 0 to the target when seen
  $$('.bar').forEach((bar) => {
    const track = $('.bar-track', bar); const fill = $('i', track); const tag = $('b', track); if (!track || !fill) return;
    const target = parseFloat(fill.style.getPropertyValue('--v')) || 100;
    const fmt = (n) => Math.round(n).toLocaleString(lang === 'ar' ? 'ar-EG' : 'en-US') + '%';
    const set = (v) => { track.style.setProperty('--cur', v); if (tag) tag.textContent = fmt(v); };
    set(0);
    const run = () => {
      if (reduced) return set(target);
      const t0 = performance.now(), d = 2000;
      const step = (t) => { const p = Math.min(1, (t - t0) / d); set(target * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(step); };
      requestAnimationFrame(step);
    };
    if ('IntersectionObserver' in window) { const o = new IntersectionObserver((e) => { if (e[0].isIntersecting) { o.disconnect(); run(); } }, { threshold: 0.6 }); o.observe(bar); } else set(target);
  });

  // testimonial sliders
  $$('[data-slider]').forEach((s) => {
    const slides = $$('.tslide', s); let i = 0; const cur = $('[data-current]', s);
    const go = (n) => { slides[i].hidden = true; i = (n + slides.length) % slides.length; slides[i].hidden = false; cur.textContent = i + 1; };
    $('[data-next]', s).addEventListener('click', () => go(i + 1));
    $('[data-prev]', s).addEventListener('click', () => go(i - 1));
    if (!reduced) { let timer = setInterval(() => go(i + 1), 7000); s.addEventListener('pointerenter', () => clearInterval(timer)); }
  });

  // Vimeo: load the player only when someone presses play (keeps pages fast)
  const vimeoSrc = (id, h) => `https://player.vimeo.com/video/${encodeURIComponent(id)}?${h ? 'h=' + encodeURIComponent(h) + '&' : ''}autoplay=1&title=0&byline=0&portrait=0&dnt=1`;
  $$('[data-vimeo]').forEach((b) => b.addEventListener('click', () => {
    const f = document.createElement('iframe');
    f.src = vimeoSrc(b.dataset.vimeo, b.dataset.hash); f.allow = 'autoplay; fullscreen; picture-in-picture'; f.allowFullscreen = true; f.title = b.getAttribute('aria-label') || 'Video';
    b.replaceWith(f);
  }));
  $$('[data-vimeo-modal]').forEach((b) => b.addEventListener('click', () => {
    const m = document.createElement('div'); m.className = 'vmodal'; m.setAttribute('role', 'dialog'); m.setAttribute('aria-modal', 'true');
    m.innerHTML = `<iframe src="${vimeoSrc(b.dataset.vimeoModal, b.dataset.hash)}" allow="autoplay; fullscreen" allowfullscreen title="Showcase"></iframe><button type="button" aria-label="Close">✕</button>`;
    const close = () => { m.remove(); b.focus(); removeEventListener('keydown', esc); };
    const esc = (e) => e.key === 'Escape' && close();
    m.addEventListener('click', (e) => { if (e.target === m || e.target.tagName === 'BUTTON') close(); });
    addEventListener('keydown', esc); document.body.append(m); $('button', m).focus();
  }));

  // self-hosted clips: load + play only when on screen
  const vids = $$('video.lazy-video');
  if (vids.length && 'IntersectionObserver' in window) {
    const vo = new IntersectionObserver((entries) => entries.forEach((e) => {
      const v = e.target;
      if (e.isIntersecting) { if (!v.src) v.src = v.dataset.src; if (!reduced) v.play().catch(() => {}); } else v.pause();
    }), { threshold: 0.25 });
    vids.forEach((v) => vo.observe(v));
  }

  // before / after slider
  $$('[data-ba]').forEach((b) => { const r = $('input', b); r.addEventListener('input', () => b.style.setProperty('--pos', r.value + '%')); });

  // portfolio filters (supports ?cat=branding and ?tag=egypt from old WordPress URLs)
  const filters = $('[data-filters]'); const list = $('[data-plist]');
  if (filters && list) {
    const items = $$('li', list); const note = $('[data-filter-note]');
    const apply = (cat, tag) => {
      items.forEach((li) => { li.hidden = !((!cat || cat === 'all' || li.dataset.cats.split(' ').includes(cat)) && (!tag || li.dataset.tags.split(' ').includes(tag))); });
      $$('[data-filter]', filters).forEach((b) => b.setAttribute('aria-pressed', b.dataset.filter === (cat || 'all')));
      if (note) { note.hidden = !tag; if (tag) note.textContent = `#${tag.replace(/-/g, ' ')}`; }
    };
    const q = new URLSearchParams(location.search);
    apply(q.get('cat'), q.get('tag'));
    filters.addEventListener('click', (e) => {
      const b = e.target.closest('[data-filter]'); if (!b) return;
      apply(b.dataset.filter, null);
      const u = new URL(location.href); u.search = b.dataset.filter === 'all' ? '' : '?cat=' + b.dataset.filter; history.replaceState(null, '', u);
    });
    list.addEventListener('pointermove', (e) => { list.style.setProperty('--mx', e.clientX + 'px'); list.style.setProperty('--my', e.clientY + 'px'); });
  }

  // "Chat with us" pill (WhatsApp), dismissible for the session
  const chat = $('[data-chat]');
  if (chat && !sessionStorage.getItem('chatClosed')) {
    // appear once the visitor scrolls past the first screen, so it never covers the hero
    const showChat = () => { if (scrollY > innerHeight * 0.9) { chat.hidden = false; removeEventListener('scroll', showChat); } };
    addEventListener('scroll', showChat, { passive: true });
    $('[data-chat-close]', chat).addEventListener('click', () => { chat.hidden = true; sessionStorage.setItem('chatClosed', '1'); });
  }

  $$('[data-top]').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' }); }));

  // hero title typing animation: "That Builds Brands" → "That Builds Results" (as on the live site)
  const typed = $('.typed');
  if (typed && !reduced) {
    const words = JSON.parse(typed.dataset.words || '[]'); let w = 0, i = words[0].length, del = true;
    const tick = () => {
      const word = words[w];
      if (del) { i--; typed.textContent = word.slice(0, i); if (i <= 0) { del = false; w = (w + 1) % words.length; } setTimeout(tick, 60); }
      else { i++; typed.textContent = words[w].slice(0, i); if (i >= words[w].length) { del = true; setTimeout(tick, 2200); } else setTimeout(tick, 110); }
    };
    setTimeout(tick, 2200);
  }

  // hero background film: loads after the page, loops between start and end seconds
  const hero = $('[data-hero-video]');
  const saveData = navigator.connection && navigator.connection.saveData;
  if (hero && !reduced && !saveData) {
    addEventListener('load', () => setTimeout(() => {
      const { heroVideo: id, hash = '', start = 0, end = 0 } = hero.dataset;
      const f = document.createElement('iframe');
      f.src = `https://player.vimeo.com/video/${encodeURIComponent(id)}?${hash ? 'h=' + encodeURIComponent(hash) + '&' : ''}background=1&autoplay=1&muted=1&loop=1&autopause=0&dnt=1#t=${start}s`;
      f.allow = 'autoplay; fullscreen'; f.title = ''; f.tabIndex = -1; f.setAttribute('aria-hidden', 'true');
      $('.hero-media', hero).append(f);
      const post = (m) => f.contentWindow && f.contentWindow.postMessage(JSON.stringify(m), 'https://player.vimeo.com');
      addEventListener('message', (e) => {
        if (e.origin !== 'https://player.vimeo.com') return;
        let d; try { d = typeof e.data === 'string' ? JSON.parse(e.data) : e.data; } catch { return; }
        if (d.event === 'ready') { post({ method: 'addEventListener', value: 'timeupdate' }); post({ method: 'addEventListener', value: 'play' }); post({ method: 'setCurrentTime', value: +start }); }
        if (d.event === 'play' || (d.event === 'timeupdate' && d.data && d.data.seconds > +start)) hero.classList.add('playing');
        if (d.event === 'timeupdate' && +end && d.data && d.data.seconds >= +end) post({ method: 'setCurrentTime', value: +start });
      });
    }, 300));
  }

  // self-hosted header video (src/content/pages/home.json → hero.video): starts after the page has loaded
  const heroFile = $('[data-hero-file]');
  if (heroFile && !reduced && !saveData) {
    addEventListener('load', () => {
      const v = document.createElement('video');
      Object.assign(v, { muted: true, loop: true, playsInline: true, autoplay: true, src: heroFile.dataset.heroFile });
      v.setAttribute('aria-hidden', 'true'); v.className = 'hero-file';
      v.addEventListener('playing', () => heroFile.classList.add('playing'), { once: true });
      $('.hero-media', heroFile).append(v); v.play().catch(() => {});
    });
  }

  // floating "next project" card: appears after the banner is scrolled past, hides at the footer
  const nextCard = $('[data-next]');
  if (nextCard) {
    const end = $('.cta-band, .cfooter'); let atEnd = false;
    const upd = () => nextCard.classList.toggle('on', scrollY > innerHeight * 0.85);
    addEventListener('scroll', upd, { passive: true }); upd();
    if (end && 'IntersectionObserver' in window) new IntersectionObserver((e) => { atEnd = e.some((x) => x.isIntersecting); nextCard.classList.toggle('off', atEnd); }).observe(end);
  }

  // search (bottom-left button). The index is loaded only when someone opens search.
  const sOpen = $('[data-search-open]'); const sBox = $('[data-search]');
  if (sOpen && sBox) {
    const input = $('.search-input', sBox); const list = $('.search-results', sBox); let data = null;
    const norm = (x) => (x || '').toString().toLowerCase().normalize('NFKD').replace(/[ً-ٰٟ]/g, '').replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي');
    const escH = (x) => x.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const render = () => {
      if (!data) return; const q = norm(input.value.trim());
      if (!q) { list.innerHTML = ''; return; }
      const words = q.split(/\s+/);
      const hits = data.items.map((it) => {
        const hay = norm([it.t, it.d, it.x].join(' ')); const title = norm(it.t);
        if (!words.every((w) => hay.includes(w))) return null;
        return [it, (title.startsWith(q) ? 3 : title.includes(q) ? 2 : 1) + (it.k === 'project' ? .5 : 0)];
      }).filter(Boolean).sort((a, b) => b[1] - a[1]).slice(0, 12);
      list.innerHTML = hits.length ? hits.map(([it]) => `<li><a href="${it.u}">${it.i ? `<img src="${it.i}" alt="" loading="lazy">` : '<span class="noimg"></span>'}<span><em>${escH(data.labels[it.k] || '')}</em><strong>${escH(it.t)}</strong><small>${escH(it.d || '')}</small></span></a></li>`).join('')
        : `<li class="none">${escH(input.dataset.empty)}</li>`;
    };
    const open = async () => {
      sBox.hidden = false; document.body.classList.add('menu-open'); input.focus();
      if (!data) { try { data = await (await fetch(input.dataset.index)).json(); render(); } catch (e) { console.error(e); } }
    };
    const close = () => { sBox.hidden = true; document.body.classList.remove('menu-open'); sOpen.focus(); };
    sOpen.addEventListener('click', open);
    $('[data-search-close]', sBox).addEventListener('click', close);
    input.addEventListener('input', render);
    addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !sBox.hidden) close();
      if (e.key === '/' && sBox.hidden && !/input|textarea|select/i.test(document.activeElement.tagName)) { e.preventDefault(); open(); }
    });
  }

  // ------------------------------------------------------------------ lead forms
  // SocialFlow integration point (see INTEGRATION.md). Today this is a STUB:
  // with no data-endpoint, nothing leaves the browser: the lead is shown in the console
  // and kept in localStorage("admepro_test_leads") so it can be checked during testing.
  const T = {
    en: { ok: 'Thank you! We received your request and will reply within 2 working hours.', test: ' (Test mode: nothing was sent.)', err: 'Something went wrong. Please email hello@admepro.com or message us on WhatsApp.', invalid: 'Please fill in the required fields.', sending: 'Sending…' },
    ar: { ok: 'شكرًا لك! استلمنا طلبك وسنرد خلال ساعتين عمل.', test: ' (وضع تجريبي: لم يتم إرسال أي بيانات.)', err: 'حدث خطأ. يرجى مراسلتنا على hello@admepro.com أو عبر واتساب.', invalid: 'يرجى ملء الحقول المطلوبة.', sending: 'جارٍ الإرسال…' },
  }[lang === 'ar' ? 'ar' : 'en'];
  const utm = (() => {
    const q = new URLSearchParams(location.search); const keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'];
    const now = Object.fromEntries(keys.filter((k) => q.get(k)).map((k) => [k, q.get(k)]));
    if (Object.keys(now).length) sessionStorage.setItem('utm', JSON.stringify(now));
    try { return JSON.parse(sessionStorage.getItem('utm') || '{}'); } catch { return {}; }
  })();

  $$('[data-lead-form]').forEach((form) => form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const status = $('.form-status', form); const btn = $('button[type=submit]', form);
    let bad = false;
    $$('[required]', form).forEach((f) => { const ok = f.type === 'email' ? /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.value.trim()) : f.value.trim() !== ''; f.closest('.field').classList.toggle('invalid', !ok); if (!ok) bad = true; });
    if (bad) { status.className = 'form-status err'; status.textContent = T.invalid; $('.invalid input, .invalid select', form)?.focus(); return; }
    if (form.website.value) return; // honeypot: bots fill hidden fields

    const fd = new FormData(form);
    const get = (k) => (fd.get(k) || '').toString().trim();
    const label = (name) => { const s = form.elements[name]; return s && s.selectedOptions ? s.selectedOptions[0].text : ''; };
    // Shape matches SocialFlow's `leads` table (name, company, phone, email, source, notes, tags)
    const lead = {
      name: get('name'), company: get('company'), email: get('email'), phone: get('phone'),
      source: 'website', status: 'new',
      tags: ['website', `form:${form.dataset.leadForm}`, `lang:${lang}`, get('service') && `service:${get('service')}`].filter(Boolean),
      notes: [
        get('message'),
        get('service') && `Service: ${label('service')}`,
        get('budget') && `Budget (USD): ${label('budget')}`,
        get('bestTime') && `Best time for a call: ${label('bestTime')}`,
        `Page: ${location.pathname}`,
      ].filter(Boolean).join('\n'),
      meta: { form: form.dataset.leadForm, lang, page: location.href, referrer: document.referrer, utm, submittedAt: new Date().toISOString(), service: get('service'), budget: get('budget'), bestTime: get('bestTime') },
    };
    const endpoint = form.dataset.endpoint;
    btn.disabled = true; status.className = 'form-status'; status.textContent = T.sending;
    try {
      if (!endpoint) {
        const file = fd.get('brief'); if (file && file.name) lead.meta.briefFileName = file.name;
        const store = JSON.parse(localStorage.getItem('admepro_test_leads') || '[]'); store.push(lead); localStorage.setItem('admepro_test_leads', JSON.stringify(store.slice(-20)));
        console.info('[admepro] lead captured in TEST MODE (not sent):', lead);
        await new Promise((r) => setTimeout(r, 500));
        status.className = 'form-status ok'; status.textContent = T.ok + T.test;
      } else {
        const body = new FormData(); body.append('lead', JSON.stringify(lead));
        const file = fd.get('brief'); if (file && file.size) body.append('brief', file);
        const res = await fetch(endpoint, { method: 'POST', body, credentials: 'omit' });
        if (!res.ok) throw new Error(res.status);
        status.className = 'form-status ok'; status.textContent = T.ok;
        window.dataLayer && window.dataLayer.push({ event: 'generate_lead', form: form.dataset.leadForm });
      }
      form.reset();
    } catch (err) {
      console.error(err); status.className = 'form-status err'; status.textContent = T.err;
    } finally { btn.disabled = false; }
  }));
})();
