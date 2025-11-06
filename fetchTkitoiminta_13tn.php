<?php
// Tämä ohjelma hakee Tilastokeskuksen API:sta T&K-toiminnan tiedot ja tallentaa ne MySQL-tietokantaan.
// Ohjelma lukee JSON-tiedoston, lähettää POST-pyynnön Tilastokeskuksen API:in, parsii vastauksen ja tallentaa tiedot tietokantaan.
// Tietokantataulu rakenne:
//   1 stat_code varchar(11)
//   2 vuosi int(5)
//   3 timestamp timestamp
//   4 sektori varchar(50)
//   5 tkmenot int(11)
//   6 tkhenkilosto int(11)
//   7 tktyovuodet int(11)
//   8 last_data timestamp

require_once __DIR__ . '/db.php';

$apiUrl = 'https://pxdata.stat.fi/PxWeb/api/v1/fi/StatFin/tkke/statfin_tkke_pxt_13tn.px';
$jsonFile = '_tkitoiminta_13tn.json';

function getRecentYears($n = 4) {
    $years = [];
    $currentYear = (int)date('Y');
    // Start from 1 year ago since 2024 data is now available
    for ($i = $n; $i >= 1; $i--) {
        $years[] = (string)($currentYear - $i);
    }
    return $years;
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
    
    // Log the outgoing request for debugging
    logMessage("Outgoing API JSON: " . json_encode($postData));
    
    $result = file_get_contents($apiUrl, false, $context);
    if ($result === FALSE) {
        // Get more detailed error information
        $error = error_get_last();
        if ($error) {
            logMessage("API error_get_last: " . print_r($error, true));
        }
        
        // Try to get HTTP response headers
        if (isset($http_response_header)) {
            logMessage("HTTP response headers: " . print_r($http_response_header, true));
        }
        
        throw new Exception("Virhe datan haussa API:sta");
    }
    
    // Log successful response (first 500 chars for debugging)
    logMessage("API response received (first 500 chars): " . substr($result, 0, 500));
    
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
        // Read JSON file and extract the actual query from queryObj
        $jsonArray = json_decode(file_get_contents($jsonFile), true);
        if (!$jsonArray) {
            throw new Exception("Failed to parse JSON file: " . $jsonFile);
        }
        
        logMessage("JSON file loaded successfully");
        
        $queryData = $jsonArray['queryObj']; // Extract the actual query structure
        if (!$queryData) {
            throw new Exception("queryObj not found in JSON file");
        }
        
        // Päivitä Vuosi-arvot viimeisimmille 4 vuodelle (including 2024)
        $recentYears = getRecentYears(4);
        logMessage("Using years: " . implode(", ", $recentYears));
        
        // Try to fetch data, if API returns error, drop the most recent year and try again
        $maxTries = count($recentYears);
        $success = false;
        for ($try = $maxTries; $try >= 1; $try--) {
            $yearsToTry = array_slice($recentYears, 0, $try); // Drop most recent years if they fail
            logMessage("Trying years: " . implode(",", $yearsToTry));
            
            foreach ($queryData['query'] as &$query) {
                if ($query['code'] === 'Vuosi') {
                    $query['selection']['values'] = $yearsToTry;
                }
            }
            unset($query);
            
            try {
                $data = fetchData($apiUrl, $queryData);
                logMessage("API success for years: " . implode(",", $yearsToTry));
                $success = true;
                break; // Success, break out of the loop
            } catch (Exception $apiEx) {
                logMessage("API error for years: " . implode(",", $yearsToTry) . " - " . $apiEx->getMessage());
                // Try again with one less year (from the newest)
            }
        }
        
        if (!$success) {
            throw new Exception("API failed for all attempted years: " . implode(",", $recentYears));
        }
        
        // Get dataset-level updated timestamp and convert to MySQL DATETIME
        $last_data = isset($data['updated']) ? $data['updated'] : null;
        if ($last_data) {
            try {
                $dt = new DateTime($last_data);
                $last_data = $dt->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                logMessage("WARNING: failed to parse last_data timestamp: " . $last_data);
                $last_data = null;
            }
        }
        
        $vuosiIndex = $data['dimension']['Vuosi']['category']['index'];
        $vuosiLabels = $data['dimension']['Vuosi']['category']['label'];
        $alueIndex = $data['dimension']['Alue']['category']['index'];
        $alueLabels = $data['dimension']['Alue']['category']['label'];
        $sektoriIndex = $data['dimension']['Sektori']['category']['index'];
        $sektoriLabels = $data['dimension']['Sektori']['category']['label'];
        $tiedotIndex = $data['dimension']['Tiedot']['category']['index'];
        $tiedotLabels = $data['dimension']['Tiedot']['category']['label'];
        
        // Build Tiedot code => index mapping for dynamic access
        $tiedot_map = $tiedotIndex;
        
        $vuosiKeys = array_keys($vuosiIndex);
        $alueKeys = array_keys($alueIndex);
        $sektoriKeys = array_keys($sektoriIndex);
        $tiedotKeys = array_keys($tiedotIndex);
        
        $vuosiCount = count($vuosiKeys);
        $alueCount = count($alueKeys);
        $sektoriCount = count($sektoriKeys);
        $tiedotCount = count($tiedotKeys);
        
        $pdo = new PDO($dsn, $db_user, $db_pass, [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"]);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // value-taulukko on järjestyksessä: for each Vuosi, Alue, Sektori, Tiedot
        for ($i = 0; $i < $vuosiCount; $i++) {
            $vuosi = trim($vuosiKeys[$i]);
            for ($j = 0; $j < $alueCount; $j++) {
                $stat_code = trim($alueKeys[$j]);
                for ($k = 0; $k < $sektoriCount; $k++) {
                    $sektoriKey = $sektoriKeys[$k];
                    $sektori = isset($sektoriLabels[$sektoriKey]) ? trim($sektoriLabels[$sektoriKey]) : $sektoriKey;
                    
                    // Dynamically extract the three Tiedot values
                    $tkmenot = null;
                    $tkhenkilosto = null;
                    $tktyovuodet = null;
                    
                    $offset = $i * $alueCount * $sektoriCount * $tiedotCount + $j * $sektoriCount * $tiedotCount + $k * $tiedotCount;
                    
                    // Extract values using dynamic mapping
                    if (isset($tiedot_map['tk_menot'])) {
                        $tkmenot = isset($data['value'][$offset + $tiedot_map['tk_menot']]) ? (int)$data['value'][$offset + $tiedot_map['tk_menot']] : null;
                    }
                    if (isset($tiedot_map['tk_hlomaara'])) {
                        $tkhenkilosto = isset($data['value'][$offset + $tiedot_map['tk_hlomaara']]) ? (int)$data['value'][$offset + $tiedot_map['tk_hlomaara']] : null;
                    }
                    if (isset($tiedot_map['tk_htv'])) {
                        $tktyovuodet = isset($data['value'][$offset + $tiedot_map['tk_htv']]) ? (int)$data['value'][$offset + $tiedot_map['tk_htv']] : null;
                    }
                    
                    // Jos kaikki arvot null, älä tallenna
                    if ($tkmenot === null && $tkhenkilosto === null && $tktyovuodet === null) {
                        continue;
                    }
                    
                    $now = date('Y-m-d H:i:s');
                    $insertQuery = "INSERT INTO Tki (stat_code, vuosi, sektori, tkmenot, tkhenkilosto, tktyovuodet, last_data, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?)\n" .
                        "ON DUPLICATE KEY UPDATE tkmenot=VALUES(tkmenot), tkhenkilosto=VALUES(tkhenkilosto), tktyovuodet=VALUES(tktyovuodet), last_data=VALUES(last_data), timestamp=VALUES(timestamp)";
                    $insertStmt = $pdo->prepare($insertQuery);
                    $insertStmt->execute([$stat_code, $vuosi, $sektori, $tkmenot, $tkhenkilosto, $tktyovuodet, $last_data, $now]);
                }
            }
        }
        
        logMessage("T&K-toiminta haettu ja tallennettu tietokantaan onnistuneesti.");
    } catch (Exception $e) {
        logMessage("T&K-toiminta Virhe: " . $e->getMessage());
        echo "Virhe: " . $e->getMessage() . "\n";
    }
}

main();