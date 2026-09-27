# Proje çalışma kuralları

- Deployment ortamı Coolify'dır. Kullanıcıya Coolify mı yoksa doğrudan Docker Compose mu kullandığını tekrar sorma.
- Geliştirme ve yapılandırma değişikliklerini bu repoda yap. Kullanıcıdan aynı değişiklikleri sunucuda veya Coolify editöründe elle yapmasını isteme.
- Çalışma akışı: asistan kodu ve ilgili dokümanları günceller, uygun yerel doğrulamayı yapar; kullanıcı Git push yapar ve Coolify'da Deploy'a basar.
- Kullanıcı ayrıca istemedikçe Git push, uzaktan sunucu değişikliği veya deploy yapma.
- Mevcut bağlamdan belli olan çalışma akışı için tekrar onay isteme. Gerçekten eksik bilgi gerekiyorsa yalnızca o bilgiyi sor.
- Elasticsearch yerel ağ erişimi Compose port eşlemesiyle yönetilir. Varsayılan sunucu iç IP'si `192.168.1.105` kabul edilmiştir; gerçek IP farklıysa `ELASTICSEARCH_BIND_IP` ile değiştirilebilir.
- Canlı sunucuda doğrulanmayan sonuçları yerelde doğrulanmış gibi raporlama.
- Aksi belirtilmedikçe yalnızca doğrudan sorulan soruya odaklan, kısa, net ve sade yanıt ver.
- Aksi belirtilmedikçe kurulum ve operasyonel süreçlerde tüm adımları tek seferde verme; en fazla 1-2 adım verip kullanıcının tamamlamasını bekle.
- Aksi belirtilmedikçe teorik arka plan veya gereksiz dolgu açıklamaları üretme.

## Kod biçimlendirme

- Kod yazmadan veya değiştirmeden önce kökteki `.editorconfig` dosyasını oku;
  genel kuralları ve değiştirdiğin dosya türüne uyan tüm bölümleri uygula.
- `.editorconfig`, kullanıcının PhpStorm biçimlendirme tercihlerinin kaynağıdır.
  `ij_*` ayarlarını da dikkate al; bunları varsayılan bir kod stiliyle değiştirme.
- Genel ayarlar UTF-8, LF, 4 boşluk girinti ve 120 karakter satır uzunluğu hedefidir.
  Dosya türüne özel girinti ve satır kaydırma ayarları önceliklidir.
  `insert_final_newline = false` ayarına uy; dosya sonuna otomatik yeni satır ekleme.
- PHP sınıf ve metot açılış süslü parantezlerini yeni satıra,
  kontrol bloklarının açılış süslü parantezlerini aynı satıra yerleştir.
  Diğer PHP ayrıntıları için `.editorconfig` içindeki PHP bölümünü esas al.
- Biçimlendirmeyi yeni veya değiştirilen kodla sınırla; ilgisiz dosyaları topluca biçimlendirme.
- Değişiklikleri teslim etmeden önce ilgili biçim kurallarına uyumu kontrol et.
  Otomatik biçimlendirici kullanılıyorsa bu ayarlarla uyumunu doğrula;
  desteklemediği `ij_*` kurallarının otomatik doğrulandığını varsayma.