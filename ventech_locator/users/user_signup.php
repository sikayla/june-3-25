<?php
// **1. Database Connection Parameters**
// IMPORTANT: Replace with your actual database credentials
$host = 'localhost';
$db   = 'ventech_db'; // Assuming the user table is in this database
$user = 'root'; // Your database username
$pass = ''; // Your database password
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false, // Use native prepared statements
];

// **2. Initialize Variables**
$errors = [];
// The original PHP redirects on success, so $success variable is not used for display on this page.

// Variables to retain form input values on error
$username_val = '';
$email_val = '';
$contact_number_val = '';
$location_val = '';
// Passwords are not retained for security

// **3. Establish PDO Connection**
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // Log the error and display a user-friendly message
    // In a production environment, you should log to a file and not display detailed errors to the user.
    error_log("Database connection error in user_signup.php: " . $e->getMessage());
    $errors[] = "Sorry, we're experiencing technical difficulties with the database. Please try again later.";
}

// **4. Handle Form Submission**
// Only process if DB connection was successful and form is submitted
if ($_SERVER["REQUEST_METHOD"] === "POST" && empty($errors)) {
    // Retain input values (except passwords)
    $username_val = trim($_POST["username"] ?? '');
    $email_val = trim($_POST["email"] ?? '');
    $contact_number_val = trim($_POST["contact_number"] ?? '');
    $location_val = trim($_POST["location"] ?? '');

    // Sanitize and validate input
    $username = htmlspecialchars($username_val, ENT_QUOTES, 'UTF-8');
    $email = filter_var($email_val, FILTER_VALIDATE_EMAIL);
    $password = $_POST["password"] ?? '';
    $repeat_password = $_POST["repeat_password"] ?? '';
    $contact_number = htmlspecialchars($contact_number_val, ENT_QUOTES, 'UTF-8');
    $location = htmlspecialchars($location_val, ENT_QUOTES, 'UTF-8');

    // Basic Validation Checks
    if (empty($username)) $errors[] = "Username is required.";
    if (empty($email)) {
        $errors[] = "Email is required.";
    } elseif ($email === false) {
        $errors[] = "Invalid email format.";
    }
    if (empty($password)) $errors[] = "Password is required.";
    if (empty($repeat_password)) $errors[] = "Please repeat the password."; // Added validation for repeat_password presence
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    }
    if ($password !== $repeat_password) {
        $errors[] = "Passwords do not match.";
    }
    if (empty($contact_number)) $errors[] = "Contact Number is required.";
    // Location is optional, so no required check here.

    // Check if username or email already exists
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "Username or email already in use.";
            }
        } catch (PDOException $e) {
            error_log("Database check error in user_signup.php: " . $e->getMessage());
            $errors[] = "An error occurred while checking user availability. Please try again.";
        }
    }

    // **5. Insert New User if No Errors**
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        try {
            // Assuming 'user' role by default for this signup
            $insert = $pdo->prepare("INSERT INTO users (username, email, password, contact_number, location, role, created_at) VALUES (?, ?, ?, ?, ?, 'user', NOW())");
            if ($insert->execute([$username, $email, $hashed_password, $contact_number, $location])) {
                // Redirect on success
                header("Location: user_login.php?registered=1");
                exit;
            } else {
                $errors[] = "Something went wrong during registration. Please try again.";
            }
        } catch (PDOException $e) {
            error_log("Database insert error in user_signup.php: " . $e->getMessage());
            $errors[] = "An error occurred during registration. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1" name="viewport"/>
    <title>Create Your Account</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Roboto', sans-serif; /* Keeping Roboto from user_signup.php */
        }
        /* Ensure background image covers the screen */
        .bg-cover-full {
            /* Using the background image from the PHP code's HTML */
            background-image: url('/ventech_locator/images/header.jpg');
            background-size: cover;
            background-position: center;
        }
        /* Adjust header and footer background to be semi-transparent */
        header, footer {
            background-color: rgba(0, 0, 0, 0.3); /* Semi-transparent black */
        }
        /* Style for the language select */
        .language-select {
            background-color: transparent;
            border: 1px solid white;
            color: white;
            font-size: 0.75rem; /* text-xs */
            border-radius: 0.25rem; /* rounded */
            padding: 0.25rem 0.5rem; /* px-2 py-1 */
            outline: none;
            /* Remove default arrow */
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            /* Add custom arrow icon */
            background-image: url('/ventech_locator/images/header.jpg');
            background-repeat: no-repeat;
            background-position: right 0.5rem center;
            background-size: 0.8em auto;
            padding-right: 1.5rem; /* Make space for the arrow */
        }
        .language-select:focus {
            ring: 1px;
            ring-color: white;
        }
        .language-select option {
            color: black; /* Options in select are usually black by default */
            background-color: white; /* Make options readable */
        }

        /* Custom style to make the form container slightly transparent */
        .form-container-bg {
            background-color: rgba(255, 255, 255, 0.9); /* White with 90% opacity */
        }

    </style>
</head>
<body class="min-h-screen flex flex-col bg-cover-full">

    <header class="flex justify-between items-center px-6 py-4 text-white relative z-10">
        <div class="flex items-center space-x-1">
            <img alt="Cvent logo black text on white background" class="h-5 w-auto" height="20" src="/ventech_locator/images/logo.pmg" width="80"/>
            <span class="italic text-sm" style="font-family: 'Times New Roman', serif;">
                Supplier Network
            </span>
        </div>
        <div class="text-xs flex items-center space-x-1">
            <span>Language:</span>
            <select aria-label="Language selection" class="language-select">
                <option value="en" selected="">English</option>
                <option value="es">Spanish</option>
                <option value="fr">French</option>
                <option value="de">German</option>
            </select>
        </div>
    </header>

    <main class="flex-grow flex items-center justify-center p-4 relative z-10">
        <div class="bg-white max-w-4xl w-full flex rounded-md shadow-md overflow-hidden form-container-bg">
            <section class="hidden md:block md:w-1/2">
                <img alt="Woman with long brown hair sitting at a table with a cup of coffee and a laptop, a guitar blurred in the background" class="w-full h-full object-cover" height="800" src="https://storage.googleapis.com/a1aa/image/7a2675ef-f98c-4548-2986-2b6129dfa192.jpg" width="600"/>
            </section>

            <section class="w-full md:w-1/2 p-8 flex flex-col items-center justify-center"> <h1 class="text-2xl font-bold text-center mb-2 text-gray-800">Create Your Account on</h1> <h2 class="text-2xl font-bold text-center text-orange-500 mb-4">Courts of the World</h2>
                <p class="text-center mb-6 text-sm text-gray-700">
                    Enjoy the benefits of becoming a registered user:<br> Create your profile, add your homecourt, comment on courts and post your photos and videos!
                </p>

                <?php if (!empty($errors)): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded w-full" role="alert"> <p class="font-bold">Error:</p>
                        <ul class="list-disc list-inside text-sm">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" aria-label="User Signup Form" class="w-full max-w-xs flex flex-col gap-3"> <div class="grid grid-cols-1 md:grid-cols-2 gap-4"> <div>
                            <label for="username" class="block text-sm font-medium text-gray-700">Username <span class="text-red-500">*</span></label>
                            <input type="text" id="username" name="username" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" value="<?= htmlspecialchars($username_val) ?>" required> </div>
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700">Email <span class="text-red-500">*</span></label>
                            <input type="email" id="email" name="email" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" value="<?= htmlspecialchars($email_val) ?>" required> </div>
                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700">Password <span class="text-red-500">*</span></label>
                            <input type="password" id="password" name="password" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" required> <p class="text-xs text-gray-500 mt-1">Must be at least 8 characters long.</p>
                        </div>
                        <div>
                            <label for="repeat_password" class="block text-sm font-medium text-gray-700">Repeat password <span class="text-red-500">*</span></label>
                            <input type="password" id="repeat_password" name="repeat_password" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" required> </div>
                        <div>
                            <label for="contact_number" class="block text-sm font-medium text-gray-700">Contact Number <span class="text-red-500">*</span></label>
                            <input type="text" name="contact_number" id="contact_number" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" value="<?= htmlspecialchars($contact_number_val) ?>" required> </div>
                        <div>
                            <label for="location" class="block text-sm font-medium text-gray-700">Location</label>
                            <input type="text" name="location" id="location" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" value="<?= htmlspecialchars($location_val) ?>"> </div>
                    </div>

                    <div class="mb-4 mt-2"> <input type="checkbox" id="terms" class="mr-2" required>
                        <label for="terms" class="text-sm text-gray-700">
                            By clicking "Create Your Account", you accept our <a href="#" class="text-orange-500 hover:underline">Terms of Use</a>,
                            <a href="#" class="text-orange-500 hover:underline">Privacy Policy</a> and <a href="#" class="text-orange-500 hover:underline">Cookie Policy</a>. <span class="text-red-500">*</span>
                        </label>
                    </div>
                     <div class="mb-6">
                        <input type="checkbox" id="newsletter" class="mr-2">
                        <label for="newsletter" class="text-sm text-gray-700">You agree to receive updates via the Courts of the World newsletter.</label>
                    </div>
                    <p class="text-sm text-gray-700 mb-4"><span class="text-red-500">*</span> Mandatory fields.</p>


                    <button type="submit" class="w-full bg-orange-500 hover:bg-orange-600 text-white py-2 rounded-md text-lg font-bold transition duration-150 ease-in-out">
                        Create Your Account
                    </button>
                </form>

                <p class="text-center text-sm text-gray-700 mt-4">
                    Already have an account?
                    <a class="font-bold text-blue-700 hover:underline" href="user_login.php"> Log in
                    </a>
                </p>
            </section>
        </div>
    </main>

    <footer class="max-w-[1200px] w-full mx-auto px-6 py-3 text-[10px] text-gray-600 flex flex-col sm:flex-row justify-between items-center select-none relative z-10 text-white">
        <div class="flex items-center space-x-1 mb-2 sm:mb-0">
            <img alt="Cvent logo black text on white background small" class="h-2.5 w-auto" height="10" src="https://storage.googleapis.com/a1aa/image/e7b2b466-566e-49cf-8534-4f0872cca8ae.jpg" width="40"/>
            <span>
                © 2000-<?= date('Y') ?> Cvent, Inc. All rights reserved.
            </span>
        </div>
        <nav class="flex flex-wrap justify-center sm:justify-start space-x-2 sm:space-x-4 text-white">
            <a class="hover:underline" href="#">Product Terms of Use</a>
            <span>|</span>
            <a class="hover:underline" href="#">Website Terms of Use</a>
            <span>|</span>
            <a class="hover:underline" href="#">Privacy Policy</a>
            <span>|</span>
            <a class="hover:underline" href="#">Help and Support</a>
            <span>|</span>
            <a class="hover:underline" href="#">Your Privacy Choices</a>
        </nav>
    </footer>

</body>
</html>
