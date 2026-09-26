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
