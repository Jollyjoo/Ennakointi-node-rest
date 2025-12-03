#!/usr/bin/php
<?php
// sync_from_azure_cron.php - Cron job version for direct server scheduling
// Run via: */2 * * * * /usr/bin/php /path/to/sync_from_azure_cron.php >> /path/to/sync.log 2>&1

require_once 'db.php';

// Log start
error_log("[" . date('Y-m-d H:i:s') . "] Starting Azure queue sync");

try {
    // Azure SQL connection
    $azure_dsn = 'sqlsrv:Server=ennakointisrv.database.windows.net;Database=EnnakointiDB';
    $azure_user = 'Christian';
    $azure_pass = 'Ennakointi24';
    
    $azure_pdo = new PDO($azure_dsn, $azure_user, $azure_pass);
    
    // MySQL connection (using variables from db.php)
    $mysql_pdo = new PDO($dsn, $db_user, $db_pass, [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"]);
    
    // Get unprocessed records from MediaseurantaQueue
    $queue_query = "
        SELECT TOP 50 q.QueueID, q.MediaseurantaID, q.CreatedAt,
               m.Maakunta_ID, m.Teema, m.uutisen_pvm, m.Uutinen, m.Url, m.Hankkeen_luokitus,
               m.ai_analysis_status, m.ai_relevance_score, m.ai_economic_impact,
               m.ai_employment_impact, m.ai_key_sectors, m.ai_sentiment, m.ai_crisis_probability
        FROM MediaseurantaQueue q
        INNER JOIN Mediaseuranta m ON q.MediaseurantaID = m.ID
        WHERE q.ProcessedAt IS NULL
        ORDER BY q.CreatedAt ASC
    ";
    
    $azure_stmt = $azure_pdo->query($queue_query);
    $queue_records = $azure_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($queue_records) > 0) {
        // Prepare MySQL insert
        $mysql_insert = "INSERT INTO Mediaseuranta (
            azure_sync_id, Maakunta_ID, Teema, uutisen_pvm, Uutinen, Url, Hankkeen_luokitus,
            ai_analysis_status, ai_relevance_score, ai_economic_impact,
            ai_employment_impact, ai_key_sectors, ai_sentiment, ai_crisis_probability
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $mysql_stmt = $mysql_pdo->prepare($mysql_insert);
        
        // Prepare Azure queue update
        $update_queue = "UPDATE MediaseurantaQueue SET ProcessedAt = GETDATE() WHERE QueueID = ?";
        $update_stmt = $azure_pdo->prepare($update_queue);
        
        $synced_count = 0;
        $processed_queue_ids = [];
        
        foreach ($queue_records as $record) {
            $result = $mysql_stmt->execute([
                $record['MediaseurantaID'], // Store Azure ID for tracking
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
        
        // Mark queue records as processed
        foreach ($processed_queue_ids as $queue_id) {
            $update_stmt->execute([$queue_id]);
        }
        
        error_log("[" . date('Y-m-d H:i:s') . "] Processed $synced_count queue records and synced to MySQL");
    } else {
        error_log("[" . date('Y-m-d H:i:s') . "] No queue records to process");
    }
    
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Sync error: " . $e->getMessage());
}

// Log end
error_log("[" . date('Y-m-d H:i:s') . "] Azure queue sync completed");
?>