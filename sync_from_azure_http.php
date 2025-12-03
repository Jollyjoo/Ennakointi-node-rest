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
    
    // Instead of connecting directly to Azure SQL, call a PHP endpoint on Azure
    // You'll need to create azure_api.php on Azure App Service (free tier)
    $azure_api_url = 'https://tulevaisuus-fja2fhh4dsesakhj.westeurope-01.azurewebsites.net/azure_api.php';
    $api_key = 'your-secret-api-key';
    
    // Get queue data via HTTP
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "X-API-Key: $api_key\r\n",
            'timeout' => 30
        ]
    ]);
    
    $response = file_get_contents($azure_api_url, false, $context);
    
    if ($response === false) {
        throw new Exception("Failed to connect to Azure API");
    }
    
    $data = json_decode($response, true);
    
    if ($data['status'] !== 'success') {
        throw new Exception("Azure API error: " . $data['message']);
    }
    
    $queue_records = $data['records'];
    
    if (count($queue_records) > 0) {
        // Prepare MySQL insert
        $mysql_insert = "INSERT INTO Mediaseuranta (
            azure_sync_id, Maakunta_ID, Teema, uutisen_pvm, Uutinen, Url, Hankkeen_luokitus,
            ai_analysis_status, ai_relevance_score, ai_economic_impact,
            ai_employment_impact, ai_key_sectors, ai_sentiment, ai_crisis_probability
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $mysql_stmt = $mysql_pdo->prepare($mysql_insert);
        
        $synced_count = 0;
        $processed_queue_ids = [];
        
        foreach ($queue_records as $record) {
            $result = $mysql_stmt->execute([
                $record['MediaseurantaID'],
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
        
        // Mark records as processed via HTTP
        if (count($processed_queue_ids) > 0) {
            $mark_processed_url = $azure_api_url . '?action=mark_processed';
            $post_data = http_build_query(['queue_ids' => implode(',', $processed_queue_ids)]);
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "X-API-Key: $api_key\r\nContent-type: application/x-www-form-urlencoded\r\n",
                    'content' => $post_data,
                    'timeout' => 30
                ]
            ]);
            
            file_get_contents($mark_processed_url, false, $context);
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