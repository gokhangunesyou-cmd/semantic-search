# Türkçe ürün semantik arama PoC

2.000 ana ürün ve 2–3 eşzamanlı kullanıcı hedefi için Symfony + PostgreSQL + Elasticsearch + yerel Python embedding servisi.

**Kaynak PostgreSQL'dir. Ürünler DB'ye yazılır; yalnızca `app:products:index` komutu DB'den okuyarak Elasticsearch'e aktarır. Kuyruk veya arka plan tüketicisi yoktur.** API ile yazılan/güncellenen/silinen kayıtlar bu komut çalıştırılıncaya kadar arama indeksine yansımaz.

## Ürün listeleme arayüzü

Search API alan adının kök adresi (`/`) tek sayfalık ürün arama ekranıdır. Arama metnini girip **Ara** düğmesine basın; semantik aramayla 12, 24 ya da 50 sonuç isteyebilirsiniz. Ürün listesi API sırasını korur ve her ürünün `items[].score` değerini yuvarlamadan gösterir. Score yüzde değildir.

Satırlar ana ürün ID’sine uyan varyantı, bulunamazsa ilk varyantı gösterir. Görsel, ürün adı, marka, kategori ve seçilen varyantın buybox satıcısının (yoksa ilk satıcının) fiyatı kullanılır. Eksik görsel/fiyat için açıklama gösterilir. Görseller ürün verisindeki HTTP(S) adreslerinden yüklenir. Arama `GET /api/search` üzerinden aynı origin üzerinde yapılır; ayrı frontend servisi veya build adımı gerekmez. Değişiklikler normal Git push → Coolify Deploy akışıyla yayınlanır.

Arama metni ve sonuç sayısı URL'de `?query=bebek+arabası&limit=12` biçiminde tutulur.
URL'deki `query` değiştirilerek veya bağlantı paylaşılarak aynı arama açılabilir; geri/ileri gezinme de desteklenir.
Her satırdaki **Ürün verisi** butonu ilgili API sonuç nesnesini, **Tüm API yanıtı** butonu mevcut aramanın
bütün yanıtını JSON olarak açar. Veriler API'nin döndürdüğü tüm alanları içerir; ayrı istek yapılmaz.
Her üründeki **Vektör metinleri** bölümü ana metni ve varsa V2 ad, kategori, marka metinlerini gösterir.
Sayısal vektörler **Ürün verisi** ve **Tüm API yanıtı** içinde görülebilir.

## Ürün yapısı ve mapping

Ürün `products.document` JSONB kolonunda bütün haliyle saklanır. PostgreSQL'de JSONB tercihinin amacı; varyant, satıcı, özellik, kampanya ve diğer alanları kaybetmeden orijinal dokümanı kaynak veri olarak tutmaktır. Varyantlar ayrı ürün kayıtlarına dönüştürülmez.

Elasticsearch `_source` alanında **orijinal ürün alanları aynı seviyede kalır**: `variants`, `brand`, `category`, `breadcrumb` vb. `payload` sarmalayıcısı yoktur. Yalnızca köke uygulamaya ayrılmış `semantic` nesnesi eklenir. Her ana dokümanda bir adet 384 boyutlu vektör vardır. Arama sonucu orijinal dokümanı, tüm varyant ve satıcılarıyla verir; `/api/search` ve `/api/v2/search` yanıtlarında `items[].document.semantic` nesnesi de döner.
Bu nesne ana `text` ve `vector` alanlarını, varsa `v2` altındaki ad/kategori/marka metin ve vektörlerini içerir.
İndekste mevcut veriler doğrudan döner; bu yanıt değişikliği için yeniden indeksleme gerekmez.

İstisna: `author`, `variants.specificList`, `variants.productKeywords`, `variants.plistFeatured` ve `variants.merchantFeatured` mevcut `keyword` mapping’iyle uyumsuz nesneler içerebildiği için Elasticsearch’e gönderilmez ve yeni indekslenen ürünlerin arama sonucunda bulunmaz. PostgreSQL’deki orijinal ürün ve ürün GET API yanıtı bu alanları korur. Bu düzeltme için yeni indeks gerekmez; deploy sonrasında `php bin/console app:products:index` tekrar çalıştırıldığında daha önce başarısız kayıtlar yeniden denenir. Daha önce başarılı indekslenmiş kayıtlar değişmez.

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

### Geliştirme ve deployment akışı

Bu proje Coolify üzerinden çalıştırılır. Kod ve yapılandırma değişiklikleri repoda yapılır; kullanıcı Git push yapar ve ardından Coolify'da Deploy'a basar. Asistan değişiklikleri ve yerel doğrulamayı tamamlar. Sunucuda elle dosya düzenleme normal geliştirme akışının parçası değildir. Asistan için kalıcı çalışma kuralları `AGENTS.md` içindedir.

1. Repoyu Coolify'da Docker Compose kaynağı olarak ekleyin; dosya `compose.yaml`.
2. `.env.example` içindeki değişkenleri Coolify environment alanında gerçek ve farklı parolalarla tanımlayın. `.env` dosyasını Git'e göndermeyin.
3. Ubuntu hostta Elasticsearch için `vm.max_map_count` değerini en az `262144` yapın ve `/etc/sysctl.d/` altında kalıcılaştırın.
4. Deploy edin. `model-init` tamamlanıp model hazır olduğunda Python healthcheck başarılı olur. İlk model indirmesi zaman alabilir. Symfony, PostgreSQL/Elasticsearch/model hazır olduğunda başlar; kendi imajındaki SQL dosyasıyla tabloları oluşturup Apache'yi çalıştırır.
5. Tüneli yalnızca `search-api` servisine, port `80` üzerinden bağlayın. Elasticsearch ayrıca sunucunun yerel IP'sinde `9200` portunu yayınlar; PostgreSQL ve embedding host portu yayınlamaz.
6. Search API terminalinde aşağıdaki komutları çalıştırın:

```bash
php bin/console app:elastic:init
```

PostgreSQL kendi veritabanını hazırlar; uygulama tabloları `search-api` başlangıcında `app:db:init` ile otomatik oluşturulur. SQL dosyası imajın içindedir; hosttan SQL dosyası bind mount edilmez ve repository-preservation ayarına ihtiyaç yoktur. `app:db:init` tekrar çalıştırılabilir; mevcut tabloları/verileri silmez. Bu komut ilerideki şema değişiklikleri için migration sistemi yerine geçmez. `app:elastic:init` mevcut indeksi silmez veya değiştirmez; indeks zaten varsa hata verir.

Eski deployment'ta `001-products.sql: Is a directory` hatası görüldüyse düzeltilmiş Compose dosyasını deploy edin. Mevcut PostgreSQL volume'unu koruyun: DB zaten oluşmuş olsa bile eksik uygulama tabloları Symfony başlangıcında tamamlanır. İlk kurulum başarısız olduktan sonra PostgreSQL'in `healthy` görünmesi tek başına ürün tablolarının oluştuğunu göstermez.

Kaynak bütçeleri: Elasticsearch 3 GiB (1,5 GiB heap), embedding 1,5 GiB, Symfony 768 MiB, PostgreSQL 384 MiB. `model-init` 512 MiB limitlidir ve embedding başlamadan tamamlanır. Host işletim sistemi/Coolify için kalan alan ayrılır; gerçek tüketim sunucuda ölçülmelidir.

### Elasticsearch'e yerel ağdan erişim

`compose.yaml`, Elasticsearch portunu `${ELASTICSEARCH_BIND_IP:-192.168.1.105}:9200:9200` olarak yayınlar. Varsayılan IP, konuşmada verilen `192.168.1.105` kabul edilmiştir; sunucunun gerçek IP'si farklıysa `ELASTICSEARCH_BIND_IP` değiştirilmelidir. Değişiklikler Git push ve Coolify Deploy sonrasında uygulanır.

Aynı ağdaki bilgisayardan `http://192.168.1.105:9200/` adresine erişilir. Kullanıcı adı `elastic`, parola Coolify'daki `ELASTICSEARCH_PASSWORD` değeridir. Terminalden parola istemiyle kontrol:

```bash
curl -u elastic http://192.168.1.105:9200/
```

HTTP 401, servise erişildiğini ancak kimlik doğrulamanın başarısız veya eksik olduğunu gösterir. Bağlantı reddi/zaman aşımında Coolify deployment durumu, port eşlemesi ve sunucunun güvenlik duvarı kontrol edilir. Mevcut HTTP yapılandırmasında trafik şifrelenmez; bu erişim güvenilen yerel ağ içindir.

Yerel testte `compose.test.yaml`, `!override` ile bu eşlemeyi yalnızca `127.0.0.1:19200:9200` olarak değiştirir; yerel Docker Compose 2.24.4 veya üzeri gerekir.

## DB'ye ürün yükleme ve Elasticsearch'e aktarma

### Hazır 2.824 ürünü sunucuda DB'ye yükleme

`infrastructure/datasets/products.ndjson` Git ile taşınan ürün dosyasıdır ve deploy sırasında uygulama imajına dahil edilir. Git push ve Coolify Deploy sonrasında **Search API servisinin terminalinde** çalıştırın:

```bash
php bin/console app:products:import
```

Komut bu dosyadaki 2.824 ürünü yalnızca PostgreSQL'e yazar; Elasticsearch'e bağlanmaz ve embedding üretmez. Aynı ID mevcutsa ürün güncellenir; aynı veriyle tekrar çalıştırmak kopya kayıt oluşturmaz veya revision artırmaz. Diğer ürünler silinmez. Sonuçta `DB: 2824 başarılı, 0 hatalı.` görülmelidir.

Dosya, önceki `documentScore > 140` sorgusundan gelen 733 ürün ile sekiz kategori sorgusundan (her sorgu en fazla 200 ürün) gelen 1.202 ürün ve `category.tree.id = 5507609420` nested sorgusundan gelen 300 ürünün ID bazında birleştirilmiş halidir. İlk kategori aktarımındaki 10 ortak üründe son çekilen veri kullanılır. İlk nested sorgudaki 300 ürünün tamamı yenidir; toplam 2.824 benzersiz ürün bulunur. Eksik `id` alanları kaynak Elasticsearch `_id` değeriyle tamamlanır. Kategori sorgularının sonuç sayıları sırasıyla 149, 200, 53, 200, 200, 0, 200, 200 olmuştur; `category.id = 5507609420` sorgusu ürün döndürmemiştir; aynı ID için `category.tree` üzerinde nested sorguyla ayrıca 300 ürün alınmıştır. Son sorgudaki `230244433` ve `231394552` kategorileri birlikte 200 ürünle sınırlanmıştır. Ek üç `category.tree.id` sorgusundan (2311258000, 220366548, 2314109670) ayrı ayrı 200 ürün alınmıştır. Bu 600 ürünün biri mevcut dosyada da bulunduğu için 599 yeni ürün eklenmiştir. Kaynak erişim şifresi ve dışa aktarma scripti yalnızca Git'in yok saydığı yerel `data/` klasöründedir; sunucuya gönderilmez. Veri dosyası imajda `/app/infrastructure/datasets/` altında bulunur; `/data` volume'undan etkilenmez.

### Başka bir dosyadan yükleme

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

PoC API'leri anahtarsız çalışır. `ADMIN_API_KEY` ve `SEARCH_API_KEY` artık kullanılmaz; Compose bu değişkenleri istemez.

### Swagger ile deneme

Coolify'da Search API için kullandığınız adresin **`/docs`** yolunu açın. Üstteki seçim alanından **Arama ve ürün API** veya **Embedding API** seçin; **Try it out → Execute** ile istek gönderin.

- Yerel Swagger: `http://127.0.0.1:18080/docs`
- Arama/ürün OpenAPI: `/openapi.json`
- Embedding OpenAPI: `/api/embedding/openapi.json`
- Embedding çağrıları aynı Search API adresinde `/api/embedding/v1/embeddings` üzerinden iç servise iletilir. Ek domain veya host portu gerekmez.
- Embedding servisinin kendi Swagger'ı yerel testte `http://127.0.0.1:18000/docs`, şeması `/openapi.json` yolundadır.

Arama örneği hazır gelir; ürün PUT işlemi mevcut örnek ürünle doldurulur. Ürün ekleme/güncelleme/silme sonrasında `app:products:index` çalıştırılmalıdır. Swagger'ın JavaScript/CSS dosyaları CDN'den yüklenir; tarayıcının internet erişimi gerekir.

| Endpoint | Davranış |
|---|---|
| `PUT /api/documents/{id}` | İstek gövdesindeki orijinal dokümanı PostgreSQL'e yazar |
| `GET /api/documents/{id}` | DB'deki güncel dokümanı ve revision'ı döndürür |
| `DELETE /api/documents/{id}` | DB'de silinmiş olarak işaretler; index komutu ES'den siler |
| `GET /api/search` | Elasticsearch'teki son indekslenmiş dokümanları döndürür |
| `GET /health/live` | PHP süreci |
| `GET /health/ready` | DB şeması, Elasticsearch durumu ve model readiness/sürümü |

PUT gövdesi doğrudan ürün JSON'udur; `document` veya `payload` içine sarılmaz. URL'deki kimlikle doküman `id` değeri eşleşmelidir. Ürün alanında `semantic` kullanılamaz.

Tarayıcı adres çubuğundan açılabilecek örnekler (kendi Search API adresinize ekleyin):

```text
/api/search?query=bebek%20arabası&limit=10
/api/search?query=bebek%20arabası&brand_id=20048005&category_ids=11,12
/api/embedding/v1/embeddings?kind=query&texts=bebek%20arabası
```

Arama parametreleri URL query string üzerinden gönderilir. `brand_id` ve virgülle ayrılmış `category_ids` opsiyoneldir. Embedding için `kind=query|document` ve `texts` gerekir; birden fazla metin için `texts=ilk&texts=ikinci` kullanılır (1–8 metin). Dışarıya açılan arama ve embedding yolları yalnızca GET kabul eder. Uzun dokümanların toplu indekslenmesi için iç embedding servisi POST desteğini korur; bu dahili işlem Swagger'da gösterilmez.

Marka ve kategori AND, kategori listesi OR mantığıyla uygulanır. Kategori ağacı nested ise uygun nested query kullanılır. Filtre kNN aday seçimine uygulanır. Arama semantik kNN ile yapılır. Limit 1–50; derin sayfalama yoktur. Skor olasılık değildir.

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

### V2: alan bazlı vektör araması

`GET /api/v2/search?query=telefon&limit=2` ana vektörle ürün bulur; ad, kategori ve marka vektörleriyle sıralar. `brand_id`,
`category_ids` ve `limit` parametreleri v1 ile aynıdır; yalnızca `mode=semantic` desteklenir. `/api/search` aynı
davranışı sürdürür. Swagger `/docs` içinde v2 de yer alır.

Coolify deploy sonrasında **search-api** servisinin terminalinde:

```bash
php bin/console app:products:index-v2 --batch-size=8
```

Komut `ELASTICSEARCH_INDEX` fiziksel indeksindeki **mevcut Elasticsearch dokümanlarını** tarar; PostgreSQL'den ürün
kopyalamaz. Mapping'e `semantic.v2.name.vector`, `semantic.v2.category.vector`, `semantic.v2.brand.vector` (384 boyut)
ekler. Kaynak ürün alanlarını, v1 vektörünü ve dış sürüm numarasını korur; alias değiştirmez. Arama aliasının bu indeksi
gösteriyor olması gerekir. Kategori vektörü sıralı kategori yolu ve yaprak kategori adından üretilir. Analyzer alt
alanları kullanılmaz. Eksik marka/kategori için vektör üretilmez. Vektör boyutu/modeli v1 ile aynıdır.

Metin ve model sürümü hash'i sayesinde değişmeyen kayıtlar atlanır; kesilen/hatalı çalıştırma aynı komutla tekrar
denenebilir. `--force` tüm v2 vektörlerini yeniden üretir. Komut toplu hatalarda başarısız çıkış kodu döndürür. Kaynak
JSON nesne/dizi tipleri korunur. Normal `app:products:index` ile aynı PostgreSQL kilidini kullanır; v2 mapping'i
eklendikten sonra normal indeksleyici de yeni/değişen ürünlerin v2 vektörlerini üretir. Harici Elasticsearch yazıcıları
bu kilidi kullanmadığından bu komut sırasında durdurulmalıdır; doğrudan ES yazmaları bu uygulamanın desteklediği
güncelleme akışı değildir.

V2, Elasticsearch `script_score` sorgusunda `semantic.vector` benzerliğini ana skor olarak hesaplar;
ad, kategori ve marka vektörleri bu skora boost ekler. `category.categoryFactor` sıralamada kullanılmaz.
Sabit aday havuzu, kNN ön seçimi veya ikinci sorgu yoktur.
`limit` yalnızca döndürülen ürün sayısını belirler; puanlanan ürün sayısını sınırlamaz.

Son puan: `1.0 × ana vektör + 0.4 × ad + 0.5 × kategori + 0.1 × marka`.
Her benzerlik `(cosine + 1) / 2` ölçeğindedir. Ana vektörü olmayan kayıtlar dahil edilmez; eksik v2 alanının
boost'u sıfırdır. `constant_score` kullanılmaz; ana vektörün benzerlik farkları korunur. Katsayılar
`ProductVectorQuery::BASE_WEIGHT` ve `WEIGHTS` içindedir. Toplam skor 0–2 aralığındadır, olasılık değildir.
Vektörler yanıtta gizlenir; `version` ve ana vektör dahil `weights` döner.

Bu yöntem filtreye uyan ve ana vektörü bulunan bütün ürünlerde benzerlik hesaplar. V1'in yaklaşık kNN aramasına
göre büyük kataloglarda daha fazla CPU ve süre gerektirir; `limit` düşürmek bu hesaplama yükünü azaltmaz.
Elasticsearch `search.allow_expensive_queries` ayarının `script_score` sorgularına izin vermesi gerekir.

V2 arama sırasında mapping/model sürümü kontrolü veya `_mapping` çağrısı yapılmaz. Mapping komut tarafından hazırlanır.
`category.tree` repodaki mapping gibi nested olmalıdır. V2 alanları henüz eklenmemiş ürünler ana vektörleriyle
puanlanır; eksik alanlar boost vermez. V1 bu süreçte çalışmaya devam eder. Canlı kalite ve performans ayrıca ölçülmelidir.

V2 kodu ayrı namespace altında düzenlenmiştir:

- `Controller/V2/SearchController`: HTTP isteği ve yanıtı.
- `Service/V2/Search/ElasticSearchService`: embedding ve Elasticsearch çağrılarının koordinasyonu.
- `Service/V2/Search/QueryBuilder/QueryBuilder`: fluent `applyFilter`, `vector`, `size`, `source`, `build`.
- `QueryBuilder/Filter`: marka ve kategori filtreleri.
- `QueryBuilder/ProductVectorQuery`: ana vektör skoru ve alan boost’larını içeren tek sorgu.
- `Service/V2/Index/FieldVectors` ve `Command/V2/IndexCommand`: v2 vektör üretimi ve aktarımı.

V1 controller/arama servisi v2'ye yönlendirme yapmaz. V2 builder her adımda kopya döndürür;
ardışık istekler birbirinin filtrelerini değiştirmez.