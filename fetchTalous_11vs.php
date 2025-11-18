<?php
/**
 * TALOUSTIETOJEN HAKU TILASTOKESKUKSESTA
 * 
 * Tämä ohjelma hakee Tilastokeskuksen API:sta talousindikaattorit ja tallentaa ne MySQL-tietokantaan tauluun Talous.
 * Ohjelma lukee JSON-tiedoston, lähettää POST-pyynnön Tilastokeskuksen API:in, parsii vastauksen ja tallentaa tiedot tietokantaan.
 * 
 * Haetut tiedot:
 * - Kuluttajaluottamusindikaattori (CCI_A1)
 * - Oman talouden tila (CCI_B1) 
 * - Kuluttajahintojen kehitys (CCI_B5)
 * - Työttömyyden uhka (CCI_B8)
 * 
 * Tietokantataulu Talous rakenne:
 *   1 stat_code varchar(10) - Aluekoodi
 *   2 kuukausi varchar(10) - Kuukausi (YYYY-MM muodossa)
 *   3 kuluttajienluottamus decimal(10,0) - Kuluttajien luottamus
 *   4 omatalous decimal(10,0) - Oman talouden arviointi
 *   5 kuluttajahinnat decimal(10,0) - Kuluttajahintojen odotukset
 *   6 tyottomyydenuhka decimal(10,0) - Työttömyyden uhka
 *   7 timestamp timestamp - Päivitysaika
 */

// Sisällytä tietokantayhteyden konfiguraatio
require_once __DIR__ . '/db.php';

// API-osoite ja kyselytiedosto
$apiUrl = 'https://pxdata.stat.fi/PxWeb/api/v1/fi/StatFin/kbar/statfin_kbar_pxt_11vs.px';
$jsonFile = '_talous_11vs.json';

/**
 * Hakee viimeisimmät kuukaudet
 * 
 * @param int $n Kuukausien määrä (oletus 12)
 * @return array Kuukausien lista YYYYMM-muodossa
 */
function getRecentMonths($n = 12) {
    $months = [];
    $base = strtotime(date('Y-m-01')); // Kuluvan kuukauden ensimmäinen päivä
    
    // Generoi viimeiset n kuukautta taaksepäin
    for ($i = 1; $i <= $n; $i++) {
        $months[] = date('Y', strtotime("-$i month", $base)) . 'M' . date('m', strtotime("-$i month", $base));
    }
    
    return array_reverse($months); // Palauta vanhimmasta uusimpaan
}

/**
 * Hakee datan Tilastokeskuksen API:sta
 * 
 * @param string $apiUrl API:n osoite
 * @param array $postData Lähetettävä JSON-data
 * @return array Parsittu JSON-vastaus
 * @throws Exception Jos API-kutsu epäonnistuu
 */
function fetchData($apiUrl, $postData) {
    // HTTP POST -pyynnön asetukset
    $options = [
        'http' => [
            'header'  => "Content-Type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($postData),
            'timeout' => 10 // 10 sekunnin timeout
        ]
    ];
    
    $context  = stream_context_create($options);
    $result = file_get_contents($apiUrl, false, $context);
    
    if ($result === FALSE) {
        throw new Exception("Virhe datan haussa API:sta");
    }
    
    return json_decode($result, true);
}

/**
 * Kirjoittaa viestin lokitiedostoon aikaleimalla
 * 
 * @param string $message Lokiviesti
 */
function logMessage($message) {
    $logFile = 'fetch-data.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

/**
 * Pääfunktio - suorittaa datan haun ja tallennuksen
 */
function main() {
    global $dsn, $db_user, $db_pass, $apiUrl, $jsonFile;
    
    try {
        // Lue JSON-kyselytiedosto
        $jsonArray = json_decode(file_get_contents($jsonFile), true);
        
        // Päivitä Kuukausi-arvot viimeisimmille 12 kuukaudelle
        $recentMonths = getRecentMonths(12);
        foreach ($jsonArray['query'] as &$query) {
            if ($query['code'] === 'Kuukausi') {
                $query['selection']['values'] = $recentMonths;
            }
        }
        unset($query); // Poista referenssi
        
        // Hae data API:sta
        $data = fetchData($apiUrl, $jsonArray);
        
        // Pura dimensioiden tiedot API-vastauksesta
        $alueIndex = $data['dimension']['Alue']['category']['index'];
        $alueLabels = $data['dimension']['Alue']['category']['label'];
        $kuukausiIndex = $data['dimension']['Kuukausi']['category']['index'];
        $kuukausiLabels = $data['dimension']['Kuukausi']['category']['label'];
        $tiedotIndex = $data['dimension']['Tiedot']['category']['index'];
        $tiedotLabels = $data['dimension']['Tiedot']['category']['label'];
        
        // Hae dimensioiden avaimet ja laske määrät
        $alueKeys = array_keys($alueIndex);
        $kuukausiKeys = array_keys($kuukausiIndex);
        $tiedotKeys = array_keys($tiedotIndex);
        
        $alueCount = count($alueKeys);
        $kuukausiCount = count($kuukausiKeys);
        $tiedotCount = count($tiedotKeys);
        
        // Luo tietokantayhteys
        $pdo = new PDO($dsn, $db_user, $db_pass, [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"]);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Käy läpi kaikki alueet, kuukaudet ja tiedot
        // value-taulukko on järjestyksessä: for each Alue, Kuukausi, Tiedot
        for ($i = 0; $i < $alueCount; $i++) {
            $alueId = trim($alueKeys[$i]);
            
            for ($j = 0; $j < $kuukausiCount; $j++) {
                $kuukausi = trim($kuukausiKeys[$j]);
                
                // Alusta taloustietojen muuttujat
                $kuluttajienluottamus = null;
                $omatalous = null;
                $kuluttajahinnat = null;
                $tyottomyydenuhka = null;
                
                // Käy läpi kaikki tietokentät tälle alueelle ja kuukaudelle
                for ($k = 0; $k < $tiedotCount; $k++) {
                    // Laske indeksi value-taulukossa
                    $offset = $i * $kuukausiCount * $tiedotCount + $j * $tiedotCount + $k;
                    $tiedotKey = $tiedotKeys[$k];
                    $maara = isset($data['value'][$offset]) ? $data['value'][$offset] : null;
                    
                    // Kartoita API:n kentät tietokantakentiksi
                    switch ($tiedotKey) {
                        case 'CCI_A1': $kuluttajienluottamus = $maara; break; // Kuluttajaluottamus
                        case 'CCI_B1': $omatalous = $maara; break;            // Oma talous
                        case 'CCI_B5': $kuluttajahinnat = $maara; break;      // Kuluttajahinnat
                        case 'CCI_B8': $tyottomyydenuhka = $maara; break;     // Työttömyyden uhka
                    }
                }
                
                // Jos kaikki arvot null, älä tallenna tyhjää riviä
                if ($kuluttajienluottamus === null && $omatalous === null && $kuluttajahinnat === null && $tyottomyydenuhka === null) {
                    continue;
                }
                
                // Tallenna tietokantaan (päivitä jos löytyy, lisää jos ei löydy)
                $now = date('Y-m-d H:i:s');
                $insertQuery = "INSERT INTO Talous (stat_code, kuukausi, kuluttajienluottamus, omatalous, kuluttajahinnat, tyottomyydenuhka, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?)\n" .
                    "ON DUPLICATE KEY UPDATE kuluttajienluottamus=VALUES(kuluttajienluottamus), omatalous=VALUES(omatalous), kuluttajahinnat=VALUES(kuluttajahinnat), tyottomyydenuhka=VALUES(tyottomyydenuhka), timestamp=VALUES(timestamp)";
                $insertStmt = $pdo->prepare($insertQuery);
                $insertStmt->execute([$alueId, $kuukausi, $kuluttajienluottamus, $omatalous, $kuluttajahinnat, $tyottomyydenuhka, $now]);
            }
        }
        
        // Kirjaa onnistunut suoritus lokiin
        logMessage("Talous haettu ja tallennettu tietokantaan onnistuneesti.");
        
    } catch (Exception $e) {
        // Kirjaa virhe lokiin ja näytä virheviesti
        logMessage("Talous Virhe: " . $e->getMessage());
        echo "Virhe: " . $e->getMessage() . "\n";
    }
}

// Suorita ohjelma
main();
