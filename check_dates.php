<?php
/**
 * Product Date Checker Script
 * Monitors product dates from multiple URLs and sends email notifications on changes
 */

// Load Composer autoloader for phpseclib
require_once __DIR__ . '/vendor/autoload.php';

use phpseclib3\Net\SSH2;

// Load environment variables
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        die("Error: .env file not found at {$filePath}\n");
    }
    
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];
    
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B'\"");
        $env[$key] = $value;
    }
    
    return $env;
}

// Load environment variables
$env = loadEnv(__DIR__ . '/.env');

// Product URLs to monitor (loaded from .env)
$productUrls = array_map('trim', explode(',', $env['PRODUCT_URLS']));

// Configuration
$logDir = __DIR__ . '/logs';
$logFile = $logDir . '/product_dates.json';

// Create logs directory if it doesn't exist
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

/**
 * Fetch HTML content from URL with retry logic
 */
function fetchUrl($url, $retries = 3) {
    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1'
        ]);
        
        // Use system DNS resolver
        curl_setopt($ch, CURLOPT_DNS_USE_GLOBAL_CACHE, false);
        curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 2);
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch);
        $errorMsg = curl_error($ch);
        
        curl_close($ch);
        
        // If successful, return immediately
        if (!$curlError && $httpCode === 200 && $html) {
            return ['success' => true, 'html' => $html];
        }
        
        // If this was the last attempt, return the error
        if ($attempt === $retries) {
            if ($curlError) {
                return ['success' => false, 'error' => $errorMsg];
            }
            if ($httpCode !== 200) {
                return ['success' => false, 'error' => "HTTP {$httpCode}"];
            }
            return ['success' => false, 'error' => 'Unknown error'];
        }
        
        // Wait before retry (exponential backoff)
        sleep($attempt);
    }
    
    return ['success' => false, 'error' => 'Max retries exceeded'];
}

/**
 * Extract product date from HTML
 */
function extractProductDate($html) {
    // Load HTML into DOMDocument
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    
    // Find h1 tag
    $h1Tags = $dom->getElementsByTagName('h1');
    
    foreach ($h1Tags as $h1) {
        $text = $h1->textContent;
        
        // Pattern: DDMMYYYY_HHMMSS (e.g., 01102025_205131)
        if (preg_match('/(\d{2})(\d{2})(\d{4})_\d{6}/', $text, $matches)) {
            $day = $matches[1];
            $month = $matches[2];
            $year = $matches[3];
            
            return [
                'raw' => $matches[0],
                'date' => "{$day}/{$month}/{$year}",
                'day' => $day,
                'month' => $month,
                'year' => $year,
                'full_text' => trim($text)
            ];
        }
    }
    
    return null;
}

/**
 * Get site name from URL
 */
function getSiteName($url) {
    $domain = parse_url($url, PHP_URL_HOST);
    
    // Remove www. prefix
    $domain = preg_replace('/^www\./', '', $domain);
    
    // Extract main domain name
    $parts = explode('.', $domain);
    if (count($parts) > 1) {
        return ucfirst($parts[0]);
    }
    
    return ucfirst($domain);
}

/**
 * Send email via SMTP to multiple recipients
 */
function sendEmail($env, $subject, $body) {
    // Parse recipients (comma-separated)
    $recipients = array_map('trim', explode(',', $env['RECIPIENT_EMAIL']));
    $from = $env['SMTP_USER'];
    $host = $env['SMTP_HOSTNAME'];
    $port = $env['SMTP_PORT'];
    $username = $env['SMTP_USER'];
    $password = $env['SMTP_PASS'];
    
    // Helper function to read multi-line SMTP responses
    $readResponse = function($smtp) {
        $response = '';
        while ($line = fgets($smtp, 515)) {
            $response .= $line;
            // Check if this is the last line (doesn't start with code followed by hyphen)
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }
        return $response;
    };
    
    // Create socket connection
    $smtp = fsockopen('ssl://' . $host, $port, $errno, $errstr, 30);
    
    if (!$smtp) {
        echo "SMTP Connection Error: {$errstr} ({$errno})\n";
        return false;
    }
    
    // Read server greeting
    $response = $readResponse($smtp);
    
    // Send EHLO
    fputs($smtp, "EHLO {$host}\r\n");
    $response = $readResponse($smtp);
    
    // Send AUTH LOGIN
    fputs($smtp, "AUTH LOGIN\r\n");
    $response = $readResponse($smtp);
    
    // Send username
    fputs($smtp, base64_encode($username) . "\r\n");
    $response = $readResponse($smtp);
    
    // Send password
    fputs($smtp, base64_encode($password) . "\r\n");
    $response = $readResponse($smtp);
    
    if (strpos($response, '235') === false) {
        echo "SMTP Authentication Failed: {$response}\n";
        fclose($smtp);
        return false;
    }
    
    // Send MAIL FROM
    fputs($smtp, "MAIL FROM: <{$from}>\r\n");
    $response = $readResponse($smtp);
    
    if (strpos($response, '250') === false) {
        echo "MAIL FROM failed: {$response}\n";
        fclose($smtp);
        return false;
    }
    
    // Send RCPT TO for each recipient
    $successfulRecipients = [];
    foreach ($recipients as $recipient) {
        if (!empty($recipient)) {
            fputs($smtp, "RCPT TO: <{$recipient}>\r\n");
            $response = $readResponse($smtp);
            
            if (strpos($response, '250') !== false) {
                $successfulRecipients[] = $recipient;
                echo "  ✓ Recipient accepted: {$recipient}\n";
            } else {
                echo "  ✗ Recipient rejected: {$recipient} - {$response}\n";
            }
        }
    }
    
    if (empty($successfulRecipients)) {
        echo "No recipients accepted by server\n";
        fclose($smtp);
        return false;
    }
    
    // Send DATA
    fputs($smtp, "DATA\r\n");
    $response = $readResponse($smtp);
    
    // Send email headers and body
    $headers = "From: Product Date Checker <{$from}>\r\n";
    $headers .= "To: " . implode(', ', $recipients) . "\r\n";
    $headers .= "Subject: {$subject}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "\r\n";
    
    fputs($smtp, $headers . $body . "\r\n.\r\n");
    $response = $readResponse($smtp);
    
    // Send QUIT
    fputs($smtp, "QUIT\r\n");
    fclose($smtp);
    
    return true;
}

/**
 * Check remote file modification time via SSH
 */
function checkRemoteFileModification($env) {
    $sshHost = $env['SSH_HOST'];
    $sshPort = (int)$env['SSH_PORT'];
    $sshUser = $env['SSH_USER'];
    $sshPass = $env['SSH_PASS'] ?? '';
    $remoteFile = $env['SSH_REMOTE_FILE'];
    
    // Local file to store the last known timestamp
    $timestampFile = __DIR__ . '/logs/remote_file_timestamp.txt';
    
    echo "Checking remote file modification via SSH...\n";
    echo "  Host: {$sshUser}@{$sshHost}:{$sshPort}\n";
    echo "  File: {$remoteFile}\n";
    
    try {
        // Create SSH connection using phpseclib
        $ssh = new SSH2($sshHost, $sshPort);
        $ssh->setTimeout(10);
        
        // Login with password
        if (!$ssh->login($sshUser, $sshPass)) {
            return [
                'success' => false,
                'error' => 'SSH authentication failed',
                'changed' => false
            ];
        }
        
        echo "  ✓ SSH connection established\n";
        
        // Execute stat command to get file modification timestamp
        $output = $ssh->exec("stat -c %Y {$remoteFile}");
        
        // Disconnect
        $ssh->disconnect();
        
        if ($output === false || trim($output) === '') {
            return [
                'success' => false,
                'error' => 'Failed to execute remote command',
                'changed' => false
            ];
        }
        
        $newTimestamp = trim($output);
        
        // Validate that we got a numeric timestamp
        if (!is_numeric($newTimestamp)) {
            return [
                'success' => false,
                'error' => "Invalid timestamp received: {$newTimestamp}",
                'changed' => false
            ];
        }
        
        echo "  ✓ Current remote file timestamp: {$newTimestamp}\n";
        
        // Convert timestamp to readable date
        $readableDate = date('Y-m-d H:i:s', (int)$newTimestamp);
        echo "  ✓ Last modified: {$readableDate}\n";
        
        // Load previous timestamp if exists
        $oldTimestamp = null;
        $oldReadableDate = 'N/A';
        $hasChanged = false;
        
        if (file_exists($timestampFile)) {
            $oldTimestamp = trim(file_get_contents($timestampFile));
            if (is_numeric($oldTimestamp)) {
                $oldReadableDate = date('Y-m-d H:i:s', (int)$oldTimestamp);
                echo "  ℹ Previous timestamp: {$oldTimestamp} ({$oldReadableDate})\n";
                
                // Compare timestamps
                if ((int)$newTimestamp > (int)$oldTimestamp) {
                    $hasChanged = true;
                    echo "  ⚠ FILE MODIFIED! Remote file has been updated.\n";
                } else {
                    echo "  ✓ No changes detected.\n";
                }
            }
        } else {
            echo "  ℹ No previous timestamp found. This is the first check.\n";
        }
        
        // Save new timestamp
        file_put_contents($timestampFile, $newTimestamp);
        
        return [
            'success' => true,
            'changed' => $hasChanged,
            'old_timestamp' => $oldTimestamp,
            'new_timestamp' => $newTimestamp,
            'old_date' => $oldReadableDate,
            'new_date' => $readableDate,
            'remote_file' => $remoteFile
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'SSH connection error: ' . $e->getMessage(),
            'changed' => false
        ];
    }
}

/**
 * Generate HTML email body with responsive table
 */
function generateEmailBody($changes, $currentData, $previousData, $sshCheckResult = null) {
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        .container { max-width: 1000px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px 20px; border-radius: 10px; text-align: center; }
        .header h1 { margin: 0 0 10px 0; font-size: 28px; }
        .header p { margin: 0; font-size: 14px; opacity: 0.9; }
        .content { background: #f9f9f9; padding: 20px; margin-top: 20px; border-radius: 10px; }
        .summary { background: white; padding: 15px; margin-bottom: 20px; border-radius: 8px; border-left: 4px solid #667eea; }
        
        /* Table Styles */
        .table-wrapper { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        thead { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        th { padding: 15px 10px; text-align: left; font-weight: 600; font-size: 14px; }
        td { padding: 15px 10px; border-bottom: 1px solid #e0e0e0; font-size: 13px; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #f5f5f5; }
        
        /* Site name column */
        .site-cell { width: 120px; text-align: left; font-weight: 600; color: #667eea; }
        
        /* Product link column */
        .product-link { max-width: 250px; word-break: break-word; }
        .product-link a { color: #667eea; text-decoration: none; font-weight: 500; }
        .product-link a:hover { text-decoration: underline; }
        
        /* Date columns */
        .date-cell { font-family: "Courier New", monospace; font-weight: 600; white-space: nowrap; }
        .old-date { color: #999; }
        .new-date { color: #333; }
        
        /* Status column */
        .status-cell { text-align: center; width: 100px; }
        .status-changed { color: #4CAF50; font-size: 24px; }
        .status-unchanged { color: #f44336; font-size: 24px; }
        
        .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 12px; text-align: center; }
        
        /* Mobile Responsive - Strict */
        @media only screen and (max-width: 768px) {
            .container { padding: 10px !important; max-width: 100% !important; }
            .header { padding: 20px 10px !important; }
            .header h1 { font-size: 20px !important; }
            .header p { font-size: 12px !important; }
            .content { padding: 10px !important; }
            .summary { font-size: 12px !important; padding: 10px !important; }
            table { font-size: 11px !important; }
            th, td { padding: 8px 4px !important; font-size: 11px !important; }
            .site-cell { width: 80px !important; font-size: 10px !important; }
            .product-link { max-width: 120px !important; font-size: 10px !important; word-break: break-all !important; }
            .date-cell { font-size: 10px !important; }
            .status-cell { width: 40px !important; }
            .status-changed, .status-unchanged { font-size: 16px !important; }
        }
        
        @media only screen and (max-width: 480px) {
            .container { padding: 5px !important; }
            .header { padding: 15px 5px !important; border-radius: 5px !important; }
            .header h1 { font-size: 18px !important; }
            .content { padding: 5px !important; border-radius: 5px !important; }
            .summary { font-size: 11px !important; padding: 8px !important; margin-bottom: 10px !important; }
            table { font-size: 10px !important; }
            th, td { padding: 6px 2px !important; font-size: 10px !important; }
            .site-cell { width: 60px !important; font-size: 9px !important; }
            .product-link { max-width: 100px !important; font-size: 9px !important; }
            .date-cell { font-size: 9px !important; white-space: normal !important; }
            .status-cell { width: 35px !important; }
            .status-changed, .status-unchanged { font-size: 14px !important; }
            .footer { font-size: 10px !important; padding: 10px 5px !important; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 Product Date Checker Report</h1>
            <p>Generated on ' . date('F j, Y \a\t g:i A') . '</p>
        </div>
        
        <div class="content">';
    
    // Summary section
    $changedCount = count($changes);
    $totalCount = count($currentData);
    $unchangedCount = $totalCount - $changedCount;
    
    $html .= '<div class="summary">';
    if ($changedCount > 0) {
        $html .= '<strong style="color: #4CAF50;">⚠️ ' . $changedCount . ' product(s) changed</strong> | ';
    }
    $html .= '<strong style="color: #2196F3;">' . $unchangedCount . ' product(s) unchanged</strong> | ';
    $html .= '<strong>Total: ' . $totalCount . ' products monitored</strong>';
    
    // Add SSH check result to summary
    if ($sshCheckResult !== null && $sshCheckResult['success']) {
        $html .= '<br><br>';
        if ($sshCheckResult['changed']) {
            $html .= '<strong style="color: #FF9800;">🔄 Remote file MODIFIED</strong> | ';
        } else {
            $html .= '<strong style="color: #4CAF50;">✓ Remote file unchanged</strong> | ';
        }
        $html .= '<strong>Last checked: ' . htmlspecialchars($sshCheckResult['new_date']) . '</strong>';
    } elseif ($sshCheckResult !== null && !$sshCheckResult['success']) {
        $html .= '<br><br>';
        $html .= '<strong style="color: #f44336;">✗ SSH check failed: ' . htmlspecialchars($sshCheckResult['error']) . '</strong>';
    }
    
    $html .= '</div>';
    
    // Table
    $html .= '<div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th class="site-cell">Site</th>
                    <th class="product-link">Product Link</th>
                    <th>Old Date</th>
                    <th>Current Date</th>
                    <th class="status-cell">Status</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($currentData as $url => $data) {
        $siteName = getSiteName($url);
        $hasChanged = isset($changes[$url]);
        $oldDate = $hasChanged ? $changes[$url]['old'] : ($previousData[$url]['date'] ?? 'N/A');
        $newDate = $data['date'];
        
        $html .= '<tr>
            <td class="site-cell">' . htmlspecialchars($siteName) . '</td>
            <td class="product-link"><a href="' . htmlspecialchars($url) . '" target="_blank">' . htmlspecialchars($url) . '</a></td>
            <td class="date-cell old-date">' . htmlspecialchars($oldDate) . '</td>
            <td class="date-cell new-date">' . htmlspecialchars($newDate) . '</td>
            <td class="status-cell">';
        
        // Show ✓ if no old date (first run) or if date changed
        // Show ✗ only if old date exists and hasn't changed
        if ($oldDate === 'N/A' || $hasChanged) {
            $html .= '<span class="status-changed" title="Date Changed">✓</span>';
        } else {
            $html .= '<span class="status-unchanged" title="No Change">✗</span>';
        }
        
        $html .= '</td>
        </tr>';
    }
    
    $html .= '</tbody>
        </table>
    </div>';
    
    // Add SSH check details section if available
    if ($sshCheckResult !== null && $sshCheckResult['success']) {
        $html .= '<div style="margin-top: 20px; background: white; padding: 15px; border-radius: 8px; border-left: 4px solid #FF9800;">';
        $html .= '<h3 style="margin-top: 0; color: #FF9800;">📁 Remote File Check (SSH)</h3>';
        $html .= '<table style="width: 100%; border: none; box-shadow: none;">';
        $html .= '<tr><td style="border: none; padding: 5px 0;"><strong>Remote File:</strong></td><td style="border: none; padding: 5px 0;">' . htmlspecialchars($sshCheckResult['remote_file']) . '</td></tr>';
        $html .= '<tr><td style="border: none; padding: 5px 0;"><strong>Previous Modified:</strong></td><td style="border: none; padding: 5px 0;">' . htmlspecialchars($sshCheckResult['old_date']) . '</td></tr>';
        $html .= '<tr><td style="border: none; padding: 5px 0;"><strong>Current Modified:</strong></td><td style="border: none; padding: 5px 0;">' . htmlspecialchars($sshCheckResult['new_date']) . '</td></tr>';
        $html .= '<tr><td style="border: none; padding: 5px 0;"><strong>Status:</strong></td><td style="border: none; padding: 5px 0;">';
        if ($sshCheckResult['changed']) {
            $html .= '<span style="color: #4CAF50; font-weight: bold;">⚠️ FILE MODIFIED</span>';
        } else {
            $html .= '<span style="color: #a80000ff; font-weight: bold;">✓ No Changes</span>';
        }
        $html .= '</td></tr>';
        $html .= '</table>';
        $html .= '</div>';
    }
    
    $html .= '</div>
        
        <div class="footer">
            <p>This is an automated report from the Product Date Checker script.</p>
            <p>Script Location: ' . htmlspecialchars(__FILE__) . '</p>
        </div>
    </div>
</body>
</html>';
    
    return $html;
}

// Main execution
echo "=== Product Date Checker ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

$currentData = [];
$errors = [];

// Fetch and extract dates from all URLs
foreach ($productUrls as $url) {
    echo "Checking: {$url}\n";
    
    $result = fetchUrl($url, 3); // 3 retries
    
    if (!$result['success']) {
        echo "  ✗ Error: {$result['error']}\n";
        echo "  ⚠ Retried 3 times, skipping this URL\n";
        $errors[$url] = $result['error'];
        continue;
    }
    
    $dateInfo = extractProductDate($result['html']);
    
    if ($dateInfo) {
        echo "  ✓ Date found: {$dateInfo['date']} (Raw: {$dateInfo['raw']})\n";
        $currentData[$url] = $dateInfo;
    } else {
        echo "  ✗ No date found in product title\n";
        $errors[$url] = "Date not found in HTML";
        continue;
    }
}

echo "\n";

// Load existing log file to get previous data
$previousData = [];
$logHistory = [];

if (file_exists($logFile)) {
    $existingLog = json_decode(file_get_contents($logFile), true);
    $logHistory = $existingLog['history'] ?? [];
    
    // Get the most recent previous data from history (excluding today)
    $dates = array_keys($logHistory);
    rsort($dates); // Latest first
    foreach ($dates as $date) {
        if ($date !== date('Y-m-d')) {
            $previousData = $logHistory[$date]['data'] ?? [];
            break;
        }
    }
}

// Compare with previous day's data
$changes = [];

if (!empty($previousData)) {
    echo "Comparing with previous day's data...\n";
    
    foreach ($currentData as $url => $currentInfo) {
        if (isset($previousData[$url])) {
            if ($currentInfo['date'] !== $previousData[$url]['date']) {
                $changes[$url] = [
                    'old' => $previousData[$url]['date'],
                    'new' => $currentInfo['date']
                ];
                echo "  ⚠ CHANGED: {$url}\n";
                echo "    Old: {$previousData[$url]['date']}\n";
                echo "    New: {$currentInfo['date']}\n";
            } else {
                echo "  ✓ Unchanged: {$url} ({$currentInfo['date']})\n";
            }
        } else {
            echo "  ℹ New URL: {$url} ({$currentInfo['date']})\n";
        }
    }
    
    echo "\n";
} else {
    echo "No previous data found. This is the first run.\n\n";
}

// Update log file with today's data
if (!empty($currentData)) {
    $today = date('Y-m-d');
    
    // Add today's entry to history
    $logHistory[$today] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => $currentData,
        'errors' => $errors
    ];
    
    // Keep only last 3 days of history
    if (count($logHistory) > 3) {
        $logHistory = array_slice($logHistory, -3, 3, true);
    }
    
    // Save updated log file
    $logData = [
        'last_updated' => date('Y-m-d H:i:s'),
        'history' => $logHistory
    ];
    
    file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "✓ Data logged to: {$logFile}\n\n";
}

// Check remote file modification via SSH
echo "\n";
echo "=== SSH Remote File Check ===\n";
$sshCheckResult = checkRemoteFileModification($env);
echo "\n";

// Update log file to include SSH check result
if (!empty($currentData)) {
    $today = date('Y-m-d');
    
    // Update today's entry with SSH check result
    $logHistory[$today]['ssh_check'] = $sshCheckResult;
    
    // Save updated log file
    $logData = [
        'last_updated' => date('Y-m-d H:i:s'),
        'history' => $logHistory
    ];
    
    file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "✓ SSH check result logged\n\n";
}

// Send email notification
if (!empty($currentData)) {
    echo "Sending email notification...\n";
    
    // Update subject to reflect SSH changes if any
    $hasAnyChanges = !empty($changes) || ($sshCheckResult['success'] && $sshCheckResult['changed']);
    
    $subject = $hasAnyChanges
        ? "⚠️ Changes Detected - " . date('Y-m-d')
        : "✓ Check Complete - No Changes - " . date('Y-m-d');
    
    $body = generateEmailBody($changes, $currentData, $previousData, $sshCheckResult);
    
    // Save email HTML for debugging
    $debugEmailFile = __DIR__ . '/last_email.html';
    file_put_contents($debugEmailFile, $body);
    echo "  ℹ Email HTML saved to: {$debugEmailFile}\n";
    
    $emailSent = sendEmail($env, $subject, $body);
    
    if ($emailSent) {
        echo "✓ Email sent successfully to: {$env['RECIPIENT_EMAIL']}\n";
    } else {
        echo "✗ Failed to send email\n";
    }
} else {
    echo "✗ No data to report. Email not sent.\n";
}

echo "\n=== Completed at: " . date('Y-m-d H:i:s') . " ===\n";
