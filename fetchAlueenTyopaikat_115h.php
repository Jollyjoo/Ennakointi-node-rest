<?php

// tuotannossa: Aja tämä tiedosto esim. crontabilla säännöllisesti.
// Tiedosto on cgi-bin kansiossa palvelimella
// Tilasto päivitetään kantaan automaattisesti, kun Tilastokeskus julkaisee uuden tilaston.
// Tilasto julkaistaan yleensä 25. päivänä kuukaudessa, joten voit ajastaa tämän ohjelman ajettavaksi esim. 26. päivä kuukaudessa.

// Tämä ohjelma hakee Tilastokeskuksen API:sta työttömät työnhakijat kuukausittain ja tallentaa ne MySQL-tietokantaan.
// Ohjelma lukee JSON-tiedoston, lähettää POST-pyynnön Tilastokeskuksen API:in, parsii vastauksen ja tallentaa tiedot tietokantaan.
// Ohjelma yrittää hakea tilastokeskukselta viimeisimmät viisi kuukautta, ja jos API palauttaa virheen (tilastoa ei vielä tullut), se pudottaa viimeisimmän kuukauden ja yrittää uudelleen.

/*
Tietokantataulu Alueentyopaikat rakenne:

    1	Kunta_ID	varchar(100)	latin1_swedish_ci		Kyllä	NULL	
	2	ID Perusavain	int(11)			Ei	None			
	3	Kunta	char(50)	latin1_swedish_ci		Kyllä	NULL			
	4	Maakunta_ID	char(50)	latin1_swedish_ci		Kyllä	NULL			
	5	Vuosi	char(50)	latin1_swedish_ci		Kyllä	NULL			
	6	Tyopaikat	char(50)	latin1_swedish_ci		Kyllä	NULL			
	7	Toimiala	char(100)	latin1_swedish_ci		Kyllä	NULL			
	8	Sukupuoli	char(100)	latin1_swedish_ci		Kyllä	NULL			
*/



// MySQL-tietokannan asetukset
$servername = "tulevaisuusluotain.fi";
$username = "catbxjbt_Christian";
$password = "Juustonaksu5";
$dbname = "catbxjbt_ennakointi";

// Tilastokeskuksen API:n osoite
$apiUrl = 'https://pxdata.stat.fi/PxWeb/api/v1/fi/StatFin/tyokay/115h.px';
$jsonFile = '_alueentyopaikat_115h.json';
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

        // 115h on vuositason aineisto, ei kuukausitasoista dataa.
        $recentVuodet = getRecentYears(5);
        $yearCandidates = ['Vuosi', 'timeperiod_y', 'Year'];
        foreach ($jsonArray['query'] as &$query) {
            if (isset($query['code']) && in_array($query['code'], $yearCandidates, true)) {
                $query['selection']['values'] = $recentVuodet;
            }
        }
        unset($query);

        $newStatData = $jsonArray;

        // Yritetään hakea data viimeisimmistä vuosista.
        // Jos API hylkää uusimman vuoden, pudotetaan se pois ja yritetään uudelleen vanhemmilla vuosilla.
        $maxTries = count($recentVuodet);
        $success = false;
        for ($try = 0; $try < $maxTries; $try++) {
            $yearsToTry = array_slice($recentVuodet, $try);
            foreach ($jsonArray['query'] as &$query) {
                if (isset($query['code']) && in_array($query['code'], $yearCandidates, true)) {
                    $query['selection']['values'] = $yearsToTry;
                }
            }
            unset($query);
            $newStatData = $jsonArray;
            try {
                $data = fetchData($apiUrl, $newStatData);
                $success = true;
                break;
            } catch (Exception $apiEx) {
                logMessage("API-virhe vuosille: " . implode(",", $yearsToTry) . " - Virhe: " . $apiEx->getMessage());
            }
        }
        if (!$success) {
            logMessage("API epäonnistui kaikille kokeilluille vuosille: " . implode(",", $recentVuodet));
            throw new Exception("API epäonnistui kaikille viimeisimmille vuosille");
        }

    // Parsitaan data dynaamisesti ja kirjoitetaan diagnostinen informaatio lokiin
    dump_dimensions_for_debug($data);
    // 115h:n dimensioiden avaimet ovat StatFinin metadata-koodit, eivät vanhoja nimiä.
    // Käytetään niitä tarkasti, ja vain jos ne puuttuvat, tehdään fallback vanhojen nimien kautta.
    $dimensions = $data['dimension'] ?? [];
    $alueKey = isset($dimensions['alue_23_20260101']) ? 'alue_23_20260101'
        : (isset($dimensions['alue_23_20230101']) ? 'alue_23_20230101' : (find_dimension_key($data, ['Työpaikan alue', 'Alue', 'alue']) ?? (isset($dimensions['Työpaikan alue']) ? 'Työpaikan alue' : null)));
    $toimialaKey = isset($dimensions['toimiala_108_20230101']) ? 'toimiala_108_20230101'
        : (find_dimension_key($data, ['Toimiala', 'toimiala']) ?? (isset($dimensions['Toimiala']) ? 'Toimiala' : null));
    $sukupuoliKey = isset($dimensions['sukupuoli_9_20180101']) ? 'sukupuoli_9_20180101'
        : (find_dimension_key($data, ['Sukupuoli', 'sukupuoli']) ?? (isset($dimensions['Sukupuoli']) ? 'Sukupuoli' : null));
    $vuosiKey = isset($dimensions['timeperiod_y']) ? 'timeperiod_y'
        : (find_dimension_key($data, ['Vuosi', 'Year']) ?? (isset($dimensions['Vuosi']) ? 'Vuosi' : null));
    $tiedotKey = isset($dimensions['contentscode']) ? 'contentscode'
        : (find_dimension_key($data, ['Tiedot', 'Information']) ?? (isset($dimensions['Tiedot']) ? 'Tiedot' : null));
    if (!$alueKey || !$toimialaKey || !$sukupuoliKey || !$vuosiKey || !$tiedotKey) {
        throw new Exception("Ei löytynyt odotettuja dimensioita: alue={$alueKey}, toimiala={$toimialaKey}, sukupuoli={$sukupuoliKey}, vuosi={$vuosiKey}, tiedot={$tiedotKey}");
    }
    $alueIndex = $data['dimension'][$alueKey]['category']['index'];
    $alueLabels = $data['dimension'][$alueKey]['category']['label'];
    $toimialaIndex = $data['dimension'][$toimialaKey]['category']['index'];
    $toimialaLabels = $data['dimension'][$toimialaKey]['category']['label'];
    $sukupuoliIndex = $data['dimension'][$sukupuoliKey]['category']['index'];
    $sukupuoliLabels = $data['dimension'][$sukupuoliKey]['category']['label'];
    $vuosiIndex = $data['dimension'][$vuosiKey]['category']['index'];
    $vuosiLabels = $data['dimension'][$vuosiKey]['category']['label'];
    $tiedotIndex = $data['dimension'][$tiedotKey]['category']['index'];
    $tiedotLabels = $data['dimension'][$tiedotKey]['category']['label'];

    $alueKeys = array_keys($alueIndex);
    $toimialaKeys = array_keys($toimialaIndex);
    $sukupuoliKeys = array_keys($sukupuoliIndex);
    $vuosiKeys = array_keys($vuosiIndex);
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

        // 115h on vuositason data: [Alue][Toimiala][Sukupuoli][Vuosi][Tiedot]
        $alueCount = count($alueKeys);
        $toimialaCount = count($toimialaKeys);
        $sukupuoliCount = count($sukupuoliKeys);
        $vuosiCount = count($vuosiKeys);
        $tiedotCount = count($tiedotKeys);
        $valueIndex = 0;
        for ($i = 0; $i < $alueCount; $i++) {
            $alueId = $alueKeys[$i];
            $alueLabel = isset($alueLabels[$alueId]) ? $alueLabels[$alueId] : $alueId;
            for ($j = 0; $j < $toimialaCount; $j++) {
                $toimialaId = $toimialaKeys[$j];
                $toimialaLabel = isset($toimialaLabels[$toimialaId]) ? $toimialaLabels[$toimialaId] : $toimialaId;
                for ($k = 0; $k < $sukupuoliCount; $k++) {
                    $sukupuoliId = $sukupuoliKeys[$k];
                    $sukupuoliLabel = isset($sukupuoliLabels[$sukupuoliId]) ? $sukupuoliLabels[$sukupuoliId] : $sukupuoliId;
                    for ($l = 0; $l < $vuosiCount; $l++) {
                        $vuosiId = $vuosiKeys[$l];
                        $vuosiLabel = isset($vuosiLabels[$vuosiId]) ? $vuosiLabels[$vuosiId] : $vuosiId;
                        for ($m = 0; $m < $tiedotCount; $m++) {
                            $tiedotId = $tiedotKeys[$m];
                            $tiedotLabel = isset($tiedotLabels[$tiedotId]) ? $tiedotLabels[$tiedotId] : $tiedotId;
                            $tiedot_map = $tiedotIndex;
                            $candidates = ['TYOPAIKAT', 'TYOPAIKATLOPUSSA', 'AVPAIKATLOPUSSA', 'Tyopaikat', 'TYOPAISKAT'];
                            $tiedotIdx = null;
                            foreach ($candidates as $cand) {
                                $tiedotIdx = resolve_code_index($tiedot_map, $cand);
                                if ($tiedotIdx !== null) break;
                            }
                            if ($tiedotIdx === null) {
                                $tiedotIdx = $m;
                            }
                            $offset = $i * $toimialaCount * $sukupuoliCount * $vuosiCount * $tiedotCount
                                    + $j * $sukupuoliCount * $vuosiCount * $tiedotCount
                                    + $k * $vuosiCount * $tiedotCount
                                    + $l * $tiedotCount
                                    + $tiedotIdx;
                            $tyopaikat = isset($data['value'][$offset]) ? cast_numeric_or_null($data['value'][$offset]) : null;
                            $maakuntaId = null;
                            $mkQuery = "SELECT maakunta_id FROM Kunta WHERE stat_code = ? LIMIT 1";
                            $mkStmt = $conn->prepare($mkQuery);
                            if ($mkStmt) {
                                $mkStmt->bind_param("s", $alueId);
                                $mkStmt->execute();
                                $mkStmt->bind_result($mkId);
                                if ($mkStmt->fetch()) {
                                    $maakuntaId = $mkId;
                                }
                                $mkStmt->close();
                            } else {
                                logMessage("Virhe Kunta-haussa: " . $conn->error);
                            }
                            $timestamp = date('Y-m-d H:i:s');
                            $checkQuery = "SELECT COUNT(*) FROM `Alueentyopaikat` WHERE Kunta_ID = ? AND Vuosi = ? AND Toimiala = ? AND Sukupuoli = ?";
                            $checkStmt = $conn->prepare($checkQuery);
                            if (!$checkStmt) {
                                throw new Exception("Virhe SQL-tarkistuslauseen valmistelussa: " . $conn->error);
                            }
                            $bindOk = bind_stmt_params($checkStmt, [$alueId, $vuosiLabel, $toimialaLabel, $sukupuoliLabel]);
                            if (!$bindOk || !$checkStmt->execute()) {
                                throw new Exception("Virhe SQL-tarkistuslauseen suorittamisessa: " . $checkStmt->error);
                            }
                            $checkStmt->bind_result($count);
                            $checkStmt->fetch();
                            $checkStmt->close();
                            if ($count > 0) {
                                $updateQuery = "UPDATE `Alueentyopaikat` SET Kunta=?, Maakunta_ID=?, Tyopaikat=?, stat_code=?, timestamp=? WHERE Kunta_ID=? AND Vuosi=? AND Toimiala=? AND Sukupuoli=?";
                                $updateStmt = $conn->prepare($updateQuery);
                                if (!$updateStmt) {
                                    throw new Exception("Virhe SQL-päivityslauseen valmistelussa: " . $conn->error);
                                }
                                $bindOk = bind_stmt_params($updateStmt, [$alueLabel, $maakuntaId, $tyopaikat, $alueId, $timestamp, $alueId, $vuosiLabel, $toimialaLabel, $sukupuoliLabel]);
                                if (!$bindOk || !$updateStmt->execute()) {
                                    throw new Exception("Virhe SQL-päivityslauseen suorittamisessa: " . $updateStmt->error);
                                }
                                $updateStmt->close();
                            } else {
                                $query = "INSERT INTO `Alueentyopaikat` (Kunta_ID, Kunta, Maakunta_ID, Vuosi, Tyopaikat, Toimiala, Sukupuoli, stat_code, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                                $stmt = $conn->prepare($query);
                                if (!$stmt) {
                                    throw new Exception("Virhe SQL-lauseen valmistelussa: " . $conn->error);
                                }
                                $bindOk = bind_stmt_params($stmt, [$alueId, $alueLabel, $maakuntaId, $vuosiLabel, $tyopaikat, $toimialaLabel, $sukupuoliLabel, $alueId, $timestamp]);
                                if (!$bindOk || !$stmt->execute()) {
                                    throw new Exception("Virhe SQL-lauseen suorittamisessa: " . $stmt->error);
                                }
                                $stmt->close();
                            }
                        }
                    }
                }
            }
        }

        // Suljetaan tietokantayhteys
        $conn->close();

        // Kirjoitetaan onnistumisviesti lokiin
        logMessage("Alueen työpaikat haettu ja tallennettu tietokantaan onnistuneesti.");

    } catch (Exception $e) {
        // Kirjoitetaan virheviesti lokiin
        logMessage("115h Virhe: " . $e->getMessage());
        echo "Virhe: " . $e->getMessage() . "\n";
    }
}

// Suoritetaan pääfunktio
main();
?>