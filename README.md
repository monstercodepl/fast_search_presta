# FastSearch - Zaawansowana Wyszukiwarka dla PrestaShop

Błyskawiczna wyszukiwarka dla sklepów z dużymi bazami produktów (100k+). Wykorzystuje indeksowanie FULLTEXT MySQL i zaawansowany system cache.

## Funkcje

- ⚡ **Ultraszybkie wyszukiwanie** - wyniki w < 100ms
- 🎨 **Nowoczesny interfejs** - responsive design z animacjami
- 🧠 **Inteligentne sugestie** - autocomplete z historią
- 📱 **Mobile-first** - zoptymalizowane dla urządzeń mobilnych
- 💾 **3-poziomowy cache** - Memory + File + Redis/Memcached
- 📊 **Szczegółowe statystyki** - analytics wyszukiwań
- 🛠️ **Auto-optymalizacja** - automatyczne dostrajanie

## Wymagania

### Minimalne:
- PrestaShop 1.7.0+
- PHP 7.1+
- MySQL 5.6+ z MyISAM
- 256MB RAM

### Zalecane:
- PrestaShop 1.7.8+
- PHP 8.0+
- MySQL 8.0+
- 512MB+ RAM
- SSD dla bazy danych

## Instalacja

### 1. Upload modułu
```bash
# Skopiuj katalog fastsearch/ do modules/
/modules/fastsearch/
```

### 2. Zainstaluj w PrestaShop
- Przejdź do **Moduły** → **Menedżer modułów**
- Znajdź "FastSearch" i kliknij **Zainstaluj**
- Kliknij **Konfiguruj** po instalacji

### 3. Pozycjonowanie
- Przejdź do **Projekt** → **Pozycje**
- Przeciągnij "FastSearch" do pozycji **displayTop**

## Konfiguracja

### Podstawowa konfiguracja
W panelu administracyjnym (**Moduły** → **FastSearch**):

- **Minimalna długość zapytania**: 2 znaki
- **Maksymalna liczba wyników**: 15
- **Pola wyszukiwania**: nazwa, opis, SKU, EAN13, tagi
- **Wyświetlanie**: obrazki, ceny, opisy, kategorie

### Optymalizacja MySQL
Dodaj do `my.cnf`:
```ini
[mysqld]
ft_min_word_len = 2
ft_max_word_len = 84
key_buffer_size = 256M
query_cache_size = 128M
```

### Cron Jobs (zalecane)
```bash
# Optymalizacja co godzinę
0 * * * * wget -q -O - "https://sklep.pl/module/fastsearch/cronoptimize?secure_key=KLUCZ" >/dev/null

# Przebudowa indeksu codziennie o 2:00
0 2 * * * wget -q -O - "https://sklep.pl/module/fastsearch/cronrebuild?secure_key=KLUCZ" >/dev/null
```

## Użycie

### Dla użytkowników
- Wpisz minimum 2 znaki w pole wyszukiwania
- Używaj strzałek ↑↓ do nawigacji
- Enter - wybór produktu
- Esc - zamknięcie wyników
- Kliknij mikrofon dla wyszukiwania głosowego

### Dla deweloperów

#### JavaScript API
```javascript
// Wyszukiwanie programowe
FastSearch.search('laptop gaming');

// Słuchanie eventów
FastSearch.on('searchCompleted', (data) => {
    console.log('Wyniki:', data.products.length);
});

// Czyszczenie cache
FastSearch.clearCache();
```

#### REST API
```http
GET /module/fastsearch/search?q=laptop&limit=10
```

Odpowiedź:
```json
{
  "products": [...],
  "total": 156,
  "execution_time": 45.2
}
```

## Struktura plików

```
fastsearch/
├── fastsearch.php                  # Główny moduł
├── config.xml                      # Konfiguracja
├── controllers/front/search.php    # Kontroler AJAX
├── classes/
│   ├── FastSearchOptimizer.php     # Optymalizator wydajności
│   └── FastSearchCache.php         # System cache
├── views/
│   ├── templates/hook/fastsearch.tpl
│   ├── css/fastsearch.css
│   └── js/fastsearch.js
└── README.md
```

## Optymalizacja

### Automatyczna (wbudowana)
- Optymalizacja indeksów FULLTEXT
- Czyszczenie wygasłych wpisów cache
- Monitoring wydajności
- Auto-cleanup starych danych

### Ręczna (panel admin)
- **Przebuduj indeks** - pełna przebudowa
- **Optymalizuj indeks** - szybka optymalizacja  
- **Wyczyść cache** - reset cache
- **Eksportuj statystyki** - dane do CSV

## Rozwiązywanie problemów

### Powolne wyszukiwanie
```sql
-- Sprawdź indeksy
SHOW INDEX FROM ps_fastsearch_index;

-- Optymalizuj tabelę
OPTIMIZE TABLE ps_fastsearch_index;
```

### Brak wyników
- Sprawdź czy indeks jest zbudowany
- Przebuduj indeks w panelu konfiguracji
- Sprawdź czy produkty są aktywne

### Błędy JavaScript
- Sprawdź konsolę przeglądarki (F12)
- Sprawdź czy pliki CSS/JS się ładują
- Sprawdź konfigurację AJAX endpoints

## Performance

### Typowe wyniki:
- **Wyszukiwanie**: 20-100ms dla 100k+ produktów
- **Cache hit rate**: 80-95%
- **Memory usage**: 20-50MB RAM
- **Disk usage**: 5-20MB cache

### Optymalne ustawienia:
- **Cache TTL**: 30 minut (1800s)
- **Memory limit**: 512MB PHP
- **Key buffer**: 256MB MySQL
- **Batch size**: 1000 produktów

## Wsparcie

### Logi i debugging
- Logi w `/var/log/prestashop/`
- Włącz tryb deweloperski w PrestaShop
- Sprawdź logi MySQL slow query
- Użyj narzędzi deweloperskich przeglądarki

### Często zadawane pytania

**Q: Czy moduł działa z PrestaShop 8.x?**  
A: Tak, moduł jest kompatybilny z PrestaShop 1.7.x i 8.x.

**Q: Czy mogę używać z InnoDB zamiast MyISAM?**  
A: MyISAM jest zalecane dla FULLTEXT, ale InnoDB również działa (MySQL 5.7+).

**Q: Jak zwiększyć wydajność?**  
A: Włącz Redis/Memcached, ustaw cron jobs, użyj SSD dla bazy danych.

**Q: Czy moduł wpływa na SEO?**  
A: Nie, moduł nie wpływa na SEO. Używa AJAX tylko dla wyszukiwania.

## Licencja

MIT License - Zobacz plik LICENSE dla szczegółów.

## Changelog

### v1.0.0 (2025-05-25)
- Pierwsza stabilna wersja
- FULLTEXT search z MySQL
- 3-poziomowy system cache
- Responsive UI z animacjami
- Voice search support
- Zaawansowane analytics
- Auto-optimization tools