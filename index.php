<?php
session_start();
// Database configuration
$db_config = [
    'host' => 'localhost',
    'dbname' => '',
    'username' => '',
    'password' => ''
];
// Database connection
try {
    $pdo = new PDO("mysql:host={$db_config['host']};dbname={$db_config['dbname']}", 
                   $db_config['username'], $db_config['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
// Initialize database tables
function initDatabase($pdo) {
    // Users table
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'user') DEFAULT 'user',
        theme ENUM('light', 'dark') DEFAULT 'light',
        view_mode ENUM('list', 'grid') DEFAULT 'list',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Settings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL,
        setting_value TEXT,
        user_id INT,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    // File permissions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS file_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        hidden_extensions TEXT,
        allowed_operations TEXT,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    // Create default admin user if not exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
        $stmt->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'admin']);
    }
}
initDatabase($pdo);
// Authentication functions
function login($username, $password, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['theme'] = $user['theme'];
        $_SESSION['view_mode'] = $user['view_mode'];
        return true;
    }
    return false;
}
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}
function logout() {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
// File operations
function getDirectoryContents($path, $searchQuery = '') {
    $items = [];
    if (!is_dir($path)) return $items;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        $searchQuery ? RecursiveIteratorIterator::SELF_FIRST : RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($iterator as $file) {
        if ($searchQuery && stripos($file->getFilename(), $searchQuery) === false) {
            continue;
        }
        
        $items[] = [
            'name' => $file->getFilename(),
            'path' => $file->getPathname(),
            'type' => $file->isDir() ? 'folder' : 'file',
            'size' => $file->isFile() ? $file->getSize() : 0,
            'modified' => $file->getMTime(),
            'extension' => $file->isFile() ? strtolower($file->getExtension()) : ''
        ];
    }
    
    return $items;
}
function formatFileSize($size) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    for ($i = 0; $size >= 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, 2) . ' ' . $units[$i];
}
function getFileIcon($extension, $type) {
    if ($type === 'folder') return '📁';
    
    $icons = [
        'txt' => '📄', 'doc' => '📄', 'docx' => '📄',
        'pdf' => '📕', 'xls' => '📊', 'xlsx' => '📊',
        'jpg' => '🖼️', 'jpeg' => '🖼️', 'png' => '🖼️', 'gif' => '🖼️',
        'mp3' => '🎵', 'wav' => '🎵', 'mp4' => '🎬', 'avi' => '🎬',
        'zip' => '📦', 'rar' => '📦', '7z' => '📦',
        'php' => '⚙️', 'html' => '🌐', 'css' => '🎨', 'js' => '⚡'
    ];
    
    return $icons[$extension] ?? '📄';
}
function isHiddenFile($filename, $extension, $isAdmin) {
    if ($isAdmin) return false;
    
    // Hide system files and sensitive extensions for non-admin users
    $hiddenExtensions = ['php', 'htaccess', 'sql', 'ini', 'conf'];
    return in_array($extension, $hiddenExtensions) || strpos($filename, '.') === 0;
}
// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }
    
    switch ($_POST['action']) {
        case 'get_directory':
            $path = $_POST['path'] ?? './';
            $search = $_POST['search'] ?? '';
            $sort = $_POST['sort'] ?? 'name';
            $order = $_POST['order'] ?? 'asc';
            
            $items = getDirectoryContents($path, $search);
            
            // Filter hidden files for non-admin users
            if (!isAdmin()) {
                $items = array_filter($items, function($item) {
                    return !isHiddenFile($item['name'], $item['extension'], false);
                });
            }
            
            // Sort items
            usort($items, function($a, $b) use ($sort, $order) {
                $result = 0;
                switch ($sort) {
                    case 'name':
                        $result = strcasecmp($a['name'], $b['name']);
                        break;
                    case 'type':
                        $result = strcasecmp($a['extension'], $b['extension']);
                        break;
                    case 'size':
                        $result = $a['size'] <=> $b['size'];
                        break;
                    case 'modified':
                        $result = $a['modified'] <=> $b['modified'];
                        break;
                }
                return $order === 'desc' ? -$result : $result;
            });
            
            echo json_encode(['success' => true, 'items' => array_values($items)]);
            break;
            
        case 'create_folder':
            $path = $_POST['path'];
            $name = $_POST['name'];
            $fullPath = rtrim($path, '/') . '/' . $name;
            
            if (mkdir($fullPath, 0755, true)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create folder']);
            }
            break;
            
        case 'delete_item':
            $path = $_POST['path'];
            
            if (is_dir($path)) {
                // Delete directory and all its contents
                $success = deleteDirectory($path);
            } else {
                $success = unlink($path);
            }
            
            echo json_encode(['success' => $success]);
            break;
            
        case 'rename_item':
            $oldPath = $_POST['old_path'];
            $newPath = $_POST['new_path'];
            
            $success = rename($oldPath, $newPath);
            echo json_encode(['success' => $success]);
            break;
            
        case 'get_file_info':
            $path = $_POST['path'];
            
            if (file_exists($path)) {
                $info = [
                    'name' => basename($path),
                    'path' => $path,
                    'size' => is_file($path) ? filesize($path) : 0,
                    'modified' => filemtime($path),
                    'type' => is_dir($path) ? 'Directory' : 'File',
                    'permissions' => substr(sprintf('%o', fileperms($path)), -4)
                ];
                echo json_encode(['success' => true, 'info' => $info]);
            } else {
                echo json_encode(['success' => false, 'message' => 'File not found']);
            }
            break;
            
        case 'upload_file':
            $targetDir = $_POST['current_path'] ?? './';
            
            if (!empty($_FILES)) {
                $totalFiles = count($_FILES['files']['name']);
                $successCount = 0;
                
                for ($i = 0; $i < $totalFiles; $i++) {
                    $fileName = $_FILES['files']['name'][$i];
                    $fileTmpName = $_FILES['files']['tmp_name'][$i];
                    $fileSize = $_FILES['files']['size'][$i];
                    $fileError = $_FILES['files']['error'][$i];
                    
                    if ($fileError === UPLOAD_ERR_OK) {
                        $targetPath = $targetDir . '/' . $fileName;
                        
                        if (move_uploaded_file($fileTmpName, $targetPath)) {
                            $successCount++;
                        }
                    }
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => "$successCount of $totalFiles files uploaded successfully"
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'No files uploaded']);
            }
            break;
            
        case 'get_users':
            if (!isAdmin()) {
                echo json_encode(['success' => false, 'message' => 'Permission denied']);
                exit;
            }
            
            $stmt = $pdo->prepare("SELECT id, username, role, created_at FROM users");
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'users' => $users]);
            break;
            
        case 'add_user':
            if (!isAdmin()) {
                echo json_encode(['success' => false, 'message' => 'Permission denied']);
                exit;
            }
            
            $username = $_POST['username'];
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $role = $_POST['role'];
            
            try {
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
                $stmt->execute([$username, $password, $role]);
                echo json_encode(['success' => true, 'message' => 'User created successfully']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            break;
            
        case 'delete_user':
            if (!isAdmin()) {
                echo json_encode(['success' => false, 'message' => 'Permission denied']);
                exit;
            }
            
            $userId = $_POST['user_id'];
            
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            break;
            
        case 'update_settings':
            if (!isAdmin()) {
                echo json_encode(['success' => false, 'message' => 'Permission denied']);
                exit;
            }
            
            $settings = json_decode($_POST['settings'], true);
            
            try {
                foreach ($settings as $key => $value) {
                    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, user_id) 
                                         VALUES (?, ?, ?) 
                                         ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$key, $value, null, $value]);
                }
                echo json_encode(['success' => true, 'message' => 'Settings updated successfully']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            break;
    }
    exit;
}
// Helper function to delete a directory and its contents
function deleteDirectory($dir) {
    if (!file_exists($dir)) {
        return true;
    }
    
    if (!is_dir($dir)) {
        return unlink($dir);
    }
    
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }
    
    return rmdir($dir);
}
// Handle login/logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (login($_POST['username'], $_POST['password'], $pdo)) {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $error = "Invalid username or password";
    }
}
if (isset($_GET['logout'])) {
    logout();
}
// Main application
$currentPath = $_GET['path'] ?? './';
$viewMode = $_SESSION['view_mode'] ?? 'list';
$theme = $_SESSION['theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Manager - Windows Explorer Clone</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        :root {
            --bg-primary: #f0f0f0;
            --bg-secondary: #ffffff;
            --text-primary: #333333;
            --text-secondary: #666666;
            --border-color: #cccccc;
            --hover-color: #e6f3ff;
            --selected-color: #cce8ff;
            --button-bg: #0078d4;
            --button-text: #ffffff;
            --success-color: #28a745;
            --danger-color: #dc3545;
        }
        [data-theme="dark"] {
            --bg-primary: #1e1e1e;
            --bg-secondary: #2d2d2d;
            --text-primary: #ffffff;
            --text-secondary: #cccccc;
            --border-color: #404040;
            --hover-color: #404040;
            --selected-color: #0078d4;
            --button-bg: #0078d4;
            --button-text: #ffffff;
            --success-color: #28a745;
            --danger-color: #dc3545;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            height: 100vh;
            overflow: hidden;
        }
        .login-container {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .login-form {
            background: var(--bg-secondary);
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
        }
        .login-form h2 {
            text-align: center;
            margin-bottom: 1.5rem;
            color: var(--text-primary);
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-size: 1rem;
            background: var(--bg-secondary);
            color: var(--text-primary);
        }
        .btn {
            background: var(--button-bg);
            color: var(--button-text);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        .btn-full {
            width: 100%;
        }
        .btn-success {
            background: var(--success-color);
        }
        .btn-danger {
            background: var(--danger-color);
        }
        .error {
            color: var(--danger-color);
            text-align: center;
            margin-bottom: 1rem;
        }
        .app-container {
            display: flex;
            height: 100vh;
            flex-direction: column;
        }
        .toolbar {
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            padding: 0.5rem 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .toolbar button {
            background: var(--button-bg);
            color: var(--button-text);
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .toolbar button:hover {
            opacity: 0.9;
        }
        .search-box {
            flex: 1;
            min-width: 200px;
            max-width: 300px;
        }
        .search-box input {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 3px;
            background: var(--bg-secondary);
            color: var(--text-primary);
        }
        .main-content {
            display: flex;
            flex: 1;
            overflow: hidden;
        }
        .sidebar {
            width: 250px;
            background: var(--bg-secondary);
            border-right: 1px solid var(--border-color);
            padding: 1rem;
            overflow-y: auto;
        }
        .file-area {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .breadcrumb {
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            padding: 0.5rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .breadcrumb-item {
            color: var(--button-bg);
            cursor: pointer;
            text-decoration: none;
        }
        .breadcrumb-item:hover {
            text-decoration: underline;
        }
        .file-container {
            flex: 1;
            overflow: auto;
            padding: 1rem;
            position: relative;
        }
        .file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
        }
        .file-list {
            display: table;
            width: 100%;
        }
        .file-item {
            display: flex;
            align-items: center;
            padding: 0.5rem;
            border-radius: 3px;
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s;
        }
        .file-item:hover {
            background: var(--hover-color);
        }
        .file-item.selected {
            background: var(--selected-color);
        }
        .file-item.grid-item {
            flex-direction: column;
            text-align: center;
            padding: 1rem;
            border: 1px solid transparent;
        }
        .file-icon {
            font-size: 1.5rem;
            margin-right: 0.5rem;
        }
        .grid-item .file-icon {
            font-size: 3rem;
            margin-bottom: 0.5rem;
            margin-right: 0;
        }
        .file-name {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .file-size, .file-date {
            width: 100px;
            text-align: right;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        .context-menu {
            position: fixed;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 5px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            z-index: 1000;
            min-width: 150px;
            display: none;
        }
        .context-menu-item {
            padding: 0.5rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
        }
        .context-menu-item:last-child {
            border-bottom: none;
        }
        .context-menu-item:hover {
            background: var(--hover-color);
        }
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .modal-content {
            background: var(--bg-secondary);
            padding: 2rem;
            border-radius: 10px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
        }
        .property-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        .media-preview {
            max-width: 100%;
            max-height: 300px;
            margin: 1rem 0;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-left: auto;
        }
        .theme-toggle {
            background: none;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            cursor: pointer;
        }
        .upload-area {
            border: 2px dashed var(--border-color);
            border-radius: 5px;
            padding: 2rem;
            text-align: center;
            margin: 1rem 0;
            transition: all 0.3s ease;
        }
        .upload-area.drag-over {
            border-color: var(--button-bg);
            background: var(--hover-color);
        }
        .upload-area p {
            margin-bottom: 1rem;
            color: var(--text-secondary);
        }
        .file-input {
            display: none;
        }
        .user-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        .user-table th, .user-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        .user-table th {
            background: var(--hover-color);
            font-weight: 600;
        }
        .user-table tr:hover {
            background: var(--hover-color);
        }
        .form-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .form-row .form-group {
            flex: 1;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 3px;
            background: var(--bg-secondary);
            color: var(--text-primary);
        }
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem;
            border-radius: 5px;
            background: var(--bg-secondary);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 2000;
            display: none;
        }
        .notification.success {
            border-left: 4px solid var(--success-color);
        }
        .notification.error {
            border-left: 4px solid var(--danger-color);
        }
        @media (max-width: 768px) {
            .sidebar {
                display: none;
            }
            
            .toolbar {
                flex-wrap: wrap;
            }
            
            .search-box {
                order: -1;
                flex-basis: 100%;
            }
        }
    </style>
</head>
<body data-theme="<?php echo $theme; ?>">
<?php if (!isLoggedIn()): ?>
    <div class="login-container">
        <form class="login-form" method="post">
            <h2>🗂️ File Manager</h2>
            <?php if (isset($error)): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <div class="form-group">
                <input type="text" name="username" placeholder="Username" required>
            </div>
            <div class="form-group">
                <input type="password" name="password" placeholder="Password" required>
            </div>
            <button type="submit" name="login" class="btn btn-full">Login</button>
            <div style="margin-top: 1rem; text-align: center; font-size: 0.9rem; color: var(--text-secondary);">
                Default: admin / admin123
            </div>
        </form>
    </div>
<?php else: ?>
    <div class="app-container">
        <div class="toolbar">
            <button onclick="goBack()">⬅️ Back</button>
            <button onclick="goForward()">➡️ Forward</button>
            <button onclick="goUp()">⬆️ Up</button>
            <button onclick="refresh()">🔄 Refresh</button>
            <button onclick="createFolder()">📁 New Folder</button>
            <button onclick="toggleView()"><?php echo $viewMode === 'list' ? '⊞' : '☰'; ?> View</button>
            <button onclick="showUploadModal()">📤 Upload</button>
            
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Search files..." onkeyup="performSearch()">
            </div>
            
            <div class="user-info">
                <button class="theme-toggle" onclick="toggleTheme()">
                    <?php echo $theme === 'light' ? '🌙' : '☀️'; ?>
                </button>
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <?php if (isAdmin()): ?>
                    <span style="color: var(--button-bg);">(Admin)</span>
                <?php endif; ?>
                <a href="?logout" style="color: var(--text-secondary); text-decoration: none;">Logout</a>
            </div>
        </div>
        <div class="main-content">
            <div class="sidebar">
                <h3>Quick Access</h3>
                <div style="margin-top: 1rem;">
                    <div class="file-item" onclick="navigateToPath('./')">
                        📁 Root Directory
                    </div>
                    <div class="file-item" onclick="navigateToPath('./uploads')">
                        📁 Uploads
                    </div>
                    <div class="file-item" onclick="navigateToPath('./documents')">
                        📁 Documents
                    </div>
                    <div class="file-item" onclick="navigateToPath('./images')">
                        🖼️ Images
                    </div>
                </div>
                
                <?php if (isAdmin()): ?>
                <div style="margin-top: 2rem;">
                    <h3>Admin Panel</h3>
                    <div style="margin-top: 1rem;">
                        <button class="btn" onclick="showUserManagement()">👥 Manage Users</button>
                        <button class="btn" onclick="showSettings()" style="margin-top: 0.5rem;">⚙️ Settings</button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="file-area">
                <div class="breadcrumb" id="breadcrumb">
                    <a href="#" class="breadcrumb-item" onclick="navigateToPath('./')">🏠 Home</a>
                </div>
                <div class="file-container" id="fileContainer">
                    <!-- Files will be loaded here -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- Context Menu -->
    <div class="context-menu" id="contextMenu">
        <div class="context-menu-item" onclick="openFile()">📂 Open</div>
        <div class="context-menu-item" onclick="shareFile()">🔗 Share</div>
        <div class="context-menu-item" onclick="downloadFile()">⬇️ Download</div>
        <div class="context-menu-item" onclick="copyFile()">📋 Copy</div>
        <div class="context-menu-item" onclick="cutFile()">✂️ Cut</div>
        <div class="context-menu-item" onclick="renameFile()">✏️ Rename</div>
        <div class="context-menu-item" onclick="showProperties()">ℹ️ Properties</div>
        <div class="context-menu-item" onclick="deleteFile()" style="color: var(--danger-color);">🗑️ Delete</div>
    </div>
    
    <!-- Upload Modal -->
    <div class="modal" id="uploadModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Upload Files</h3>
                <button class="modal-close" onclick="closeModal('uploadModal')">&times;</button>
            </div>
            <div class="upload-area" id="uploadArea">
                <p>Drag and drop files here or click to select files</p>
                <button class="btn" onclick="document.getElementById('fileInput').click()">Select Files</button>
                <input type="file" id="fileInput" class="file-input" multiple>
            </div>
            <div id="uploadProgress" style="margin-top: 1rem; display: none;">
                <p>Uploading files...</p>
                <progress id="progressBar" value="0" max="100" style="width: 100%;"></progress>
            </div>
        </div>
    </div>
    
    <!-- User Management Modal -->
    <div class="modal" id="userManagementModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>User Management</h3>
                <button class="modal-close" onclick="closeModal('userManagementModal')">&times;</button>
            </div>
            <div>
                <h4>Add New User</h4>
                <form id="addUserForm" onsubmit="addUser(event)">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="newUsername">Username</label>
                            <input type="text" id="newUsername" required>
                        </div>
                        <div class="form-group">
                            <label for="newPassword">Password</label>
                            <input type="password" id="newPassword" required>
                        </div>
                        <div class="form-group">
                            <label for="newRole">Role</label>
                            <select id="newRole">
                                <option value="user">User</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success">Add User</button>
                </form>
                
                <h4 style="margin-top: 2rem;">Existing Users</h4>
                <div id="userTableContainer">
                    <table class="user-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="userTableBody">
                            <!-- Users will be loaded here -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Settings Modal -->
    <div class="modal" id="settingsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>System Settings</h3>
                <button class="modal-close" onclick="closeModal('settingsModal')">&times;</button>
            </div>
            <div>
                <form id="settingsForm" onsubmit="updateSettings(event)">
                    <div class="form-group">
                        <label for="maxFileSize">Maximum File Size (MB)</label>
                        <input type="number" id="maxFileSize" value="10" min="1" max="100">
                    </div>
                    <div class="form-group">
                        <label for="allowedFileTypes">Allowed File Types (comma separated)</label>
                        <input type="text" id="allowedFileTypes" value="jpg,jpeg,png,gif,pdf,doc,docx,txt">
                    </div>
                    <div class="form-group">
                        <label for="defaultViewMode">Default View Mode</label>
                        <select id="defaultViewMode">
                            <option value="list">List</option>
                            <option value="grid">Grid</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="defaultTheme">Default Theme</label>
                        <select id="defaultTheme">
                            <option value="light">Light</option>
                            <option value="dark">Dark</option>
                        </select>
                    </div>
                    <button type="submit" class="btn">Save Settings</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Properties Modal -->
    <div class="modal" id="propertiesModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>File Properties</h3>
                <button class="modal-close" onclick="closeModal('propertiesModal')">&times;</button>
            </div>
            <div id="propertiesContent">
                <!-- Properties will be loaded here -->
            </div>
        </div>
    </div>
    
    <!-- Media Preview Modal -->
    <div class="modal" id="mediaModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Media Preview</h3>
                <button class="modal-close" onclick="closeModal('mediaModal')">&times;</button>
            </div>
            <div id="mediaContent">
                <!-- Media will be loaded here -->
            </div>
        </div>
    </div>
    
    <!-- Notification -->
    <div class="notification" id="notification"></div>
    
    <script>
        let currentPath = './';
        let selectedItem = null;
        let viewMode = '<?php echo $viewMode; ?>';
        let sortBy = 'name';
        let sortOrder = 'asc';
        let searchQuery = '';
        let history = [];
        let historyIndex = -1;
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadDirectory(currentPath);
            addToHistory(currentPath);
            
            // Hide context menu on click outside
            document.addEventListener('click', function() {
                document.getElementById('contextMenu').style.display = 'none';
            });
            
            // Setup drag and drop for upload
            setupDragAndDrop();
            
            // Setup file input change
            document.getElementById('fileInput').addEventListener('change', function(e) {
                handleFileUpload(e.target.files);
            });
        });
        
        // Navigation functions
        function navigateToPath(path) {
            currentPath = path;
            loadDirectory(path);
            updateBreadcrumb(path);
            addToHistory(path);
        }
        
        function addToHistory(path) {
            // Remove any forward history
            history = history.slice(0, historyIndex + 1);
            
            // Add new path
            history.push(path);
            historyIndex = history.length - 1;
        }
        
        function goBack() {
            if (historyIndex > 0) {
                historyIndex--;
                navigateToPath(history[historyIndex]);
            }
        }
        
        function goForward() {
            if (historyIndex < history.length - 1) {
                historyIndex++;
                navigateToPath(history[historyIndex]);
            }
        }
        
        function goUp() {
            if (currentPath !== './') {
                const parts = currentPath.split('/');
                parts.pop();
                if (parts.length === 1) {
                    navigateToPath('./');
                } else {
                    navigateToPath(parts.join('/') + '/');
                }
            }
        }
        
        function refresh() {
            loadDirectory(currentPath);
        }
        
        // Directory loading
        function loadDirectory(path, search = '') {
            const formData = new FormData();
            formData.append('action', 'get_directory');
            formData.append('path', path);
            formData.append('search', search);
            formData.append('sort', sortBy);
            formData.append('order', sortOrder);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayFiles(data.items);
                } else {
                    showNotification('Failed to load directory: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showNotification('Error loading directory: ' + error.message, 'error');
            });
        }
        
        // Display files
        function displayFiles(items) {
            const container = document.getElementById('fileContainer');
            container.innerHTML = '';
            
            if (viewMode === 'grid') {
                container.className = 'file-container file-grid';
            } else {
                container.className = 'file-container file-list';
            }
            
            if (items.length === 0) {
                container.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--text-secondary);">No files found</div>';
                return;
            }
            
            items.forEach(item => {
                const fileItem = createFileElement(item);
                container.appendChild(fileItem);
            });
        }
        
        function createFileElement(item) {
            const div = document.createElement('div');
            div.className = `file-item ${viewMode === 'grid' ? 'grid-item' : ''}`;
            div.dataset.path = item.path;
            div.dataset.type = item.type;
            
            const icon = getFileIcon(item.extension, item.type);
            const name = item.name;
            const size = item.type === 'file' ? formatFileSize(item.size) : '';
            const date = new Date(item.modified * 1000).toLocaleDateString();
            
            if (viewMode === 'grid') {
                div.innerHTML = `
                    <div class="file-icon">${icon}</div>
                    <div class="file-name" title="${name}">${name}</div>
                `;
            } else {
                div.innerHTML = `
                    <div class="file-icon">${icon}</div>
                    <div class="file-name" title="${name}">${name}</div>
                    <div class="file-size">${size}</div>
                    <div class="file-date">${date}</div>
                `;
            }
            
            // Event listeners
            div.addEventListener('click', function(e) {
                selectItem(this);
            });
            
            div.addEventListener('dblclick', function(e) {
                openFileOrFolder(item);
            });
            
            div.addEventListener('contextmenu', function(e) {
                e.preventDefault();
                selectItem(this);
                showContextMenu(e.pageX, e.pageY, item);
            });
            
            return div;
        }
        
        function getFileIcon(extension, type) {
            if (type === 'folder') return '📁';
            
            const icons = {
                'txt': '📄', 'doc': '📄', 'docx': '📄',
                'pdf': '📕', 'xls': '📊', 'xlsx': '📊',
                'jpg': '🖼️', 'jpeg': '🖼️', 'png': '🖼️', 'gif': '🖼️',
                'mp3': '🎵', 'wav': '🎵', 'mp4': '🎬', 'avi': '🎬',
                'zip': '📦', 'rar': '📦', '7z': '📦',
                'php': '⚙️', 'html': '🌐', 'css': '🎨', 'js': '⚡'
            };
            
            return icons[extension] || '📄';
        }
        
        function formatFileSize(size) {
            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            let i = 0;
            while (size >= 1024 && i < units.length - 1) {
                size /= 1024;
                i++;
            }
            return Math.round(size * 100) / 100 + ' ' + units[i];
        }
        
        // Selection and context menu
        function selectItem(element) {
            // Remove previous selection
            document.querySelectorAll('.file-item.selected').forEach(item => {
                item.classList.remove('selected');
            });
            
            // Select current item
            element.classList.add('selected');
            selectedItem = element;
        }
        
        function showContextMenu(x, y, item) {
            const contextMenu = document.getElementById('contextMenu');
            contextMenu.style.display = 'block';
            contextMenu.style.left = x + 'px';
            contextMenu.style.top = y + 'px';
        }
        
        // File operations
        function openFileOrFolder(item) {
            if (item.type === 'folder') {
                navigateToPath(item.path);
            } else {
                openFile();
            }
        }
        
        function openFile() {
            if (!selectedItem) return;
            
            const path = selectedItem.dataset.path;
            const extension = path.split('.').pop().toLowerCase();
            
            // Handle different file types
            if (['jpg', 'jpeg', 'png', 'gif', 'bmp'].includes(extension)) {
                showImagePreview(path);
            } else if (['mp3', 'wav', 'ogg'].includes(extension)) {
                showAudioPlayer(path);
            } else if (['mp4', 'webm', 'ogg'].includes(extension)) {
                showVideoPlayer(path);
            } else {
                // Download or open in new tab
                window.open(path, '_blank');
            }
        }
        
        function showImagePreview(path) {
            const modal = document.getElementById('mediaModal');
            const content = document.getElementById('mediaContent');
            content.innerHTML = `<img src="${path}" class="media-preview" alt="Image Preview">`;
            modal.style.display = 'flex';
        }
        
        function showAudioPlayer(path) {
            const modal = document.getElementById('mediaModal');
            const content = document.getElementById('mediaContent');
            content.innerHTML = `
                <audio controls class="media-preview" style="width: 100%;">
                    <source src="${path}" type="audio/mpeg">
                    Your browser does not support the audio element.
                </audio>
            `;
            modal.style.display = 'flex';
        }
        
        function showVideoPlayer(path) {
            const modal = document.getElementById('mediaModal');
            const content = document.getElementById('mediaContent');
            content.innerHTML = `
                <video controls class="media-preview" style="width: 100%;">
                    <source src="${path}" type="video/mp4">
                    Your browser does not support the video element.
                </video>
            `;
            modal.style.display = 'flex';
        }
        
        function shareFile() {
            if (!selectedItem) return;
            
            const path = selectedItem.dataset.path;
            const url = window.location.origin + '/' + path;
            
            if (navigator.share) {
                navigator.share({
                    title: 'Shared File',
                    url: url
                });
            } else {
                // Fallback: copy to clipboard
                navigator.clipboard.writeText(url).then(() => {
                    showNotification('File URL copied to clipboard!', 'success');
                });
            }
        }
        
        function downloadFile() {
            if (!selectedItem) return;
            
            const path = selectedItem.dataset.path;
            const link = document.createElement('a');
            link.href = path;
            link.download = '';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        function copyFile() {
            if (!selectedItem) return;
            // Store in clipboard (simplified implementation)
            localStorage.setItem('clipboard', JSON.stringify({
                action: 'copy',
                path: selectedItem.dataset.path
            }));
            showNotification('File copied to clipboard', 'success');
        }
        
        function cutFile() {
            if (!selectedItem) return;
            // Store in clipboard (simplified implementation)
            localStorage.setItem('clipboard', JSON.stringify({
                action: 'cut',
                path: selectedItem.dataset.path
            }));
            showNotification('File cut to clipboard', 'success');
        }
        
        function renameFile() {
            if (!selectedItem) return;
            
            const currentName = selectedItem.querySelector('.file-name').textContent;
            const newName = prompt('Enter new name:', currentName);
            
            if (newName && newName !== currentName) {
                const oldPath = selectedItem.dataset.path;
                const newPath = oldPath.replace(currentName, newName);
                
                const formData = new FormData();
                formData.append('action', 'rename_item');
                formData.append('old_path', oldPath);
                formData.append('new_path', newPath);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        refresh();
                        showNotification('File renamed successfully', 'success');
                    } else {
                        showNotification('Failed to rename file', 'error');
                    }
                })
                .catch(error => {
                    showNotification('Error renaming file: ' + error.message, 'error');
                });
            }
        }
        
        function deleteFile() {
            if (!selectedItem) return;
            
            const fileName = selectedItem.querySelector('.file-name').textContent;
            if (confirm(`Are you sure you want to delete "${fileName}"?`)) {
                const formData = new FormData();
                formData.append('action', 'delete_item');
                formData.append('path', selectedItem.dataset.path);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        refresh();
                        showNotification('File deleted successfully', 'success');
                    } else {
                        showNotification('Failed to delete file', 'error');
                    }
                })
                .catch(error => {
                    showNotification('Error deleting file: ' + error.message, 'error');
                });
            }
        }
        
        function showProperties() {
            if (!selectedItem) return;
            
            const formData = new FormData();
            formData.append('action', 'get_file_info');
            formData.append('path', selectedItem.dataset.path);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayProperties(data.info);
                } else {
                    showNotification('Failed to get file information', 'error');
                }
            })
            .catch(error => {
                showNotification('Error getting file information: ' + error.message, 'error');
            });
        }
        
        function displayProperties(info) {
            const content = document.getElementById('propertiesContent');
            content.innerHTML = `
                <div class="property-item">
                    <strong>Name:</strong>
                    <span>${info.name}</span>
                </div>
                <div class="property-item">
                    <strong>Path:</strong>
                    <span>${info.path}</span>
                </div>
                <div class="property-item">
                    <strong>Type:</strong>
                    <span>${info.type}</span>
                </div>
                <div class="property-item">
                    <strong>Size:</strong>
                    <span>${formatFileSize(info.size)}</span>
                </div>
                <div class="property-item">
                    <strong>Modified:</strong>
                    <span>${new Date(info.modified * 1000).toLocaleString()}</span>
                </div>
                <div class="property-item">
                    <strong>Permissions:</strong>
                    <span>${info.permissions}</span>
                </div>
            `;
            document.getElementById('propertiesModal').style.display = 'flex';
        }
        
        // UI functions
        function toggleView() {
            viewMode = viewMode === 'list' ? 'grid' : 'list';
            
            // Update button text
            const button = event.target;
            button.innerHTML = viewMode === 'list' ? '⊞ View' : '☰ View';
            
            // Reload directory with new view mode
            loadDirectory(currentPath, searchQuery);
        }
        
        function toggleTheme() {
            const body = document.body;
            const currentTheme = body.getAttribute('data-theme');
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            
            body.setAttribute('data-theme', newTheme);
            
            // Update button icon
            const button = event.target;
            button.innerHTML = newTheme === 'light' ? '🌙' : '☀️';
            
            // Save theme preference
            localStorage.setItem('theme', newTheme);
        }
        
        function createFolder() {
            const folderName = prompt('Enter folder name:');
            if (folderName) {
                const formData = new FormData();
                formData.append('action', 'create_folder');
                formData.append('path', currentPath);
                formData.append('name', folderName);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        refresh();
                        showNotification('Folder created successfully', 'success');
                    } else {
                        showNotification('Failed to create folder: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showNotification('Error creating folder: ' + error.message, 'error');
                });
            }
        }
        
        function performSearch() {
            const searchInput = document.getElementById('searchInput');
            searchQuery = searchInput.value;
            
            // Debounce search
            clearTimeout(window.searchTimeout);
            window.searchTimeout = setTimeout(() => {
                loadDirectory(currentPath, searchQuery);
            }, 300);
        }
        
        function updateBreadcrumb(path) {
            const breadcrumb = document.getElementById('breadcrumb');
            breadcrumb.innerHTML = '<a href="#" class="breadcrumb-item" onclick="navigateToPath(\'./\')">🏠 Home</a>';
            
            if (path !== './') {
                const parts = path.split('/').filter(part => part && part !== '.');
                let currentPath = './';
                
                parts.forEach((part, index) => {
                    currentPath += part + '/';
                    breadcrumb.innerHTML += ` / <a href="#" class="breadcrumb-item" onclick="navigateToPath('${currentPath}')">${part}</a>`;
                });
            }
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Upload functions
        function showUploadModal() {
            document.getElementById('uploadModal').style.display = 'flex';
        }
        
        function setupDragAndDrop() {
            const uploadArea = document.getElementById('uploadArea');
            
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                uploadArea.addEventListener(eventName, preventDefaults, false);
            });
            
            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            ['dragenter', 'dragover'].forEach(eventName => {
                uploadArea.addEventListener(eventName, highlight, false);
            });
            
            ['dragleave', 'drop'].forEach(eventName => {
                uploadArea.addEventListener(eventName, unhighlight, false);
            });
            
            function highlight(e) {
                uploadArea.classList.add('drag-over');
            }
            
            function unhighlight(e) {
                uploadArea.classList.remove('drag-over');
            }
            
            uploadArea.addEventListener('drop', handleDrop, false);
            
            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                handleFileUpload(files);
            }
        }
        
        function handleFileUpload(files) {
            if (files.length === 0) return;
            
            const formData = new FormData();
            formData.append('action', 'upload_file');
            formData.append('current_path', currentPath);
            
            // Add files to form data
            for (let i = 0; i < files.length; i++) {
                formData.append('files[]', files[i]);
            }
            
            // Show progress
            document.getElementById('uploadProgress').style.display = 'block';
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('uploadProgress').style.display = 'none';
                if (data.success) {
                    closeModal('uploadModal');
                    refresh();
                    showNotification(data.message, 'success');
                } else {
                    showNotification('Upload failed: ' + data.message, 'error');
                }
            })
            .catch(error => {
                document.getElementById('uploadProgress').style.display = 'none';
                showNotification('Error uploading files: ' + error.message, 'error');
            });
        }
        
        // Admin functions
        function showUserManagement() {
            document.getElementById('userManagementModal').style.display = 'flex';
            loadUsers();
        }
        
        function loadUsers() {
            const formData = new FormData();
            formData.append('action', 'get_users');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayUsers(data.users);
                } else {
                    showNotification('Failed to load users: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showNotification('Error loading users: ' + error.message, 'error');
            });
        }
        
        function displayUsers(users) {
            const tbody = document.getElementById('userTableBody');
            tbody.innerHTML = '';
            
            users.forEach(user => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${user.id}</td>
                    <td>${user.username}</td>
                    <td>${user.role}</td>
                    <td>${new Date(user.created_at).toLocaleDateString()}</td>
                    <td>
                        ${user.id !== <?php echo $_SESSION['user_id']; ?> ? 
                            `<button class="btn btn-danger" onclick="deleteUser(${user.id})">Delete</button>` : 
                            '<span>Current user</span>'}
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }
        
        function addUser(event) {
            event.preventDefault();
            
            const username = document.getElementById('newUsername').value;
            const password = document.getElementById('newPassword').value;
            const role = document.getElementById('newRole').value;
            
            const formData = new FormData();
            formData.append('action', 'add_user');
            formData.append('username', username);
            formData.append('password', password);
            formData.append('role', role);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('User created successfully', 'success');
                    document.getElementById('addUserForm').reset();
                    loadUsers();
                } else {
                    showNotification('Failed to create user: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showNotification('Error creating user: ' + error.message, 'error');
            });
        }
        
        function deleteUser(userId) {
            if (confirm('Are you sure you want to delete this user?')) {
                const formData = new FormData();
                formData.append('action', 'delete_user');
                formData.append('user_id', userId);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('User deleted successfully', 'success');
                        loadUsers();
                    } else {
                        showNotification('Failed to delete user: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showNotification('Error deleting user: ' + error.message, 'error');
                });
            }
        }
        
        function showSettings() {
            document.getElementById('settingsModal').style.display = 'flex';
        }
        
        function updateSettings(event) {
            event.preventDefault();
            
            const settings = {
                maxFileSize: document.getElementById('maxFileSize').value,
                allowedFileTypes: document.getElementById('allowedFileTypes').value,
                defaultViewMode: document.getElementById('defaultViewMode').value,
                defaultTheme: document.getElementById('defaultTheme').value
            };
            
            const formData = new FormData();
            formData.append('action', 'update_settings');
            formData.append('settings', JSON.stringify(settings));
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Settings updated successfully', 'success');
                    closeModal('settingsModal');
                } else {
                    showNotification('Failed to update settings: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showNotification('Error updating settings: ' + error.message, 'error');
            });
        }
        
        // Notification system
        function showNotification(message, type = 'success') {
            const notification = document.getElementById('notification');
            notification.textContent = message;
            notification.className = 'notification ' + type;
            notification.style.display = 'block';
            
            setTimeout(() => {
                notification.style.display = 'none';
            }, 3000);
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey) {
                switch(e.key) {
                    case 'c':
                        e.preventDefault();
                        copyFile();
                        break;
                    case 'x':
                        e.preventDefault();
                        cutFile();
                        break;
                    case 'f':
                        e.preventDefault();
                        document.getElementById('searchInput').focus();
                        break;
                }
            }
            
            if (e.key === 'Delete' && selectedItem) {
                deleteFile();
            }
            
            if (e.key === 'F2' && selectedItem) {
                renameFile();
            }
            
            if (e.key === 'Enter' && selectedItem) {
                openFile();
            }
        });
        
        // File sorting
        function sortFiles(column) {
            if (sortBy === column) {
                sortOrder = sortOrder === 'asc' ? 'desc' : 'asc';
            } else {
                sortBy = column;
                sortOrder = 'asc';
            }
            loadDirectory(currentPath, searchQuery);
        }
    </script>
<?php endif; ?>
</body>
</html>
