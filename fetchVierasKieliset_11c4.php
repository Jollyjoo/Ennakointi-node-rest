<?php

// tuotannossa: Aja tämä tiedosto esim. crontabilla säännöllisesti.
// Tiedosto on cgi-bin kansiossa palvelimella
// Tilasto päivitetään kantaan automaattisesti, kun Tilastokeskus julkaisee uuden tilaston.
// Tilasto julkaistaan yleensä 25. päivänä kuukaudessa, joten voit ajastaa tämän ohjelman ajettavaksi esim. 26. päivä kuukaudessa.

// Tämä ohjelma hakee Tilastokeskuksen API:sta vieraskielisten määrät ja tallentaa ne MySQL-tietokantaan tauluun 'Vieraskieliset'.
// Ohjelma lukee JSON-tiedoston, lähettää POST-pyynnön Tilastokeskuksen API:in, parsii vastauksen ja tallentaa tiedot tietokantaan.
// Ohjelma yrittää hakea tilastokeskukselta viimeisimmät kolme vuotta, ja jos API palauttaa virheen (tilastoa ei vielä tullut), se pudottaa viimeisimmän vuoden ja yrittää uudelleen.

// laita ohjelma Linuxille ajastukseen crontabilla
// Crontab -e 
// i
// muokkaa crontabia, lisää rivi....
// esim:
// 7 4 * * *  php /home/catbxjbt/public_html/cgi-bin/fetchValmistuneet_12bs.php
// 7 4 * * *  php /home/catbxjbt/public_html/cgi-bin/fetchAvoimetPaikat_12tw.php
// tallenna ja sulje (ESC, :wq, ENTER)

/*
Tietokantataulu Vieraskieliset rakenne:
    1 Kunta_ID int(11)
    2 Yhteensa bigint(20)
    3 Tilastovuosi date
    4 stat_code char(10)
    5 sektori char(50)
    6 Ulkomaiset int(11)
    7 Vieraskieliset int(11)
*/


// MySQL-tietokannan asetukset
require_once __DIR__ . '/db.php';

// Tilastokeskuksen API:n osoite
$apiUrl = 'https://pxdata.stat.fi/PxWeb/api/v1/fi/StatFin/opiskt/statfin_opiskt_pxt_11c4.px';
$jsonFile = '_vieraskiel_11c4.json';

// Funktio JSON-tiedoston lukemiseen
function getData($jsonFile) {
    // Tarkistetaan että tiedosto on olemassa
    if (!file_exists($jsonFile)) {
        throw new Exception("JSON-tiedostoa ei löytynyt: $jsonFile");
    }
    // Luetaan tiedoston sisältö ja dekoodataan JSON
    // Tiedostossa oleva vuosi/kuukausi lasketaaan dynaamisesti
    // tästä hetkestä viisi vuotta taaksepäin, ei käytetä sitä .json tiedoston kovakoodattua
    // muut tiedot käytetään siitä tiedostosta
    $data = file_get_contents($jsonFile);
    return json_decode($data, true);
}

// Funktio datan hakemiseen API:sta
function fetchData($apiUrl, $postData) {
    // Määritellään HTTP POST -pyynnön asetukset
    $options = [
        'http' => [
            'header'  => "Content-Type: application/json\r\n" .
                         "User-Agent: Ennakointi-fetch-script/1.0\r\n",
            'method'  => 'POST',
            'content' => json_encode($postData),
            'timeout' => 30,
            // allow reading response body even on HTTP error (so we can log it)
            'ignore_errors' => true
        ]
    ];
    $context  = stream_context_create($options);
    // Lähetetään pyyntö ja otetaan vastaus talteen (palauttaa body myös virhekoodilla jos ignore_errors=true)
    $result = @file_get_contents($apiUrl, false, $context);
    $responseHeaders = isset($http_response_header) ? $http_response_header : [];
    $statusLine = isset($responseHeaders[0]) ? $responseHeaders[0] : '';
    if ($result === FALSE && empty($statusLine)) {
        $err = error_get_last();
        throw new Exception("Virhe datan haussa API:sta, file_get_contents failed: " . ($err['message'] ?? 'unknown'));
    }
    // tarkista HTTP-status
    $status = 0;
    if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $statusLine, $m)) {
        $status = intval($m[1]);
    }
    // Jos status ei ole 200, palauta virhe sisältäen body (jos saatavilla)
    if ($status !== 200) {
        $bodyPreview = is_string($result) ? substr($result, 0, 2000) : '';
        throw new Exception("API HTTP status $status. Response body: " . $bodyPreview);
    }
    // Palautetaan dekoodattu JSON
    $decoded = json_decode($result, true);
    if ($decoded === null) {
        throw new Exception("Virhe JSON-dekodauksessa API-vastauksesta: " . substr($result ?? '', 0, 2000));
    }
    return $decoded;
}

// Funktio lokiviestien kirjoittamiseen tiedostoon
function logMessage($message) {
    $logFile = 'fetch-data.log';
    $timestamp = date('Y-m-d H:i:s');
    // Kirjoitetaan viesti lokitiedostoon aikaleiman kanssa
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Funktio, joka palauttaa viimeiset 5 vuotta (mukaan lukien kuluvan vuoden)
function getLastFiveYears() {
    $years = [];
    $currentYear = (int)date('Y');
    for ($y = $currentYear - 4; $y <= $currentYear; $y++) {
        $years[] = (string)$y;
    }
    return $years;
}

// Pääfunktio
function main() {
    global $dsn, $db_user, $db_pass, $apiUrl, $jsonFile;

    try {
    // Luetaan JSON-tiedosto taulukoksi
    $jsonArray = json_decode(file_get_contents($jsonFile), true);

        // Generoidaan viimeisimmät N vuotta dynaamisesti
            $lastFiveYears = getLastFiveYears();

        // Yritetään hakea data, jos API palauttaa virheen, pudotetaan uusin vuosi ja yritetään uudelleen
            $yearsToTry = $lastFiveYears;
            $maxTries = count($yearsToTry);
        $success = false;
        for ($try = $maxTries; $try >= 1; $try--) {
                $yearsToTry = array_slice($yearsToTry, 0, $try); // Use all years
            logMessage("Yritetään hakea vuosille: " . implode(",", $yearsToTry));
            foreach ($jsonArray['query'] as &$query) {
                if ($query['code'] === 'Vuosi') {
                    $query['selection']['values'] = $yearsToTry;
                }
                // Fix: ensure we use 'Ikä' (with accent) as in the original JSON
                if ($query['code'] === 'Ika' && isset($query['selection']['values'])) {
                    $query['code'] = 'Ikä';
                }
            }
            unset($query);
            $newStatData = $jsonArray;
            // Log the outgoing JSON payload for debugging
            logMessage("Outgoing API JSON: " . json_encode($newStatData));
            try {
                $data = fetchData($apiUrl, $newStatData);
                logMessage("API-vastaus saatu vuosille: " . implode(",", $yearsToTry));
                $success = true;
                break; // Onnistui, poistutaan silmukasta
            } catch (Exception $apiEx) {
                // Try to log the API response body if available
                $errorContext = stream_context_create([
                    'http' => [
                        'header'  => "Content-Type: application/json\r\n",
                        'method'  => 'POST',
                        'content' => json_encode($newStatData),
                        'timeout' => 10
                    ]
                ]);
                $errorResult = @file_get_contents($apiUrl, false, $errorContext);
                if ($errorResult !== false) {
                    logMessage("API error response: " . $errorResult);
                } else {
                    $error = error_get_last();
                    if ($error) {
                        logMessage("API error_get_last: " . print_r($error, true));
                    }
                }
                logMessage("API-virhe vuosille: " . implode(",", $yearsToTry) . " - Virhe: " . $apiEx->getMessage());
                // Yritetään uudelleen yhdellä vuodella vähemmän
            }
        }
        if (!$success) {
            logMessage("API epäonnistui kaikille kokeilluille vuosille: " . implode(",", $lastFiveYears));
            throw new Exception("API epäonnistui kaikille viimeisimmille vuosille");
        }


                // Parsitaan data (Alue, Koulutussektori, Vuosi, Tiedot)
                $alueIndex = $data['dimension']['Alue']['category']['index'];
                $alueLabels = $data['dimension']['Alue']['category']['label'];
                $sektoriIndex = $data['dimension']['Koulutussektori']['category']['index'];
                $sektoriLabels = $data['dimension']['Koulutussektori']['category']['label'];
                $vuosiIndex = $data['dimension']['Vuosi']['category']['index'];
                $vuosiLabels = $data['dimension']['Vuosi']['category']['label'];
                $tiedotIndex = $data['dimension']['Tiedot']['category']['index'];
                $tiedotLabels = $data['dimension']['Tiedot']['category']['label'];

                $alueKeys = array_keys($alueIndex);
                $sektoriKeys = array_keys($sektoriIndex);
                $vuosiKeys = array_keys($vuosiIndex);
                $tiedotKeys = array_keys($tiedotIndex);

                $alueCount = count($alueKeys);
                $sektoriCount = count($sektoriKeys);
                $vuosiCount = count($vuosiKeys);
                $tiedotCount = count($tiedotKeys);

                $pdo = new PDO($dsn, $db_user, $db_pass, [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"]);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // value-taulukko on järjestyksessä: for each Alue, Koulutussektori, Vuosi, Tiedot
                for ($i = 0; $i < $alueCount; $i++) {
                    $alueId = $alueKeys[$i]; // stat_code
                    for ($j = 0; $j < $sektoriCount; $j++) {
                        $sektoriKey = $sektoriKeys[$j];
                        $sektori = isset($sektoriLabels[$sektoriKey]) ? $sektoriLabels[$sektoriKey] : $sektoriKey;
                        for ($k = 0; $k < $vuosiCount; $k++) {
                            $vuosi = $vuosiKeys[$k];
                            // Store Tilastovuosi as a valid MySQL date (YYYY-01-01)
                            $tilastovuosi = (preg_match('/^\d{4}$/', $vuosi)) ? ($vuosi . '-01-01') : null;
                            $yhteensa = null;
                            $ulkomaiset = null;
                            $vieraskieliset = null;
                            for ($l = 0; $l < $tiedotCount; $l++) {
                                $tiedotKey = $tiedotKeys[$l];
                                $tiedotLabel = isset($tiedotLabels[$tiedotKey]) ? $tiedotLabels[$tiedotKey] : $tiedotKey;
                                $offset = $i * $sektoriCount * $vuosiCount * $tiedotCount
                                        + $j * $vuosiCount * $tiedotCount
                                        + $k * $tiedotCount
                                        + $l;
                                $maara = isset($data['value'][$offset]) ? $data['value'][$offset] : null;
                                // Tallenna tiedot oikeaan sarakkeeseen
                                if (stripos($tiedotLabel, 'yhteensä') !== false) {
                                    $yhteensa = $maara;
                                } elseif (trim($tiedotLabel) === 'Ulkomaalaiset opiskelijat') {
                                    $ulkomaiset = $maara;
                                } elseif (stripos($tiedotLabel, 'vieraskieliset') !== false) {
                                    $vieraskieliset = $maara;
                                }
                            }
                            // Jos kaikki arvot null, älä tallenna
                            if ($yhteensa === null && $ulkomaiset === null && $vieraskieliset === null) {
                                continue;
                            }
                            // Tarkista onko tietue jo olemassa
                            $checkQuery = "SELECT COUNT(*) as cnt FROM Vieraskieliset WHERE stat_code = ? AND Tilastovuosi = ? AND sektori = ?";
                            $checkStmt = $pdo->prepare($checkQuery);
                            $checkStmt->execute([$alueId, $tilastovuosi, $sektori]);
                            $checkRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
                            $count = $checkRow ? intval($checkRow['cnt']) : 0;
                            $now = date('Y-m-d H:i:s');
                            if ($count > 0) {
                                $updateQuery = "UPDATE Vieraskieliset SET Yhteensa=?, Ulkomaiset=?, Vieraskieliset=?, timestamp=? WHERE stat_code=? AND Tilastovuosi=? AND sektori=?";
                                $updateStmt = $pdo->prepare($updateQuery);
                                $updateStmt->execute([$yhteensa, $ulkomaiset, $vieraskieliset, $now, $alueId, $tilastovuosi, $sektori]);
                            } else {
                                $insertQuery = "INSERT INTO Vieraskieliset (Yhteensa, Tilastovuosi, stat_code, sektori, Ulkomaiset, Vieraskieliset, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?)";
                                $insertStmt = $pdo->prepare($insertQuery);
                                $insertStmt->execute([$yhteensa, $tilastovuosi, $alueId, $sektori, $ulkomaiset, $vieraskieliset, $now]);
                            }
                        }
                    }
                }
                logMessage("Vieraskieliset haettu ja tallennettu tietokantaan onnistuneesti.");

    } catch (Exception $e) {
        // Kirjoitetaan virheviesti lokiin
    logMessage("Vieraskieliset Virhe: " . $e->getMessage());
    echo "Virhe: " . $e->getMessage() . "\n";
    }
}

// Suoritetaan pääfunktio
main();
?>