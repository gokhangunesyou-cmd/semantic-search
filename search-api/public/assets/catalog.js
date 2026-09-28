'use strict';
const form = document.querySelector('#search-form');
const query = document.querySelector('#query');
const mode = document.querySelector('#mode');
const limit = document.querySelector('#limit');
const products = document.querySelector('#products');
const status = document.querySelector('#status');
const meta = document.querySelector('#result-meta');
const results = document.querySelector('.results');
const responseButton = document.querySelector('#response-button');
const dataDialog = document.querySelector('#data-dialog');
const dataTitle = document.querySelector('#data-title');
const dataContent = document.querySelector('#data-content');
let activeRequest;
let responseData;

function showData(title, data) {
  dataTitle.textContent = title;
  dataContent.textContent = JSON.stringify(data, null, 4);
  dataDialog.showModal();
  dataContent.scrollTop = 0;
  dataContent.scrollLeft = 0;
}

responseButton.addEventListener('click', () => showData('Tüm API yanıtı', responseData));

function element(tag, className, text) {
  const node = document.createElement(tag);
  node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function showStatus(title, detail, error = false) {
  status.textContent = [title, detail].filter(Boolean).join(' ');
  status.classList.toggle('error', error);
  status.hidden = false;
}

function productCard(item, index) {
  const doc = item.document ?? {};
  const variants = Array.isArray(doc.variants) ? doc.variants : [];
  const variant = variants.find(v => String(v.id) === String(item.id)) ?? variants[0] ?? doc;
  const card = element('article', 'card');
  const picture = element('div', 'picture');
  picture.append(element('span', 'placeholder', 'Görsel mevcut değil'));
  const images = Array.isArray(variant.images) ? variant.images : [];
  const source = 'https://dummyimage.com/{size}'; /**(images.find(img => img.isDefault) ?? images[0])?.src**/;
  if (typeof source === 'string') {
    try {
      const url = new URL(source.replace('{size}', '200x200/'));
      if (['https:', 'http:'].includes(url.protocol)) {
        const img = document.createElement('img');
        img.alt = variant.name ?? 'Ürün görseli';
        img.loading = 'lazy';
        img.referrerPolicy = 'no-referrer';
        img.addEventListener('error', () => {
          img.replaceWith(element('span', 'placeholder', 'Görsel yüklenemedi'));
        }, { once: true });
        img.src = url.href;
        picture.replaceChildren(img);
      }
    } catch { /* Invalid image URLs use the placeholder. */ }
  }
  picture.append(element('span', 'rank', `#${index + 1}`));
  const body = element('div', 'card-body');
  body.append(
    element('p', 'brand', doc.brand?.name ?? variant.brandName ?? 'Marka belirtilmemiş'),
    element('h3', '', variant.name ?? doc.name ?? `Ürün ${item.id}`),
    element('p', 'category', doc.category?.name ?? variant.categoryName ?? 'Kategori belirtilmemiş')
  );
  const merchants = Array.isArray(variant.merchants) ? variant.merchants : [];
  const merchant = merchants.find(m => String(m.merchant) === String(variant.buyboxMerchantId)) ?? merchants[0];
  const price = merchant?.discountedSalesPrice ?? merchant?.price ?? variant.price;
  const priceText = typeof price === 'number' && Number.isFinite(price)
    ? new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY' }).format(price)
    : 'Fiyat belirtilmemiş';
  body.append(element('p', 'price', priceText));
  body.append(element('p', 'product-id', `Ürün ID: ${item.id}`));
  const score = element('div', 'score');
  const scoreText = typeof item.score === 'number' && Number.isFinite(item.score) ? String(item.score) : '—';
  score.append(element('span', '', 'Score'), element('strong', '', scoreText));
  const dataButton = element('button', '', 'Ürün verisi');
  dataButton.type = 'button';
  dataButton.addEventListener('click', () => showData(`Ürün ${item.id} · API verisi`, item));
  const vectorTexts = element('details', 'vector-texts');
  vectorTexts.append(element('summary', '', 'Vektör metinleri'));
  const texts = [
    ['Ana metin', doc.semantic?.text],
    ['Ürün adı', doc.semantic?.v2?.name?.text],
    ['Kategori', doc.semantic?.v2?.category?.text],
    ['Marka', doc.semantic?.v2?.brand?.text]
  ];
  let hasText = false;
  for (const [label, text] of texts) {
    if (typeof text !== 'string' || !text.trim()) continue;
    hasText = true;
    vectorTexts.append(element('strong', '', label), element('p', 'vector-text', text));
  }
  if (!hasText) vectorTexts.append(element('p', '', 'Vektör metni mevcut değil.'));
  body.append(score, dataButton, vectorTexts);
  card.append(picture, body);
  return card;
}

async function search(updateUrl = true) {
  const term = query.value.trim();
  query.setCustomValidity('');
  if (!term || new TextEncoder().encode(term).length > 2000) {
    query.setCustomValidity(!term ? 'Lütfen bir arama yazın.' : 'Arama metni çok uzun; lütfen kısaltın.');
    query.reportValidity();
    return;
  }
  activeRequest?.abort();
  const request = new AbortController();
  activeRequest = request;
  const params = new URLSearchParams({ query: term, mode: mode.value, limit: limit.value });
  if (updateUrl) {
    const url = new URL(window.location.href);
    params.forEach((value, key) => url.searchParams.set(key, value));
    if (url.href !== window.location.href) window.history.pushState(null, '', url);
  }
  const started = performance.now();
  const timeout = setTimeout(() => request.abort(), 180000);
  results.setAttribute('aria-busy', 'true');
  products.replaceChildren();
  meta.textContent = '';
  responseData = undefined;
  responseButton.disabled = true;
  showStatus('Aranıyor…');
  try {
    const response = await fetch(`/api/v2/search?${params}`, {
      signal: request.signal,
      headers: { Accept: 'application/json' }
    });
    const data = await response.json().catch(() => null);
    if (!response.ok) throw new Error(data?.error || 'Arama servisine erişilemedi. Lütfen tekrar deneyin.');
    if (!data || !Array.isArray(data.items)) throw new Error('Arama yanıtı okunamadı. Lütfen tekrar deneyin.');
    if (activeRequest !== request) return;
    responseData = data;
    responseButton.disabled = false;
    const fragment = document.createDocumentFragment();
    data.items.forEach((item, index) => fragment.append(productCard(item, index)));
    products.replaceChildren(fragment);
    const elapsed = ((performance.now() - started) / 1000).toLocaleString('tr-TR', { maximumFractionDigits: 2 });
    const modeName = data.mode === 'hybrid' ? 'Hibrit' : 'Semantik';
    meta.textContent = `${data.items.length} ürün · ${modeName} · ${elapsed} sn`;
    if (data.items.length) {
      status.hidden = true;
    } else {
      showStatus('Sonuç bulunamadı.', 'Farklı bir arama deneyin.');
    }
  } catch (error) {
    if (activeRequest !== request) return;
    const message = error.name === 'AbortError'
      ? 'Arama zaman aşımına uğradı. Lütfen tekrar deneyin.'
      : error.message;
    showStatus('Arama tamamlanamadı', message, true);
  } finally {
    clearTimeout(timeout);
    if (activeRequest === request) results.setAttribute('aria-busy', 'false');
  }
}

form.addEventListener('submit', event => {
  event.preventDefault();
  search();
});

query.addEventListener('input', () => query.setCustomValidity(''));

function restoreFromUrl() {
  activeRequest?.abort();
  activeRequest = undefined;
  const params = new URLSearchParams(window.location.search);
  query.value = params.get('query') ?? '';
  query.setCustomValidity('');
  mode.value = ['semantic', 'hybrid'].includes(params.get('mode')) ? params.get('mode') : 'semantic';
  limit.value = ['12', '24', '50'].includes(params.get('limit')) ? params.get('limit') : '12';
  products.replaceChildren();
  meta.textContent = '';
  responseData = undefined;
  responseButton.disabled = true;
  results.setAttribute('aria-busy', 'false');
  dataDialog.close();
  showStatus('Ürünleri görmek için arama yapın.');
  if (query.value.trim()) search(false);
}

window.addEventListener('popstate', restoreFromUrl);
restoreFromUrl();