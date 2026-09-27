'use strict';
const form = document.querySelector('#search-form');
const query = document.querySelector('#query');
const mode = document.querySelector('#mode');
const limit = document.querySelector('#limit');
const products = document.querySelector('#products');
const status = document.querySelector('#status');
const meta = document.querySelector('#result-meta');
const results = document.querySelector('.results');
let activeRequest;

function element(tag, className, text) {
  const node = document.createElement(tag);
  node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}
function showStatus(title, detail, error = false) {
  status.replaceChildren(element('span', 'state-icon', error ? '!' : '⌕'), element('h3', '', title), element('p', '', detail));
  status.classList.remove('has-results');
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
  const source = (images.find(img => img.isDefault) ?? images[0])?.src;
  if (typeof source === 'string') {
    try {
      const url = new URL(source.replace('{size}', '400x400/'));
      if (['https:', 'http:'].includes(url.protocol)) {
        const img = document.createElement('img');
        img.alt = variant.name ?? 'Ürün görseli';
        img.loading = 'lazy';
        img.referrerPolicy = 'no-referrer';
        img.addEventListener('error', () => img.replaceWith(element('span', 'placeholder', 'Görsel yüklenemedi')), { once: true });
        img.src = url.href;
        picture.replaceChildren(img);
      }
    } catch { /* Invalid image URLs use the placeholder. */ }
  }
  picture.append(element('span', 'rank', `#${index + 1}`));
  const body = element('div', 'card-body');
  body.append(element('p', 'brand', doc.brand?.name ?? variant.brandName ?? 'Marka belirtilmemiş'), element('h3', '', variant.name ?? doc.name ?? `Ürün ${item.id}`), element('p', 'category', doc.category?.name ?? variant.categoryName ?? 'Kategori belirtilmemiş'));
  const merchants = Array.isArray(variant.merchants) ? variant.merchants : [];
  const merchant = merchants.find(m => String(m.merchant) === String(variant.buyboxMerchantId)) ?? merchants[0];
  const price = merchant?.discountedSalesPrice ?? merchant?.price ?? variant.price;
  body.append(element('p', 'price', typeof price === 'number' && Number.isFinite(price) ? new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY' }).format(price) : 'Fiyat belirtilmemiş'));
  body.append(element('p', 'product-id', `Ürün ID: ${item.id}`));
  const score = element('div', 'score');
  score.append(element('span', '', 'SCORE'), element('strong', '', typeof item.score === 'number' && Number.isFinite(item.score) ? String(item.score) : '—'));
  body.append(score);
  card.append(picture, body);
  return card;
}
form.addEventListener('submit', async event => {
  event.preventDefault();
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
  const started = performance.now();
  const timeout = setTimeout(() => request.abort(), 180000);
  results.setAttribute('aria-busy', 'true');
  products.replaceChildren();
  meta.textContent = '';
  showStatus('Ürünler aranıyor…', 'Aramanızla ilgili ürünler ve skorlar hazırlanıyor.');
  try {
    const response = await fetch(`/api/search?${params}`, { signal: request.signal, headers: { Accept: 'application/json' } });
    const data = await response.json().catch(() => null);
    if (!response.ok) throw new Error(data?.error || 'Arama servisine erişilemedi. Lütfen tekrar deneyin.');
    if (!data || !Array.isArray(data.items)) throw new Error('Arama yanıtı okunamadı. Lütfen tekrar deneyin.');
    if (activeRequest !== request) return;
    const fragment = document.createDocumentFragment();
    data.items.forEach((item, index) => fragment.append(productCard(item, index)));
    products.replaceChildren(fragment);
    meta.textContent = `${data.items.length} ürün · ${data.mode === 'hybrid' ? 'Hibrit' : 'Semantik'} · ${((performance.now() - started) / 1000).toLocaleString('tr-TR', { maximumFractionDigits: 2 })} sn`;
    if (data.items.length) {
      status.hidden = false;
      status.classList.remove('error');
      status.replaceChildren(element('p', '', `“${term}” için sonuçlar, en yüksek score değerinden başlayarak listeleniyor.`));
      status.classList.add('has-results');
    } else showStatus('Sonuç bulunamadı', 'Farklı bir ürün adı veya daha genel bir ifadeyle tekrar deneyin.');
  } catch (error) {
    if (activeRequest !== request) return;
    showStatus('Arama tamamlanamadı', error.name === 'AbortError' ? 'Arama zaman aşımına uğradı. Lütfen tekrar deneyin.' : error.message, true);
  } finally {
    clearTimeout(timeout);
    if (activeRequest === request) results.setAttribute('aria-busy', 'false');
  }
});
query.addEventListener('input', () => query.setCustomValidity(''));
