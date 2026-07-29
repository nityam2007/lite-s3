<?php
/**
 * Complete AWS S3 and MinIO Compatible Storage System
 * Main entry point with comprehensive logging and full S3 API support
 */

// Router functionality - handle all requests including static files
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = parse_url($uri, PHP_URL_PATH) ?: '/';

// For PHP built-in server, we need to handle all requests through index.php
// Only serve static files directly if they exist and it's a GET request
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri) && !str_ends_with($uri, '.php')) {
    $mimeType = mime_content_type(__DIR__ . $uri);
    header('Content-Type: ' . $mimeType);
    readfile(__DIR__ . $uri);
    exit;
}

// Check if installation is needed
if (!file_exists(__DIR__ . '/.installed')) {
    header('Location: /install.php');
    exit;
}

// Otherwise, continue with the main application
require_once 'config.php';
require_once 'auth.php';
require_once 'storage.php';
require_once 's3-api.php';
require_once 'rate-limiter.php';
require_once 'security.php';

// Enhanced logging function with S3 operation details
function logRequest($method, $uri, $status, $responseTime = null, $details = '') {
    $timestamp = date('Y-m-d H:i:s');
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $contentLength = $_SERVER['CONTENT_LENGTH'] ?? '0';
    
    // Extract S3 operation details
    $s3Operation = $_SERVER['HTTP_X_AMZ_TARGET'] ?? $_GET['x-id'] ?? 'Unknown';
    $bucket = '';
    $object = '';
    
    // Parse URI for bucket and object
    if (preg_match('#^/([^/]+)(?:/(.*))?$#', $uri, $matches)) {
        $bucket = $matches[1] ?? '';
        $object = $matches[2] ?? '';
    }
    
    $logMessage = sprintf(
        "[%s] %s:%s [%d]: %s %s (Size: %s, Time: %sms, UA: %s) S3-OP: %s, Bucket: %s, Object: %s, Details: %s",
        $timestamp,
        $clientIP,
        $_SERVER['REMOTE_PORT'] ?? 'unknown',
        $status,
        $method,
        $uri,
        $contentLength,
        $responseTime ? round($responseTime * 1000, 2) : 'N/A',
        substr($userAgent, 0, 50),
        $s3Operation,
        $bucket,
        $object,
        $details
    );
    
    error_log($logMessage);
}

// Start request timing
$requestStart = microtime(true);

// Check rate limiting
if (!checkRateLimit()) {
    http_response_code(429);
    header('Retry-After: ' . RATE_LIMIT_WINDOW);
    header('Content-Type: application/json');
    echo json_encode(['code' => 'SlowDown', 'message' => 'Please reduce your request rate']);
    exit;
}

// Initialize performance monitor (com fallback de segurança)
if (!class_exists('PerformanceMonitor')) {
    class PerformanceMonitor {
        public function logRequest($method, $uri, $responseTime, $status, $details) {
            // Just silently ignore if the original class doesn't exist
        }
    }
}
$perfMonitor = new PerformanceMonitor();
error_log("REQUEST: method=" . ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ", uri=" . ($_SERVER['REQUEST_URI'] ?? '/') . ", ip=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

// Initialize S3 API
$s3api = new S3API();

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, HEAD, OPTIONS, COPY');
header('Access-Control-Allow-Headers: Authorization, Content-Type, x-amz-date, x-amz-content-sha256, x-amz-user-agent, x-amz-target, x-amz-copy-source, x-amz-acl');
header('Access-Control-Expose-Headers: ETag, x-amz-request-id, x-amz-id-2, Content-Length, Last-Modified');

// Handle preflight requests
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    $s3api->handleOptions();
    logRequest('OPTIONS', $_SERVER['REQUEST_URI'], 200, microtime(true) - $requestStart, 'CORS preflight');
    exit;
}

// Register shutdown function to log final response
register_shutdown_function(function() use ($requestStart, $perfMonitor) {
    $responseTime = microtime(true) - $requestStart;
    $status = http_response_code() ?: 200;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    
    // Get additional details
    $details = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($auth, 'AWS4-HMAC-SHA256') === 0) {
            $details = 'AWS4-Auth';
        } elseif (strpos($auth, 'AWS ') === 0) {
            $details = 'AWS-Auth';
        } else {
            $details = 'Other-Auth';
        }
    } else {
        $details = 'No-Auth';
    }
    
    // Log to both systems
    logRequest($method, $uri, $status, $responseTime, $details);
    $perfMonitor->logRequest($method, $uri, $responseTime, $status, $details);
});

// Get request path
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = trim($path, '/');

// Route requests
try {
    if (strpos($path, 'admin/') === 0) {
        // Admin UI routes
        handleAdminRoutes($path);
    } else {
        // S3 API routes
        handleS3Routes($path, $s3api);
    }
} catch (Exception $e) {
    http_response_code(500);
    S3Response::error('InternalError', 'Server error: ' . $e->getMessage(), 500);
    error_log("Fatal error: " . $e->getMessage());
}

// (Removed showApiInfo; root now lists buckets via handleS3Routes)

function handleAdminRoutes($path) {
    $adminPath = str_replace('admin/', '', $path);
    
    if (empty($adminPath)) {
        // Redirect to admin dashboard
        header('Location: /admin/?page=dashboard');
        exit;
    } else {
        // Include admin page
        $adminFile = __DIR__ . '/admin/' . $adminPath . '.php';
        if (file_exists($adminFile)) {
            include $adminFile;
        } else {
            http_response_code(404);
            echo 'Admin page not found';
        }
    }
}

function handleS3Routes($path, $s3api) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $parts = explode('/', $path);
    
    error_log("S3 Route: Path='$path', Method=$method, Parts=" . json_encode($parts));
    
    // Browser-friendly HTML view when no auth header is sent  
    $wantsHtml = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'text/html') !== false;
    $hasAuth = !empty($_SERVER['HTTP_AUTHORIZATION']) || isset($_GET['AWSAccessKeyId']);
    if ($method === 'GET' && $wantsHtml && !$hasAuth) {
        if (empty($path)) {
            // Show public landing page (no data exposed)
            renderPublicLandingPage();
            return;
        } else {
            // Redirect to admin login for bucket/object access
            header('Location: /admin/login.php');
            exit;
        }
    }

    // Authenticate request - REQUIRED for all S3 API operations
    $user = authenticateRequest();
    if (!$user) {
        S3Response::error('AccessDenied', 'Authentication required', 403);
        return;
    }
    error_log("Auth: User authenticated - " . $user['access_key']);
    
    // Handle different S3 operations based on path and query parameters
    if (empty($path)) {
        // List all buckets
        $s3api->listBuckets($user);
    } elseif (count($parts) == 1) {
        // Single bucket operations
        $bucketName = rawurldecode($parts[0]);
        
        switch ($method) {
            case 'PUT':
                // Create bucket
                $s3api->createBucket($bucketName, $user);
                break;
                
            case 'DELETE':
                // Delete bucket
                $s3api->deleteBucket($bucketName, $user);
                break;
                
            case 'GET':
                // List objects in bucket or get bucket location
                if (isset($_GET['location'])) {
                    $s3api->getBucketLocation($bucketName, $user);
                } else {
                    $s3api->listObjects($bucketName, $user, $_GET);
                }
                break;
                
            case 'HEAD':
                // Check if bucket exists
                $s3api->getBucketLocation($bucketName, $user);
                break;
                
            default:
                S3Response::error('MethodNotAllowed', 'Method not allowed', 405);
        }
    } else {
        // Object operations
        $bucketName = rawurldecode($parts[0]);
        $decodedParts = array_map('rawurldecode', array_slice($parts, 1));
        $objectKey = implode('/', $decodedParts);
        
        error_log("S3 Object Operation: Bucket='$bucketName', Key='$objectKey', Method=$method");
        
        switch ($method) {
            case 'PUT':
                // Check for multipart upload part first
                if (isset($_GET['partNumber']) && isset($_GET['uploadId'])) {
                    $s3api->uploadPart($bucketName, $objectKey, $user, $_GET['uploadId'], $_GET['partNumber']);
                } else {
                    // Regular put object
                    $s3api->putObject($bucketName, $objectKey, $user);
                }
                break;
                
            case 'GET':
                // Get object
                $s3api->getObject($bucketName, $objectKey, $user);
                break;
                
            case 'DELETE':
                // Delete object
                $s3api->deleteObject($bucketName, $objectKey, $user);
                break;
                
            case 'HEAD':
                // Head object
                $s3api->headObject($bucketName, $objectKey, $user);
                break;
                
            case 'POST':
                // Handle multipart uploads and other POST operations
                if (isset($_GET['uploads'])) {
                    $s3api->createMultipartUpload($bucketName, $objectKey, $user);
                } elseif (isset($_GET['uploadId']) && isset($_GET['partNumber'])) {
                    $s3api->uploadPart($bucketName, $objectKey, $user, $_GET['uploadId'], $_GET['partNumber']);
                } elseif (isset($_GET['uploadId']) && !isset($_GET['partNumber'])) {
                    $s3api->completeMultipartUpload($bucketName, $objectKey, $user, $_GET['uploadId']);
                } elseif (isset($_GET['abort'])) {
                    $s3api->abortMultipartUpload($bucketName, $objectKey, $user, $_GET['uploadId']);
                } elseif (isset($_GET['list-parts'])) {
                    // List parts of multipart upload
                    S3Response::error('NotImplemented', 'ListParts not yet implemented', 501);
                } else {
                    // Regular POST upload (treat as PUT)
                    $s3api->putObject($bucketName, $objectKey, $user);
                }
                break;
                
            case 'COPY':
                // Copy object
                $source = $_SERVER['HTTP_X_AMZ_COPY_SOURCE'] ?? '';
                if ($source) {
                    $sourceParts = explode('/', $source, 2);
                    if (count($sourceParts) == 2) {
                        $s3api->copyObject($bucketName, $objectKey, $user, $sourceParts[0], $sourceParts[1]);
                    } else {
                        S3Response::error('InvalidRequest', 'Invalid copy source', 400);
                    }
                } else {
                    S3Response::error('InvalidRequest', 'Copy source required', 400);
                }
                break;
                
            default:
                S3Response::error('MethodNotAllowed', 'Method not allowed', 405);
        }
    }
}

function renderPublicLandingPage() {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>S3 Object Storage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); min-height: 100vh; color: #fff; }
        .navbar { background: rgba(0,0,0,0.3) !important; backdrop-filter: blur(10px); }
        .hero { padding: 100px 0; text-align: center; }
        .hero h1 { font-size: 3.5rem; font-weight: 700; margin-bottom: 1rem; }
        .hero p { font-size: 1.25rem; opacity: 0.8; max-width: 600px; margin: 0 auto 2rem; }
        .feature-card { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 2rem; text-align: center; height: 100%; }
        .feature-card i { font-size: 2.5rem; color: #38ef7d; margin-bottom: 1rem; }
        code { background: rgba(0,0,0,0.3); padding: 2px 8px; border-radius: 4px; color: #38ef7d; }
        .api-box { background: rgba(0,0,0,0.3); border-radius: 12px; padding: 1.5rem; margin-top: 3rem; }
        a { color: #38ef7d; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="/"><i class="bi bi-cloud-fill"></i> S3 Storage</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="/admin/login.php"><i class="bi bi-box-arrow-in-right"></i> Login</a>
            </div>
        </div>
    </nav>
    
    <div class="hero">
        <div class="container">
            <i class="bi bi-cloud-arrow-up-fill" style="font-size: 5rem; color: #38ef7d;"></i>
            <h1>S3 Object Storage</h1>
            <p>Self-hosted, S3-compatible object storage for your files. Simple, secure, and designed for shared hosting.</p>
            <a href="/admin/login.php" class="btn btn-lg btn-primary px-5"><i class="bi bi-box-arrow-in-right"></i> Sign In</a>
        </div>
    </div>
    
    <div class="container pb-5">
        <div class="row g-4 mb-5">
            <div class="col-md-4">
                <div class="feature-card">
                    <i class="bi bi-shield-check"></i>
                    <h5>S3 Compatible</h5>
                    <p class="opacity-75 mb-0">Works with any S3 client - boto3, rclone, Cyberduck, and more.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <i class="bi bi-people"></i>
                    <h5>Multi-User</h5>
                    <p class="opacity-75 mb-0">Create users with separate buckets and granular permissions.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <i class="bi bi-hdd-stack"></i>
                    <h5>Large Files</h5>
                    <p class="opacity-75 mb-0">Supports files up to 5GB with multipart uploads.</p>
                </div>
            </div>
        </div>
        
        <div class="api-box">
            <h5 class="mb-3"><i class="bi bi-code-slash"></i> API Endpoints</h5>
            <div class="row">
                <div class="col-md-6">
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2"><code>PUT /{bucket}</code> Create bucket</li>
                        <li class="mb-2"><code>GET /{bucket}</code> List objects</li>
                        <li class="mb-0"><code>PUT /{bucket}/{key}</code> Upload object</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2"><code>GET /{bucket}/{key}</code> Download object</li>
                        <li class="mb-2"><code>DELETE /{bucket}/{key}</code> Delete object</li>
                        <li class="mb-0"><code>HEAD /{bucket}/{key}</code> Object info</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>';
}

function renderBucketsHtml() {
    header('Content-Type: text/html; charset=UTF-8');
    $pdo = getDB();
    $buckets = $pdo->query("SELECT b.name, b.created_at, COUNT(o.id) as object_count, COALESCE(SUM(o.size), 0) as total_size FROM buckets b LEFT JOIN objects o ON b.id = o.bucket_id GROUP BY b.id ORDER BY b.name")->fetchAll(PDO::FETCH_ASSOC);
    $stats = [
        'buckets' => count($buckets),
        'objects' => $pdo->query("SELECT COUNT(*) FROM objects")->fetchColumn(),
        'total_size' => $pdo->query("SELECT COALESCE(SUM(size), 0) FROM objects")->fetchColumn()
    ];
    
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>S3 Object Storage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --bs-primary: #0d6efd; }
        body { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); min-height: 100vh; }
        .navbar { background: rgba(0,0,0,0.3) !important; backdrop-filter: blur(10px); }
        .card { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.1); backdrop-filter: blur(10px); }
        .card, .card-body, .list-group-item { color: #fff; }
        .list-group-item { background: rgba(255,255,255,0.05); border-color: rgba(255,255,255,0.1); }
        .list-group-item:hover { background: rgba(255,255,255,0.15); }
        .stat-card { background: linear-gradient(135deg, var(--color1), var(--color2)); border: none; }
        .stat-card.blue { --color1: #667eea; --color2: #764ba2; }
        .stat-card.green { --color1: #11998e; --color2: #38ef7d; }
        .stat-card.orange { --color1: #f093fb; --color2: #f5576c; }
        .btn-action { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: #fff; }
        .btn-action:hover { background: rgba(255,255,255,0.2); color: #fff; }
        .hero { padding: 60px 0; text-align: center; color: #fff; }
        .hero h1 { font-size: 3rem; font-weight: 700; }
        .hero p { font-size: 1.25rem; opacity: 0.8; }
        code { background: rgba(0,0,0,0.3); padding: 2px 8px; border-radius: 4px; color: #38ef7d; }
        .api-section { background: rgba(0,0,0,0.2); border-radius: 12px; padding: 20px; margin-top: 20px; }
        a { color: #38ef7d; }
        a:hover { color: #11998e; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="/"><i class="bi bi-cloud-fill"></i> S3 Storage</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="/admin/login.php"><i class="bi bi-gear"></i> Admin</a>
                <a class="nav-link" href="/health.php"><i class="bi bi-heart-pulse"></i> Health</a>
            </div>
        </div>
    </nav>
    
    <div class="hero">
        <div class="container">
            <h1><i class="bi bi-cloud-arrow-up-fill"></i> S3 Object Storage</h1>
            <p>S3-compatible storage for your applications</p>
        </div>
    </div>
    
    <div class="container pb-5">
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card stat-card blue h-100">
                    <div class="card-body text-center py-4">
                        <i class="bi bi-folder-fill" style="font-size: 2rem;"></i>
                        <h2 class="mt-2 mb-0">' . $stats['buckets'] . '</h2>
                        <p class="mb-0 opacity-75">Buckets</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card stat-card green h-100">
                    <div class="card-body text-center py-4">
                        <i class="bi bi-file-earmark-fill" style="font-size: 2rem;"></i>
                        <h2 class="mt-2 mb-0">' . $stats['objects'] . '</h2>
                        <p class="mb-0 opacity-75">Objects</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card stat-card orange h-100">
                    <div class="card-body text-center py-4">
                        <i class="bi bi-hdd-fill" style="font-size: 2rem;"></i>
                        <h2 class="mt-2 mb-0">' . formatBytesUI($stats['total_size']) . '</h2>
                        <p class="mb-0 opacity-75">Storage Used</p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-lg-8 mb-4">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-folder2-open"></i> Buckets</h5>
                    </div>
                    <div class="card-body">';
    if (empty($buckets)) {
        echo '<div class="text-center py-4 opacity-75"><i class="bi bi-inbox" style="font-size: 3rem;"></i><p class="mt-2">No buckets yet</p></div>';
    } else {
        echo '<div class="list-group list-group-flush">';
        foreach ($buckets as $b) {
            $name = htmlspecialchars($b['name']);
            $count = (int)$b['object_count'];
            $size = formatBytesUI($b['total_size']);
            echo "<a href='/$name' class='list-group-item list-group-item-action d-flex justify-content-between align-items-center'>
                <div><i class='bi bi-folder-fill text-warning'></i> <strong>$name</strong></div>
                <div><span class='badge bg-primary'>$count objects</span> <span class='badge bg-secondary'>$size</span></div>
            </a>";
        }
        echo '</div>';
    }
    echo '      </div>
                </div>
            </div>
            
            <div class="col-lg-4 mb-4">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0"><i class="bi bi-code-slash"></i> API Endpoints</h5></div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2"><code>GET /</code> List buckets</li>
                            <li class="mb-2"><code>PUT /{bucket}</code> Create bucket</li>
                            <li class="mb-2"><code>GET /{bucket}</code> List objects</li>
                            <li class="mb-2"><code>PUT /{bucket}/{key}</code> Upload</li>
                            <li class="mb-2"><code>GET /{bucket}/{key}</code> Download</li>
                            <li class="mb-0"><code>DELETE /{bucket}/{key}</code> Delete</li>
                        </ul>
                    </div>
                </div>
                
                <div class="card mt-3">
                    <div class="card-header"><h5 class="mb-0"><i class="bi bi-key"></i> Quick Start</h5></div>
                    <div class="card-body">
                        <p class="small opacity-75 mb-2">Default credentials:</p>
                        <p class="mb-1"><strong>Access Key:</strong> <code>admin</code></p>
                        <p class="mb-3"><strong>Secret Key:</strong> <code>admin123</code></p>
                        <a href="/admin/login.php" class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right"></i> Login to Admin</a>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="api-section">
            <h5 class="mb-3"><i class="bi bi-terminal"></i> cURL Example</h5>
            <pre class="mb-0" style="color: #38ef7d; overflow-x: auto;"><code># Upload a file
curl -X PUT -H "Authorization: AWS admin:admin123" \\
     --data-binary @file.txt http://localhost:8081/mybucket/file.txt

# Download a file
curl -H "Authorization: AWS admin:admin123" http://localhost:8081/mybucket/file.txt</code></pre>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>';
}

function renderBucketObjectsHtml($bucketName) {
    header('Content-Type: text/html; charset=UTF-8');
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM buckets WHERE name = ?");
    $stmt->execute([$bucketName]);
    $bucket = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $bucketNameSafe = htmlspecialchars($bucketName);
    
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bucket: ' . $bucketNameSafe . '</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); min-height: 100vh; color: #fff; }
        .navbar { background: rgba(0,0,0,0.3) !important; backdrop-filter: blur(10px); }
        .card { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.1); backdrop-filter: blur(10px); color: #fff; }
        .table { color: #fff; }
        .table thead th { border-color: rgba(255,255,255,0.1); }
        .table td, .table th { border-color: rgba(255,255,255,0.05); }
        .table-hover tbody tr:hover { background: rgba(255,255,255,0.1); }
        code { background: rgba(0,0,0,0.3); padding: 2px 6px; border-radius: 4px; color: #38ef7d; font-size: 0.75rem; }
        a { color: #38ef7d; }
        .file-icon { font-size: 1.2rem; }
        .breadcrumb { background: transparent; }
        .breadcrumb-item a { color: #38ef7d; }
        .breadcrumb-item.active { color: rgba(255,255,255,0.7); }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="/"><i class="bi bi-cloud-fill"></i> S3 Storage</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="/admin/login.php"><i class="bi bi-gear"></i> Admin</a>
            </div>
        </div>
    </nav>
    
    <div class="container py-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/"><i class="bi bi-house"></i> Home</a></li>
                <li class="breadcrumb-item active">' . $bucketNameSafe . '</li>
            </ol>
        </nav>
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-folder-fill text-warning"></i> ' . $bucketNameSafe . '</h2>
        </div>';
    
    if (!$bucket) {
        echo '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Bucket not found</div>';
        echo '<a href="/" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>';
    } else {
        $stmt = $pdo->prepare("SELECT object_key, size, created_at, etag, mime_type FROM objects WHERE bucket_id = ? ORDER BY object_key");
        $stmt->execute([$bucket['id']]);
        $objects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($objects)) {
            echo '<div class="card"><div class="card-body text-center py-5">
                <i class="bi bi-inbox" style="font-size: 4rem; opacity: 0.5;"></i>
                <h4 class="mt-3 opacity-75">No objects in this bucket</h4>
                <p class="opacity-50">Upload files using the S3 API or Admin panel</p>
            </div></div>';
        } else {
            echo '<div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-files"></i> ' . count($objects) . ' Objects</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr>
                                <th>Name</th>
                                <th>Size</th>
                                <th>Type</th>
                                <th>Modified</th>
                                <th></th>
                            </tr></thead>
                            <tbody>';
            foreach ($objects as $o) {
                $key = htmlspecialchars($o['object_key']);
                $size = formatBytesUI($o['size']);
                $mime = htmlspecialchars($o['mime_type'] ?: 'unknown');
                $created = date('M j, Y H:i', strtotime($o['created_at']));
                $icon = getFileIcon($o['mime_type']);
                $url = '/' . rawurlencode($bucketName) . '/' . str_replace('%2F', '/', rawurlencode($o['object_key']));
                echo "<tr>
                    <td><i class='bi $icon file-icon text-primary'></i> $key</td>
                    <td>$size</td>
                    <td><code>$mime</code></td>
                    <td>$created</td>
                    <td><a href='$url' class='btn btn-sm btn-outline-light' target='_blank'><i class='bi bi-download'></i></a></td>
                </tr>";
            }
            echo '</tbody></table></div></div></div>';
        }
    }
    
    echo '</div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>';
}

function formatBytesUI($size, $precision = 1) {
    if ($size == 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    for ($i = 0; $size >= 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, $precision) . ' ' . $units[$i];
}

function getFileIcon($mimeType) {
    if (!$mimeType) return 'bi-file-earmark';
    if (strpos($mimeType, 'image/') === 0) return 'bi-file-earmark-image';
    if (strpos($mimeType, 'video/') === 0) return 'bi-file-earmark-play';
    if (strpos($mimeType, 'audio/') === 0) return 'bi-file-earmark-music';
    if (strpos($mimeType, 'text/') === 0) return 'bi-file-earmark-text';
    if (strpos($mimeType, 'application/pdf') === 0) return 'bi-file-earmark-pdf';
    if (strpos($mimeType, 'application/zip') === 0 || strpos($mimeType, 'application/x-rar') === 0) return 'bi-file-earmark-zip';
    return 'bi-file-earmark';
}
?>

