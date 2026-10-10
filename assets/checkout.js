const ConfigEndpoint = '/plugins/flex/econt/config';
const LOCATORS = {
  test: 'https://staging.officelocator.econt.com/',
  live: 'https://officelocator.econt.com/',
};

let locatorConfig = null;
let configPromise = null;

function isTrustedOfficeMessage(event, frame) {
  const allowed = [
    'https://officelocator.econt.com',
    'https://staging.officelocator.econt.com',
    'https://agreeable-forest-09fdc1003.1.azurestaticapps.net',
    'https://salmon-bay-01cca7d03.1.azurestaticapps.net',
  ];
  return event.source === frame.contentWindow && allowed.includes(event.origin);
}

function loadConfig() {
  if (locatorConfig) return Promise.resolve(locatorConfig);
  if (configPromise) return configPromise;
  configPromise = fetch(ConfigEndpoint, {
    headers: {Accept: 'application/json'},
    credentials: 'same-origin',
    cache: 'no-store',
  })
    .then(async (response) => {
      const body = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(body.error?.message || 'Конфигурацията на Еконт не е достъпна.');
      locatorConfig = {
        mode: body.mode === 'live' ? 'live' : 'test',
        locator_url: body.locator_url || LOCATORS[body.mode === 'live' ? 'live' : 'test'],
        enabled: Boolean(body.enabled),
      };
      return locatorConfig;
    })
    .catch((error) => {
      configPromise = null;
      throw error;
    });
  return configPromise;
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (char) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char]));
}

function readCheckoutCity() {
  const city = document.querySelector('#checkout-city, input[name="city"]');
  return city && 'value' in city ? String(city.value || '').trim() : '';
}

function findOfficeInput(root) {
  return root.querySelector('#checkout-office_code, input[name="office_code"]')
    || root.parentElement?.querySelector('#checkout-office_code, input[name="office_code"]')
    || null;
}

function syncOfficeError(root) {
  const message = String(root.getAttribute('data-office-error') || '').trim();
  const button = root.querySelector('[data-econt-office-open]');
  // Visible copy lives in commerce checkout (sibling of the mount); keep the trigger marked.
  if (!message) {
    if (button) {
      button.removeAttribute('aria-invalid');
      button.removeAttribute('aria-describedby');
    }
    root.classList.remove('is-invalid');
    return;
  }
  if (button) {
    button.setAttribute('aria-invalid', 'true');
    button.setAttribute('aria-describedby', 'checkout-office_code-error');
  }
  root.classList.add('is-invalid');
}

function applyOffice(root, office) {
  const code = String(office.code || office.office_code || '').trim();
  if (!code) return;
  const name = String(office.name || '').trim();
  const address = office.address?.fullAddress
    || [office.address?.street, office.address?.streetNumber].filter(Boolean).join(' ')
    || String(office.address || '');
  const city = office.address?.city?.name || office.city || '';
  const postcode = office.address?.city?.postCode || office.postcode || '';

  const input = findOfficeInput(root);
  if (input) {
    input.value = code;
    input.dispatchEvent(new Event('input', {bubbles: true}));
    input.dispatchEvent(new Event('change', {bubbles: true}));
  }

  const summary = root.querySelector('[data-econt-office-summary]');
  if (summary) {
    summary.hidden = false;
    summary.innerHTML = `<strong>${escapeHtml(name || 'Офис ' + code)}</strong>`
      + `<span>${escapeHtml([city, address, 'код ' + code].filter(Boolean).join(' · '))}</span>`;
  }

  const button = root.querySelector('[data-econt-office-open]');
  if (button) button.textContent = 'Смени офиса';

  document.dispatchEvent(new CustomEvent('commerce:shipping-meta', {
    detail: {
      shipping: 'econt_office',
      shipping_meta: {
        office_code: code,
        office_name: name,
        office_address: address,
        city,
        postcode,
      },
    },
  }));
}

function closeModal(modal) {
  modal.remove();
  document.documentElement.classList.remove('econt-locator-open');
}

function openLocatorModal(root) {
  const existing = document.querySelector('.econt-locator-modal');
  if (existing) existing.remove();

  const city = readCheckoutCity();
  const params = new URLSearchParams({
    lang: 'bg',
    officeType: 'office',
    shopUrl: window.location.origin,
  });
  if (city) params.set('city', city);

  const modal = document.createElement('div');
  modal.className = 'econt-locator-modal';
  modal.setAttribute('role', 'dialog');
  modal.setAttribute('aria-modal', 'true');
  modal.setAttribute('aria-label', 'Избор на офис на Еконт');
  modal.innerHTML = `
    <div class="econt-locator-backdrop" data-econt-close></div>
    <div class="econt-locator-panel">
      <header class="econt-locator-header">
        <h2>Избери офис на Еконт</h2>
        <button type="button" class="econt-locator-close" data-econt-close aria-label="Затвори">×</button>
      </header>
      <div class="econt-locator-body">
        <p class="econt-locator-loading" data-econt-loading>Зареждане на картата…</p>
        <iframe title="Econt Office Locator" allow="geolocation" class="econt-locator-frame" hidden></iframe>
      </div>
    </div>
  `;
  document.body.append(modal);
  document.documentElement.classList.add('econt-locator-open');

  const frame = modal.querySelector('iframe');
  const loading = modal.querySelector('[data-econt-loading]');
  const closeButtons = modal.querySelectorAll('[data-econt-close]');

  function onClose() {
    window.removeEventListener('message', onMessage);
    document.removeEventListener('keydown', onKey);
    closeModal(modal);
  }

  function onKey(event) {
    if (event.key === 'Escape') onClose();
  }

  function onMessage(event) {
    const office = event.data?.office;
    if (!office || typeof office !== 'object') return;
    if (!isTrustedOfficeMessage(event, frame)) {
      return;
    }
    if (!office.code && !office.id) return;
    applyOffice(root, office);
    onClose();
  }

  closeButtons.forEach((button) => button.addEventListener('click', onClose));
  window.addEventListener('message', onMessage);
  document.addEventListener('keydown', onKey);

  loadConfig()
    .then((config) => {
      const base = (config.locator_url || LOCATORS[config.mode] || LOCATORS.test).replace(/\?$/, '');
      frame.src = base + (base.includes('?') ? '&' : '?') + params.toString();
      frame.hidden = false;
      if (loading) loading.hidden = true;
    })
    .catch((error) => {
      if (loading) {
        loading.textContent = error instanceof Error ? error.message : 'Картата не можа да бъде заредена.';
        loading.classList.add('is-error');
      }
    });
}

function enhanceOfficeMount(root) {
  if (!root) return;
  const method = root.getAttribute('data-shipping-method') || '';
  if (method !== 'econt_office') return;

  // React re-renders clear foreign DOM inside the mount; rebuild when the picker is gone.
  if (root.querySelector('[data-econt-office-open]')) {
    syncOfficeError(root);
    return;
  }

  const input = findOfficeInput(root);
  if (input && input.type !== 'hidden') input.type = 'hidden';

  const ui = document.createElement('div');
  ui.className = 'econt-office-ui';
  ui.innerHTML = `
    <div class="econt-office-summary" data-econt-office-summary hidden></div>
    <button type="button" class="flex-btn flex-btn--primary econt-office-open commerce-cart-primary" data-econt-office-open>
      Избери офис на Еконт
    </button>
    <p class="econt-office-hint">Отваря картата на Еконт, за да изберете офис или автомат.</p>
  `;
  root.replaceChildren(ui);

  const metaRaw = root.getAttribute('data-shipping-meta');
  try {
    const meta = metaRaw ? JSON.parse(metaRaw) : null;
    if (meta?.office_code) {
      applyOffice(root, {
        code: meta.office_code,
        name: meta.office_name || '',
        address: meta.office_address || '',
        city: meta.city || '',
        postcode: meta.postcode || '',
      });
    }
  } catch {
    /* ignore invalid meta */
  }

  syncOfficeError(root);
  ui.querySelector('[data-econt-office-open]')?.addEventListener('click', () => openLocatorModal(root));
}

function scan() {
  document.querySelectorAll('[data-commerce-shipping-mount]').forEach((root) => enhanceOfficeMount(root));
}

scan();
document.addEventListener('flex:page-ready', scan);
const observer = new MutationObserver(() => scan());
observer.observe(document.documentElement, {childList: true, subtree: true});
