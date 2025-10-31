<?php
// testaus: KÄYNNISTÄ TERMINALISSA KOMENNOLLA: php fetchTyottomat_12r5.php
// tuotannossa: Aja tämä tiedosto crontabissa säännöllisesti, esim. kerran kuukaudessa.
// tilasto päivitetään automaattisesti, kun Tilastokeskus julkaisee uuden tilaston.
// tilasto julkaistaan yleensä 25. päivänä kuukaudessa, joten voit ajastaa tämän ohjelman ajettavaksi esim. 26. päivä kuukaudessa.

// Tämä ohjelma hakee Tilastokeskuksen API:sta työttömät työnhakijat kuukausittain ja tallentaa ne MySQL-tietokantaan.
// Ohjelma lukee JSON-tiedoston, lähettää POST-pyynnön Tilastokeskuksen API:in, parsii vastauksen ja tallentaa tiedot tietokantaan.
// Ohjelma ajetaan aluksi kerran ja sen jälkeen säännöllisin väliajoin määritetyn aikavälin mukaan.
// Ohjelma yrittää hakea tilastokeskukselta viimeisimmät kolme kuukautta, ja jos API palauttaa virheen (tilastoa ei vielä tullut), se pudottaa viimeisimmän kuukauden ja yrittää uudelleen.


// MySQL Database configuration
$servername = "tulevaisuusluotain.fi";
$username = "catbxjbt_Christian";
$password = "Juustonaksu5";
$dbname = "catbxjbt_ennakointi";

// Tilastokeskuksen API endpoint tilastolle
$apiUrl = 'https://pxdata.stat.fi:443/PxWeb/api/v1/fi/StatFin/vkour/statfin_vkour_pxt_12bs.px';
$jsonFile = '_valmistuneet_12bs.json';

// Function to read the JSON file
function getData($jsonFile) {
    if (!file_exists($jsonFile)) {
        throw new Exception("JSON file not found: $jsonFile");
    }
    $data = file_get_contents($jsonFile);
    return json_decode($data, true);
}

// Function to fetch data from the API
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
        throw new Exception("Error fetching data from API");
    }
    return json_decode($result, true);
}

// Function to log success or error messages to a file
function logMessage($message) {
    $logFile = 'fetch-data.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Function to generate Kuukausi values for the last N months (including last month)
function getRecentKuukaudet($n = 3) {
    $months = [];
    for ($i = 1; $i <= $n; $i++) {
        $months[] = date('Y', strtotime("-$i month")) . 'M' . date('m', strtotime("-$i month"));
    }
    return array_reverse($months); // Newest first, oldest last
}

// Main function
function main() {
    global $servername, $username, $password, $dbname, $apiUrl, $jsonFile;

    try {
        // Read JSON file as array
        $jsonArray = json_decode(file_get_contents($jsonFile), true);

        // Dynamically set Kuukausi values to last 3 months (change as needed)
        $recentKuukaudet = getRecentKuukaudet(3); // e.g. [2025M03, 2025M04, 2025M05]
        $recentKuukaudet = array_reverse($recentKuukaudet); // Now: [2025M05, 2025M04, 2025M03]
        foreach ($jsonArray['query'] as &$query) {
            if ($query['code'] === 'Kuukausi') {
                $query['selection']['values'] = $recentKuukaudet;
            }
        }
        unset($query);

        // Use this updated array for the API request
        $newStatData = $jsonArray;

        // Try to fetch data, if API returns error, drop the most recent month and try again
        $maxTries = count($recentKuukaudet); // Try up to N times (for N months)
        $success = false;
        for ($try = $maxTries; $try >= 1; $try--) {
            $monthsToTry = array_slice($recentKuukaudet, 1 - $try); // Drops the most recent month each time
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
                break; // Success, break out of the loop
            } catch (Exception $apiEx) {
                logMessage("Valmistuneet API error for months: " . implode(",", $monthsToTry) . " - " . $apiEx->getMessage());
                // Try again with one less month (from the newest)
            }
        }
        if (!$success) {
            throw new Exception("API failed for all recent months");
        }

    // Parse the data
    // Get dataset-level updated timestamp and convert to MySQL DATETIME (Y-m-d H:i:s)
    $stat_latest = isset($data['updated']) ? $data['updated'] : null;
    if ($stat_latest) {
        try {
            $dt = new DateTime($stat_latest);
            // Convert to server local time in 'Y-m-d H:i:s' format suitable for TIMESTAMP/DATETIME
            $stat_latest = $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            // If parsing fails, set to null and log
            logMessage("WARNING: failed to parse stat_latest timestamp: " . $stat_latest);
            $stat_latest = null;
        }
    }
        $alueIndex = $data['dimension']['Alue']['category']['index'];
        $alueLabels = $data['dimension']['Alue']['category']['label'];
        $vuosiIndex = $data['dimension']['Vuosi']['category']['index'];
        $vuosiLabels = $data['dimension']['Vuosi']['category']['label'];
        $tiedotIndex = $data['dimension']['Tiedot']['category']['index'];
        $tiedotLabels = $data['dimension']['Tiedot']['category']['label'];

        $alueKeys = array_keys($alueIndex);
        $vuosiKeys = array_keys($vuosiIndex);
        $tiedotKeys = array_keys($tiedotIndex);

        // Connect to MySQL Database
        $conn = new mysqli($servername, $username, $password, $dbname);
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }

        // The value array is ordered as: for each vuosi, for each alue, for each tiedot
        $alueCount = count($alueKeys);
        $vuosiCount = count($vuosiKeys);
        $tiedotCount = count($tiedotKeys);
        $valueIndex = 0;
        for ($i = 0; $i < $vuosiCount; $i++) {
            $vuosiId = $vuosiKeys[$i];
            $vuosiLabel = isset($vuosiLabels[$vuosiId]) ? $vuosiLabels[$vuosiId] : $vuosiId;
            for ($j = 0; $j < $alueCount; $j++) {
                $alueId = $alueKeys[$j];
                $alueLabel = isset($alueLabels[$alueId]) ? $alueLabels[$alueId] : $alueId;
                $offset = ($i * $alueCount + $j) * $tiedotCount;
                $perusjalk = isset($data['value'][$offset]) ? $data['value'][$offset] : null;
                $toisenjalk = isset($data['value'][$offset + 1]) ? $data['value'][$offset + 1] : null;
                $korkeajalk = isset($data['value'][$offset + 2]) ? $data['value'][$offset + 2] : null;
                // Check if record exists for this alue, vuosi
                $checkQuery = "SELECT COUNT(*) FROM Opiskelijat WHERE stat_code = ? AND Vuosi = ?";
                $checkStmt = $conn->prepare($checkQuery);
                if (!$checkStmt) {
                    throw new Exception("Error preparing check SQL statement: " . $conn->error);
                }
                $checkStmt->bind_param("ss", $alueId, $vuosiId);
                $checkStmt->execute();
                $checkStmt->bind_result($count);
                $checkStmt->fetch();
                $checkStmt->close();

                if ($count > 0) {
                    // Update existing record
                    $updateQuery = "UPDATE Opiskelijat SET stat_label=?, perusjalk=?, toisenjalk=?, korkeajalk=?, stat_latest=?, stat_update_date=NOW() WHERE stat_code=? AND Vuosi=?";
                    $updateStmt = $conn->prepare($updateQuery);
                    if (!$updateStmt) {
                        throw new Exception("Error preparing update SQL statement: " . $conn->error);
                    }
                    $updateStmt->bind_param("sdddsss", $alueLabel, $perusjalk, $toisenjalk, $korkeajalk, $stat_latest, $alueId, $vuosiId);
                    if (!$updateStmt->execute()) {
                        throw new Exception("Error executing update SQL statement: " . $updateStmt->error);
                    }
                    $updateStmt->close();
                } else {
                    // Insert new record
                    $query = "INSERT INTO Opiskelijat (stat_code, stat_label, Vuosi, perusjalk, toisenjalk, korkeajalk, stat_latest, stat_update_date) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        throw new Exception("Error preparing SQL statement: " . $conn->error);
                    }
                    $stmt->bind_param("sssddds", $alueId, $alueLabel, $vuosiId, $perusjalk, $toisenjalk, $korkeajalk, $stat_latest);
                    if (!$stmt->execute()) {
                        throw new Exception("Error executing SQL statement: " . $stmt->error);
                    }
                    $stmt->close();
                }
            }
        }

        // Close the database connection
        $conn->close();

        // Log success message
        logMessage("Valmistuneet data successfully fetched and inserted into the database.");

    } catch (Exception $e) {
        // Log error message
        logMessage("Valmistuneet Error: " . $e->getMessage());
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// Run the main function
main();
?>