# Yerel doğrulama — 26 Eylül 2026

Testler geliştirme bilgisayarında, Docker'ın ARM64 Linux ortamında çalıştırıldı. Docker'a 8 CPU / yaklaşık 4 GiB RAM ayrılmıştı. **Bunlar i5-5200U ev sunucusunun ölçümleri değildir.**

## Geçen kontroller

- Symfony/PHPUnit: 4 test, 42 assertion.
- Symfony DI container ve YAML lint.
- Python: 3 test; API doğrulama, padding maskeli pooling, gerçek ONNX modeliyle 384 boyut ve normalizasyon, 512 token kesilmesi.
- Sekiz uzun metin içeren batch'in içeride küçük gruplarla işlenmesi; sıralı ve tekil inference ile eşdeğer sonuç üretmesi (1,5 GiB container limiti altında).
- Gerçek PostgreSQL 16 + Elasticsearch 8.19 + E5-small ONNX ile `scripts/smoke_test.py`.
- Örnek dokümanın DB ve Elasticsearch `_source` arasında bütün alanlarıyla eşitliği.
- Bilinmeyen alanlarda boş nesne `{}` ile boş dizi `[]` ayrımının korunması.
- Ana dokümanda iki varyant bulunmasına rağmen tek arama sonucu dönmesi.
- `variants` ve `variants.merchants` nested sorgularında farklı nesneler arasında yanlış eşleşme olmaması.
- Marka ve üst kategori filtreleri; semantik ve hibrit mod.
- Aynı aktarım tekrarlandığında değişmemiş kayıtların atlanması.
- Python kapalıyken fiyat değişiminin eski vektörle indekslenmesi.
- Python kapalıyken içerik değişiminin eski ES dokümanını bozmaması; servis döndüğünde tamamlanması.
- Elasticsearch bulk mapping hatasının başarılı checkpoint oluşturmaması ve sonraki komutta düzeltilebilmesi.
- DB'de soft delete → komut → ES'den silinme; orijinal örneğin tekrar yüklenmesi.
- Yeni fiziksel indekste tam yeniden aktarım, indeks UUID'sine göre checkpoint ayrımı, aliasın atomik geçişi ve eski indekse geri alınması.
- Temiz container'da önce root CLI, sonra Apache isteği çalıştırıldığında Symfony cache dizininin iki süreç tarafından kullanılabilmesi.
- Composer audit: bildirilmiş güvenlik açığı bulunmadı.

## Küçük eşzamanlılık denemesi

Model ısındıktan sonra **bir örnek ana doküman** üzerinde 3 eşzamanlı istemci / 30 semantik arama:

| Ölçüm | Yerel sonuç |
|---|---:|
| p50 | 0,048 saniye |
| p95 | 0,075 saniye |
| En uzun | 0,108 saniye |

Bu deneme istek zincirinin eşzamanlı çalıştığını kontrol eder. 2.000 gerçek ürünle Türkçe relevance değerlendirmesi veya ev sunucusunda performans kabul testi yapılmış değildir. Gerçek katalog ve ilgili dokümanları işaretlenmiş 50+ sorgu sağlandığında bunlar ayrıca ölçülmelidir.

Ölçüm anındaki yaklaşık bellek tüketimi: Python 912 MiB, Elasticsearch 945 MiB, Symfony 50 MiB, PostgreSQL 29 MiB. Elasticsearch yerel test heap'i 768 MiB idi; Coolify yapılandırmasında 1,5 GiB heap kullanılıyor.

## Henüz doğrulanamayanlar

- Orijinal üretim indeksinin gerçek `_mapping` çıktısıyla birebir eşitlik: çıktı paylaşılmadı. Mevcut mapping örneğe göre açık nested tanımlarla hazırlandı; gerçek export'u alan `--mapping` yolu birim testle doğrulandı.
- Ev sunucusunda Coolify deploy, tünel erişimi, 2.000 gerçek ürün ve Türkçe relevance hedefleri.
- FP32 / INT8 kalite ve hız karşılaştırması. Çalışan varsayılan FP32 ONNX'tir.

## Anahtarsız API ve Swagger doğrulaması

26 Eylül 2026 tarihinde yerel Docker ortamında güncel Search API ve embedding imajları yeniden oluşturuldu.

- PHPUnit: 6 test, 56 assertion; embedding şemasındaki public URL ve proxy'nin 422/429 durum kodlarını, gövdeyi ve Retry-After başlığını koruması dahil.
- Compose config, PHP syntax, Symfony DI container lint ve `git diff --check` başarılı.
- API anahtarı göndermeyen `scripts/smoke_test.py` baştan sona başarılı: ürün PUT/GET/DELETE, semantik/hibrit arama, indeksleme ve hata sonrası toparlanma.
- `/docs` tarayıcıda açıldı; arama örneği Swagger Execute üzerinden HTTP 200 ve bir ürün döndürdü.
- Swagger seçiminden Embedding API şeması başarıyla açıldı; sunucu adresi `/api/embedding`.
- İki OpenAPI JSON adresi ve embedding readiness proxy'si HTTP 200 döndürdü. Anahtarsız embedding POST proxy çağrısı gerçek modelle 384 boyutlu vektör döndürdü.
- Swagger UI kurulumu resmi dağıtım biçimini kullanır: https://swagger.io/docs/open-source-tools/swagger-ui/usage/installation/ . Tarayıcı JavaScript/CSS dosyalarını CDN'den yükler.

Bu bölümdeki sonuçlar yereldir; canlı Coolify deploy ve dış domain üzerinden erişim bu değişiklik kapsamında doğrulanmadı.

## GET endpoint değişikliği — 27 Eylül 2026

- Dış arama ve embedding proxy yolları GET kullanır; Swagger ve README URL parametrelerini gösterir. İç embedding POST yolu uzun doküman batch'leri için korunmuştur.
- Yerel PHPUnit: 9 test, 85 assertion başarılı. GET aramasında limit dönüşümü, marka/kategori filtreleri, geçersiz parametreler ve proxy üzerinden tekrarlanan `texts` parametrelerinin korunması kontrol edildi. Bağımlı HTTP servisleri mock kullanır.
- Mevcut embedding Docker imajında güncel kaynaklar salt okunur bağlanarak Python testleri çalıştırıldı: 3 başarılı; gerçek model testi seçilmedi. GET parametre doğrulaması, çoklu metin, OpenAPI GET sözleşmesi ve dahili POST desteği kontrol edildi.
- Canlı sunucuda veya yeniden deploy edilmiş uçtan uca ortamda doğrulama yapılmadı.
