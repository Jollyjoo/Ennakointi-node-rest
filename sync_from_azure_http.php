#!/usr/bin/php
<?php
// sync_from_azure_http.php - Alternative using HTTP API instead of direct SQL connection
// This avoids the SQL Server driver requirement

require_once 'db.php';

// Log start
error_log("[" . date('Y-m-d H:i:s') . "] Starting Azure HTTP queue sync");

try {
    // MySQL connection (this should work since you have MySQL driver)
    $mysql_pdo = new PDO($dsn, $db_user, $db_pass, [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"]);
    
    // Call Azure App Service API using the stable endpoint and action
    $azure_base = 'https://tulevaisuus-fja2fhh4dsesakhj.westeurope-01.azurewebsites.net/api_stub.php';
    $get_queue_url = $azure_base . '?action=get_queue&api_key=your-secret-api-key';
    
    // Use GET for get_queue (proven reliable)
    $response = file_get_contents($get_queue_url);
    
    if ($response === false) {
        throw new Exception("Failed to connect to Azure API");
    }
    
    $data = json_decode($response, true);
    
    // Now we should get success with queue records
    if ($data && ($data['status'] ?? '') !== 'success') {
        throw new Exception("Azure API error: " . $data['message']);
    }
    
    $queue_records = $data['records'] ?? [];
    
    if (count($queue_records) > 0) {
        // Prepare MySQL insert - matches your actual table structure
        $mysql_insert = "INSERT INTO Mediaseuranta (
            Maakunta_ID, Teema, uutisen_pvm, Uutinen, Url, Hankkeen_luokitus,
            ai_analysis_status, ai_relevance_score, ai_economic_impact,
            ai_employment_impact, ai_key_sectors, ai_sentiment, ai_crisis_probability
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $mysql_stmt = $mysql_pdo->prepare($mysql_insert);
        
        $synced_count = 0;
        $processed_queue_ids = [];
        
        foreach ($queue_records as $record) {
            $result = $mysql_stmt->execute([
                $record['Maakunta_ID'],
                $record['Teema'],
                $record['uutisen_pvm'],
                $record['Uutinen'],
                $record['Url'],
                $record['Hankkeen_luokitus'],
                $record['ai_analysis_status'] ?? 'pending',
                $record['ai_relevance_score'],
                $record['ai_economic_impact'],
                $record['ai_employment_impact'],
                $record['ai_key_sectors'],
                $record['ai_sentiment'],
                $record['ai_crisis_probability']
            ]);
            
            if ($result) {
                $synced_count++;
                $processed_queue_ids[] = $record['QueueID'];
            }
        }
        
        // Mark records as processed via api_stub.php GET
        if (count($processed_queue_ids) > 0) {
            $queue_ids_string = implode(',', $processed_queue_ids);
            $mark_processed_url = $azure_base . '?action=mark_processed&queue_ids=' . urlencode($queue_ids_string) . '&api_key=your-secret-api-key';
            $mp_response = file_get_contents($mark_processed_url);
            $mp = json_decode($mp_response, true);
            if (!$mp || ($mp['status'] ?? '') !== 'success') {
                error_log("[" . date('Y-m-d H:i:s') . "] Mark processed call returned: " . ($mp_response ?: 'no response'));
            }
        }
        
        error_log("[" . date('Y-m-d H:i:s') . "] Processed $synced_count queue records via HTTP API");
    } else {
        error_log("[" . date('Y-m-d H:i:s') . "] No queue records to process");
    }
    
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] HTTP sync error: " . $e->getMessage());
}

// Log end
error_log("[" . date('Y-m-d H:i:s') . "] Azure HTTP queue sync completed");
?>