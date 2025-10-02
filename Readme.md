# Ennakointi-node-rest

Tämä projekti sisältää PHP- ja Node.js-ohjelmia, jotka hakevat Tilastokeskuksen rajapinnoista (API) ajankohtaista tietoa Hämeen alueen väestöstä, työpaikoista, valmistuneista, työttömistä, avoimista työpaikoista ja vieraskielisistä. Ohjelmat tallentavat tiedot MySQL-tietokantaan jatkokäyttöä varten.

## Mitä ohjelmat tekevät?
- **fetchVaesto_11re.php**: Hakee väestötiedot Tilastokeskuksen API:sta ja tallentaa ne Asukasmaara-tauluun.
- **fetchVierasKieliset_11c4.php**: Hakee vieraskielisten ja ulkomaalaisten opiskelijoiden määrät ja tallentaa ne Vieraskieliset-tauluun.
- **fetchTyottomat_12r5.php**: Hakee työttömien määrät ja tallentaa ne tietokantaan.
- **fetchAlueenTyopaikat_115h.php**: Hakee alueen työpaikkatiedot ja tallentaa ne tietokantaan.
- **fetchavoimetpaikat12tw.php**: Hakee avoimet työpaikat ja tallentaa ne tietokantaan.
- **fetchValmistuneet_12bs.php**: Hakee valmistuneiden määrät ja tallentaa ne tietokantaan.

Kaikki ohjelmat lukevat .json-muotoisen kyselytiedoston, lähettävät sen Tilastokeskuksen API:lle, käsittelevät JSON-stat2-vastauksen ja päivittävät tietokannan.

## Ajastettu ajo (crontab)
Ohjelmat voidaan ajastaa Linux-palvelimella ajettavaksi automaattisesti esimerkiksi kerran päivässä crontabilla.

1. Avaa crontab muokattavaksi:
   ```
   crontab -e
   ```
2. Lisää rivi jokaista ajettavaa ohjelmaa varten, esim. joka päivä klo 04:07:
   ```
   7 4 * * * php /polku/projektiin/fetchVaesto_11re.php
   7 4 * * * php /polku/projektiin/fetchVierasKieliset_11c4.php
   7 4 * * * php /polku/projektiin/fetchTyottomat_12r5.php
   7 4 * * * php /polku/projektiin/fetchAlueenTyopaikat_115h.php
   7 4 * * * php /polku/projektiin/fetchavoimetpaikat12tw.php
   7 4 * * * php /polku/projektiin/fetchValmistuneet_12bs.php
   ```
   Vaihda `/polku/projektiin/` oikeaksi hakemistopoluksi.

3. Tallenna ja sulje crontab (esim. nano: Ctrl+O, Enter, Ctrl+X).

## Vaatimukset
- PHP (vähintään 7.x)
- MySQL-tietokanta
- Yhteys Tilastokeskuksen PxWeb API:in
- Oikeat tietokantataulut ja -rakenne (katso kunkin skriptin kommentit)

## Yhteystiedot
Lisätietoja: [Tilastokeskus PxWeb API](https://stat.fi/tilastot/pxweb)
