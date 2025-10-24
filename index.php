<?php

require __DIR__ . '/vendor/autoload.php';

use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\Printer;
use Mike42\Escpos\EscposImage;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

// Only accept POST requests from GitHub webhooks
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Error: Expecting a POST request';
    return;
}

// Read GitHub event type from header
$event = isset($_SERVER['HTTP_X_GITHUB_EVENT']) ? $_SERVER['HTTP_X_GITHUB_EVENT'] : null;

// Grab the full input coming in as JSON via a POST request
$payload = json_decode(file_get_contents('php://input'), true);

// Basic validation
if (!is_array($payload)) {
    http_response_code(400);
    echo 'Invalid JSON payload';
    return;
}

// initialize the printer connection. Keep the original device, but allow override via env var PRINTER_DEVICE
$printerDevice = getenv('PRINTER_DEVICE') ?: '/dev/usb/lp0';
$connector = new FilePrintConnector($printerDevice);
$printer = new Printer($connector);

// Determine action and dispatch
try {
    switch ($event) {
        case 'issues':
            handleIssueEvent($printer, $payload);
            break;
        case 'pull_request':
            handlePullRequestEvent($printer, $payload);
            break;
        default:
            // Unknown/unhandled event: ignore with 204
            http_response_code(204);
            echo 'Event ignored';
            break;
    }
} catch (Exception $e) {
    // Attempt to cut the paper on error
    try { $printer->cut(); } catch (Exception $ex) {}
    http_response_code(500);
    echo 'Printer error: ' . $e->getMessage();
    return;
}

// success
http_response_code(200);
echo 'Printed';

// --- Handlers -------------------------------------------------------------
function handleIssueEvent($printer, $data)
{
    $action = isset($data['action']) ? $data['action'] : '';
    // Only print when opened or reopened
    if (!in_array($action, ['opened', 'reopened'])) {
        return;
    }

    $issue = $data['issue'];
    $repo = $data['repository'];

    $repoName = isset($repo['full_name']) ? $repo['full_name'] : (isset($repo['name']) ? $repo['name'] : 'unknown');
    $repoUrl = isset($repo['html_url']) ? rtrim($repo['html_url'], '/') : '';

    $user = isset($issue['user']['login']) ? $issue['user']['login'] : 'unknown';
    $title = isset($issue['title']) ? $issue['title'] : '';
    $body = isset($issue['body']) ? $issue['body'] : '';
    $created = isset($issue['created_at']) ? $issue['created_at'] : '';
    $labels = isset($issue['labels']) ? $issue['labels'] : [];
    $issueUrl = isset($issue['html_url']) ? $issue['html_url'] : ($repoUrl ? $repoUrl . '/issues/' . ($issue['number'] ?? '') : '');

    printHeader($printer, ucfirst($action) . ' Issue', $user, $repoName, $repoUrl);
    printTitle($printer, $title);
    printLabels($printer, $labels);
    printBody($printer, $body);
    printQRCode($printer, $issueUrl);
    printFooter($printer, $created);
}

function handlePullRequestEvent($printer, $data)
{
    $action = isset($data['action']) ? $data['action'] : '';
    // We want to print when PR is opened or when it is marked ready for review (from draft)
    if (!in_array($action, ['opened', 'ready_for_review'])) {
        return;
    }

    $pr = $data['pull_request'];
    $repo = $data['repository'];

    $repoName = isset($repo['full_name']) ? $repo['full_name'] : (isset($repo['name']) ? $repo['name'] : 'unknown');
    $repoUrl = isset($repo['html_url']) ? rtrim($repo['html_url'], '/') : '';

    $user = isset($pr['user']['login']) ? $pr['user']['login'] : 'unknown';
    $title = isset($pr['title']) ? $pr['title'] : '';
    $body = isset($pr['body']) ? $pr['body'] : '';
    $created = isset($pr['created_at']) ? $pr['created_at'] : '';
    $labels = isset($pr['labels']) ? $pr['labels'] : [];
    $prUrl = isset($pr['html_url']) ? $pr['html_url'] : ($repoUrl ? $repoUrl . '/pull/' . ($pr['number'] ?? '') : '');

    printHeader($printer, ucfirst($action) . ' Pull Request', $user, $repoName, $repoUrl);
    printTitle($printer, $title);
    printLabels($printer, $labels);
    printBody($printer, $body);
    printQRCode($printer, $prUrl);
    printFooter($printer, $created);
}

// --- Printing primitives -------------------------------------------------
function printHeader($printer, $kind, $user, $repoFullName, $repoUrl = '')
{
    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->setTextSize(2, 2);
    $printer->setUnderline(true);
    $printer->setEmphasis(true);
    $printer->text($kind . "\n");
    $printer->feed(2);

    $printer->setJustification(Printer::JUSTIFY_LEFT);
    $printer->setTextSize(1, 1);
    $printer->setUnderline(false);
    $printer->setEmphasis(false);
    if ($repoUrl) {
        $printer->text("Repo: " . $repoFullName . "\n");
        $printer->text("Repo URL: " . $repoUrl . "\n");
    } else {
        $printer->text("Repo: " . $repoFullName . "\n");
    }
    $printer->text("User: @" . $user . "\n");

    $printer->feed(1);
}

function printTitle($printer, $title)
{
    if ($title === null || $title === '') return;
    $printer->setEmphasis(true);
    $printer->text($title . "\n");
    $printer->setEmphasis(false);
    $printer->feed(1);
}

function printLabels($printer, $labels)
{
    if (empty($labels) || !is_array($labels)) return;
    $names = array_map(function ($l) { return isset($l['name']) ? $l['name'] : ''; }, $labels);
    $names = array_filter($names);
    if (empty($names)) return;

    $printer->text("Tags: " . implode(', ', $names) . "\n");
    $printer->feed(1);
}

function printBody($printer, $body)
{
    if ($body === null || $body === '') return;
    // Wrap to ~42 chars per line (old value)
    $printer->text(wordwrap($body, 42) . "\n");
    $printer->feed(1);
}

function printQRCode($printer, $url)
{
    if (!$url) return;

    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gh_qr_' . uniqid() . '.png';

    $options = new QROptions([
        'outputType' => QRCode::OUTPUT_IMAGE_PNG,
        'imageBase64'=> false,
    ]);
    $qrcode  = new QRCode($options);
    $pngData = $qrcode->render($url);
    file_put_contents($tmp, $pngData);

    try {
        $img = EscposImage::load($tmp);
        $printer->bitImage($img);
        $printer->feed(1);
    } catch (Throwable $e) {
        $printer->text("URL: " . $url . "\n");
    } finally {
        @unlink($tmp);
    }
}

function printFooter($printer, $timestamp)
{
    if ($timestamp) {
        $printer->text($timestamp . "\n");
    }
    $printer->feed(2);
    $printer->cut();
}