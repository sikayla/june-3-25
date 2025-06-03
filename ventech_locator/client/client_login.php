<?php
// Include the database connection and config files from the 'includes' folder
// Adjust paths as needed based on your file structure
include_once('../includes/db_connection.php'); // Assuming includes folder is one level up
// include_once('../includes/config.php'); // Uncomment if you have a config file and need it

// Start session for login
session_start();

// Initialize variables for errors and success messages
$errors = [];
$success = ""; // This variable is not used in the provided PHP login logic, but kept for consistency

// Check if the form was submitted via POST method
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize email and password input
    // Using filter_input is generally preferred for sanitization and validation
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST["password"] ?? ''; // Use null coalescing to avoid undefined index notice

    // Validate email and password presence
    if (empty($email)) {
        $errors[] = "Email is required.";
    }
    if (empty($password)) {
        $errors[] = "Password is required.";
    }

    // If no initial validation errors, proceed to authenticate against the database
    if (empty($errors)) {
        // Check if $pdo connection is available from db_connection.php
        if (!isset($pdo) || !$pdo instanceof PDO) {
             error_log("PDO connection not available in client_login.php");
             $errors[] = "Database connection error. Please try again later.";
        } else {
            try {
                // Prepare SQL query to find user by email and ensure their role is 'client'
                // Using prepared statements prevents SQL injection
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = 'client'");
                $stmt->execute([$email]);
                $user = $stmt->fetch(); // Fetch the user row

                // Check if a user was found
                if ($user) {
                    // Verify the submitted password against the hashed password in the database
                    if (password_verify($password, $user['password'])) {
                        // Password is correct, set session variables for the logged-in user
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['email'] = $user['email']; // Optionally store email in session
                        $_SESSION['user_role'] = 'client'; // Set the user's role in the session

                        // Redirect the client to their dashboard page
                        // Adjust the redirection path if necessary
                        header("Location: /ventech_locator/client_dashboard.php"); // Assuming dashboard is in a 'client' subdirectory
                        exit; // Stop script execution after redirection
                    } else {
                        // Password does not match
                        $errors[] = "Incorrect password.";
                    }
                } else {
                    // No user found with the provided email and 'client' role
                    $errors[] = "No client account found with this email.";
                }
            } catch (PDOException $e) {
                // Log database errors
                error_log("Database login error in client_login.php: " . $e->getMessage());
                $errors[] = "An error occurred during login. Please try again.";
            }
        }
    }
}

// Determine the path to the client signup page
// Adjust this path based on where client_signup.php is located relative to client_login.php
$clientSignupLink = 'client_signup.php'; // Assuming client_signup.php is in the same directory
// If client_signup.php is in the parent directory, you might use:
// $clientSignupLink = '../client_signup.php';

// Determine the path to the forgot password page
// Adjust this path based on where forgot_password.php is located relative to client_login.php
$forgotPasswordLink = 'forgot_password.php'; // Assuming forgot_password.php is in the same directory
// If forgot_password.php is in a different directory, e.g., 'users', you might use:
// $forgotPasswordLink = '../users/forgot_password.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1" name="viewport"/>
    <title>Client Login - Ventech Locator</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans&amp;display=swap" rel="stylesheet"/>
    <style>
        body {
            font-family: "Open Sans", sans-serif;
        }
        /* Ensure the background image covers the whole page */
        .bg-cover-full {
            background-size: cover;
            background-position: center;
        }
        /* Custom styles for the blue section on the right */
        .blue-section {
            background: linear-gradient(to bottom, #0f6ddb, #0a4a9e); /* Gradient from screenshot */
            color: white;
        }
        .blue-section h3 {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .blue-section p {
            font-size: 0.875rem; /* text-sm */
            margin-bottom: 0.5rem;
        }
        .blue-section img {
            max-width: 100%;
            height: auto;
            border-radius: 8px; /* Match container */
            margin-bottom: 1rem;
        }
        /* Custom styles for the webinar section */
        .webinar-section {
             background: linear-gradient(to right, #1e3a8a, #3b82f6); /* Example gradient */
             color: white;
        }
         .webinar-section h3 {
             font-weight: 600;
             margin-bottom: 0.25rem;
         }
         .webinar-section p {
             font-size: 0.75rem; /* text-xs */
             margin-bottom: 0.75rem;
         }
         .webinar-section a {
             display: inline-block;
             background-color: #f59e0b; /* Amber 500 */
             color: #fff;
             padding: 0.5rem 1rem;
             border-radius: 0.25rem;
             font-size: 0.75rem;
             font-weight: 500;
             transition: background-color 0.2s ease-in-out;
         }
         .webinar-section a:hover {
             background-color: #d97706; /* Amber 600 */
         }

        /* --- Loading Overlay Styles --- */
        #loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.8); /* White with transparency */
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999; /* Ensure it's on top of everything */
            transition: opacity 0.3s ease-in-out;
            opacity: 0; /* Start hidden */
            visibility: hidden; /* Start hidden */
        }

        #loading-overlay.visible {
            opacity: 1;
            visibility: visible;
        }

        /* Loading Animation Styles */
        .loader-container {
            text-align: center;
        }

        .loader-pin {
            color: #ff6347; /* Orange color for the pin */
            font-size: 3rem; /* Adjust size as needed */
            margin-bottom: 10px;
        }

        .loader-bar {
            width: 200px; /* Width of the loading bar */
            height: 4px;
            background-color: #e0e0e0; /* Light gray track */
            border-radius: 2px;
            position: relative;
            margin: 0 auto; /* Center the bar */
        }

        .loader-indicator {
            width: 10px; /* Size of the moving dot */
            height: 10px;
            background-color: #ff6347; /* Orange dot */
            border-radius: 50%;
            position: absolute;
            top: -3px; /* Center vertically on the bar */
            left: 0;
            animation: moveIndicator 2s infinite ease-in-out; /* Animation */
        }

        /* Keyframes for the animation */
        @keyframes moveIndicator {
            0% { left: 0; }
            50% { left: calc(100% - 10px); } /* Move to the end of the bar */
            100% { left: 0; }
        }
        /* --- End Loading Overlay Styles --- */
    </style>
</head>
<body class="min-h-screen flex flex-col bg-cover-full" style="background-image: url('/ventech_locator/images/header.jpg');">
    <div class="fixed inset-0 bg-black opacity-50 -z-10"></div>

    <div id="loading-overlay">
        <div class="loader-container">
            <i class="fas fa-map-marker-alt loader-pin"></i>
            <div class="loader-bar">
                <div class="loader-indicator"></div>
            </div>
        </div>
    </div>
    <header class="flex justify-between items-center px-6 py-6 max-w-[1200px] w-full mx-auto relative z-10">
        <div class="flex items-center space-x-1">
            <span class="text-white font-bold text-lg leading-none">Ventech</span>
            <span class="text-white italic text-lg leading-none">Locator</span>
        </div>
        <div class="text-white text-sm flex items-center space-x-1 cursor-pointer select-none">
            <span>Language:</span>
            <span class="underline">English</span>
            <i class="fas fa-chevron-down text-xs"></i>
        </div>
    </header>

    <main class="flex-grow flex justify-center items-center px-4 py-8 relative z-10">
        <div class="bg-white rounded-lg shadow-xl overflow-hidden flex flex-col md:flex-row max-w-4xl w-full">

            <div class="w-full md:w-1/2 p-6 md:p-8 flex flex-col justify-center">
                <h2 class="font-semibold text-black text-xl mb-4">Client Login</h2>

                <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-3 mb-4 rounded text-sm" role="alert">
                    <p class="font-bold">Login Error:</p>
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php /* if (!empty($success_message)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-3 mb-4 rounded text-sm" role="alert">
                    <p class="font-bold">Success:</p>
                    <p><?= htmlspecialchars($success_message) ?></p>
                </div>
                <?php endif; */ ?>


                <form id="loginForm" action="client_login.php" method="POST" aria-label="Client Login form" novalidate="">
                    <div class="flex flex-col mb-4">
                        <label class="sr-only" for="email">Email</label> <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-envelope text-gray-400"></i> </div>
                            <input autocomplete="email" class="border border-gray-300 rounded-md px-3 py-2 pl-10 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 w-full" id="email" name="email" type="email" placeholder="Email" value="<?= isset($email) ? htmlspecialchars($email) : '' ?>" required/>
                        </div>
                    </div>
                    <div class="flex flex-col mb-6">
                        <label class="sr-only" for="password">Password</label> <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-gray-400"></i> </div>
                            <input autocomplete="current-password" class="border border-gray-300 rounded-md px-3 py-2 pl-10 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 w-full" id="password" name="password" type="password" placeholder="Password" required/>
                        </div>
                    </div>

                    <button class="w-full bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-md py-2.5 mb-4 transition duration-150 ease-in-out" type="submit">
                        Log In
                    </button>
                </form>

                <p class="text-center text-xs mb-4">
                    <a class="font-bold text-blue-700 hover:underline" href="<?= $forgotPasswordLink ?>">Forgot your password?</a>
                </p>
                <p class="text-center text-xs">
                    Don't have an account? <a class="font-bold text-blue-700 hover:underline" href="<?= $clientSignupLink ?>">Register here</a>
                </p>
                


            </div>

            <div class="w-full md:w-1/2 flex flex-col">
                <div class="blue-section p-6 md:p-8 flex-grow flex flex-col justify-center items-center text-center">
                    <img src="https://via.placeholder.com/300x200/0f6ddb/ffffff?text=Marketing+Image" alt="Marketing Image" class="mb-4 rounded-lg"/>
                    <h3 class="text-lg mb-2">Accelerate your career</h3>
                    <p>Build industry connections</p>
                    <p>Grow your Cvent skills</p>
                    </div>

                <div class="webinar-section p-6 md:p-8 mt-auto">
                    <h3 class="text-base mb-2">Product update webinar</h3>
                    <p>Hear recent product updates and future release news with live Q&A</p>
                    <p class="text-xs mb-4">Wednesday, May 21, 2025 | 12 PM ET | 5 PM BST</p>
                    <a href="#" class="text-xs font-semibold">Register now</a>
                </div>
            </div>

        </div>
    </main>

    <footer class="max-w-[1200px] w-full mx-auto px-6 py-3 text-xs text-gray-600 flex flex-col sm:flex-row justify-between items-center select-none relative z-10 text-white">
        <div>
            © <?= date('Y') ?> Ventech Locator. All rights reserved.
        </div>
        <nav class="flex flex-wrap justify-center sm:justify-start space-x-2 sm:space-x-4 mt-2 sm:mt-0">
            <a class="hover:underline" href="#">Product Terms</a>
            <span>|</span>
            <a class="hover:underline" href="#">Website Terms</a>
            <span>|</span>
            <a class="hover:underline" href="#">Privacy Policy</a>
            <span>|</span>
            <a class="hover:underline" href="#">Help</a>
            <span>|</span>
            <a class="hover:underline" href="#">Privacy Choices</a>
        </nav>
    </footer>

    <script>
        // JavaScript to show the loading overlay on form submission and hide on page load

        document.addEventListener('DOMContentLoaded', function() {
            const loginForm = document.getElementById('loginForm');
            const loadingOverlay = document.getElementById('loading-overlay');

            if (loginForm && loadingOverlay) {
                // Show loading overlay when the form is submitted
                loginForm.addEventListener('submit', function() {
                    loadingOverlay.classList.add('visible');
                });
            }
        });

        // Hide loading overlay when the page has fully loaded (including after form submission/redirect)
        window.addEventListener('load', function() {
            const loadingOverlay = document.getElementById('loading-overlay');
            if (loadingOverlay) {
                loadingOverlay.classList.remove('visible');
                // Optional: Remove the element from the DOM after transition
                loadingOverlay.addEventListener('transitionend', function() {
                     // Check if the overlay is actually hidden before removing
                    if (!loadingOverlay.classList.contains('visible')) {
                         loadingOverlay.remove();
                    }
                });
            }
        });
    </script>

</body>
</html>
