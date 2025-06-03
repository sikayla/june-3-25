<?php
session_start();

// ==================== Configuration ====================
define('DASHBOARD_PATH', '/ventech_locator/users/user_dashboard.php'); // Corrected path to client_dashboard.php
define('SIGNUP_PATH', '/ventech_locator/users/user_signup.php');
define('FORGOT_PASSWORD_PATH', '#'); // Replace with actual path when available

// ==================== Redirect if Logged In ====================
if (isset($_SESSION['user_id'])) {
    header("Location: " . DASHBOARD_PATH);
    exit;
}

// ==================== Database Connection ====================
$host = 'localhost';
$db = 'ventech_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$error = "";
$login_val = "";

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log("DB connection error in user_login.php: " . $e->getMessage());
    $error = "We're experiencing technical issues. Please try again later.";
}

// ==================== Handle POST Login ====================
if ($_SERVER["REQUEST_METHOD"] === "POST" && empty($error)) {

    // -------- Guest Login --------
    if (isset($_POST['login_as_guest'])) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'guest' LIMIT 1");
            $stmt->execute();
            $guest_user = $stmt->fetch();

            if ($guest_user) {
                // Use existing guest
                $_SESSION['user_id'] = $guest_user['id'];
                $_SESSION['username'] = 'Guest';
                $_SESSION['role'] = 'guest';
            } else {
                // Create new guest account
                $guest_username = 'guest_' . uniqid();
                $guest_email = $guest_username . '@example.com';
                $guest_password_hash = password_hash('guest_' . uniqid(), PASSWORD_DEFAULT);

                $stmt_guest = $pdo->prepare("INSERT INTO users (username, email, password, role, created_at) VALUES (:username, :email, :password, 'guest', NOW())");
                $stmt_guest->execute([
                    ':username' => $guest_username,
                    ':email' => $guest_email,
                    ':password' => $guest_password_hash,
                ]);

                $_SESSION['user_id'] = $pdo->lastInsertId();
                $_SESSION['username'] = $guest_username;
                $_SESSION['role'] = 'guest';
            }

            header("Location: " . DASHBOARD_PATH);
            exit;

        } catch (PDOException $e) {
            error_log("Guest login error: " . $e->getMessage());
            $error = "Guest login failed. Please try again.";
        }

    } else {
        // -------- Regular User Login --------
        $login_val = trim($_POST['email_or_username'] ?? '');
        $password = $_POST['password'] ?? '';
        $login = htmlspecialchars($login_val, ENT_QUOTES, 'UTF-8');

        if (empty($login) || empty($password)) {
            $error = "Please enter both username/email and password.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1");
                $stmt->execute([$login, $login]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    // Set session and redirect
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];

                    $redirect_url = DASHBOARD_PATH; // Optionally: $_SESSION['intended_page'] ?? DASHBOARD_PATH;
                    header("Location: " . $redirect_url);
                    exit;
                } else {
                    $error = "Invalid login credentials.";
                }
            } catch (PDOException $e) {
                error_log("Login error: " . $e->getMessage());
                $error = "Login failed. Please try again.";
            }
        }
    }
}

// ==================== Handle Registration Redirect ====================
$success_message = '';
if (isset($_GET['registered']) && $_GET['registered'] == 1) {
    $success_message = "Registration successful! Please log in.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Login - Ventech Locator</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Open Sans', sans-serif;
            background-color: #f0f2f5; /* Light gray background */
            line-height: 1.5; /* Default line height */
        }
        .login-container {
            display: grid;
            grid-template-columns: minmax(300px, 400px) 1fr; /* Login form fixed width, right side takes remaining space */
            gap: 2rem; /* Space between columns */
            max-width: 1000px; /* Max width of the main content area */
            margin: auto; /* Center the container */
            padding: 2rem;
            align-items: start; /* Align items to the top */
        }

        @media (max-width: 768px) { /* Stack columns on smaller screens */
            .login-container {
                grid-template-columns: 1fr;
                gap: 1.5rem;
                padding: 1rem;
            }
             .right-content {
                 /* Hide promotional blocks on small screens */
                 display: none;
             }
              main {
                 padding-top: 1rem; /* Reduce top padding on small screens */
                 padding-bottom: 1rem; /* Reduce bottom padding on small screens */
             }
        }

         .login-form-block {
             background-color: #ffffff;
             padding: 2rem;
             border-radius: 0.5rem;
             box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
         }

         .promo-block {
             border-radius: 0.5rem;
             overflow: hidden; /* Ensure image/content stays within borders */
             box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
         }

         .promo-block img {
             width: 100%;
             height: auto;
             display: block; /* Remove extra space below image */
         }

         .promo-block .content {
             padding: 1rem;
         }

         .bg-cvent-blue {
             background-color: #0073e6; /* Example Cvent blue */
             color: white;
         }

         .bg-cvent-gradient {
            background: linear-gradient(to right, #0073e6, #00b4d8); /* Example gradient */
            color: white;
         }

          .btn-register {
              background-color: #28a745; /* Example green */
              color: white;
              padding: 0.5rem 1rem;
              border-radius: 0.25rem;
              font-weight: 600;
              text-align: center;
              display: inline-block; /* Allow padding */
              margin-top: 1rem;
          }

           .btn-register:hover {
               background-color: #218838;
           }

           .btn-webinar {
                background-color: #ffc107; /* Example yellow */
                color: #212529; /* Dark text */
                padding: 0.5rem 1rem;
                border-radius: 0.25rem;
                font-weight: 600;
                 text-align: center;
                display: inline-block; /* Allow padding */
                margin-top: 1rem;
           }

           .btn-webinar:hover {
               background-color: #e0a800;
           }

           .footer-nav a {
               color: #555; /* Darker gray */
               font-size: 0.75rem; /* text-xs */
               white-space: nowrap; /* Prevent links from wrapping */
           }
            .footer-nav a:hover {
                text-decoration: underline;
            }
            .footer-nav span {
                margin: 0 0.5rem;
                 color: #ccc; /* Light gray separator */
            }

             .language-select {
                 background-color: transparent;
                 border: 1px solid #ccc; /* Light border */
                 color: #555; /* Darker text */
                 font-size: 0.75rem; /* text-xs */
                 border-radius: 0.25rem; /* rounded */
                 padding: 0.25rem 0.5rem; /* px-2 py-1 */
                 outline: none;
             }
             .language-select:focus {
                 ring: 1px;
                 ring-color: #0073e6; /* Cvent blue ring */
             }
             .language-select option {
                 color: black;
                 background-color: white;
             }
             .header-logo {
                 height: 20px; /* Adjust as needed */
                 margin-right: 0.5rem; /* Space between logo and text */
             }
              .error-message {
                 background-color: #fee2e2; /* red-100 */
                 border-left: 4px solid #dc2626; /* red-600 */
                 color: #b91c1c; /* red-700 */
                 padding: 0.75rem 1rem;
                 margin-bottom: 1rem;
                 border-radius: 0.25rem;
                 font-size: 0.875rem; /* text-sm */
             }
             .success-message {
                 background-color: #d1fae5; /* green-100 */
                 border-left: 4px solid #10b981; /* green-500 */
                 color: #065f46; /* green-700 */
                  padding: 0.75rem 1rem;
                 margin-bottom: 1rem;
                 border-radius: 0.25rem;
                 font-size: 0.875rem; /* text-sm */
             }
              .login-button {
                 background-color: #ef4444; /* red-500 from your signup */
                 color: white;
                 padding-top: 0.5rem;
                 padding-bottom: 0.5rem;
                 border-radius: 0.25rem;
                 font-weight: 600; /* semibold */
                 text-align: center;
                 width: 100%;
                 transition: background-color 150ms ease-in-out;
             }
             .login-button:hover {
                 background-color: #dc2626; /* red-600 */
             }
             .guest-login-button {
                 background-color: #f59e0b; /* yellow-500 from your signup */
                 color: white;
                 padding-top: 0.5rem;
                 padding-bottom: 0.5rem;
                 border-radius: 0.25rem;
                 font-weight: 600; /* semibold */
                 text-align: center;
                 width: 100%;
                 transition: background-color 150ms ease-in-out;
             }
             .guest-login-button:hover {
                 background-color: #d97706; /* yellow-600 */
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
<body class="min-h-screen flex flex-col">

    <div id="loading-overlay">
        <div class="loader-container">
            <i class="fas fa-map-marker-alt loader-pin"></i>
            <div class="loader-bar">
                <div class="loader-indicator"></div>
            </div>
        </div>
    </div>

    <header class="flex justify-between items-center px-6 py-4 bg-white border-b border-gray-200">
        <div class="flex items-center">
            <span class="text-black font-bold text-lg leading-none">Ventech</span>
            <span class="text-black italic text-lg leading-none ml-1">Locator</span>
             </div>
         <div class="text-xs flex items-center space-x-1 text-gray-700">
            <span>Language:</span>
            <select aria-label="Language selection" class="language-select">
                 <option value="en" selected>English</option>
                 <option value="es">Spanish</option>
                 <option value="fr">French</option>
                 <option value="de">German</option>
                 </select>
             <i class="fas fa-chevron-down text-xs text-gray-500"></i>
         </div>
    </header>

    <main class="flex-grow flex justify-center items-start px-4 py-8">
        <div class="login-container">

            <div class="login-form-block">
                <h2 class="text-2xl font-bold mb-6 text-center text-gray-800">Log in to Ventech Locator</h2>

                 <?php if (!empty($error)): ?>
                    <div class="error-message" role="alert">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                 <?php if (!empty($success_message)): ?>
                    <div class="success-message" role="alert">
                        <?= htmlspecialchars($success_message) ?>
                    </div>
                <?php endif; ?>


                <form id="loginForm" method="POST" action="">
                     <div class="mb-4">
                         <label for="email_or_username" class="block text-sm font-medium text-gray-700 mb-1">Email or Username</label>
                         <input type="text" id="email_or_username" name="email_or_username" class="w-full border border-gray-300 rounded-md p-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" value="<?= htmlspecialchars($login_val) ?>" required>
                     </div>
                     <div class="mb-6">
                         <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                         <input type="password" id="password" name="password" class="w-full border border-gray-300 rounded-md p-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" required>
                     </div>
                     <button type="submit" class="login-button">
                         Log in
                     </button>
                </form>

                 <form id="guestLoginForm" method="POST" action="" class="mt-4">
                    <button type="submit" name="login_as_guest" class="guest-login-button">Log in as Guest</button>
                </form>


                <div class="mt-6 text-center text-sm">
                    <p class="mb-2"><a href="/ventech_locator/users/user_signup.php" class="text-blue-700 hover:underline">Register here</a></p> <p class="mb-2"><a href="<?= $forgot_password_path ?>" class="text-blue-700 hover:underline">Forgot password?</a></p>
                    <p><a href="#" class="text-blue-700 hover:underline">Log in using Single Sign-On</a></p>
                </div>
            </div>

            <div class="right-content flex flex-col space-y-6">
                <div class="promo-block bg-cvent-blue">
                    <img src="https://via.placeholder.com/600x300?text=Cvent+Connect+Image+Placeholder" alt="Cvent Connect Event" class="w-full h-auto"> <div class="content text-white">
                         <h3 class="text-xl font-bold mb-2">CVENT CONNECT</h3>
                         <p class="text-sm mb-2">SANTIAGO/ONLINE & VIRTUAL</p>
                         <p class="text-xl font-bold mb-4">JUNE 9-12</p>
                         <p class="text-sm font-semibold">Accelerate your career</p>
                         <p class="text-sm font-semibold">Build industry connections</p>
                         <p class="text-sm font-semibold">Grow your Cvent skills</p>
                         <a href="#" class="btn-register hover:no-underline">Register Now</a>
                    </div>
                </div>

                <div class="promo-block bg-cvent-gradient">
                     <div class="content text-white">
                          <h3 class="text-lg font-bold mb-2">cvent</h3>
                          <h4 class="text-xl font-bold mb-2">Product update webinar</h4>
                          <p class="text-sm mb-2">Hear recent product updates and future release news with live Q&A</p>
                          <p class="text-xs mb-4">Wednesday, May 21, 2025, 12PM ET | 5PM BST</p>
                          <a href="#" class="btn-webinar hover:no-underline">Register Now</a>
                     </div>
                 </div>
            </div>
        </div>
    </main>

    <footer class="text-center py-4 text-xs text-gray-600 mt-auto border-t border-gray-200 bg-white">
        <div class="footer-nav flex flex-wrap justify-center">
            <a href="#">Need an account?</a><span>|</span>
            <a href="#">Event Management</a><span>|</span>
            <a href="#">Webinar</a><span>|</span>
            <a href="#">Sourcing</a><span>|</span>
            <a href="#">Supplier Network</a><span>|</span>
            <a href="#">Customer Support</a><span>|</span>
            <a href="#">Privacy Policy</a><span>|</span>
            <a href="#">Your Privacy Choices</a>
        </div>
         <div class="mt-2 text-gray-500 text-[10px]">
             © 2000-<?= date('Y') ?> Cvent, Inc. All rights reserved. </div>
    </footer>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const loginForm = document.getElementById('loginForm');
            const guestLoginForm = document.getElementById('guestLoginForm');
            const loadingOverlay = document.getElementById('loading-overlay');

            // Function to show the loading overlay
            function showLoadingOverlay() {
                if (loadingOverlay) {
                    loadingOverlay.classList.add('visible');
                }
            }

            // Attach event listener to the regular login form
            if (loginForm) {
                loginForm.addEventListener('submit', function(event) {
                    event.preventDefault(); // Prevent default form submission
                    showLoadingOverlay();
                    setTimeout(() => {
                        loginForm.submit(); // Submit the form after 3 seconds
                    }, 3000); // 3000 milliseconds = 3 seconds
                });
            }

            // Attach event listener to the guest login form
            if (guestLoginForm) {
                guestLoginForm.addEventListener('submit', function(event) {
                    event.preventDefault(); // Prevent default form submission
                    showLoadingOverlay();
                    setTimeout(() => {
                        guestLoginForm.submit(); // Submit the form after 3 seconds
                    }, 3000); // 3000 milliseconds = 3 seconds
                });
            }

            // Hide loading overlay when the page has fully loaded (after redirect or initial load)
            window.addEventListener('load', function() {
                if (loadingOverlay) {
                    loadingOverlay.classList.remove('visible');
                    // Optional: Remove the element from the DOM after transition
                    loadingOverlay.addEventListener('transitionend', function() {
                        if (!loadingOverlay.classList.contains('visible')) {
                            loadingOverlay.remove();
                        }
                    });
                }
            });
        });
    </script>

</body>
</html>
