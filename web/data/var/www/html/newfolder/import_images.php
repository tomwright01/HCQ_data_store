<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Process import if requested
if (isset($_POST['import'])) {
    // Path to the FAF directory (can be OCT, VF, MFERG, etc. as needed)
    $directory = '/var/www/html/data/FAF';  // Make sure this path is correct
    $imported = scanAndImportImages($directory);  // Call the function to import images
    echo "<p>$imported images imported successfully.</p>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Images</title>
</head>
<body>
    <h1>Import FAF Images</h1>
    <form method="POST">
        <button type="submit" name="import">Import Images</button>
    </form>
</body>
</html>
