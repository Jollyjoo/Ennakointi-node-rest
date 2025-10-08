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
$apiUrl = 'https://pxdata.stat.fi:443/PxWeb/api/v1/fi/StatFin/tyonv/statfin_tyonv_pxt_12r5.px';
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

// Funktio lokiviestien kirjoittamiseen tiedostoon
function logMessage($message) {
    $logFile = 'fetch-data.log';
    $timestamp = date('Y-m-d H:i:s');
    // Kirjoitetaan viesti lokitiedostoon aikaleiman kanssa
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Funktio, joka generoi viimeisimmät N kuukautta (mukaan lukien viimeisin kuukausi)
function getRecentKuukaudet($n = 50) {
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
        $recentKuukaudet = getRecentKuukaudet(50);
        foreach ($jsonArray['query'] as &$query) {
            if ($query['code'] === 'Kuukausi') {
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
                if ($query['code'] === 'Kuukausi') {
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

        // Parsitaan data
        $alueIndex = $data['dimension']['Alue']['category']['index'];
        $alueLabels = $data['dimension']['Alue']['category']['label'];
        $kuukausiIndex = $data['dimension']['Kuukausi']['category']['index'];
        $kuukausiLabels = $data['dimension']['Kuukausi']['category']['label'];
        $tiedotIndex = $data['dimension']['Tiedot']['category']['index'];
        $tiedotLabels = $data['dimension']['Tiedot']['category']['label'];

        $alueKeys = array_keys($alueIndex);
        $kuukausiKeys = array_keys($kuukausiIndex);
        $tiedotKeys = array_keys($tiedotIndex);

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
                $tyottomatlopussa = isset($data['value'][$offset]) ? $data['value'][$offset] : null;
                $tyotosuus = isset($data['value'][$offset + 1]) ? $data['value'][$offset + 1] : null;
                $tyottomat20 = isset($data['value'][$offset + 2]) ? $data['value'][$offset + 2] : null;
                $tyottomat25 = isset($data['value'][$offset + 3]) ? $data['value'][$offset + 3] : null;
                $tyottomat50 = isset($data['value'][$offset + 4]) ? $data['value'][$offset + 4] : null;
                $tyottomatulk = isset($data['value'][$offset + 5]) ? $data['value'][$offset + 5] : null;
                $pitkaaikaistyottomat = isset($data['value'][$offset + 6]) ? $data['value'][$offset + 6] : null;
                $uudetavp = isset($data['value'][$offset + 7]) ? $data['value'][$offset + 7] : null;
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
                    // Debug: log all update params
                    logMessage("UPDATE PARAMS: maakunta_ID=$maakunta_ID, tyottomatlopussa=$tyottomatlopussa, tyotosuus=$tyotosuus, tyottomat20=$tyottomat20, tyottomat25=$tyottomat25, tyottomat50=$tyottomat50, tyottomatulk=$tyottomatulk, uudetavp=$uudetavp, stat_label=$alueLabel, pitkaaikaistyottomat=$pitkaaikaistyottomat, stat_code=$alueId, aika=$aika");
                    // Päivitetään olemassa oleva tietue
                    $updateQuery = "UPDATE Tyonhakijat SET maakunta_ID=?, tyottomatlopussa=?, tyotosuus=?, tyottomat20=?, tyottomat25=?, tyottomat50=?, tyottomatulk=?, uudetavp=?, stat_label=?, pitkaaikaistyottomat=?, stat_update_date=NOW() WHERE stat_code=? AND aika=?";
                    $updateStmt = $conn->prepare($updateQuery);
                    if (!$updateStmt) {
                        throw new Exception("Virhe SQL-päivityslauseen valmistelussa: " . $conn->error);
                    }
                    $updateStmt->bind_param("idddddddsss", $maakunta_ID, $tyottomatlopussa, $tyotosuus, $tyottomat20, $tyottomat25, $tyottomat50, $tyottomatulk, $uudetavp, $alueLabel, $pitkaaikaistyottomat, $alueId, $aika);
                    if (!$updateStmt->execute()) {
                        throw new Exception("Virhe SQL-päivityslauseen suorittamisessa: " . $updateStmt->error);
                    }
                    $updateStmt->close();
                } else {
                    // Debug: log all insert params
                    logMessage("INSERT PARAMS: maakunta_ID=$maakunta_ID, aika=$aika, tyottomatlopussa=$tyottomatlopussa, tyotosuus=$tyotosuus, tyottomat20=$tyottomat20, tyottomat25=$tyottomat25, tyottomat50=$tyottomat50, tyottomatulk=$tyottomatulk, uudetavp=$uudetavp, stat_code=$alueId, stat_label=$alueLabel, pitkaaikaistyottomat=$pitkaaikaistyottomat");
                    // Lisätään uusi tietue
                    $query = "INSERT INTO Tyonhakijat (Maakunta_ID, Aika, tyottomatlopussa, tyotosuus, tyottomat20, tyottomat25, tyottomat50, tyottomatulk, uudetavp, stat_code, stat_label, pitkaaikaistyottomat, stat_update_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        throw new Exception("Virhe SQL-lauseen valmistelussa: " . $conn->error);
                    }
                    $stmt->bind_param("isidddddddsss", $maakunta_ID, $aika, $tyottomatlopussa, $tyotosuus, $tyottomat20, $tyottomat25, $tyottomat50, $tyottomatulk, $uudetavp, $alueId, $alueLabel, $pitkaaikaistyottomat);
                    if (!$stmt->execute()) {
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