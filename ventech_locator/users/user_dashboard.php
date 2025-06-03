<?php
// user_dashboard.php

// **1. Start Session**
// Ensure this is the absolute first line, before any output (including whitespace)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// **2. Include Database Connection**
// Ensure this path is correct relative to user_dashboard.php
// Assuming includes folder is one level up from the directory containing user_dashboard.php (e.g., users/)
require_once '../includes/db_connection.php';

// **3. Check User Authentication**
// Assuming user ID is stored in 'user_id' session variable after login
if (!isset($_SESSION['user_id'])) {
    // Redirect to user login page. Adjust path if needed.
    header("Location: user_login.php");
    exit;
}
$user_id = $_SESSION['user_id'];

// **4. Check if PDO connection is available**
// This check is good practice after including the connection file
if (!isset($pdo) || !$pdo instanceof  PDO ) {
    // Log the error and display a user-friendly message
    error_log("PDO connection not available in user_dashboard.php");
    // Use a simple HTML output for fatal error if redirect is not possible or desired
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Error</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-gray-100 flex items-center justify-center h-screen"><div class="text-center p-8 bg-white rounded-lg shadow-md"><h1 class="text-2xl font-bold text-red-600 mb-4">System Error</h1><p class="text-gray-700">Sorry, we\'re experiencing technical difficulties with the database. Please try again later.</p></div></body></html>';
    exit;
}

// **5. Fetch Logged-in User Details**
$user = null; // Initialize user variable
try {
    $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch( PDO ::FETCH_ASSOC); // Fetch as associative array

    if (!$user) {
        // Invalid user ID in session, log out
        error_log("Invalid user_id {$user_id} in session (user_dashboard).");
        session_unset();
        session_destroy();
        // Redirect to login with an error message. Adjust path if needed.
        header("Location: user_login.php?error=invalid_session");
        exit;
    }
} catch ( PDOException  $e) {
    error_log("Error fetching user details for user ID {$user_id} in user_dashboard: " . $e->getMessage());
    // Display a user-friendly error message without exposing database details
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Error</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-gray-100 flex items-center justify-center h-screen"><div class="text-center p-8 bg-white rounded-lg shadow-md"><h1 class="text-2xl font-bold text-red-600 mb-4">Error Loading User Data</h1><p class="text-gray-700">There was an error loading your user information. Please try again later.</p></div></body></html>';
    exit;
}

// **6. Fetch Dashboard Counts Using Efficient Queries**
$total_reservations_count = 0;
$upcoming_reservations_count = 0;
$pending_reservations_count = 0;

try {
    // Query 1: Total Reservations Count for the user
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM venue_reservations WHERE user_id = ?");
    $stmtTotal->execute([$user_id]);
    $total_reservations_count = $stmtTotal->fetchColumn(); // Fetch only the count

    // Query 2: Pending Reservations Count for the user
    $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM venue_reservations WHERE user_id = ? AND status = 'pending'");
    $stmtPending->execute([$user_id]);
    $pending_reservations_count = $stmtPending->fetchColumn(); // Fetch only the count

    // Query 3: Upcoming Reservations Count for the user (Confirmed and date is today or later)
    // Using CURDATE() in the SQL query is generally more reliable than passing a PHP date string
    $stmtUpcoming = $pdo->prepare("SELECT COUNT(*) FROM venue_reservations WHERE user_id = ? AND (status = 'accepted' OR status = 'confirmed') AND event_date >= CURDATE()"); // Added 'accepted' status to upcoming
    $stmtUpcoming->execute([$user_id]);
    $upcoming_reservations_count = $stmtUpcoming->fetchColumn(); // Fetch only the count

} catch ( PDOException  $e) {
    error_log("Error fetching dashboard counts for user $user_id: " . $e->getMessage());
    // Counts will remain 0, dashboard cards will show 0, which is acceptable in case of error
    // Optionally set a user-friendly message here if counts fail
}

// **7. Fetch User's Recent Reservations for the Table**
$recent_reservations = []; // Use a new variable name for clarity
try {
     $stmtRecent = $pdo->prepare(
         "SELECT r.id, r.event_date, r.status, r.created_at,
                 v.id as venue_id, v.title as venue_title
            FROM venue_reservations r -- Corrected table name
            JOIN venue v ON r.venue_id = v.id
            WHERE r.user_id = ?
            ORDER BY r.event_date DESC, r.created_at DESC
            LIMIT 5" // Limit to fetch only the most recent 5 for the dashboard table
     );
     $stmtRecent->execute([$user_id]);
     $recent_reservations = $stmtRecent->fetchAll( PDO ::FETCH_ASSOC); // Fetch as associative array

} catch ( PDOException  $e) {
     error_log("Error fetching recent reservations for user $user_id: " . $e->getMessage());
     // $recent_reservations remains empty, user will see a "no reservations" message in the table
     // Optionally set a user-friendly message here if recent reservations fail
}

// **8. Fetch Unread Notification Count**
$unread_notification_count = 0;
try {
     // Assuming you have a 'user_notifications' table with 'user_id' and 'is_read' columns
     $stmtNotifyCount = $pdo->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = :user_id AND is_read = FALSE");
     $stmtNotifyCount->execute([':user_id' => $user_id]);
     $unread_notification_count = $stmtNotifyCount->fetchColumn();
} catch ( PDOException  $e) {
     error_log("Error fetching unread notification count for user $user_id: " . $e->getMessage());
     $unread_notification_count = 0; // Default to 0 if fetching fails
}


// --- Helper function for status badges (reused from client_dashboard) ---
// This function is fine as is, no changes needed based on the request
function  getStatusBadgeClass($status) {
    $status = strtolower($status ?? 'unknown');
    switch ($status) {
        case 'confirmed': case 'accepted': case 'completed': // Added accepted and completed for user view
            return 'bg-green-100 text-green-800';
        case 'cancelled': case 'rejected':
            return 'bg-red-100 text-red-800';
        case 'pending':
            return 'bg-yellow-100 text-yellow-800';
        default: return 'bg-gray-100 text-gray-800'; // For other statuses
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1" name="viewport"/>
    <title>User Dashboard - Wedding Spot</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&display=swap");
        body {
            font-family: "Open Sans", sans-serif;
            background-color: #fff; /* Match index (5).html body background */
            color: #1f2937; /* Default text color */
        }

        /* Custom styles for notification badge */
        .notification-icon-container {
             position : relative;
             display : inline-block; /* Allows positioning the badge relative to this */
             margin-right : 1.5rem; /* Space between notification icon and logout */
        }

        .notification-badge {
             position : absolute;
             top : -8px; /* Adjust vertical position */
             right : -8px; /* Adjust horizontal position */
             background-color : #ef4444; /* Red color */
             color : white;
             border-radius : 9999px; /* Full rounded */
             padding : 0.1rem 0.4rem; /* Adjust padding */
             font-size : 0.75rem; /* Smaller font size */
             font-weight : bold;
             min-width : 1.25rem; /* Minimum width to ensure circle shape */
             text-align : center;
             line-height : 1; /* Adjust line height for vertical centering */
        }

         /* Enhanced Table Styles - Adapted for Wedding Spot colors */
         .table-container {
              background-color : #ffffff;
              border-radius : 0.75rem; /* rounded-xl */
              box-shadow : 0 4px 6px rgba(0, 0, 0, 0.1), 0 1px 3px rgba(0, 0, 0, 0.08);
             overflow-hidden; /* Ensures rounded corners on table */
         }
         .table-header {
              background-color : #f3f4f6; /* gray-100 */
             text-xs uppercase text-gray-600;
         }
         .table-row {
              border-bottom : 1px solid #e5e7eb; /* gray-200 */
              transition : background-color 0.2s ease-in-out;
         }
         .table-row:last-child {
              border-bottom : none;
         }
         .table-row:hover {
              background-color : #f9fafb; /* gray-50 */
         }
         .table-cell {
              padding : 1rem 1.5rem; /* px-6 py-4 */
         }
         .table-cell.font-medium {
              font-weight : 500; /* font-medium */
              color : #1f2937; /* gray-900 */
         }
          .table-cell.text-gray-600 {
               color : #4b5563; /* gray-600 */
          }
         .status-badge {
              padding : 0.125rem 0.5rem; /* py-0.5 px-2 */
              display : inline-block;
              border-radius : 9999px; /* rounded-full */
              font-size : 0.75rem; /* text-xs */
              font-weight : 600; /* font-semibold */
         }
         /* Status badge colors remain the same as they are functional */
         .status-badge.bg-green-100 {  background-color : #dcfce7;  color : #166534; } /* green-100 text-green-800 */
         .status-badge.bg-red-100 {  background-color : #fee2e2;  color : #991b1b; } /* red-100 text-red-800 */
         .status-badge.bg-yellow-100 {  background-color : #fffbeb;  color : #92400e; } /* yellow-100 text-yellow-800 */
         .table-link {
              color : #8b1d52; /* Wedding Spot primary color */
             hover:text-[#6f153f]; /* Darker shade on hover */
             font-medium;
              text-decoration : none;
              transition : color 0.2s ease-in-out;
         }
         .table-link:hover {
              text-decoration : underline;
         }

         /* Call to Action Section - Adapted for Wedding Spot colors */
         .hero-section {
            position: relative;
            width: 100%;
            height: 300px; /* Fixed height for hero image */
            object-fit: cover;
            background-image: url('https://storage.googleapis.com/a1aa/image/d10291e9-3be5-467c-d385-9e13224e260e.jpg'); /* Example image from index (5).html */
            background-size: cover;
            background-position: center;
            border-radius: 0.5rem; /* rounded-lg */
            overflow: hidden;
            margin-bottom: 2.5rem; /* mb-10 */
         }
         .hero-overlay {
            position: absolute;
            inset: 0;
            background-color: rgba(0, 0, 0, 0.5); /* bg-black bg-opacity-50 */
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 1rem; /* px-4 */
            border-radius: 0.5rem; /* rounded-lg */
         }
         .hero-title {
            color: white;
            font-size: 2rem; /* text-2xl sm:text-3xl */
            font-weight: 600; /* font-semibold */
            line-height: 1.25; /* leading-tight */
            max-width: 32rem; /* max-w-lg */
            margin-bottom: 0.25rem; /* mt-1 */
         }
         .hero-description {
            color: white;
            font-size: 0.875rem; /* text-xs sm:text-sm */
            margin-top: 0.25rem; /* mt-1 */
            max-width: 20rem; /* max-w-xs */
         }
         .hero-button {
            background-color : #8b1d52; /* Wedding Spot primary color */
            color : white;
            font-weight : 600; /* font-semibold */
            padding : 0.625rem 1rem; /* px-4 py-2 */
            border-radius : 0.375rem; /* rounded-md */
            transition : background-color 0.2s ease-in-out;
            display : inline-flex;
            align-items : center;
            margin-top: 1rem; /* mt-4 */
         }
         .hero-button:hover {
            background-color : #6f153f; /* Darker shade on hover */
         }
         .hero-button i {
            margin-right : 0.5rem; /* mr-2 */
         }


         /* Responsive adjustments for CTA */
         @media (min-width: 768px) { /* md breakpoint */
             .cta-section {
                  flex-direction : row;
                  justify-content : space-between;
                  text-align : left;
                  padding : 2rem; /* p-8 */
             }
             .cta-description {
                  margin-bottom : 0; /* Remove bottom margin on larger screens */
             }
         }

          /* Responsive table container for horizontal scrolling on small screens */
          .responsive-table-container {
              overflow-x : auto; /* Enable horizontal scrolling */
              -webkit-overflow-scrolling : touch; /* Smooth scrolling on iOS */
          }
           /* Ensure table takes full width within its container */
           .responsive-table-container table {
               width : 100%;
           }

           /* Desktop Layout Adjustments */
           @media (min-width: 768px) { /* md breakpoint */
               /* Hide mobile menu button on desktop */
               #mobile-menu-button {
                   display: none;
               }
               /* Adjust main content margin for no sidebar */
               main {
                   margin-left: 0;
                   background-color: #fff; /* Match body background */
                   padding-top: 0; /* Adjusted since header is fixed and content starts below */
               }
               .dashboard-container { /* New class for the main flex container */
                   display: block; /* Changed from flex as sidebar is removed */
                   /* min-height: calc(100vh - 64px); */ /* Adjusted based on header height */
                   padding-top: 64px; /* Push content below fixed header */
               }
           }
           /* General padding for main content when no sidebar */
           main {
               padding-top: 6rem; /* Adjust based on header height */
           }
    </style>
</head>
<body class="bg-white text-gray-900">
    <header class="flex items-center justify-between py-4 text-xs sm:text-sm fixed w-full top-0 left-0 right-0 z-30 bg-white shadow-md px-4">
        <div class="flex items-center space-x-2">
            <button id="mobile-menu-toggle" aria-label="Menu" class="text-gray-700 text-xl md:hidden">
                <i class="fas fa-bars"></i>
            </button>
            <img alt="Wedding Spot logo with text and a ring icon" class="w-[80px] h-[30px] object-contain" height="30" src="https://storage.googleapis.com/a1aa/image/f5672a7b-464f-4829-45ce-433cf84362c0.jpg" width="80"/>
        </div>

        <nav class="hidden md:flex items-center space-x-4 text-xs sm:text-sm text-gray-900 font-normal">
            <ul class="flex items-center space-x-4">

                <li class="cursor-pointer hover:underline hover:text-[#8b1d52]">
                    <a href="user_reservation_manage.php">My Reservations</a>
                </li>
                <li class="cursor-pointer hover:underline hover:text-[#8b1d52]">
                    <a href="/ventech_locator/client_map.php">Find Venues</a>
                </li>
                <li class="cursor-pointer hover:underline hover:text-[#8b1d52]">
                    <a href="user_profile.php">Profile</a>
                </li>
            </ul>

            <ul class="flex items-center space-x-4 ml-6">
                <?php if ($user): ?>
                    <li class="relative group cursor-pointer">
                        <div class="notification-icon-container inline-block">
                            <a href="user_notification_list.php" class="text-gray-700 hover:text-[#8b1d52] transition-colors" title="View Notifications">
                                <i class="fas fa-bell text-xl"></i>
                            </a>
                            <?php if ($unread_notification_count > 0): ?>
                                <span id="notification-count-badge" class="notification-badge"><?= htmlspecialchars($unread_notification_count) ?></span>
                            <?php else: ?>
                                <span id="notification-count-badge" class="notification-badge" style="display: none;">0</span>
                            <?php endif; ?>
                        </div>
                    </li>
                    <li class="cursor-pointer">
                        <span class="hidden lg:inline text-gray-700">Welcome, <strong class="font-semibold text-[#8b1d52]"><?= htmlspecialchars($user['username'] ?? 'User') ?></strong>!</span>
                    </li>
                    <li class="cursor-pointer">
                        <a href="/ventech_locator/users/user_logout.php" class="bg-[#8b1d52] text-white hover:bg-[#6f153f] py-1.5 px-4 rounded-md text-sm font-medium transition duration-150 ease-in-out shadow-sm flex items-center">
                            <i class="fas fa-sign-out-alt mr-1"></i> Logout
                        </a>
                    </li>
                <?php else: ?>
                    <li class="cursor-pointer hover:underline hover:text-[#8b1d52]">Sign In</li>
                    <li class="cursor-pointer">
                        <a href="/ventech_locator/client/client_signup.php" class="bg-[#8b1d52] text-white hover:bg-[#6f153f] py-1.5 px-4 rounded-md text-sm font-medium transition duration-150 ease-in-out shadow-sm">
                            <i class="fas fa-user-plus mr-1"></i> Register
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <div id="mobile-menu" class="md:hidden bg-white py-2 px-4 mt-[64px] fixed w-full z-20 shadow-md hidden">
        <ul class="flex flex-col space-y-2 text-gray-900 font-normal">
            <li><a href="user_dashboard.php" class="block py-2 px-3 hover:bg-gray-100 rounded">Dashboard</a></li>
            <li><a href="user_reservation_manage.php" class="block py-2 px-3 hover:bg-gray-100 rounded">My Reservations</a></li>
            <li><a href="/ventech_locator/client_map.php" class="block py-2 px-3 hover:bg-gray-100 rounded">Find Venues</a></li>
            <li><a href="user_profile.php" class="block py-2 px-3 hover:bg-gray-100 rounded">Profile</a></li>
            <li class="border-t border-gray-200 pt-2 mt-2">
                <a href="user_notification_list.php" class="block py-2 px-3 hover:bg-gray-100 rounded flex items-center">
                    Notifications
                    <?php if ($unread_notification_count > 0): ?>
                        <span class="ml-auto bg-[#ef4444] text-white text-xs font-bold px-2 py-1 rounded-full"><?= htmlspecialchars($unread_notification_count) ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="/ventech_locator/users/user_logout.php" class="block py-2 px-3 hover:bg-red-50 rounded flex items-center text-red-600">
                    <i class="fas fa-sign-out-alt mr-2"></i> Logout
                </a>
            </li>
        </ul>
    </div>

    <div class="dashboard-container max-w-7xl mx-auto px-4">
        <main class="flex-1 p-6 md:p-8 lg:p-10 overflow-y-auto">
            <section class="hero-section">
                <div class="hero-overlay">
                    <h1 class="hero-title">Find Your Perfect Venue</h1>
                    <p class="hero-description">Browse and price out thousands of venues</p>
                    <a href="../user_venue_list.php" class="hero-button">
                        <i class="fas fa-search"></i> Find Venues
                    </a>
                </div>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-800 mb-4 border-b pb-3">Your Recent Reservations</h2>
                <div class="responsive-table-container table-container">
                    <?php if (count($recent_reservations) > 0): ?>
                        <table class="w-full table-auto text-sm text-left">
                            <thead class="table-header">
                                <tr>
                                    <th scope="col" class="table-cell">Venue</th>
                                    <th scope="col" class="table-cell">Event Date</th>
                                    <th scope="col" class="table-cell">Status</th>
                                    <th scope="col" class="table-cell">Reserved On</th>
                                    <th scope="col" class="table-cell">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_reservations as $reservation): // Loop through the recent 5 ?>
                                <tr class="table-row">
                                     <td class="table-cell font-medium">
                                         <?php // Link to venue display page if available (adjust path) ?>
                                         <a href="../venue_display.php?id=<?= htmlspecialchars($reservation['venue_id'] ?? '') ?>" class="table-link" title="View Venue Details">
                                             <?= htmlspecialchars($reservation['venue_title'] ?? 'N/A') ?>
                                         </a>
                                     </td>
                                     <td class="table-cell">
                                         <?= htmlspecialchars(date("D, M d, Y", strtotime($reservation['event_date'] ?? ''))) ?>
                                     </td>
                                     <td class="table-cell">
                                         <span class="status-badge <?= getStatusBadgeClass($reservation['status']) ?>">
                                             <?= htmlspecialchars(ucfirst($reservation['status'] ?? 'N/A')) ?>
                                         </span>
                                     </td>
                                     <td class="table-cell text-gray-600">
                                         <?= htmlspecialchars(date("M d, Y H:i", strtotime($reservation['created_at'] ?? ''))) ?>
                                     </td>
                                     <td class="table-cell">
                                         <a href="user_reservation_manage.php?id=<?= htmlspecialchars($reservation['id'] ?? '') ?>" class="table-link">View Details</a>
                                     </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if($total_reservations_count > count($recent_reservations)): // Check if there are more than 5 total reservations ?>
                        <div class="p-4 text-center border-t border-gray-200">
                            <a href="user_reservation_manage.php" class="text-[#8b1d52] hover:text-[#6f153f] text-sm font-medium">View All Reservations &rarr;</a>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="p-6 text-center text-gray-600">You haven't made any reservations yet.</p>
                    <?php endif; ?>
                </div>
            </section>
        </main>

       
    
    </div>


    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Mobile menu toggle logic
            const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
            const mobileMenu = document.getElementById('mobile-menu');

            if (mobileMenuToggle) {
                mobileMenuToggle.addEventListener('click', function() {
                    mobileMenu.classList.toggle('hidden');
                });
            }

            // Function to fetch unread notifications count
             function  fetchNotificationCount() {
                 const  countEndpoint = 'get_unread_count.php'; // Ensure this path is correct

                fetch(countEndpoint)
                    .then( response   =>  {
                        if (!response.ok) {
                            console.error('Error fetching notification count:', response.statusText);
                            document.getElementById('notification-count-badge').style.display = 'none';
                            return  Promise .reject('Network response was not ok.');
                        }
                        return response.json();
                    })
                    .then( data   =>  {
                         const  badge = document.getElementById('notification-count-badge');
                        if (badge) {
                             const  unreadCount = data.count || 0;

                            if (unreadCount > 0) {
                                badge.textContent = unreadCount;
                                badge.style.display = 'inline-block';
                            } else {
                                badge.style.display = 'none';
                            }
                        }
                    })
                    .catch( error   =>  {
                        console.error('There was a problem fetching the notification count:', error);
                         const  badge = document.getElementById('notification-count-badge');
                        if (badge) {
                             badge.style.display = 'none';
                        }
                    });
            }

            // Fetch notification count when the page loads
            fetchNotificationCount();

            // Periodically fetch notification count (e.g., every 30 seconds)
             const  notificationCountCheckInterval = 30000;
            setInterval(fetchNotificationCount, notificationCountCheckInterval);

        });

    </script>

</body>
</html>