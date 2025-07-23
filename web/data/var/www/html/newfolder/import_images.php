<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Increase system limits for bulk processing
set_time_limit(600);
ini_set('memory_limit', '1024M');
ini_set('max_file_uploads', '1000');

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_type'])) {
    try {
        $testType = $_POST['test_type'];
        
        // Validate test type
        if (!array_key_exists($testType, ALLOWED_TEST_TYPES)) {
            throw new Exception("Invalid test type selected");
        }
        
        // Get the full directory path
        $fullDirPath = IMAGE_BASE_DIR . ALLOWED_TEST_TYPES[$testType] . '/';
        
        $message = "Selected directory for $testType: <code>$fullDirPath</code>";
        $messageType = 'success';
        
    } catch (Exception $e) {
        $message = "<strong>Error:</strong> " . $e->getMessage();
        $messageType = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Image Directory Selector</title>
    <style>
        :root {
            --primary: rgb(0, 168, 143);
            --primary-dark: rgb(0, 140, 120);
            --primary-light: rgba(0, 168, 143, 0.1);
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --light: #f8f9fa;
            --dark: #343a40;
            --gray: #6c757d;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            line-height: 1.6;
            color: var(--dark);
            background-color: white;
            padding: 0;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        
        .container {
            width: 100%;
            max-width: 600px;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            border: 1px solid #ddd;
            margin: 20px;
        }
        
        h1 {
            color: var(--primary);
            margin-top: 0;
            text-align: center;
        }
        
        .test-options {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin: 30px 0;
        }
        
        .test-option {
            background-color: var(--primary-light);
            border: 2px solid var(--primary);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .test-option:hover {
            background-color: var(--primary);
            color: white;
        }
        
        .test-option input[type="radio"] {
            display: none;
        }
        
        .test-option input[type="radio"]:checked + label {
            background-color: var(--primary);
            color: white;
        }
        
        .test-option label {
            display: block;
            width: 100%;
            height: 100%;
            cursor: pointer;
            font-weight: bold;
            font-size: 18px;
        }
        
        button[type="submit"] {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 18px;
            transition: background-color 0.3s;
            width: 100%;
            margin-top: 20px;
        }
        
        button[type="submit"]:hover {
            background-color: var(--primary-dark);
        }
        
        .message {
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            text-align: center;
        }
        
        .success {
            background-color: #e6f7e6;
            color: #3c763d;
            border: 1px solid #d6e9c6;
        }
        
        .error {
            background-color: #f2dede;
            color: #a94442;
            border: 1px solid #ebccd1;
        }
        
        code {
            background-color: #e0e0e0;
            padding: 2px 4px;
            border-radius: 3px;
            font-family: monospace;
        }
        
        @media (max-width: 768px) {
            .test-options {
                grid-template-columns: 1fr;
            }
            
            .container {
                padding: 20px;
                margin: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Select Test Type</h1>
        
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= $message ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="test-options">
                <?php foreach (ALLOWED_TEST_TYPES as $type => $dir): ?>
                    <div class="test-option">
                        <input type="radio" name="test_type" id="test_<?= $type ?>" value="<?= $type ?>" required>
                        <label for="test_<?= $type ?>"><?= $type ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <button type="submit">Show Directory Path</button>
        </form>
    </div>

    <script>
        // Add click handler for better UX
        document.querySelectorAll('.test-option').forEach(option => {
            option.addEventListener('click', function() {
                this.querySelector('input[type="radio"]').checked = true;
                document.querySelectorAll('.test-option').forEach(opt => {
                    opt.style.backgroundColor = '';
                    opt.style.color = '';
                });
                this.style.backgroundColor = 'rgb(0, 168, 143)';
                this.style.color = 'white';
            });
        });
    </script>
</body>
</html>
