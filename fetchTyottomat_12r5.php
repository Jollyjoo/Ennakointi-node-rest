<?php
// testaus: KÄYNNISTÄ TERMINALISSA KOMENNOLLA: php fetchTyottomat_12r5.php
// tuotannossa: Aja tämä tiedosto esim. crontabilla säännöllisesti.
// Tiedosto on cgi-bin kansiossa palvelimella
// Tilasto päivitetään kantaan automaattisesti, kun Tilastokeskus julkaisee uuden tilaston.
// Tilasto julkaistaan yleensä 25. päivänä kuukaudessa, joten voit ajastaa tämän ohjelman ajettavaksi esim. 26. päivä kuukaudessa.

// Tämä ohjelma hakee Tilastokeskuksen API:sta työttömät työnhakijat kuukausittain ja tallentaa ne MySQL-tietokantaan.
// Ohjelma lukee JSON-tiedoston, lähettää POST-pyynnön Tilastokeskuksen API:in, parsii vastauksen ja tallentaa tiedot tietokantaan.
// Ohjelma yrittää hakea tilastokeskukselta viimeisimmät viisi kuukautta, ja jos API palauttaa virheen (tilastoa ei vielä tullut), se pudottaa viimeisimmän kuukauden ja yrittää uudelleen.

// MySQL-tietokannan asetukset
$servername = "tulevaisuusluotain.fi";
$username = "catbxjbt_Christian";
$password = "Juustonaksu5";
$dbname = "catbxjbt_ennakointi";

// Tilastokeskuksen API:n osoite
$apiUrl = 'https://pxdata.stat.fi:443/PxWeb/api/v1/fi/StatFin/tyonv/12r5.px';
$jsonFile = '_tyottomat_12r5.json';

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

// Apufunktio: etsi dimension-avain annetuilla nimiehdotuksilla
function find_dimension_key($data, $candidates = []) {
    if (!isset($data['dimension']) || !is_array($data['dimension'])) return null;
    foreach ($data['dimension'] as $key => $dim) {
        if (in_array($key, $candidates, true)) return $key;
        // Tarkista mahdolliset id/variablecode-polut
        if (isset($dim['id']) && in_array($dim['id'], $candidates, true)) return $key;
        if (isset($dim['variableCode']) && in_array($dim['variableCode'], $candidates, true)) return $key;
        // Tarkista labelit
        if (isset($dim['category']['label']) && is_array($dim['category']['label'])) {
            foreach ($dim['category']['label'] as $lbl) {
                if (in_array($lbl, $candidates, true)) return $key;
            }
        }
    }
    return null;
}

// Apufunktio: yritä ratkaista analyysikentän koodin indeksi joustavasti
function resolve_code_index($tiedot_map, $code) {
    if (!$tiedot_map || !is_array($tiedot_map)) return null;
    // suora löytyminen
    if (isset($tiedot_map[$code])) return $tiedot_map[$code];
    // etsi avain joka sisältää haettavan koodin (esim. etuliitteen muutos)
    foreach ($tiedot_map as $k => $v) {
        if (stripos($k, $code) !== false) return $v;
        // myös tarkista, päättyykö avain koodiin (esim. ton-kuol)
        if (preg_match('/' . preg_quote($code, '/') . '$/i', $k)) return $v;
    }
    return null;
}

// Apufunktio: muunna arvo numeroksi tai nulliksi
function cast_numeric_or_null($val) {
    if ($val === null) return null;
    if (is_numeric($val)) return $val + 0; // säilyttää int/float
    return null;
}

// Diagnostiikka: tulosta dimension-avaimet ja tiedot-koodit lokiin
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

// Apufunktio: sitoo parametrilistan dynaamisesti tyyppien mukaan (NULL tukena)
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

        // Päivitetään Kuukausi-arvot viimeisimmille 5 kuukaudelle
        $recentKuukaudet = getRecentKuukaudet(5);
        $monthCandidates = ['Kuukausi', 'timeperiod_m', 'Month', 'Kuukausi'];
        foreach ($jsonArray['query'] as &$query) {
            if (isset($query['code']) && in_array($query['code'], $monthCandidates, true)) {
                $query['selection']['values'] = $recentKuukaudet;
            }
        }
        unset($query);

        // Käytetään päivitettyä taulukkoa API-kutsuun
        $newStatData = $jsonArray;

        // Yritetään hakea data, jos API palauttaa virheen, pudotetaan uusin kuukausi ja yritetään uudelleen
        $maxTries = count($recentKuukaudet);
        $success = false;
        for ($try = $maxTries; $try >= 1; $try--) {
            $monthsToTry = array_slice($recentKuukaudet, 0, $try); // Pudotetaan uusin kuukausi pois joka yrityksellä
            foreach ($jsonArray['query'] as &$query) {
                if (isset($query['code']) && in_array($query['code'], $monthCandidates, true)) {
                    $query['selection']['values'] = $monthsToTry;
                }
            }
            unset($query);
            $newStatData = $jsonArray;
            try {
                $data = fetchData($apiUrl, $newStatData);
                $success = true;
                break; // Onnistui, poistutaan silmukasta
            } catch (Exception $apiEx) {
                logMessage("API-virhe kuukausille: " . implode(",", $monthsToTry) . " - " . $apiEx->getMessage());
                // Yritetään uudelleen yhdellä kuukaudella vähemmän
            }
        }
        if (!$success) {
            throw new Exception("API epäonnistui kaikille viimeisimmille kuukausille");
        }

        // Parsitaan data (dynaamisesti etsitään dimension-avaimet)
        // Tulostetaan diagnostinen informaatio dimensiosta
        dump_dimensions_for_debug($data);
        $alueKey = find_dimension_key($data, ['Alue', 'alue', 'Region', 'Region']) ?? (isset($data['dimension']['Alue']) ? 'Alue' : null);
        $kuukausiKey = find_dimension_key($data, ['Kuukausi', 'timeperiod_m', 'Month']) ?? (isset($data['dimension']['Kuukausi']) ? 'Kuukausi' : null);
        $tiedotKey = find_dimension_key($data, ['Tiedot', 'contentscode', 'Information']) ?? (isset($data['dimension']['Tiedot']) ? 'Tiedot' : null);
        if (!$alueKey || !$kuukausiKey || !$tiedotKey) {
            throw new Exception("Ei löytynyt odotettuja dimensioita: alue={$alueKey}, kuukausi={$kuukausiKey}, tiedot={$tiedotKey}");
        }
        $alueIndex = $data['dimension'][$alueKey]['category']['index'];
        $alueLabels = $data['dimension'][$alueKey]['category']['label'];
        $kuukausiIndex = $data['dimension'][$kuukausiKey]['category']['index'];
        $kuukausiLabels = $data['dimension'][$kuukausiKey]['category']['label'];
        $tiedotIndex = $data['dimension'][$tiedotKey]['category']['index'];
        $tiedotLabels = $data['dimension'][$tiedotKey]['category']['label'];

        $alueKeys = array_keys($alueIndex);
        $kuukausiKeys = array_keys($kuukausiIndex);
        $tiedotKeys = array_keys($tiedotIndex);
        // Build Tiedot code => index mapping for dynamic access
        $tiedot_map = $tiedotIndex; // code => index

        // Yhdistetään MySQL-tietokantaan
        $conn = new mysqli($servername, $username, $password, $dbname);
        if ($conn->connect_error) {
            throw new Exception("Yhteys epäonnistui: " . $conn->connect_error);
        }

        // value-taulukko on järjestyksessä: jokaiselle alueelle, jokaiselle kuukaudelle, jokaiselle tiedolle
        $alueCount = count($alueKeys);
        $kuukausiCount = count($kuukausiKeys);
        $tiedotCount = count($tiedotKeys);
        $valueIndex = 0;
        for ($i = 0; $i < $alueCount; $i++) {
            $alueId = $alueKeys[$i];
            $alueLabel = isset($alueLabels[$alueId]) ? $alueLabels[$alueId] : $alueId;
            // Hae maakunta_ID kunta-taulusta stat_code:n perusteella
            $maakunta_ID = null;
            $mkQuery = "SELECT maakunta_ID FROM Kunta WHERE stat_code = ? LIMIT 1";
            $mkStmt = $conn->prepare($mkQuery);
            if ($mkStmt) {
                $mkStmt->bind_param("s", $alueId);
                $mkStmt->execute();
                $mkStmt->bind_result($maakunta_ID);
                $mkStmt->fetch();
                $mkStmt->close();
            }
            for ($j = 0; $j < $kuukausiCount; $j++) {
                $kuukausi = $kuukausiKeys[$j];
                $aika = $kuukausi;
                $offset = ($i * $kuukausiCount + $j) * $tiedotCount;
                $idx = resolve_code_index($tiedot_map, 'TYOTTOMATLOPUSSA');
                $tyottomatlopussa = ($idx !== null && isset($data['value'][$offset + $idx])) ? cast_numeric_or_null($data['value'][$offset + $idx]) : null;
                $idx = resolve_code_index($tiedot_map, 'TYOTOSUUS');
                $tyotosuus = ($idx !== null && isset($data['value'][$offset + $idx])) ? cast_numeric_or_null($data['value'][$offset + $idx]) : null;
                $idx = resolve_code_index($tiedot_map, 'TYOTTOMAT20');
                $tyottomat20 = ($idx !== null && isset($data['value'][$offset + $idx])) ? cast_numeric_or_null($data['value'][$offset + $idx]) : null;
                $idx = resolve_code_index($tiedot_map, 'TYOTTOMAT25');
                $tyottomat25 = ($idx !== null && isset($data['value'][$offset + $idx])) ? cast_numeric_or_null($data['value'][$offset + $idx]) : null;
                $idx = resolve_code_index($tiedot_map, 'TYOTTOMAT50');
                $tyottomat50 = ($idx !== null && isset($data['value'][$offset + $idx])) ? cast_numeric_or_null($data['value'][$offset + $idx]) : null;
                $idx = resolve_code_index($tiedot_map, 'TYOTTOMATULK');
                $tyottomatulk = ($idx !== null && isset($data['value'][$offset + $idx])) ? cast_numeric_or_null($data['value'][$offset + $idx]) : null;
                $idx = resolve_code_index($tiedot_map, 'PITKAAIKAISTYOTTOMAT');
                $pitkaaikaistyottomat = ($idx !== null && isset($data['value'][$offset + $idx])) ? cast_numeric_or_null($data['value'][$offset + $idx]) : null;
                $idx = resolve_code_index($tiedot_map, 'UUDETAVP');
                $uudetavp = ($idx !== null && isset($data['value'][$offset + $idx])) ? cast_numeric_or_null($data['value'][$offset + $idx]) : null;
                // Dynamically sum the 5 palvelut params (integers, 0 fallback)
                $idx = resolve_code_index($tiedot_map, 'TYOLLISTETTYLOP');
                $tyollistettylop = ($idx !== null && isset($data['value'][$offset + $idx]) && is_numeric($data['value'][$offset + $idx])) ? (int)$data['value'][$offset + $idx] : 0;
                $idx = resolve_code_index($tiedot_map, 'KOKEILULOP');
                $kokeilulop = ($idx !== null && isset($data['value'][$offset + $idx]) && is_numeric($data['value'][$offset + $idx])) ? (int)$data['value'][$offset + $idx] : 0;
                $idx = resolve_code_index($tiedot_map, 'TVKOULUTUSLOP');
                $tvkoulutuslop = ($idx !== null && isset($data['value'][$offset + $idx]) && is_numeric($data['value'][$offset + $idx])) ? (int)$data['value'][$offset + $idx] : 0;
                $idx = resolve_code_index($tiedot_map, 'VALMENNUSLOP');
                $valmennuslop = ($idx !== null && isset($data['value'][$offset + $idx]) && is_numeric($data['value'][$offset + $idx])) ? (int)$data['value'][$offset + $idx] : 0;
                $idx = resolve_code_index($tiedot_map, 'MTPPALVELUTLOP');
                $mtppalvelutlop = ($idx !== null && isset($data['value'][$offset + $idx]) && is_numeric($data['value'][$offset + $idx])) ? (int)$data['value'][$offset + $idx] : 0;
                $palvelut_yht = $tyollistettylop + $kokeilulop + $tvkoulutuslop + $valmennuslop + $mtppalvelutlop;
                // Tarkistetaan onko tietue jo olemassa tällä stat_code ja aika -arvolla
                $checkQuery = "SELECT COUNT(*) FROM Tyonhakijat WHERE stat_code = ? AND aika = ?";
                $checkStmt = $conn->prepare($checkQuery);
                if (!$checkStmt) {
                    throw new Exception("Virhe SQL-tarkistuslauseen valmistelussa: " . $conn->error);
                }
                $checkStmt->bind_param("ss", $alueId, $aika);
                $checkStmt->execute();
                $checkStmt->bind_result($count);
                $checkStmt->fetch();
                $checkStmt->close();

                if ($count > 0) {
                    // Päivitetään olemassa oleva tietue
                    $updateQuery = "UPDATE Tyonhakijat SET maakunta_ID=?, stat_label=?, tyottomatlopussa=?, tyotosuus=?, tyottomat20=?, tyottomat25=?, tyottomat50=?, tyottomatulk=?, pitkaaikaistyottomat=?, uudetavp=?, palvelut_yht=?, stat_update_date=NOW() WHERE stat_code=? AND aika=?";
                    $updateStmt = $conn->prepare($updateQuery);
                    if (!$updateStmt) {
                        throw new Exception("Virhe SQL-päivityslauseen valmistelussa: " . $conn->error);
                    }
                    $bindOk = bind_stmt_params($updateStmt, [$maakunta_ID, $alueLabel, $tyottomatlopussa, $tyotosuus, $tyottomat20, $tyottomat25, $tyottomat50, $tyottomatulk, $pitkaaikaistyottomat, $uudetavp, $palvelut_yht, $alueId, $aika]);
                    if (!$bindOk || !$updateStmt->execute()) {
                        throw new Exception("Virhe SQL-päivityslauseen suorittamisessa: " . $updateStmt->error);
                    }
                    $updateStmt->close();
                } else {
                    // Lisätään uusi tietue
                    $query = "INSERT INTO Tyonhakijat (maakunta_ID, stat_code, stat_label, aika, tyottomatlopussa, tyotosuus, tyottomat20, tyottomat25, tyottomat50, tyottomatulk, pitkaaikaistyottomat, uudetavp, palvelut_yht, stat_update_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        throw new Exception("Virhe SQL-lauseen valmistelussa: " . $conn->error);
                    }
                    $bindOk = bind_stmt_params($stmt, [$maakunta_ID, $alueId, $alueLabel, $aika, $tyottomatlopussa, $tyotosuus, $tyottomat20, $tyottomat25, $tyottomat50, $tyottomatulk, $pitkaaikaistyottomat, $uudetavp, $palvelut_yht]);
                    if (!$bindOk || !$stmt->execute()) {
                        throw new Exception("Virhe SQL-lauseen suorittamisessa: " . $stmt->error);
                    }
                    $stmt->close();
                }
            }
        }

        // Suljetaan tietokantayhteys
        $conn->close();

        // Kirjoitetaan onnistumisviesti lokiin
        logMessage("Työttömät haettu ja tallennettu tietokantaan onnistuneesti.");

    } catch (Exception $e) {
        // Kirjoitetaan virheviesti lokiin
        logMessage("Työttömät Virhe: " . $e->getMessage());
        echo "Virhe: " . $e->getMessage() . "\n";
    }
}

// Suoritetaan pääfunktio
main();
?>