# Türkçe ürün semantik arama PoC

2.000 ana ürün ve 2–3 eşzamanlı kullanıcı hedefi için Symfony + PostgreSQL + Elasticsearch + yerel Python embedding servisi.

**Kaynak PostgreSQL'dir. Ürünler DB'ye yazılır; yalnızca `app:products:index` komutu DB'den okuyarak Elasticsearch'e aktarır. Kuyruk veya arka plan tüketicisi yoktur.** API ile yazılan/güncellenen/silinen kayıtlar bu komut çalıştırılıncaya kadar arama indeksine yansımaz.

## Ürün yapısı ve mapping

Ürün `products.document` JSONB kolonunda bütün haliyle saklanır. PostgreSQL'de JSONB tercihinin amacı; varyant, satıcı, özellik, kampanya ve diğer alanları kaybetmeden orijinal dokümanı kaynak veri olarak tutmaktır. Varyantlar ayrı ürün kayıtlarına dönüştürülmez.

Elasticsearch `_source` alanında **orijinal ürün alanları aynı seviyede kalır**: `variants`, `brand`, `category`, `breadcrumb` vb. `payload` sarmalayıcısı yoktur. Yalnızca köke uygulamaya ayrılmış `semantic` nesnesi eklenir. Her ana dokümanda bir adet 384 boyutlu vektör vardır. Arama sonucu orijinal dokümanı, tüm varyant ve satıcılarıyla verir; `semantic` nesnesi yanıttan çıkarılır.

`infrastructure/elasticsearch/products.mapping.json` verilen örnek dokümana göre hazırlanmıştır. `variants`, `variants.merchants` ve örnekteki nesne dizileri açıkça `nested` tanımlanmıştır. `variants.attributes`, `variants.images`, `variants.listings`, `variants.merchants.regions`, `variants.merchants.merchantBadges`, `category.tree`, `breadcrumb` dahil tüm tanımları dosyada görebilirsiniz. ID listeleri gibi skaler diziler `keyword` alanlarıdır.

**Orijinal indeksin gerçek `_mapping` çıktısı henüz verilmedi. Bu dosya orijinal mapping'in birebir kopyası değildir.** Null veya boş dizi içeren alanların gerçek Elasticsearch tipleri örnek dokümandan kesin çıkarılamaz. Bilinmeyen alanlar `dynamic: false` sayesinde `_source` içinde korunur; yeni alanlar açık mapping eklenmedikçe aranamaz. Var olan bir üretim indeksinin üzerine yazılmaz.

Gerçek mapping elde edildiğinde `GET product_list_080326/_mapping` çıktısını dosyaya kaydedip kullanın:

```bash
docker compose cp ./original-mapping.json search-api:/data/original-mapping.json
docker compose exec search-api php bin/console app:elastic:init --mapping=/data/original-mapping.json
```

Komut kaynak mapping'in mevcut alanlarını değiştirmeden `semantic` alanını ve sürüm metadata'sını ekler. `variants` ve `variants.merchants` nested değilse hata verir. Özel analizörler kullanılıyorsa mapping tek başına yeterli değildir; gerekli `settings.analysis` ile birlikte create-index JSON dosyası verilmelidir. Oluşturulmuş indekste `object` alanını `nested` yapmaya çalışmayın; yeni indeks oluşturun.

## Model

- `intfloat/multilingual-e5-small`
- Sabit Hugging Face revision: `614241f622f53c4eeff9890bdc4f31cfecc418b3`
- CPU / ONNX Runtime, tek worker, iki inference thread'i.
- Maskeli mean pooling + L2 normalization; 384 float.
- Dokümanlarda `passage:`, sorgularda `query:` öneki Python tarafından eklenir.
- Önek ve özel token'lar dahil 512 token sınırı. Kesilme bilgisi kaydedilir.
- İstek başına 1–8 metin; bir çalışan inference ve en fazla üç bekleyen istek; doluysa HTTP 429.
- Uzun batch'ler içeride en fazla 1024 padded token içeren küçük gruplara ayrılır; yanıt sırası korunur. Bu sınır küçük sunucuda bellek sıçramasını azaltır.
- Varsayılan FP32 ONNX, kalite referansıdır. INT8 denemesi `quantize_model.py` ile ayrı yapılır; karşılaştırma olmadan INT8 üstün kabul edilmez.

INT8 denemesi için ayrı test ortamında `requirements-quantize.txt` bağımlılıklarını kurup `python quantize_model.py` çalıştırın. Oluşan `onnx/model.int8.onnx` dosyasını `MODEL_FILE` olarak seçerken yeni `EMBEDDING_VERSION` ve yeni Elasticsearch indeksi kullanın.

Model dosyaları `model-init` tek seferlik servisi tarafından kalıcı volume'a indirilir. Normal embedding API başlangıcı indirme veya dönüşüm yapmaz. İnternet yalnızca ilk image/model hazırlığında gerekir.

## Coolify kurulumu

1. Repoyu Coolify'da Docker Compose kaynağı olarak ekleyin; dosya `compose.yaml`.
2. `.env.example` içindeki değişkenleri Coolify environment alanında gerçek ve farklı parolalarla tanımlayın. `.env` dosyasını Git'e göndermeyin.
3. Ubuntu hostta Elasticsearch için `vm.max_map_count` değerini en az `262144` yapın ve `/etc/sysctl.d/` altında kalıcılaştırın.
4. Deploy edin. `model-init` tamamlanıp model hazır olduğunda Python healthcheck başarılı olur. İlk model indirmesi zaman alabilir.
5. Tüneli yalnızca `search-api` servisine, port `80` üzerinden bağlayın. Diğer servisler Docker iç ağındadır; host portu yayınlamaz.
6. Search API terminalinde aşağıdaki komutları çalıştırın:

```bash
php bin/console app:db:init
php bin/console app:elastic:init
```

Veritabanı ilk boş volume başlangıcında da hazırlanır. `app:db:init` tekrar çalıştırılabilir. `app:elastic:init` mevcut indeksi silmez veya değiştirmez; indeks zaten varsa hata verir.

Kaynak bütçeleri: Elasticsearch 3 GiB (1,5 GiB heap), embedding 1,5 GiB, Symfony 768 MiB, PostgreSQL 384 MiB. `model-init` 512 MiB limitlidir ve embedding başlamadan tamamlanır. Host işletim sistemi/Coolify için kalan alan ayrılır; gerçek tüketim sunucuda ölçülmelidir.

## DB'ye ürün yükleme ve Elasticsearch'e aktarma

JSON dosyası tek `_source` dokümanı, tek Elasticsearch GET yanıtı veya bunların bir dizisi olabilir. Büyük aktarım için `.ndjson` kullanın: her satır bir doküman.

```bash
docker compose cp ./products.ndjson search-api:/data/products.ndjson
docker compose exec search-api php bin/console app:products:import /data/products.ndjson
docker compose exec search-api php bin/console app:products:index --batch-size=8
```

Örnek ürünle denemek için:

```bash
docker compose exec search-api php bin/console app:products:import tests/fixtures/product.json
docker compose exec search-api php bin/console app:products:index
```

Aktarım komutu şu akışı izler:

1. DB'de yeni/değişmiş veya silinmiş ürünleri artan ID sırasıyla alır.
2. Marka, kategori, benzersiz adlar ve özelliklerden doküman metni üretir.
3. Metin/model sürümü hash'i değişmediyse DB'deki önceki embedding'i kullanır. Fiyat/stok/kampanya güncellemeleri yeniden embedding üretmez.
4. Gerekli metinleri Python'a küçük batch olarak yollar.
5. Orijinal dokümanı `semantic` alanıyla birlikte Bulk API'ye gönderir.
6. Her bulk sonucunu ayrı kontrol eder; yalnızca başarılı kayıtların DB indeks durumunu günceller.

İlerleme PostgreSQL'de tutulur. Komut kesilirse tekrar çalıştırın. DB commit'i öncesinde Elasticsearch yazımı olmuşsa aynı revision ile güvenle tekrar edilir. Aynı anda ikinci indexer çalıştırılmaz. Başarısız bulk kayıtları raporlanır, başarılı kayıtlar korunur; komut hata koduyla çıkar. DB satırları batch işlenirken kilitlidir; API yazımı kısa süre bekleyebilir.

Revision DB tarafından artırılır. Aynı dokümanın tekrar yüklenmesi revision artırmaz. Güncelleme tam doküman değişimidir; varyant silmek için yeni tam dokümanı gönderin. API'ye ulaşıp DB'de son tamamlanan yazım geçerlidir; eski bir harici sistem olayını algılayan kaynak revision sözleşmesi bu PoC'de yoktur.

## API

Tüm ürün endpoint'leri `X-API-Key: ADMIN_API_KEY`, arama `X-API-Key: SEARCH_API_KEY` ister.

| Endpoint | Davranış |
|---|---|
| `PUT /api/documents/{id}` | İstek gövdesindeki orijinal dokümanı PostgreSQL'e yazar |
| `GET /api/documents/{id}` | DB'deki güncel dokümanı ve revision'ı döndürür |
| `DELETE /api/documents/{id}` | DB'de silinmiş olarak işaretler; index komutu ES'den siler |
| `POST /api/search` | Elasticsearch'teki son indekslenmiş dokümanları döndürür |
| `GET /health/live` | PHP süreci |
| `GET /health/ready` | DB şeması, Elasticsearch durumu ve model readiness/sürümü |

PUT gövdesi doğrudan ürün JSON'udur; `document` veya `payload` içine sarılmaz. URL'deki kimlikle doküman `id` değeri eşleşmelidir. Ürün alanında `semantic` kullanılamaz.

Arama örneği:

```json
{
  "query": "tam yatan bebek arabası",
  "mode": "semantic",
  "limit": 10,
  "filters": {
    "brand_id": "20048005",
    "category_ids": ["11"]
  }
}
```

`filters` opsiyoneldir. Marka ve kategori AND, kategori listesi OR mantığıyla uygulanır. Kategori ağacı nested ise uygun nested query kullanılır. Filtre kNN aday seçimine uygulanır. Varsayılan mod `semantic`; `hybrid` modda BM25 ve vektör aramasının ilk 50 adayı uygulamada RRF (`k=60`) ile birleştirilir. Doküman kimliği ve barkodlar kesin eşleşmeye eklenir; ürün model kodları ürün metninde aranır. Limit 1–50; derin sayfalama yoktur. Skor olasılık değildir.

Yanıt: `mode`, `count`, `items: [{id, score, document}]`. `document`, **orijinal ürünün tüm alanlarını** içerir. Hangi varyantın eşleştiği hesaplanmaz. Stok/fiyat/renk kombinasyon filtreleri bu sürümün API kapsamına dahil değildir; nested mapping ileride bunları doğru kurmak için korunur.

Python kullanılamazsa arama 503 döner; sessiz metinsel fallback yoktur. Yeni embedding gereken indeksleme başarısızsa eski ES kaydı korunur ve DB değişikliği sonraki komuta kalır.

## Yeni model veya mapping sürümü

Yeni fiziksel indeks adı (`products_v2`) kullanın. Model dosyası değişince `EMBEDDING_VERSION` da değişmelidir. İndeks metadata'sı farklıysa arama ve indeksleme reddedilir. DB checkpoint'i indeks UUID'sine bağlıdır; yeni/recreated indekste tüm kayıtlar yeniden ele alınır.

İndeksleme `ELASTICSEARCH_INDEX` fiziksel indeksine, arama `ELASTICSEARCH_ALIAS` (varsayılan `products_current`) aliasına gider. İlk başarılı aktarım aliası oluşturur. Yeni indeks için `app:products:index --activate` kullanın; bütün aktarım başarılıysa alias atomik olarak yeni indekse geçer. Eski indeks silinmez. Model değişiminde Python ve Symfony embedding sürümlerini birlikte güncelleyin; eski modelle sorgulama ve yeni modelle indeksleme için tek model süreci yeterli olmadığından bu PoC'de model değişimi sırasında bakım penceresi gerekir.

## Yedekleme

PostgreSQL kaynak verinin asıl yedeğidir:

```bash
docker compose exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > products.dump
```

Boş bir DB'ye geri yüklemek için dump dosyasını `pg_restore` ile yükleyin; yeni Elasticsearch indeksi oluşturup `app:products:index` çalıştırın. ES snapshot volume'u ve `path.repo` ayrıca hazırdır; snapshot repository kaydı ve snapshot alma yönetim işlemleridir. Aynı diskteki volume tek başına yedek değildir; dump dosyasını sunucu dışına kopyalayın.

## Yerel doğrulama

```bash
cp .env.example .env  # Önce içindeki parolaları değiştirin.
docker compose -f compose.yaml -f compose.test.yaml up -d --build
composer install --working-dir=search-api
php search-api/vendor/bin/phpunit -c search-api/phpunit.xml
python3 scripts/smoke_test.py
python3 scripts/reindex_test.py
python3 scripts/benchmark.py --concurrency=3 --requests=30
```

`compose.test.yaml` yalnızca local test içindir: API 18080, ES 19200, embedding 18000 portlarını `127.0.0.1` üzerinden açar ve 4 GiB Docker ortamına uygun ES heap'i kullanır. Coolify'da bu override kullanılmaz. Smoke test yalnızca boş, ayrılmış PoC DB/indeksinde çalıştırılmalıdır; örnek dokümanı oluşturur, günceller ve test sonunda geri yükler. Testlerdeki süreler ev sunucusunun kapasite ölçümü yerine geçmez.

Python testleri:

```bash
docker compose run --rm --no-deps embedding sh -c 'pip install pytest==8.3.5 httpx==0.28.1 && python -m pytest -q'
```

Yerel sonuçların kapsamı ve sınırları `docs/VERIFICATION.md` dosyasında kayıtlıdır.
