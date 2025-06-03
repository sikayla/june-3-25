<?php
// **1. Start Session**
session_start();

// **2. Language Handling**
$supported_languages = [
    'en' => 'English',
    'es' => 'Español',
    'fr' => 'Français',
    'de' => 'Deutsch',
    'it' => 'Italiano',
    'pt' => 'Português',
    'ru' => 'Русский',
    'zh' => '中文',
    'ja' => '日本語',
    'ko' => '한국어',
];

// Determine the language to use
$lang_code = 'en'; // Default language

// Check if a language is set in the URL
if (isset($_GET['lang']) && array_key_exists($_GET['lang'], $supported_languages)) {
    $lang_code = $_GET['lang'];
    $_SESSION['lang'] = $lang_code;
} elseif (isset($_SESSION['lang']) && array_key_exists($_SESSION['lang'], $supported_languages)) {
    $lang_code = $_SESSION['lang'];
}

// Load the language file
$lang_file = __DIR__ . "/lang/{$lang_code}.php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    include __DIR__ . "/lang/en.php";
    $lang_code = 'en';
}

// Function to get translated string
function __($key) {
    global $lang;
    return $lang[$key] ?? $key;
}

// **3. Database Connection Parameters**
$host = 'localhost';
$db   = 'ventech_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// **4. Initialize Variables**
$errors = [];
$success_message = '';

// Variables to retain form input values
$username_val = '';
$email_val = '';
$contact_number_val = ''; // Added for contact number
$location_val = '';       // Added for location

// **5. Establish PDO Connection**
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log("Database connection error in client_signup.php: " . $e->getMessage());
    $errors[] = __('error_general');
}

// **6. Handle Form Submission**
if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($errors)) {
    // Retain input values
    $username_val = trim($_POST['username'] ?? '');
    $email_val = trim($_POST['email'] ?? '');
    $contact_number_val = trim($_POST['contact_number'] ?? ''); // Get contact number
    $location_val = trim($_POST['location'] ?? '');           // Get location

    // Sanitize and validate input
    $username = htmlspecialchars($username_val, ENT_QUOTES, 'UTF-8');
    $email = filter_var($email_val, FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $contact_number = htmlspecialchars($contact_number_val, ENT_QUOTES, 'UTF-8'); // Sanitize contact number
    $location = htmlspecialchars($location_val, ENT_QUOTES, 'UTF-8');           // Sanitize location

    // Basic Validation Checks
    if (empty($username)) $errors[] = __('error_required_username');
    if (empty($email)) {
        $errors[] = __('error_required_email');
    } elseif ($email === false) {
        $errors[] = __('error_invalid_email');
    }
    if (empty($password)) $errors[] = __('error_required_password');
    if ($password !== $confirm_password) $errors[] = __('error_password_match');
    if (strlen($password) < 8) $errors[] = __('error_password_length');
    if (empty($contact_number)) $errors[] = "Contact number is required."; // Add validation for contact number
    if (empty($location)) $errors[] = "Location is required.";             // Add validation for location

    // Check if username or email already exists in the database
    if (empty($errors)) {
        try {
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
            $stmt_check->execute([$username, $email]);
            $count = $stmt_check->fetchColumn();

            if ($count > 0) {
                $errors[] = __('error_user_exists');
            }
        } catch (PDOException $e) {
            error_log("Database check error in client_signup.php: " . $e->getMessage());
            $errors[] = __('error_db_check');
        }
    }

    // **7. Insert New User if No Errors**
    if (empty($errors)) {
        // Hash the password before storing
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        try {
            // Include contact_number and location in the INSERT statement
            $stmt_insert = $pdo->prepare("INSERT INTO users (username, email, password, role, created_at, contact_number, location) VALUES (?, ?, ?, 'client', NOW(), ?, ?)");
            $stmt_insert->execute([$username, $email, $hashed_password, $contact_number, $location]);

            $success_message = __('success_signup');
            // Clear input values on successful submission
            $username_val = '';
            $email_val = '';
            $contact_number_val = '';
            $location_val = '';

        } catch (PDOException $e) {
            error_log("Database insert error in client_signup.php: " . $e->getMessage());
            $errors[] = __('error_db_insert');
        }
    }
}

// Determine login link
$loginLink = 'client_login.php';

?>
<!DOCTYPE html>
<html lang="<?= $lang_code ?>">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1" name="viewport"/>
    <title><?= __('signup_title') ?> - <?= __('app_name') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans&amp;display=swap" rel="stylesheet"/>
    <style>
        body {
            font-family: "Open Sans", sans-serif;
        }

        .bg-cover-full {
            background-image: url('https://storage.googleapis.com/a1aa/image/776ddc13-c8ad-4eb8-8dd1-6a18bcfeb731.jpg');
            background-size: cover;
            background-position: center;
        }

        .language-dropdown {
            position: relative;
            display: inline-block;
        }

        .language-dropdown-content {
            display: none;
            position: absolute;
            background-color: #f9f9f9;
            min-width: 120px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            z-index: 100;
            right: 0;
            border-radius: 0.25rem;
            overflow: hidden;
            padding: 0;
        }

        .language-dropdown-content a {
            color: black;
            padding: 8px 12px;
            text-decoration: none;
            display: block;
            font-size: 0.75rem;
            text-align: left;
        }

        .language-dropdown-content a:hover {
            background-color: #f1f1f1;
        }

        .language-dropdown:hover .language-dropdown-content {
            display: block;
        }
    </style>
</head>
<body class="min-h-screen flex flex-col bg-cover-full">
    <div class="fixed inset-0 bg-black opacity-50 -z-10"></div>

    <header class="flex justify-between items-center px-6 py-6 max-w-[1200px] w-full mx-auto relative z-20">
        <div class="flex items-center space-x-1">
            <span class="text-white font-bold text-lg leading-none">Ventech</span>
            <span class="text-white italic text-lg leading-none">Locator</span>
        </div>
        <div class="language-dropdown text-white text-sm flex items-center space-x-1 cursor-pointer select-none">
            <span><?= __('header_language') ?></span>
            <span class="underline"><?= $supported_languages[$lang_code] ?? 'English' ?></span>
            <i class="fas fa-chevron-down text-xs"></i>
            <div class="language-dropdown-content">
                <?php foreach ($supported_languages as $code => $name): ?>
                    <a href="?lang=<?= $code ?>"><?= htmlspecialchars($name) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </header>

    <main class="flex-grow flex justify-center items-center px-4 py-8 relative z-10">
        <div class="bg-white rounded-lg p-6 max-w-md w-full shadow-xl">
            <h2 class="font-semibold text-black text-base mb-2"><?= __('signup_title') ?></h2>
            <p class="text-gray-500 text-xs mb-4 leading-tight">
                <?= __('signup_description_1') ?>
            </p>
            <p class="text-gray-500 text-xs mb-6">
                <?= __('signup_description_2') ?>
            </p>

            <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded" role="alert">
                    <p class="font-bold">Error:</p>
                    <ul class="list-disc list-inside text-sm">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded" role="alert">
                    <p class="font-bold">Success!</p>
                    <p><?= htmlspecialchars($success_message) ?></p>
                    <p class="mt-2 text-sm">
                        Proceed to <a href="<?= $loginLink ?>"
                                     class="font-bold underline hover:text-green-900"><?= __('success_login_link') ?></a>.
                    </p>
                </div>
            <?php endif; ?>


            <form action="client_signup.php" method="POST" aria-label="<?= __('signup_title') ?> form" novalidate="">
                <div class="flex flex-col mb-4">
                    <label class="text-xs text-gray-700 mb-1" for="username"><?= __('label_username') ?></label>
                    <input autocomplete="username"
                           class="border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
                           id="username" name="username" type="text"
                           value="<?= htmlspecialchars($username_val) ?>" required/>
                </div>
                <div class="flex flex-col mb-4">
                    <label class="text-xs text-gray-700 mb-1" for="email"><?= __('label_email') ?></label>
                    <input autocomplete="email"
                           class="border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
                           id="email" name="email" type="email" value="<?= htmlspecialchars($email_val) ?>" required/>
                </div>
                <div class="flex flex-col mb-4">
                    <label class="text-xs text-gray-700 mb-1" for="password"><?= __('label_password') ?></label>
                    <input autocomplete="new-password"
                           class="border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
                           id="password" name="password" type="password" required/>
                </div>
                <div class="flex flex-col mb-6">
                    <label class="text-xs text-gray-700 mb-1" for="confirm_password"><?= __('label_confirm_password') ?></label>
                    <input autocomplete="new-password"
                           class="border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
                           id="confirm_password" name="confirm_password" type="password" required/>
                </div>
                <div class="flex flex-col mb-4">
                    <label class="text-xs text-gray-700 mb-1" for="contact_number">Contact Number</label>
                    <input autocomplete="tel"
                           class="border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
                           id="contact_number" name="contact_number" type="tel"
                           value="<?= htmlspecialchars($contact_number_val) ?>" required/>
                </div>
                <div class="flex flex-col mb-4">
                    <label class="text-xs text-gray-700 mb-1" for="location">Location</label>
                    <input autocomplete="address-level1"
                           class="border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
                           id="location" name="location" type="text"
                           value="<?= htmlspecialchars($location_val) ?>" required/>
                </div>

                <button
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-md py-2 mb-4 transition duration-150 ease-in-out"
                    type="submit">
                    <?= __('button_signup') ?>
                </button>
                <p class="text-center text-xs text-black">
                    <?= __('login_prompt') ?>
                    <a class="font-bold text-blue-700 hover:underline" href="<?= $loginLink ?>">
                        <?= __('login_link') ?>
                    </a>
                </p>
            </form>
        </div>
    </main>

    <footer
        class="max-w-[1200px] w-full mx-auto px-6 py-3 text-xs text-gray-600 flex flex-col sm:flex-row justify-between items-center select-none relative z-10 text-white">
        <div>
            <?= sprintf(__('footer_copyright'), date('Y')) ?>
        </div>
        <nav class="flex flex-wrap justify-center sm:justify-start space-x-2 sm:space-x-4 mt-2 sm:mt-0">
            <a class="hover:underline" href="#"><?= __('footer_product_terms') ?></a>
            <span>|</span>
            <a class="hover:underline" href="#"><?= __('footer_website_terms') ?></a>
            <span>|</span>
            <a class="hover:underline" href="#"><?= __('footer_privacy_policy') ?></a>
            <span>|</span>
            <a class="hover:underline" href="#"><?= __('footer_help') ?></a>
            <span>|</span>
            <a class="hover:underline" href="#"><?= __('footer_privacy_choices') ?></a>
        </nav>
    </footer>

</body>
</html>
