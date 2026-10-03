# RomCommerce
## Pachet de funcționalități pentru magazinele românești

[![Versiune WordPress.org](https://img.shields.io/wordpress/plugin/v/romcommerce.svg?logo=wordpress&logoColor=white&label=wp.org)](https://ro.wordpress.org/plugins/romcommerce/)
[![Testat până la](https://img.shields.io/wordpress/plugin/tested/romcommerce.svg?label=testat%20p%C3%A2n%C4%83%20la)](https://ro.wordpress.org/plugins/romcommerce/)
[![Descărcări](https://img.shields.io/wordpress/plugin/dt/romcommerce.svg?label=desc%C4%83rc%C4%83ri)](https://ro.wordpress.org/plugins/romcommerce/)
[![Rating](https://img.shields.io/wordpress/plugin/rating/romcommerce.svg?label=rating)](https://ro.wordpress.org/plugins/romcommerce/#reviews)
[![CI](https://github.com/rwkyyy/romcommerce/actions/workflows/ci.yml/badge.svg)](https://github.com/rwkyyy/romcommerce/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white)](composer.json)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0%2B-7f54b3?logo=woocommerce&logoColor=white)](https://ro.wordpress.org/plugins/romcommerce/)
[![HPOS](https://img.shields.io/badge/HPOS-compatibil-4c1)](romcommerce.php)
[![Licență](https://img.shields.io/badge/licen%C8%9B%C4%83-GPL--2.0--or--later-blue)](LICENSE)

Extensie modulară pentru WooCommerce, gândită pentru piața românească: în loc să instalezi zeci de
pluginuri mici cu un singur scop, activezi doar modulele de care ai nevoie dintr-un singur sistem
coerent, cu unelte de conformitate legală, localizare checkout și integrări specifice pieței românești.

Site oficial: **[romcommerce.ro](https://romcommerce.ro)**.

Acesta este repository-ul de dezvoltare al versiunii **Lite**. Pluginul este publicat pe WordPress.org
la **[ro.wordpress.org/plugins/romcommerce](https://ro.wordpress.org/plugins/romcommerce/)**. Acolo
găsiți versiunea stabilă de instalat. Descrierea completă și changelog-ul publicate pe WordPress.org se
află în [`readme.txt`](readme.txt).

RomCommerce Lite este complet funcțional de sine stătător: niciun modul din Lite nu e o versiune
trunchiată care așteaptă un upgrade.

## Funcționalități

**Conformitate legală**

* **Pictograma SAL**: afișează pictograma oficială ANPC pe prima pagină, cu link către platforma SAL.
* **Notificarea UE privind garanția legală + eticheta EU GARAN**: notificarea armonizată UE privind
  garanția legală de conformitate (obligatorie în toată UE din 27 septembrie 2026), plus eticheta
  opțională EU GARAN pentru produsele cu garanție de durabilitate din partea producătorului.
* **Istoric de preț (Omnibus)**: înregistrează fiecare schimbare de preț și afișează cel mai mic preț
  din ultimele 30 de zile lângă un preț redus.
* **Câmpuri de facturare PF/PJ**: câmpuri de facturare persoană fizică/juridică la checkout, scrise
  acolo unde le citesc pluginurile românești de facturare (SmartBill, Oblio, EasySales).
* **Validare CUI/CIF**: verificare de format și cifră de control la checkout.
* **Detector de comenzi posibil legate (MAOF)**: semnalează comenzile posibil legate ale unui client și
  comenzile active duplicate, pentru verificare manuală (doar suport decizional, nu blochează sau
  anulează nimic automat).

**Checkout și facturare**

* **Județe și coduri poștale**: date de județe plus validarea codului poștal (6 cifre) la checkout.
* **Ascunde câmpul de cupon la checkout**.
* **Adrese multiple de livrare**: clienții pot salva mai multe adrese de livrare în cont.

**Catalog și merchandising**

* **Bară de transport gratuit**: indicator „mai adaugă X pentru transport gratuit”.
* **Sincronizare categorie de reduceri**: menține o categorie populată automat cu produsele la reducere.
* **Produse similare**: control pe bază de reguli asupra blocului de produse similare.
* **Pagină de recenzii multi-produs**: o singură pagină pe care clientul recenzează toate produsele
  dintr-o comandă anterioară.
* **Buton flotant WhatsApp / contact**: buton multi-canal, cu link contextual către WhatsApp.
* **Golire coș**: acțiune „golește coșul” cu un click.
* **Curățare cupoane**: mută automat cupoanele expirate la coș de gunoi, cu opțiune de golire automată
  a coșului de gunoi.

**Admin și branding**

* **Branding admin și login**: sigla site-ului pe ecranul de autentificare și o culoare de brand
  aplicată în wp-admin, pe ecranul de autentificare și pe butoane.

Lista completă e în [`readme.txt`](readme.txt), secțiunea `== Description ==`.

## Lite vs. Pro

**RomCommerce Pro** (licențiat separat, acces plătit) adaugă module suplimentare:

* Validare telefon (RO/internațional)
* Verificare email la checkout
* Confirmare comandă cu un click (email + cod QR)
* Plăți și validare prin cod QR
* Taxe de livrare urgentă
* Autocompletare adresă din baza de date de coduri poștale
* Nomenclator curieri
* Rezervare pentru ridicare din magazin
* Depozite și prețuri personalizate
* Ambalaj cadou
* Insignă SEAP
* Stratul de risc MAOF: urmărire comenzi neridicate, coduri de motiv pentru retur, flux de excepție
* Checklist de conformitate

Licențiere pe site, nu per-modul.

Detalii și prețuri: **[romcommerce.ro/pro](https://romcommerce.ro/pro)**.

## Comparație

O comparație independentă, funcționalitate cu funcționalitate, cu alte soluții din ecosistemul
WooCommerce pentru piața românească este disponibilă la **[romcommerce.ro/compara](https://romcommerce.ro/compara)**.

## Cerințe

* PHP 7.4+
* WordPress 6.0+
* WooCommerce 7.0+

## Dezvoltare

```bash
composer install
composer phpcs      # coding standards (WordPress-Extra)
composer phpstan    # analiză statică (nivel 5)
composer test       # PHPUnit
```

`composer.json` este strict pentru dezvoltare: `vendor/` și `composer.lock` nu sunt niciodată livrate
(vezi `.distignore`). Pluginul livrat nu are niciun pas de build: autoloaderul PSR-4 e scris de mână,
fără Composer sau Node în runtime.

## Testare / CI

La fiecare push sau pull request pe `main`, [GitHub Actions](.github/workflows/ci.yml) rulează:

* lint PHP pe PHP 7.4, 8.0, 8.1, 8.2 și 8.3;
* PHPCS (WordPress Coding Standards) și PHPStan;
* suita PHPUnit (Brain Monkey/Mockery, fără o instalare WordPress reală), pe PHP 8.1, 8.2 și 8.3.

Comportamentul real față de WordPress/WooCommerce e verificat separat, pe un mediu de staging real.
Suita PHPUnit acoperă doar logica pură.

## Suport și contribuții

Probleme și sugestii: [issue tracker-ul de pe GitHub](https://github.com/rwkyyy/romcommerce/issues) sau
forumul de suport de pe [pagina pluginului](https://ro.wordpress.org/plugins/romcommerce/#reviews).
Suport community/best-effort, fără SLA garantat.

## Licență

GPL-2.0-or-later. Vezi [LICENSE](LICENSE).
