### Yksinkertainen Vipusen rajapinnasta tietoa csv-tiedostoon noutava koodaus-Tiedostopolkuesimerkki
### 27.9.2022 CSC
### Huom. kaikki  ### merkit sisältävät rivit ovat selityksiä varten, joten ne voi poistaa tiedostosta huoletta

### VALMISTELUT
### Haetaan tarvittavat kirjastot
import requests, time, sys
from pandas import DataFrame

### Asetetaan pohjatietojatietoja
limit=5000

### muiden tietojoukkojen nimet löytyvät rajapinnasta: https://api.vipunen.fi/api/resources/
tietojoukko="alayksikkokoodisto" 
max_latausyritykset = 3

### Pohja rajapinnan urlille, johon myöhemmin lisätään offset arvo dynaamisesti. Limit-muuttuja on kokonaisluku (integer) joten se muunnetaan tekstiksi
url_pohja="https://api.vipunen.fi/api/resources/"+tietojoukko+"/data?limit="+str(limit)+"&offset="
### 5000  limit-arvolla 3. kierroksen osoite olisi siten https://api.vipunen.fi/api/resources/"+tietojoukko+"/data?limit=5000&offset=10000

### Määritetään polku rajapinnan maksimiobjektien lukumäärän tiedustelua varten
max_rivimaara_url="https://api.vipunen.fi/api/resources/"+tietojoukko+"/data/count"

### Tiedostopolkuesimerkki csv-tiedostolle Windows
### Voi määrittää myös ilman muuttujia esim. d:/temp/20210414_alayksikkokoodisto.csv
tiedosto="c:/temp/"+tietojoukko+".csv"

### Tiedostopolkuesimerkki csv-tiedostolle Linux / Mac
#tiedosto="/tmp/python/"+tietojoukko+".csv"

### Asetetaan rajapintakyselyä varten http-otsikkotiedot
otsikkotiedot = {"Content-Type": "application/json"}
otsikkotiedot["Accept"] = "application/json"
### Hyvä käytäntö on kertoa mikä organisaatio tai tietoja noutaa esim. "1.2.246.562.10.2013112012294919827487.Vipunen"
otsikkotiedot["Caller-Id"] = "organisaatio_oid.organisaationimi"

### Haetaan maksimirivimäärä max_rivimaara-muuttujaan, jota hyödynnetään myöhemmin
max_rivimaara=requests.get(max_rivimaara_url,headers=otsikkotiedot).json()

### Määritetään laskurin alkuarvo, jota käytetään offset-arvon määrittämiseen
laskuri=0

###############################################
### Varsinainen rajapintakysely alkaa tästä ###
###############################################

### Haetaan rajapinnasta aineistoa, kunnes saadaan kaikki talletettua tiedostoon
while laskuri <=max_rivimaara:

    ### Alustetaan ja nollataan apidata, johon rajapinnasta noudettu data väliaikaisesti talletetaan
    apidata=[]

    ### Muodostetaan API-pyynnön dynaaminen osoite. laskuri-muuttuja on kokonaisluku (integer) joten se muunnetaan tekstiksi
    url=url_pohja+str(laskuri)

    ### Virheenhallinnan alustus
    latausyritys = 0
    lataustila="lataa"
    
    ### API-rajapintakutsu, jonka vastaus talletetaan muuttujaan vastaus
    ### 
    while latausyritys < max_latausyritykset and lataustila=="lataa":
        try:
            vastaus = requests.get(url, headers=otsikkotiedot).json()
            print(".")
            lataustila="valmis"
        except ValueError:
            latausyritys=+1
            print(latausyritys, ". uusi latausyritys")
            time.sleep(1)
            ### API-rajapintakutsu, jonka vastaus talletetaan muuttujaan vastaus
    if latausyritys == max_latausyritykset and lataustila=="lataa":
        print("Lataus epäonnistui. Offset-parametrin arvo oli:", laskuri)
        sys.exit(0)
        
    ### Talletetaan aineisto DataFrame muotoiseksi helpon jatkokäsittelyn mahdollistamiseksi
    apidata=DataFrame(vastaus)

    ### Write_mode-muuttujalla määritetään, että 1. kierroksella luodaan uusi tiedosto tai
    ### olemassa olemassaoleva ylikirjoitetaan. Jatkokierroksilla dataa vain lisätään tiedostoon
    ### Otsikkorivi kirjoitetaan vain ensimäisellä kierroksella
    if laskuri == 0:
        write_mode="w" ### write
        otsikkorivi=True
    else:
        write_mode="a" ### append
        otsikkorivi=False
    ### Data kirjoitetaan tai lisätään csv-tiedostoon jokaisella kyselykierroksella, ettei kaikkea haettua dataa käsitellä koneen muistissa.
    ### HUOM! Joidenkin rajapintojen datasta muodostetut tiedostot voivat kasvaa erittäin suuriksi
    ### Tiedoston asetusten määrittely. Lisätietoja löytyy internetistä esim. hakusanoilla "pandas" + "dataframe"
    apidata.to_csv(path_or_buf=tiedosto, sep=";", na_rep="", header=otsikkorivi, index=False, mode=write_mode, encoding="utf-8", quoting=0, quotechar='"', escapechar="$",
   ### Muokkaa columns-listan sisältöä rajapinnan haluamasi mukaan
                columns=["tilastovuosi","organisaatioNimiFi","organisaatioNykyinenKoodi","oppilaitostyyppiFi",
                "alayksikkoKoodi","alayksikkoNimiFi"])
    ### Laskuriin lisätään haettavan tiedon määrä, jotta offset arvo muuttuu kun url seuraavan kerran määritetään luupissa
    laskuri=laskuri+limit

    ### Alla olevilla komennoilla seurataan latauksen edistymistä, joten ne voi automatisoinneista poistaa kokonaan
    if laskuri > max_rivimaara:
        print(max_rivimaara)
    else:
        print(laskuri)

### Tulostetaan loppuun vielä tiedoksi, että datan nouto on valmis
print("Valmis, ", max_rivimaara, " riviä haettu")
