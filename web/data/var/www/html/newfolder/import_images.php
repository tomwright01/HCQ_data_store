<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Process import if requested
$result = null;
if (isset($_POST['import'])) {
    $directory = '/var/www/html/data/FAF'; // Path to your FAF images
    $result = scanAndImportImages($directory, 'FAF');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Images</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .btn {
            background: #4CAF50;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .btn:hover {
            background: #45a049;
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 4px;
        }
        .success {
            background: #dff0d8;
            color: #3c763d;
        }
        .error {
            background: #f2dede;
            color: #a94442;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Import FAF Images</h1>
        <p>This will scan the FAF directory and update the database with any new images found.</p>
        
        <form method="post">
            <button type="submit" name="import" class="btn">Import Images</button>
        </form>
        
        <?php if ($result): ?>
            <div class="result <?= $result['success'] ? 'success' : 'error' ?>">
                <?php if ($result['success']): ?>
                    <h3>Import Complete</h3>
                    <p>Processed <?= $result['total'] ?> files:</p>
                    <ul>
                        <li>Imported/Updated: <?= $result['imported'] ?></li>
                        <li>Errors: <?= $result['errors'] ?></li>
                    </ul>
                <?php else: ?>
                    <h3>Error</h3>
                    <p><?= $result['message'] ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
