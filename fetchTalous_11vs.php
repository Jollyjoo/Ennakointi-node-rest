<?php
// Tämä ohjelma hakee Tilastokeskuksen API:sta talousindikaattorit ja tallentaa ne MySQL-tietokantaan tauluun Talous.
// Ohjelma lukee JSON-tiedoston, lähettää POST-pyynnön Tilastokeskuksen API:in, parsii vastauksen ja tallentaa tiedot tietokantaan.
// Tietokantataulu Talous rakenne:
//   1 stat_code varchar(10)

//   2 kuluttajienluottamus decimal(10,0)
//   3 omatalous decimal(10,0)
//   4 kuluttajahinnat decimal(10,0)
//   5 tyottomyydenuhka decimal(10,0)

require_once __DIR__ . '/db.php';

$apiUrl = 'https://pxdata.stat.fi/PxWeb/api/v1/fi/StatFin/kbar/statfin_kbar_pxt_11vs.px';
$jsonFile = '_talous_11vs.json';

function getRecentMonths($n = 50) {
    $months = [];
    $base = strtotime(date('Y-m-01'));
    for ($i = 1; $i <= $n; $i++) {
        $months[] = date('Y', strtotime("-$i month", $base)) . 'M' . date('m', strtotime("-$i month", $base));
    }
    return array_reverse($months);
}

function fetchData($apiUrl, $postData) {
    $options = [
        'http' => [
            'header'  => "Content-Type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($postData),
            'timeout' => 10
        ]
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($apiUrl, false, $context);
    if ($result === FALSE) {
        throw new Exception("Virhe datan haussa API:sta");
    }
    return json_decode($result, true);
}

function logMessage($message) {
    $logFile = 'fetch-data.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

function main() {
    global $dsn, $db_user, $db_pass, $apiUrl, $jsonFile;
    try {
        $jsonArray = json_decode(file_get_contents($jsonFile), true);
        // Päivitä Kuukausi-arvot viimeisimmille 12 kuukaudelle
        $recentMonths = getRecentMonths(50);
        foreach ($jsonArray['query'] as &$query) {
            if ($query['code'] === 'Kuukausi') {
                $query['selection']['values'] = $recentMonths;
            }
        }
        unset($query);
        $data = fetchData($apiUrl, $jsonArray);
        $alueIndex = $data['dimension']['Alue']['category']['index'];
        $alueLabels = $data['dimension']['Alue']['category']['label'];
        $kuukausiIndex = $data['dimension']['Kuukausi']['category']['index'];
        $kuukausiLabels = $data['dimension']['Kuukausi']['category']['label'];
        $tiedotIndex = $data['dimension']['Tiedot']['category']['index'];
        $tiedotLabels = $data['dimension']['Tiedot']['category']['label'];
        $alueKeys = array_keys($alueIndex);
        $kuukausiKeys = array_keys($kuukausiIndex);
        $tiedotKeys = array_keys($tiedotIndex);
        $alueCount = count($alueKeys);
        $kuukausiCount = count($kuukausiKeys);
        $tiedotCount = count($tiedotKeys);
        $pdo = new PDO($dsn, $db_user, $db_pass, [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"]);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // value-taulukko on järjestyksessä: for each Alue, Kuukausi, Tiedot
        for ($i = 0; $i < $alueCount; $i++) {
            $alueId = trim($alueKeys[$i]);
            for ($j = 0; $j < $kuukausiCount; $j++) {
                $kuukausi = trim($kuukausiKeys[$j]);
                $kuluttajienluottamus = null;
                $omatalous = null;
                $kuluttajahinnat = null;
                $tyottomyydenuhka = null;
                for ($k = 0; $k < $tiedotCount; $k++) {
                    $offset = $i * $kuukausiCount * $tiedotCount + $j * $tiedotCount + $k;
                    $tiedotKey = $tiedotKeys[$k];
                    $maara = isset($data['value'][$offset]) ? $data['value'][$offset] : null;
                    switch ($tiedotKey) {
                        case 'CCI_A1': $kuluttajienluottamus = $maara; break;
                        case 'CCI_B1': $omatalous = $maara; break;
                        case 'CCI_B5': $kuluttajahinnat = $maara; break;
                        case 'CCI_B8': $tyottomyydenuhka = $maara; break;
                    }
                }
                // Jos kaikki arvot null, älä tallenna
                if ($kuluttajienluottamus === null && $omatalous === null && $kuluttajahinnat === null && $tyottomyydenuhka === null) {
                    continue;
                }
                // Tarkista onko tietue jo olemassa
                $checkQuery = "SELECT COUNT(*) as cnt FROM Talous WHERE stat_code = ? AND kuukausi = ?";
                $checkStmt = $pdo->prepare($checkQuery);
                $checkStmt->execute([$alueId, $kuukausi]);
                $checkRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
                $count = $checkRow ? intval($checkRow['cnt']) : 0;
                $now = date('Y-m-d H:i:s');
                logMessage("DEBUG: stat_code='$alueId', kuukausi='$kuukausi', count=$count");
                if ($count > 0) {
                    logMessage("DEBUG: UPDATE for stat_code='$alueId', kuukausi='$kuukausi'");
                    $updateQuery = "UPDATE Talous SET kuluttajienluottamus=?, omatalous=?, kuluttajahinnat=?, tyottomyydenuhka=?, timestamp=? WHERE stat_code=? AND kuukausi=?";
                    $updateStmt = $pdo->prepare($updateQuery);
                    $updateStmt->execute([$kuluttajienluottamus, $omatalous, $kuluttajahinnat, $tyottomyydenuhka, $now, $alueId, $kuukausi]);
                } else {
                    logMessage("DEBUG: INSERT for stat_code='$alueId', kuukausi='$kuukausi'");
                    $insertQuery = "INSERT INTO Talous (stat_code, kuukausi, kuluttajienluottamus, omatalous, kuluttajahinnat, tyottomyydenuhka, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $insertStmt = $pdo->prepare($insertQuery);
                    $insertStmt->execute([$alueId, $kuukausi, $kuluttajienluottamus, $omatalous, $kuluttajahinnat, $tyottomyydenuhka, $now]);
                }
            }
        }
        logMessage("Talous haettu ja tallennettu tietokantaan onnistuneesti.");
    } catch (Exception $e) {
        logMessage("Talous Virhe: " . $e->getMessage());
        echo "Virhe: " . $e->getMessage() . "\n";
    }
}

main();
