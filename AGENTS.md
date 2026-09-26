# Proje çalışma kuralları

- Deployment ortamı Coolify'dır. Kullanıcıya Coolify mı yoksa doğrudan Docker Compose mu kullandığını tekrar sorma.
- Geliştirme ve yapılandırma değişikliklerini bu repoda yap. Kullanıcıdan aynı değişiklikleri sunucuda veya Coolify editöründe elle yapmasını isteme.
- Çalışma akışı: asistan kodu ve ilgili dokümanları günceller, uygun yerel doğrulamayı yapar; kullanıcı Git push yapar ve Coolify'da Deploy'a basar.
- Kullanıcı ayrıca istemedikçe Git push, uzaktan sunucu değişikliği veya deploy yapma.
- Mevcut bağlamdan belli olan çalışma akışı için tekrar onay isteme. Gerçekten eksik bilgi gerekiyorsa yalnızca o bilgiyi sor.
- Elasticsearch yerel ağ erişimi Compose port eşlemesiyle yönetilir. Varsayılan sunucu iç IP'si `192.168.1.105` kabul edilmiştir; gerçek IP farklıysa `ELASTICSEARCH_BIND_IP` ile değiştirilebilir.
- Canlı sunucuda doğrulanmayan sonuçları yerelde doğrulanmış gibi raporlama.
