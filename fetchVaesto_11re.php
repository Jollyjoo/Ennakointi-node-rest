<?php

// tuotannossa: Aja tämä tiedosto esim. crontabilla säännöllisesti.
// Tiedosto on cgi-bin kansiossa palvelimella
// Tilasto päivitetään kantaan automaattisesti, kun Tilastokeskus julkaisee uuden tilaston.
// Tilasto julkaistaan yleensä 25. päivänä kuukaudessa, joten voit ajastaa tämän ohjelman ajettavaksi esim. 26. päivä kuukaudessa.

// Tämä ohjelma hakee Tilastokeskuksen API:sta työttömät työnhakijat kuukausittain ja tallentaa ne MySQL-tietokantaan.
// Ohjelma lukee JSON-tiedoston, lähettää POST-pyynnön Tilastokeskuksen API:in, parsii vastauksen ja tallentaa tiedot tietokantaan.
// Ohjelma yrittää hakea tilastokeskukselta viimeisimmät viisi kuukautta, ja jos API palauttaa virheen (tilastoa ei vielä tullut), se pudottaa viimeisimmän kuukauden ja yrittää uudelleen.

// laita ohjelma Linuxille ajastukseen crontabilla
// Crontab -e 
// i
// muokkaa crontabia, lisää rivi....
// esim:
// 7 4 * * *  php /home/catbxjbt/public_html/cgi-bin/fetchValmistuneet_12bs.php
// 7 4 * * *  php /home/catbxjbt/public_html/cgi-bin/fetchAvoimetPaikat_12tw.php
// tallenna ja sulje (ESC, :wq, ENTER)

/*
Tietokantataulu Asukasmaara rakenne:
    #	Field	Type	Collation	Attributes	Null	Default	Extra	Privileges	Comment

	1	Kunta_ID Indeksi	int(11)			Ei	None			Muokkaa Muokkaa	Tuhoa Tuhoa	
	2	Ika	int(11)			Kyllä	NULL			Muokkaa Muokkaa	Tuhoa Tuhoa	
	3	Maara	bigint(20)			Kyllä	NULL			Muokkaa Muokkaa	Tuhoa Tuhoa	
	4	Sukupuoli_ID Indeksi	int(11)			Kyllä	NULL			Muokkaa Muokkaa	Tuhoa Tuhoa	
	5	Tilastovuosi	date			Kyllä	NULL
    6	stat_code	char(10)	latin1_swedish_ci		Kyllä	NULL
    7	timestamp	timestamp			Kyllä	NULL
*/





// MySQL-tietokannan asetukset
require_once __DIR__ . '/db.php';

// Tilastokeskuksen API:n osoite
$apiUrl = 'https://statfin.stat.fi:443/PxWeb/api/v1/fi/StatFin/vaerak/statfin_vaerak_pxt_11re.px';
$jsonFile = '_vaesto_11re.json';

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
            'header'  => "Content-Type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($postData),
            'timeout' => 10
        ]
    ];
    $context  = stream_context_create($options);
    // Lähetetään pyyntö ja otetaan vastaus talteen
    $result = file_get_contents($apiUrl, false, $context);
    if ($result === FALSE) {
        throw new Exception("Virhe datan haussa API:sta");
    }
    // Palautetaan dekoodattu JSON
    return json_decode($result, true);
}

// Funktio lokiviestien kirjoittamiseen tiedostoon
function logMessage($message) {
    $logFile = 'fetch-data.log';
    $timestamp = date('Y-m-d H:i:s');
    // Kirjoitetaan viesti lokitiedostoon aikaleiman kanssa
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Funktio, joka generoi viimeisimmät N vuotta (mukaan lukien kuluvan vuoden)
function getRecentVuodet($n = 3) {
    $years = [];
    $currentYear = (int)date('Y');
    for ($i = 0; $i < $n; $i++) {
        $years[] = (string)($currentYear - $i);
    }
    return array_reverse($years);
}

// Pääfunktio
function main() {
    global $dsn, $db_user, $db_pass, $apiUrl, $jsonFile;

    try {
    // Luetaan JSON-tiedosto taulukoksi
    $jsonArray = json_decode(file_get_contents($jsonFile), true);

        // Generoidaan viimeisimmät N vuotta dynaamisesti
        $recentVuodet = getRecentVuodet(3);

        // Yritetään hakea data, jos API palauttaa virheen, pudotetaan uusin vuosi ja yritetään uudelleen
        $maxTries = count($recentVuodet);
        $success = false;
        for ($try = $maxTries; $try >= 1; $try--) {
            $yearsToTry = array_slice($recentVuodet, 0, $try); // Pudotetaan uusin vuosi pois joka yrityksellä
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
            logMessage("API epäonnistui kaikille kokeilluille vuosille: " . implode(",", $recentVuodet));
            throw new Exception("API epäonnistui kaikille viimeisimmille vuosille");
        }

        // Parsitaan data (Alue, Ikä, Sukupuoli, Vuosi, Tiedot)
    $alueIndex = $data['dimension']['Alue']['category']['index'];
    $alueLabels = $data['dimension']['Alue']['category']['label'];
    // Handle both 'Ika' and 'Ikä' as possible keys
    $ikaKey = isset($data['dimension']['Ika']) ? 'Ika' : (isset($data['dimension']['Ikä']) ? 'Ikä' : null);
    if (!$ikaKey) {
        throw new Exception("API response missing 'Ika' or 'Ikä' dimension");
    }
    $ikaIndex = $data['dimension'][$ikaKey]['category']['index'];
    $ikaLabels = $data['dimension'][$ikaKey]['category']['label'];
    $sukupuoliIndex = $data['dimension']['Sukupuoli']['category']['index'];
    $sukupuoliLabels = $data['dimension']['Sukupuoli']['category']['label'];
    $vuosiIndex = $data['dimension']['Vuosi']['category']['index'];
    $vuosiLabels = $data['dimension']['Vuosi']['category']['label'];
    $tiedotIndex = $data['dimension']['Tiedot']['category']['index'];
    $tiedotLabels = $data['dimension']['Tiedot']['category']['label'];

        $alueKeys = array_keys($alueIndex);
        $ikaKeys = array_keys($ikaIndex);
        $sukupuoliKeys = array_keys($sukupuoliIndex);
        $vuosiKeys = array_keys($vuosiIndex);
        $tiedotKeys = array_keys($tiedotIndex);

        // Yhdistetään MySQL-tietokantaan PDO:lla
        try {
            $pdo = new PDO($dsn, $db_user, $db_pass, [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"]);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            throw new Exception("Yhteys epäonnistui: " . $e->getMessage());
        }

        // value-taulukko on järjestyksessä: for each Alue, Ikä, Sukupuoli, Vuosi, Tiedot
        $alueCount = count($alueKeys);
        $ikaCount = count($ikaKeys);
        $sukupuoliCount = count($sukupuoliKeys);
        $vuosiCount = count($vuosiKeys);
        $tiedotCount = count($tiedotKeys);

        $valueIndex = 0;
        for ($i = 0; $i < $alueCount; $i++) {
            $alueId = $alueKeys[$i];
            $alueLabel = isset($alueLabels[$alueId]) ? $alueLabels[$alueId] : $alueId;
            // Haetaan oikea Kunta_ID (Maakunta_ID) Maakunnat-taulusta
            $kuntaId = null;
            $kuntaQuery = "SELECT Maakunta_ID FROM Maakunnat WHERE stat_code = ? LIMIT 1";
            $kuntaStmt = $pdo->prepare($kuntaQuery);
            $kuntaStmt->execute([$alueId]);
            $kuntaRow = $kuntaStmt->fetch(PDO::FETCH_ASSOC);
            if ($kuntaRow && isset($kuntaRow['Maakunta_ID'])) {
                $kuntaId = $kuntaRow['Maakunta_ID'];
            } else {
                logMessage("MAAKUNNAT ERROR: stat_code not found in Maakunnat: " . $alueId);
                throw new Exception("Maakunta_ID ei löytynyt Maakunnat-taulusta stat_code: " . $alueId);
            }
            for ($j = 0; $j < $ikaCount; $j++) {
                $ikaKey = $ikaKeys[$j];
                // Use Ikä label from API for each Ikä key
                if ($ikaKey === 'SSS') {
                    $ika = 'yhteensä';
                } else {
                    $ika = isset($ikaLabels[$ikaKey]) ? $ikaLabels[$ikaKey] : strval($ikaKey);
                }
                for ($k = 0; $k < $sukupuoliCount; $k++) {
                    $sukupuoli = $sukupuoliKeys[$k];
                    // Sukupuoli_ID: 1 = Mies, 2 = Nainen, 3 = Kaikki (SSS)
                    if ($sukupuoli === 1) {
                        $sukupuoliId = 1;
                    } elseif ($sukupuoli === 2) {
                        $sukupuoliId = 2;
                    } else {
                        $sukupuoliId = 3;
                    }
                    for ($l = 0; $l < $vuosiCount; $l++) {
                        $vuosi = $vuosiKeys[$l];
                        // Store Tilastovuosi as a 4-digit year string (e.g. '2023')
                        $tilastovuosi = (preg_match('/^\d{4}$/', $vuosi)) ? $vuosi : null;
                        for ($m = 0; $m < $tiedotCount; $m++) {
                            $tiedot = $tiedotKeys[$m];
                            // Offset-laskenta
                            $offset = $i * $ikaCount * $sukupuoliCount * $vuosiCount * $tiedotCount
                                    + $j * $sukupuoliCount * $vuosiCount * $tiedotCount
                                    + $k * $vuosiCount * $tiedotCount
                                    + $l * $tiedotCount
                                    + $m;
                            $maara = isset($data['value'][$offset]) ? $data['value'][$offset] : null;
                            // Tarkistetaan onko tietue jo olemassa
                            $checkQuery = "SELECT COUNT(*) as cnt FROM Asukasmaara WHERE Kunta_ID = ? AND Ika = ? AND Sukupuoli_ID <=> ? AND Tilastovuosi = ? AND stat_code = ?";
                            $checkStmt = $pdo->prepare($checkQuery);
                            $checkStmt->execute([$kuntaId, $ika, $sukupuoliId, $tilastovuosi, $alueId]);
                            $checkRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
                            $count = $checkRow ? intval($checkRow['cnt']) : 0;
                            $now = date('Y-m-d H:i:s');
                            if ($count > 0) {
                                // Päivitä olemassa oleva tietue
                                $updateQuery = "UPDATE Asukasmaara SET Maara=?, timestamp=? WHERE Kunta_ID=? AND Ika=? AND Sukupuoli_ID <=> ? AND Tilastovuosi=? AND stat_code=?";
                                $updateStmt = $pdo->prepare($updateQuery);
                                if (!$updateStmt->execute([$maara, $now, $kuntaId, $ika, $sukupuoliId, $tilastovuosi, $alueId])) {
                                    throw new Exception("Virhe SQL-päivityslauseen suorittamisessa: " . implode("; ", $updateStmt->errorInfo()));
                                }
                            } else {
                                // Lisää uusi tietue
                                $insertQuery = "INSERT INTO Asukasmaara (Kunta_ID, Ika, Maara, Sukupuoli_ID, Tilastovuosi, stat_code, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?)";
                                $insertStmt = $pdo->prepare($insertQuery);
                                if (!$insertStmt->execute([$kuntaId, $ika, $maara, $sukupuoliId, $tilastovuosi, $alueId, $now])) {
                                    throw new Exception("Virhe SQL-lauseen suorittamisessa: " . implode("; ", $insertStmt->errorInfo()));
                                }
                            }
                        }
                    }
                }
            }
        }
        // PDO:lla ei tarvitse erikseen sulkea yhteyttä, se sulkeutuu automaattisesti

        // Kirjoitetaan onnistumisviesti lokiin
        logMessage("Asukasmaara haettu ja tallennettu tietokantaan onnistuneesti.");

    } catch (Exception $e) {
        // Kirjoitetaan virheviesti lokiin
        logMessage("Asukasmaara Virhe: " . $e->getMessage());
        echo "Virhe: " . $e->getMessage() . "\n";
    }
}

// Suoritetaan pääfunktio
main();
?>