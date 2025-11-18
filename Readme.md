# Ennakointi-node-rest

Tämä projekti sisältää PHP- ja Node.js-ohjelmia, jotka hakevat Tilastokeskuksen rajapinnoista (API) ajankohtaista tietoa Hämeen alueen väestöstä, työpaikoista, valmistuneista, työttömistä, avoimista työpaikoista, taloudesta, T&K-toiminnasta, työllisyysasteesta ja vieraskielisistä. Ohjelmat tallentavat tiedot MySQL-tietokantaan jatkokäyttöä varten.

## Mitä ohjelmat tekevät?
- **fetchVaesto_11re.php**: Hakee väestötiedot Tilastokeskuksen API:sta ja tallentaa ne Asukasmaara-tauluun.
- **fetchVierasKieliset_11c4.php**: Hakee vieraskielisten ja ulkomaalaisten opiskelijoiden määrät ja tallentaa ne Vieraskieliset-tauluun.
- **fetchTyottomat_12r5.php**: Hakee työttömien määrät ja tallentaa ne tietokantaan.
- **fetchAlueenTyopaikat_115h.php**: Hakee alueen työpaikkatiedot ja tallentaa ne tietokantaan.
- **fetchavoimetpaikat12tw.php**: Hakee avoimet työpaikat ja tallentaa ne tietokantaan.
- **fetchValmistuneet_12bs.php**: Hakee valmistuneiden määrät ja tallentaa ne Opiskelijat-tauluun.
- **fetchTalous_11vs.php**: Hakee taloustiedot (aluetalous) ja tallentaa ne Talous-tauluun.
- **fetchTkitoiminta_13tn.php**: Hakee tutkimus- ja kehitystoiminnan (T&K) tiedot ja tallentaa ne Tki-tauluun.
- **fetchTyollisyysaste_135z.php**: Hakee työllisyysasteen tiedot ja tallentaa ne tietokantaan.
- **db.php**: Tietokantayhteyden konfiguraatio ja PDO-yhteys.
- **log_script.php**: Lokitiedostojen hallinta ja virheenseuranta.

Kaikki ohjelmat lukevat .json-muotoisen kyselytiedoston, lähettävät sen Tilastokeskuksen API:lle, käsittelevät JSON-stat2-vastauksen ja päivittävät tietokannan.

## Projektin tiedostorakenne
- **\*.json**: API-kyselytiedostot (esim. `_vaesto_11re.json`, `_tkitoiminta_13tn.json`)
- **\*_response.json**: Esimerkkivastaukset Tilastokeskuksen API:sta
- **vipunen-test.py**: Python-testiohjelma Vipunen-rajapinnan testaukseen
- **backup/**: Vanhat versiot ja varmuuskopiot kehitysprosessista
- **.cpanel.yml**: cPanel-palvelimen konfiguraatio
- **package.json**: Node.js-riippuvuudet (jos tarvitaan)

## Ajastettu ajo (crontab)
Ohjelmat voidaan ajastaa Linux-palvelimella ajettavaksi automaattisesti esimerkiksi kerran päivässä crontabilla.

1. Avaa crontab muokattavaksi:
   ```
   crontab -e
   ```
2. Lisää rivi jokaista ajettavaa ohjelmaa varten, esim. joka päivä klo 04:07:
   ```
   7 4 * * * php /public_html/cgi-bin/fetchVaesto_11re.php
   8 4 * * * php /public_html/cgi-bin/fetchVierasKieliset_11c4.php
   9 4 * * * php /public_html/cgi-bin/fetchTyottomat_12r5.php
   10 4 * * * php /public_html/cgi-bin/fetchAlueenTyopaikat_115h.php
   11 4 * * * php /public_html/cgi-bin/fetchavoimetpaikat12tw.php
   12 4 * * * php /public_html/cgi-bin/fetchValmistuneet_12bs.php
   13 4 * * * php /public_html/cgi-bin/fetchTalous_11vs.php
   14 4 * * * php /public_html/cgi-bin/fetchTkitoiminta_13tn.php
   15 4 * * * php /public_html/cgi-bin/fetchTyollisyysaste_135z.php
   ```
   Vaihda `/polku/projektiin/` tarvittaessa oikeaksi hakemistopoluksi.
3.1 Muokkaa tiedostoa: i (insert)
3. Tallenna ja sulje crontab (esim. Esc , :wq (Write-Quit) , Enter .

## Vaatimukset
- PHP (vähintään 7.x)
- MySQL-tietokanta
- Yhteys Tilastokeskuksen PxWeb API:in
- Oikeat tietokantataulut ja -rakenne (katso kunkin skriptin kommentit)

## Ominaisuudet
- **Automaattinen duplikaattien esto**: Skriptit tarkistavat olemassa olevat tietueet ja päivittävät niitä uusilla arvoilla
- **Virheenkäsittely**: Kattava virhelogiikka API-yhteysongelmien varalta
- **Aikaleiman seuranta**: Jokainen tietue sisältää `stat_latest` ja `timestamp` kentät datan ajantasaisuuden seuraamiseksi
- **Retry-mekanismi**: Automaattinen uudelleenyritys puuttuvien vuosien tapauksessa (esim. T&K-data)
- **Dynaaminen kenttien kartoitus**: Mukautuu API:n muuttuviin tietorakenteisiin

## Kehitysympäristön ja versionhallinnan työkalut

- **GitHub Desktop**: Helppo graafinen käyttöliittymä versionhallintaan ja projektin synkronointiin GitHubiin.
- **Visual Studio Code (VS Code)**: Suositeltu editori PHP/Node.js-kehitykseen, tukee mm. etäyhteyksiä ja versionhallintaa.
- **SSH-yhteys palvelimelle**: Tarvitset SSH-yhteyden (esim. PuTTY, OpenSSH, VS Code Remote SSH) ohjelmien siirtoon ja ajamiseen palvelimella.
- **Palvelimella** phpMyAdmin: Tietokannan hallintaan ja tarkasteluun.
- **Linux-palvelin Domaintohelli** : Ajastettu ajo (crontab) ja PHP-ohjelmien suoritus.

### Esimerkkityökalujen asennus
- [GitHub Desktop](https://desktop.github.com/)
- [Visual Studio Code](https://code.visualstudio.com/)
- [PuTTY (Windows SSH)](https://www.putty.org/)

### Vinkit
- Pidä projektin tiedostot versionhallinnassa (GitHub), jotta muutokset ja varmuuskopiot säilyvät.
- Testaa skriptit ensin paikallisesti, ennen kuin ajastat ne tuotantopalvelimelle.

## Yhteystiedot
Lisätietoja tilasto-rajapinnoista: [Tilastokeskus PxWeb API](https://stat.fi/tilastot/pxweb)
