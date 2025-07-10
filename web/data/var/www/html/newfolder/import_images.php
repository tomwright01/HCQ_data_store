<?php
/*
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Process import if requested
if (isset($_POST['import'])) {
    // Correct directory path - directly pointing to FAF (no extra concatenation)
    $directory = '/var/www/html/data';  // Correct path inside container
    $imported = scanAndImportImages($directory);  // Call the function to import images
    echo "<p>$imported images imported successfully.</p>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import FAF Images</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f7f7f7;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            color: #333;
        }

        .container {
            background-color: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            text-align: center;
            width: 100%;
            max-width: 600px;
        }

        h1 {
            color: rgb(0, 168, 143);
            font-size: 36px;
            margin-bottom: 30px;
            font-weight: bold;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group input[type="file"] {
            font-size: 16px;
            padding: 10px;
            width: 100%;
            border: 1px solid #ddd;
            border-radius: 5px;
            background-color: #fff;
            color: #333;
        }

        .form-group button {
            padding: 12px 20px;
            background-color: rgb(0, 168, 143);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 18px;
            cursor: pointer;
            transition: background-color 0.3s ease;
            width: 100%;
        }

        .form-group button:hover {
            background-color: rgb(0, 140, 120);
        }

        .result-message {
            margin-top: 20px;
            font-size: 18px;
            font-weight: bold;
            color: rgb(0, 168, 143);
        }

        .back-button {
            display: inline-block;
            padding: 10px 20px;
            margin-top: 20px;
            background-color: rgb(0, 168, 143);
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 18px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .back-button:hover {
            background-color: rgb(0, 140, 120);
        }

    </style>
</head>
<body>

    <div class="container">
        <h1>Import FAF Images</h1>

        <!-- Image Import Form -->
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <input type="file" name="image" id="image" required>
            </div>
            <div class="form-group">
                <button type="submit" name="import">Import Images</button>
            </div>
        </form>

        <!-- Display success message after import -->
        <?php if (isset($imported)): ?>
            <div class="result-message">
                <?= $imported ?> images imported successfully.
            </div>
        <?php endif; ?>

        <!-- Back to Home button -->
        <a href="index.php" class="back-button">Back to Home</a>
    </div>

</body>
</html>
*/
