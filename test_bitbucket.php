<?php

require_once 'vendor/autoload.php';

use GuzzleHttp\Client;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$username = $_ENV['BITBUCKET_USERNAME'];
$token = $_ENV['BITBUCKET_APP_PASSWORD'];

$client = new Client([
    'base_uri' => 'https://api.bitbucket.org/2.0/',
    'timeout' => 30.0,
]);

echo "Testing authentication...\n";

try {
    $response = $client->request('GET', 'user', [
        'auth' => [$username, $token]
    ]);
    
    $data = json_decode($response->getBody(), true);
    echo "User: " . $data['display_name'] . " (" . $data['username'] . ")\n";
} catch (Exception $e) {
    echo "Auth failed: " . $e->getMessage() . "\n";
    exit;
}

echo "\nGetting repositories...\n";

try {
    $response = $client->request('GET', 'repositories/atabix', [
        'auth' => [$username, $token],
        'query' => [
            'pagelen' => 5,
            'sort' => 'updated_on'
        ]
    ]);
    
    $data = json_decode($response->getBody(), true);
    
    foreach ($data['values'] as $repo) {
        echo "Repository: " . $repo['name'] . " (updated: " . $repo['updated_on'] . ")\n";
        
        // Get commits for this repository
        echo "  Getting commits...\n";
        try {
            $commitsResponse = $client->request('GET', "repositories/atabix/{$repo['name']}/commits", [
                'auth' => [$username, $token],
                'query' => [
                    'pagelen' => 3
                ]
            ]);
            
            $commitData = json_decode($commitsResponse->getBody(), true);
            
            if (!empty($commitData['values'])) {
                foreach ($commitData['values'] as $commit) {
                    echo "    Commit: " . substr($commit['message'], 0, 50) . "... by " . $commit['author']['raw'] . " on " . $commit['date'] . "\n";
                }
            } else {
                echo "    No commits found\n";
            }
            
        } catch (Exception $e) {
            echo "    Error getting commits: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}