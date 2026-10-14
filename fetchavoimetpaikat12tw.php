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
Tietokantataulu Tyonhakijat rakenne:
    #	Field	Type	Collation	Attributes	Null	Default	Extra	Privileges	Comment

    1	Maakunta_ID Indeksi	int(11)		
	2	Aika	date			
	3	Maara	int(11)			
	4	Toimiala	varchar(150)	latin1_swedish_ci		
	5	stat_code	char(100)	latin1_swedish_ci		
	6	stat_label	char(100)	latin1_swedish_ci		
    7   Tilastokuukausi	char(50)	
*/





// MySQL-tietokannan asetukset
$servername = "tulevaisuusluotain.fi";
$username = "catbxjbt_Christian";
$password = "Juustonaksu5";
$dbname = "catbxjbt_ennakointi";

// Tilastokeskuksen API:n osoite
$apiUrl = 'https://pxdata.stat.fi:443/PxWeb/api/v1/fi/StatFin/tyonv/12tw.px';
$jsonFile = '_avoimetpaikat12tw.json';
require_once __DIR__ . '/px_helpers.php';

// Funktio JSON-tiedoston lukemiseen
function getData($jsonFile) {
    // Tarkistetaan että tiedosto on olemassa
    if (!file_exists($jsonFile)) {
        throw new Exception("JSON-tiedostoa ei löytynyt: $jsonFile");
    }
    // Luetaan tiedoston sisältö ja dekoodataan JSON
    // Tiedostossa oleva vuosi/kuukausi lasketaaan dynaamisesti
    // tästä hetkestä viisi kuukautta taaksepäin, ei käytetä sitä .json tiedoston kovakoodattua
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

// Apufunktiot (samat kuin muissa skripteissä)
function find_dimension_key($data, $candidates = []) {
    if (!isset($data['dimension']) || !is_array($data['dimension'])) return null;
    foreach ($data['dimension'] as $key => $dim) {
        if (in_array($key, $candidates, true)) return $key;
        if (isset($dim['id']) && in_array($dim['id'], $candidates, true)) return $key;
        if (isset($dim['variableCode']) && in_array($dim['variableCode'], $candidates, true)) return $key;
        if (isset($dim['category']['label']) && is_array($dim['category']['label'])) {
            foreach ($dim['category']['label'] as $lbl) {
                if (in_array($lbl, $candidates, true)) return $key;
            }
        }
    }
    return null;
}

function resolve_code_index($tiedot_map, $code) {
    if (!$tiedot_map || !is_array($tiedot_map)) return null;
    if (isset($tiedot_map[$code])) return $tiedot_map[$code];
    foreach ($tiedot_map as $k => $v) {
        if (stripos($k, $code) !== false) return $v;
        if (preg_match('/' . preg_quote($code, '/') . '$/i', $k)) return $v;
    }
    return null;
}

function cast_numeric_or_null($val) {
    if ($val === null) return null;
    if (is_numeric($val)) return $val + 0;
    return null;
}

function dump_dimensions_for_debug($data) {
    if (!isset($data['dimension']) || !is_array($data['dimension'])) return;
    $out = [];
    foreach ($data['dimension'] as $k => $dim) {
        $labels = '';
        if (isset($dim['category']['label']) && is_array($dim['category']['label'])) {
            $labels = implode(',', array_slice($dim['category']['label'], 0, 5));
        }
        $out[] = "$k: labels={$labels}";
    }
    logMessage("Dimensions: " . implode(' | ', $out));
}

// Dynaaminen bind helper: asettaa tyypit automaattisesti ja tukee NULL-arvoja
function bind_stmt_params($stmt, $params) {
    $types = '';
    $refs = [];
    foreach ($params as $i => $p) {
        if (is_null($p)) {
            $types .= 's';
            $refs[] = & $params[$i];
        } elseif (is_int($p)) {
            $types .= 'i';
            $refs[] = & $params[$i];
        } elseif (is_float($p) || is_double($p)) {
            $types .= 'd';
            $refs[] = & $params[$i];
        } else {
            $types .= 's';
            $refs[] = & $params[$i];
        }
    }
    array_unshift($refs, $types);
    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

// Funktio lokiviestien kirjoittamiseen tiedostoon
function logMessage($message) {
    $logFile = 'fetch-data.log';
    $timestamp = date('Y-m-d H:i:s');
    // Kirjoitetaan viesti lokitiedostoon aikaleiman kanssa
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Funktio, joka generoi viimeisimmät N kuukautta (mukaan lukien viimeisin kuukausi)
function getRecentKuukaudet($n = 5) {
    $months = [];
    $base = strtotime(date('Y-m-01')); // Ensimmäinen päivä kuluvasta kuukaudesta
    for ($i = 1; $i <= $n; $i++) {
        $months[] = date('Y', strtotime("-$i month", $base)) . 'M' . date('m', strtotime("-$i month", $base));
    }
    return array_reverse($months);
}

// Pääfunktio
function main() {
    global $servername, $username, $password, $dbname, $apiUrl, $jsonFile;

    try {
        // Luetaan JSON-tiedosto taulukoksi
        $jsonArray = json_decode(file_get_contents($jsonFile), true);

        // Generoidaan viimeisimmät 5 kuukautta dynaamisesti
        $recentKuukaudet = getRecentKuukaudet(5);

        // Yritetään hakea data, jos API palauttaa virheen, pudotetaan uusin kuukausi ja yritetään uudelleen
        $maxTries = count($recentKuukaudet);
        $success = false;
        $monthCandidates = ['Kuukausi', 'timeperiod_m', 'Month'];
        for ($try = $maxTries; $try >= 1; $try--) {
            $monthsToTry = array_slice($recentKuukaudet, 0, $try); // Pudotetaan uusin kuukausi pois joka yrityksellä
            logMessage("Yritetään hakea kuukausille: " . implode(",", $monthsToTry));
            foreach ($jsonArray['query'] as &$query) {
                if (isset($query['code']) && in_array($query['code'], $monthCandidates, true)) {
                    $query['selection']['values'] = $monthsToTry;
                }
            }
            unset($query);
            $newStatData = $jsonArray;
            try {
                $data = fetchData($apiUrl, $newStatData);
                logMessage("API-vastaus saatu kuukausille: " . implode(",", $monthsToTry));
                $success = true;
                break; // Onnistui, poistutaan silmukasta
            } catch (Exception $apiEx) {
                logMessage("API-virhe kuukausille: " . implode(",", $monthsToTry) . " - Virhe: " . $apiEx->getMessage());
                // Yritetään uudelleen yhdellä kuukaudella vähemmän
            }
        }
        if (!$success) {
            logMessage("API epäonnistui kaikille kokeilluille kuukausille: " . implode(",", $recentKuukaudet));
            throw new Exception("API epäonnistui kaikille viimeisimmille kuukausille");
        }

        // Parsitaan data dynaamisesti (tukee VARIABLECODE-muutoksia)
        dump_dimensions_for_debug($data);
        $alueKey = find_dimension_key($data, ['Alue', 'alue', 'Region']) ?? (isset($data['dimension']['Alue']) ? 'Alue' : null);
        $toimialaKey = find_dimension_key($data, ['Toimiala', 'toimiala', 'industry']) ?? (isset($data['dimension']['Toimiala']) ? 'Toimiala' : null);
        $kuukausiKey = find_dimension_key($data, ['Kuukausi', 'timeperiod_m', 'Month']) ?? (isset($data['dimension']['Kuukausi']) ? 'Kuukausi' : null);
        $tiedotKey = find_dimension_key($data, ['Tiedot', 'contentscode', 'Information']) ?? (isset($data['dimension']['Tiedot']) ? 'Tiedot' : null);
        if (!$alueKey || !$toimialaKey || !$kuukausiKey || !$tiedotKey) {
            throw new Exception("Ei löytynyt odotettuja dimensioita: alue={$alueKey}, toimiala={$toimialaKey}, kuukausi={$kuukausiKey}, tiedot={$tiedotKey}");
        }
        $alueIndex = $data['dimension'][$alueKey]['category']['index'];
        $alueLabels = $data['dimension'][$alueKey]['category']['label'];
        $toimialaIndex = $data['dimension'][$toimialaKey]['category']['index'];
        $toimialaLabels = $data['dimension'][$toimialaKey]['category']['label'];
        $kuukausiIndex = $data['dimension'][$kuukausiKey]['category']['index'];
        $kuukausiLabels = $data['dimension'][$kuukausiKey]['category']['label'];
        $tiedotIndex = $data['dimension'][$tiedotKey]['category']['index'];
        $tiedotLabels = $data['dimension'][$tiedotKey]['category']['label'];

        $alueKeys = array_keys($alueIndex);
        $toimialaKeys = array_keys($toimialaIndex);
        $kuukausiKeys = array_keys($kuukausiIndex);
        $tiedotKeys = array_keys($tiedotIndex);

        // Yhdistetään MySQL-tietokantaan
        $conn = new mysqli($servername, $username, $password, $dbname);
        if ($conn->connect_error) {
            throw new Exception("Yhteys epäonnistui: " . $conn->connect_error);
        }
        // Asetetaan yhteyden merkistö UTF-8:ksi
        if (!$conn->set_charset("utf8mb4")) {
            throw new Exception("UTF8MB4 charsetin asetus epäonnistui: " . $conn->error);
        }

        
        // value-taulukko on järjestyksessä: for each alue, for each toimiala, for each kuukausi, for each tiedot
        $alueCount = count($alueKeys);
        $toimialaCount = count($toimialaKeys);
        $kuukausiCount = count($kuukausiKeys);
        $tiedotCount = count($tiedotKeys);
        $valueIndex = 0;
        for ($i = 0; $i < $alueCount; $i++) {
            $alueId = $alueKeys[$i];
            $alueLabel = isset($alueLabels[$alueId]) ? $alueLabels[$alueId] : $alueId;
            for ($j = 0; $j < $toimialaCount; $j++) {
                $toimialaId = $toimialaKeys[$j];
                $toimialaLabel = isset($toimialaLabels[$toimialaId]) ? $toimialaLabels[$toimialaId] : $toimialaId;
                for ($k = 0; $k < $kuukausiCount; $k++) {
                    $kuukausi = $kuukausiKeys[$k];
                    $tilastokuukausi = $kuukausi; // esim. 2025M06
                    $aika = date('Y-m-d H:i:s'); // nykyinen aikaleima
                    // Haetaan oikea Maakunta_ID Maakunnat-taulusta
                    $maakuntaId = null;
                    $maakuntaQuery = "SELECT Maakunta_ID FROM Maakunnat WHERE stat_code = ? LIMIT 1";
                    $maakuntaStmt = $conn->prepare($maakuntaQuery);
                    if (!$maakuntaStmt) {
                        throw new Exception("Virhe Maakunnat-haun valmistelussa: " . $conn->error);
                    }
                    $maakuntaStmt->bind_param("s", $alueId);
                    $maakuntaStmt->execute();
                    $maakuntaStmt->bind_result($maakuntaId);
                    $maakuntaStmt->fetch();
                    $maakuntaStmt->close();
                    if ($maakuntaId === null) {
                        throw new Exception("Maakunta_ID ei löytynyt stat_code: " . $alueId);
                    }
                    // Tarkistetaan onko tietue jo olemassa tällä alueella, toimialalla ja tilastokuukaudella
                    $checkQuery = "SELECT COUNT(*) FROM Avoimet_tyopaikat WHERE stat_code = ? AND Tilastokuukausi = ? AND Toimiala = ?";
                    $checkStmt = $conn->prepare($checkQuery);
                    if (!$checkStmt) {
                        throw new Exception("Virhe SQL-tarkistuslauseen valmistelussa: " . $conn->error);
                    }
                    $checkStmt->bind_param("sss", $alueId, $tilastokuukausi, $toimialaLabel);
                    $checkStmt->execute();
                    $checkStmt->bind_result($count);
                    $checkStmt->fetch();
                    $checkStmt->close();

                    // Offset-laskenta ja maara haetaan ennen if-haaraa
                        // ratkaistaan tiedot-index dynaamisesti (esim. AVPAIKATLOPUSSA)
                        $tiedot_map = $tiedotIndex;
                        $tiedotIdx = resolve_code_index($tiedot_map, 'AVPAIKATLOPUSSA');
                        if ($tiedotIdx === null) {
                        // fallback: käytä ensimmäistä tiedot-arvoa
                        $tiedotIdx = 0;
                        }
                        $offset = $i * $toimialaCount * $kuukausiCount * $tiedotCount
                            + $j * $kuukausiCount * $tiedotCount
                            + $k * $tiedotCount
                            + $tiedotIdx;
                        $maara = isset($data['value'][$offset]) ? cast_numeric_or_null($data['value'][$offset]) : null;
                    if ($count > 0) {
                        // Päivitetään olemassa oleva tietue
                        $updateQuery = "UPDATE Avoimet_tyopaikat SET Maakunta_ID=?, Maara=?, stat_label=?, Aika=? WHERE stat_code=? AND Tilastokuukausi=? AND Toimiala=?";
                        $updateStmt = $conn->prepare($updateQuery);
                        if (!$updateStmt) {
                            throw new Exception("Virhe SQL-päivityslauseen valmistelussa: " . $conn->error);
                        }
                        $bindOk = bind_stmt_params($updateStmt, [$maakuntaId, $maara, $alueLabel, $aika, $alueId, $tilastokuukausi, $toimialaLabel]);
                        if (!$bindOk || !$updateStmt->execute()) {
                            throw new Exception("Virhe SQL-päivityslauseen suorittamisessa: " . $updateStmt->error);
                        }
                        $updateStmt->close();
                    } else {
                        // Lisätään uusi tietue
                        $query = "INSERT INTO Avoimet_tyopaikat (Maakunta_ID, Tilastokuukausi, Maara, Toimiala, stat_code, stat_label, Aika) VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($query);
                        if (!$stmt) {
                            throw new Exception("Virhe SQL-lauseen valmistelussa: " . $conn->error);
                        }
                        $bindOk = bind_stmt_params($stmt, [$maakuntaId, $tilastokuukausi, $maara, $toimialaLabel, $alueId, $alueLabel, $aika]);
                        if (!$bindOk || !$stmt->execute()) {
                            throw new Exception("Virhe SQL-lauseen suorittamisessa: " . $stmt->error);
                        }
                        $stmt->close();
                    }
                }
            }
        }

        // Suljetaan tietokantayhteys
        $conn->close();

        // Kirjoitetaan onnistumisviesti lokiin
        logMessage("Avoimet työpaikat haettu ja tallennettu tietokantaan onnistuneesti.");

    } catch (Exception $e) {
        // Kirjoitetaan virheviesti lokiin
        logMessage("Työttömät Virhe: " . $e->getMessage());
        echo "Virhe: " . $e->getMessage() . "\n";
    }
}

// Suoritetaan pääfunktio
main();
?>