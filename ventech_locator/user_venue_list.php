<?php
// user_venue_list.php

// **1. Start Session**
// Ensure this is the absolute first line, before any output (including whitespace)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// **2. Include Database Connection**
// Ensure this path is correct relative to user_venue_list.php
require_once 'includes/db_connection.php'; // Assuming includes folder is in the same directory

// Ensure $pdo is available from the included file
if (!isset($pdo) || !$pdo instanceof PDO ) {
    error_log("PDO connection not available in user_venue_list.php");
    die("Sorry, we're experiencing technical difficulties with the database. Please try again later.");
}

// **3. Check User Authentication (Optional but recommended for user-specific features)**
// If this page is accessible to guests, you can remove this block or make it conditional
if (!isset($_SESSION['user_id'])) {
    // Redirect to user login page if not logged in. Adjust path if needed.
    // header("Location: client/client_login.php"); // Adjust path if needed
    // exit;
    $loggedInUserId = null; // Set to null if guests can access
    $loggedInUsername = null;
} else {
     $loggedInUserId = $_SESSION['user_id'];
     // Fetch username for welcome message if needed
     try {
         $stmtUser = $pdo->prepare("SELECT username FROM users WHERE id = ?");
         $stmtUser->execute([$loggedInUserId]);
         $loggedInUsername = $stmtUser->fetchColumn();
     } catch ( PDOException  $e) {
         error_log("Error fetching username for user ID {$loggedInUserId} in venue_list: " . $e->getMessage());
         $loggedInUsername = 'User'; // Default if fetching fails
     }
}


// ** Utility Functions **
// Keeping your utility functions, though handleError might be replaced by session messages + redirect for non-fatal errors

function  handleError($message, $isWarning = false) {
    // Using Tailwind classes for basic styling
    $style = 'p-4 mb-4 rounded-md ';
    if ($isWarning) {
        $style .= 'bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700';
        echo "<div class='{$style}'>" . htmlspecialchars($message) . "</div>";
        return;
    }
    error_log("Venue Locator Error: " . $message);
    // For fatal errors, display a styled message
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Error</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-gray-100 flex items-center justify-center h-screen"><div class="text-center p-8 bg-white rounded-lg shadow-md"><h1 class="text-2xl font-bold text-red-600 mb-4">Application Error</h1><p class="text-gray-700">' . htmlspecialchars($message) . '</p></div></body></html>';
    exit;
}

function  fetchData( PDO  $pdo, $query, $params = []): array|false {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll( PDO ::FETCH_ASSOC); // Fetch as associative array
    } catch ( PDOException  $e) {
        error_log("Database query error: " . $e->getMessage() . " Query: " . $query);
        return false;
    }
}

function  getUniqueAmenities(array $venues): array {
    $allAmenities = [];
    foreach ($venues as $venue) {
        if (!empty($venue['amenities'])) {
            $amenitiesArray = array_map('trim', explode(',', $venue['amenities']));
            $allAmenities = array_merge($allAmenities, $amenitiesArray);
        }
    }
    $uniqueAmenities = array_unique($allAmenities);
    sort($uniqueAmenities);
    return $uniqueAmenities;
}

// ** Fetch Data **
// Fetch all necessary venue details for both map and list cards
$venues = fetchData($pdo, "SELECT id, title, description, latitude, longitude, image_path, amenities, price, status, reviews, num_persons FROM venue WHERE latitude IS NOT NULL AND longitude IS NOT NULL");

// Fetch unique amenities from the fetched venues
$uniqueAmenities = getUniqueAmenities($venues ?: []); // Pass empty array if $venues is false

// ** Fetch Unread Notification Count (for navigation badge) **
$unread_notification_count = 0;
if ($loggedInUserId) { // Only fetch if a user is logged in
    try {
         // Assuming you have a 'user_notifications' table with 'user_id' and 'is_read' columns
         $stmtNotifyCount = $pdo->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = :user_id AND is_read = FALSE");
         $stmtNotifyCount->execute([':user_id' => $loggedInUserId]);
         $unread_notification_count = $stmtNotifyCount->fetchColumn();
    } catch ( PDOException  $e) {
         error_log("Error fetching unread notification count for user $loggedInUserId in venue_list: " . $e->getMessage());
         $unread_notification_count = 0; // Default to 0 if fetching fails
    }
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Venue Map</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJHoWIiFsp9vF5+RmJMdxG1j97yrHDNHPxmalkGcJA==" crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZs1Kkgc8PU1cKB4UUplusxX7j35Y==" crossorigin=""></script>
    <link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="styleshtylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body { font-family: 'Roboto', sans-serif; margin: 0; padding: 0; overflow-x: hidden; background-color: #f3f4f6; }
        /* Ensure main content area takes remaining height */
        .main-content-area {
            display: flex;
            min-height: calc(100vh - 64px); /* Subtract fixed nav height */
            margin-top: 64px; /* Add margin-top to main content to avoid being hidden by fixed nav */
        }

        /* Left sidebar for filters and list */
        .left-sidebar {
            width: 350px; /* Fixed width for the left column */
            flex-shrink: 0; /* Prevent shrinking */
            background-color: #ffffff;
            border-right: 1px solid #e5e7eb; /* border-gray-200 */
            box-shadow: 2px 0 5px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            overflow-y: auto; /* Make the left sidebar scrollable */
        }

        /* Map container (right column) */
        .map-container-right {
            flex-grow: 1; /* Take remaining width */
            height: calc(100vh - 64px); /* Make map fill remaining height */
            position: sticky; /* Make map sticky */
            top: 64px; /* Position below the fixed nav */
        }
         #map { height: 100%; width: 100%; }


        /* Custom styles for filter bar */
        #filter-container {
            background-color: #ffffff;
            padding: 1rem; /* p-4 */
            border-bottom: 1px solid #e5e7eb; /* border-gray-200 */
            display: flex;
            flex-direction: column; /* Stack items vertically */
            gap: 1rem; /* gap-4 */
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            flex-shrink: 0; /* Prevent filter from shrinking */
        }
        #search-container {
             width: 100%; /* Full width in stacked layout */
        }
        #venue-search {
            width: 100%; /* Full width within its container */
            padding: 0.75rem; /* p-3 */
            border: 1px solid #d1d5db; /* border-gray-300 */
            border-radius: 0.375rem; /* rounded-md */
            font-size: 1rem; /* text-base */
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05);
            transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
         #venue-search:focus {
             border-color: #6366f1; /* indigo-500 */
             box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05), 0 0 0 3px rgba(99, 102, 241, 0.2); /* ring-indigo-200 */
             outline: none;
         }
        .amenity-filter {
             display: flex;
             flex-wrap: wrap; /* Allow checkboxes to wrap */
             gap: 0.75rem; /* gap-3 */
             align-items: center;
        }
        .amenity-filter strong {
            font-weight: 600; /* font-semibold */
            color: #374151; /* gray-700 */
        }
        .amenity-filter label {
            display: flex;
            align-items: center;
            font-size: 0.875rem; /* text-sm */
            color: #4b5563; /* gray-600 */
            cursor: pointer;
        }
        .amenity-filter input[type="checkbox"] {
            margin-right: 0.375rem; /* mr-1.5 */
             border-radius: 0.25rem; /* rounded */
             color: #4f46e5; /* indigo-600 */
             /* Custom checkbox styling can be added here if needed */
        }

         /* Venue List Container */
         #venue-list-container {
              flex-grow : 1; /* Take remaining space in sidebar */
              padding : 1rem; /* p-4 */
              display : grid; /* Use grid for cards */
              grid-template-columns : 1fr; /* Single column on mobile */
              gap : 1rem; /* gap-4 */
         }
         /* Adjust grid for larger screens if needed */
         @media (min-width: 768px) { /* md breakpoint */
            /* #venue-list-container { grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); } */
         }


        /* Venue Card Styling (for the list) */
        .venue-card-list {
            background-color: #ffffff;
            border-radius: 0.5rem; /* rounded-lg */
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1), 0 1px 2px rgba(0, 0, 0, 0.06); /* shadow-md */
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            border: 1px solid #e5e7eb; /* subtle border */
        }
        .venue-card-list:hover {
             transform: translateY(-2px); /* Slight lift effect */
             box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1), 0 2px 4px rgba(0, 0, 0, 0.08);
        }
        .venue-card-list img {
            width: 100%;
            height: 180px; /* Fixed image height */
            object-fit: cover;
        }
        .venue-card-list .card-content {
            padding: 1rem; /* p-4 */
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }
        .venue-card-list h3 {
            font-size: 1.125rem; /* text-lg */
            font-weight: 600; /* font-semibold */
            color: #1f2937; /* gray-900 */
            margin-bottom: 0.25rem; /* mb-1 - Adjusted spacing */
        }
         .venue-card-list h3 a {
             text-decoration: none;
             color: inherit;
             transition: color 0.2s ease-in-out;
         }
         .venue-card-list h3 a:hover {
             color: #f97316; /* orange-500 */
         }
        .venue-card-list .text-sm { font-size: 0.875rem; }
        .venue-card-list .text-xs { font-size: 0.75rem; }
        .venue-card-list .font-semibold { font-weight: 600; }
        .venue-card-list .font-bold { font-weight: 700; }
        .venue-card-list .text-gray-800 { color: #1f2937; }
        .venue-card-list .text-gray-600 { color: #4b5563; }
        .venue-card-list .text-gray-500 { color: #6b7280; }
        .venue-card-list .text-green-600 { color: #22c55e; }
        .venue-card-list .text-red-600 { color: #ef4444; }
        .venue-card-list .text-yellow-400 { color: #facc15; }
        .venue-card-list .mb-1 { margin-bottom: 0.25rem; }
        .venue-card-list .mb-2 { margin-bottom: 0.5rem; }
        .venue-card-list .mb-3 { margin-bottom: 0.75rem; }
        .venue-card-list .mb-4 { margin-bottom: 1rem; }
        .venue-card-list .flex { display: flex; }
        .venue-card-list .items-center { align-items: center; }
        .venue-card-list .space-x-3 > :not([hidden]) ~ :not([hidden]) { margin-left: 0.75rem; } /* space-x-3 */
        .venue-card-list .mr-1 { margin-right: 0.25rem; }
        .venue-card-list .ml-2 { margin-left: 0.5rem; }
        .venue-card-list .border-t { border-top: 1px solid #e5e7eb; }
        .venue-card-list .pt-3 { padding-top: 0.75rem; }
        .venue-card-list .rounded { border-radius: 0.25rem; }
        .venue-card-list .hover\:text-orange-600:hover { color: #f97316; }
        .venue-card-list .hover\:bg-orange-600:hover { background-color: #f97316; }
        .venue-card-list .hover\:bg-indigo-700:hover { background-color: #4338ca; }
        .venue-card-list .transition { transition-property: color, background-color, border-color, text-decoration-color, fill, stroke, opacity, box-shadow, transform, filter, backdrop-filter; transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1); transition-duration: 0.15s; }
        .venue-card-list .shadow-sm { box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); }
        .venue-card-list .w-full { width: 100%; }
        .venue-card-list .object-cover { object-fit: cover; }
        .venue-card-list .flex-grow { flex-grow: 1; }
        .venue-card-list .flex-col { flex-direction: column; }
        .venue-card-list .mt-auto { margin-top: auto; }
        .venue-card-list .text-center { text-align: center; }
        .venue-card-list .px-3 { padding-left: 0.75rem; padding-right: 0.75rem; }
        .venue-card-list .py-2 { padding-top: 0.5rem; padding-bottom: 0.5rem; }
        .venue-card-list .bg-orange-500 { background-color: #f97316; }
        .venue-card-list .bg-indigo-600 { background-color: #4f46e5; }
        .venue-card-list .bg-gray-400 { background-color: #9ca3af; }
        .venue-card-list .cursor-not-allowed { cursor: not-allowed; }


        /* Leaflet Popup Styling (retained and slightly adjusted) */
         .leaflet-popup-content-wrapper {
             padding: 0 !important; /* Remove default padding */
             border-radius: 0.5rem !important; /* rounded-lg */
             overflow: hidden; /* Ensures content respects border-radius */
             box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1), 0 1px 3px rgba(0, 0, 0, 0.08); /* Add shadow */
         }
         .leaflet-popup-content {
             margin: 0 !important; /* Remove default margin */
             width: 250px; /* Adjusted width for the popup card */
             max-width: 250px; /* Ensure it doesn't exceed width */
             max-height: 350px; /* Adjusted max height before scrolling */
             overflow-y: auto; /* Add scroll if content exceeds max height */
         }
         /* Styles for the card content *inside* the popup */
         .leaflet-popup-content .popup-venue-card img { height: 120px !important; } /* Smaller image in popup */
         .leaflet-popup-content .popup-venue-card h3 { font-size: 1rem; margin-bottom: 0.25rem; /* mb-1 - Adjusted spacing */ }
         .leaflet-popup-content .popup-venue-card .text-xl { font-size: 1.25rem; } /* Smaller price in popup */
         .leaflet-popup-content .popup-venue-card .space-x-3 > :not([hidden]) ~ :not([hidden]) { margin-left: 0.5rem; } /* Smaller gap in popup buttons */
         .leaflet-popup-content .popup-venue-card .px-3 { padding-left: 0.5rem; padding-right: 0.5rem; } /* Smaller padding in popup buttons */
         .leaflet-popup-content .popup-venue-card .py-2 { padding-top: 0.375rem; padding-bottom: 0.375rem; } /* Smaller padding in popup buttons */
         .leaflet-popup-content .popup-venue-card .mb-1 { margin-bottom: 0.25rem; } /* Adjusted spacing */
         .leaflet-popup-content .popup-venue-card .mb-2 { margin-bottom: 0.5rem; } /* Adjusted spacing */
         .leaflet-popup-content .popup-venue-card .mb-3 { margin-bottom: 0.75rem; } /* Adjusted spacing */
         .leaflet-popup-content .popup-venue-card .mb-4 { margin-bottom: 1rem; } /* Adjusted spacing */


         /* Custom styles for notification badge (for navigation) */
        .notification-icon-container {
            position: relative;
            display: inline-block; /* Allows positioning the badge relative to this */
            margin-right: 1.5rem; /* Space between notification icon and logout */
        }

        .notification-badge {
            position: absolute;
            top: -8px; /* Adjust vertical position */
            right: -8px; /* Adjust horizontal position */
            background-color: #ef4444; /* Red color */
            color: white;
            border-radius: 9999px; /* Full rounded */
            padding: 0.1rem 0.4rem; /* Adjust padding */
            font-size: 0.75rem; /* Smaller font size */
            font-weight: bold;
            min-width: 1.25rem; /* Minimum width to ensure circle shape */
            text-align: center;
            line-height: 1; /* Adjust line height for vertical centering */
            /* display: none; /* Hidden by default, shown when count > 0 */
            /* The PHP will control display: inline-block or none */
        }

         /* Responsive adjustments */
         @media (max-width: 768px) { /* Below md breakpoint */
            .main-content-area {
                flex-direction: column; /* Stack columns vertically */
            }
            .left-sidebar {
                width: 100%; /* Full width when stacked */
                border-right: none;
                border-bottom: 1px solid #e5e7eb;
                box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            }
            .map-container-right {
                width: 100%; /* Full width when stacked */
                height: 400px; /* Fixed height for the map on small screens */
                position: relative; /* Remove sticky when stacked */
                top: auto;
            }
         }

    </style>
</head>
<body>

    <nav class="bg-indigo-700 p-4 text-white shadow-lg fixed w-full top-0 z-10">
        <div class="container mx-auto flex justify-between items-center">
            <a href="index.php" class="text-2xl font-bold hover:text-indigo-200 transition-colors">Ventech Locator</a>
            <div class="flex items-center">
                 <?php if ($loggedInUserId): // Show notification icon only if logged in ?>
                     <div class="notification-icon-container">
                         <a href="user_notification_list.php" class="text-white hover:text-indigo-200 transition-colors" title="View Notifications">
                              <i class="fas fa-bell text-xl"></i>
                         </a>
                         <?php if ($unread_notification_count > 0): ?>
                             <span id="notification-count-badge" class="notification-badge"><?= htmlspecialchars($unread_notification_count) ?></span>
                         <?php else: ?>
                             <span id="notification-count-badge" class="notification-badge" style="display: none;">0</span>
                         <?php endif; ?>
                     </div>
                 <?php endif; ?>

                <?php if ($loggedInUserId): ?>
                     <span class="mr-4 hidden sm:inline text-indigo-100">Welcome, <strong class="font-semibold text-white"><?= htmlspecialchars($loggedInUsername ?? 'User') ?></strong>!</span>
                     <a href="user_logout.php" class="bg-white text-indigo-700 hover:bg-gray-200 py-1.5 px-4 rounded-md text-sm font-medium transition duration-150 ease-in-out shadow-sm flex items-center">
                         <i class="fas fa-sign-out-alt mr-1"></i> Logout
                     </a>
                <?php else: ?>
                     <a href="client/client_login.php" class="bg-white text-indigo-700 hover:bg-gray-200 py-1.5 px-4 rounded-md text-sm font-medium transition duration-150 ease-in-out shadow-sm mr-2">
                         <i class="fas fa-sign-in-alt mr-1"></i> Login
                     </a>
                     <a href="client/client_register.php" class="bg-indigo-500 text-white hover:bg-indigo-600 py-1.5 px-4 rounded-md text-sm font-medium transition duration-150 ease-in-out shadow-sm">
                         <i class="fas fa-user-plus mr-1"></i> Register
                     </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="main-content-area">
        <div class="left-sidebar">
            <div id="filter-container">
                 <h2 class="text-xl font-bold text-gray-800 mb-4">Filter & Search</h2>
                <div id="search-container" class="mb-4"> <input type="text" id="venue-search" placeholder="Search by venue name..." class="focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                 <?php if (!empty($uniqueAmenities)): ?>
                     <div class="amenity-filter mb-4"> <strong>Filter by Amenities:</strong>
                         <?php foreach ($uniqueAmenities as $amenity): ?>
                             <label>
                                 <input type="checkbox" name="amenity" value="<?php echo htmlspecialchars(strtolower($amenity)); ?>" class="form-checkbox h-4 w-4 text-indigo-600 transition duration-150 ease-in-out">
                                 <?php echo htmlspecialchars(ucfirst($amenity)); ?>
                             </label>
                         <?php endforeach; ?>
                     </div>
                 <?php endif; ?>

                 <div class="flex items-center justify-between mt-4 mb-4"> <label for="sort-by" class="text-gray-700 text-sm font-medium mr-2">Sort By:</label>
                     <select id="sort-by" class="border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 py-1.5 px-3 text-sm flex-grow">
                         <option value="title-asc">Name (A-Z)</option>
                         <option value="title-desc">Name (Z-A)</option>
                         <option value="price-asc">Price (Low to High)</option>
                         <option value="price-desc">Price (High to Low)</option>
                         <option value="reviews-desc">Reviews (Highest)</option>
                     </select>
                 </div>

                 <button id="apply-filters" class="w-full bg-indigo-600 text-white py-2.5 rounded-md hover:bg-indigo-700 transition duration-150 ease-in-out font-semibold text-base shadow-md">Update</button>
                 <p id="venue-count" class="text-sm text-gray-600 mt-2 text-center">0 Venues Found</p>
            </div>

            <div id="venue-list-container">
                 <?php if ($venues && count($venues) > 0): ?>
                     <?php foreach ($venues as $venue): ?>
                         <?php
                             $imagePathFromDB = $venue['image_path'] ?? null;
                             $uploadsBaseUrl = '/ventech_locator/uploads/'; // ADJUST PATH IF NEEDED! Relative to web root.
                             $placeholderImg = 'https://via.placeholder.co/400x250/e5e7eb/4b5563?text=No+Image'; // Placeholder with gray background
                             $imgSrc = $placeholderImg;
                             if (!empty($imagePathFromDB)) {
                                  // Ensure path is correctly formed and HTML escaped
                                 $imgSrc = $uploadsBaseUrl . ltrim(htmlspecialchars($imagePathFromDB), '/');
                             }
                         ?>
                         <div class="venue-card-list"
                              data-venue-id="<?= htmlspecialchars($venue['id'] ?? '') ?>"
                              data-amenities="<?= htmlspecialchars(strtolower($venue['amenities'] ?? '')) ?>"
                              data-title="<?= htmlspecialchars(strtolower($venue['title'] ?? '')) ?>"
                              data-price="<?= htmlspecialchars($venue['price'] ?? 0) ?>"
                              data-reviews="<?= htmlspecialchars($venue['reviews'] ?? 0) ?>"
                              data-status="<?= htmlspecialchars(strtolower($venue['status'] ?? '')) ?>"
                              >
                             <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($venue['title'] ?? 'Venue Image') ?>" class="w-full h-48 object-cover" onerror="this.onerror=null;this.src='<?= $placeholderImg ?>';">
                             <div class="card-content">
                                 <p class="text-xs text-gray-500 mb-1">
                                     Status: <span class="font-medium <?= $venue['status'] === 'open' ? 'text-green-600' : 'text-red-600' ?>">
                                         <?= htmlspecialchars($venue['status'] ? ucfirst($venue['status']) : 'Unknown') ?>
                                     </span>
                                 </p>
                                 <h3 class="text-lg font-semibold text-gray-800 mb-1"> <a href="venue_display.php?id=<?= htmlspecialchars($venue['id'] ?? '') ?>"><?= htmlspecialchars($venue['title'] ?? 'N/A') ?></a>
                                 </h3>

                                 <p class="text-sm text-gray-600 mb-1">Starting from</p>
                                 <p class="text-xl font-bold text-gray-900 mb-3">₱ <?= number_format($venue['price'] ?? 0, 2) ?> <span class="text-xs font-normal text-gray-600">/ Hour</span></p>

                                 <?php if (!empty($venue['num_persons'])): ?>
                                     <p class="text-sm text-gray-600 mb-1"><i class="fas fa-users mr-1 text-gray-400"></i> Capacity: <?= htmlspecialchars($venue['num_persons']) ?></p>
                                 <?php endif; ?>

                                 <?php if (!empty($venue['amenities'])): ?>
                                     <p class="text-sm text-gray-600 mb-1"><i class="fas fa-tags mr-1 text-gray-400"></i> Amenities: <?= htmlspecialchars($venue['amenities']) ?></p>
                                 <?php endif; ?>


                                 <div class="flex items-center text-sm text-gray-500 mb-4">
                                     <div class="flex text-yellow-400">
                                         <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star-half-alt"></i><i class="far fa-star"></i>
                                     </div>
                                     <span class="ml-2">(<?= htmlspecialchars($venue['reviews'] ?? 0) ?> Reviews)</span> </div>

                                 <div class="mt-auto pt-3 border-t border-gray-200 flex justify-center"> <a href="venue_display.php?id=<?= htmlspecialchars($venue['id'] ?? '') ?>" class="flex-1 text-center px-3 py-2 bg-orange-500 text-white text-xs font-bold rounded hover:bg-orange-600 transition shadow-sm max-w-xs"> <i class="fas fa-info-circle mr-1"></i> VIEW DETAILS
                                     </a>
                                     </div>
                             </div>
                         </div>
                     <?php endforeach; ?>
                 <?php else: ?>
                     <p class="p-4 text-center text-gray-600">No venues found with location data.</p>
                 <?php endif; ?>
            </div>
        </div>

        <div class="map-container-right">
            <div id="map"></div>
        </div>
    </div>

    <script>
        // Initialize the map
        // Centered around Las Piñas, Metro Manila based on the provided location context
         const  map = L.map('map').setView([14.4797, 120.9936], 13);

        // Add OpenStreetMap tiles
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        let venueMarkers = [];
        // Get venue data from PHP
        const allVenuesData = <?php echo json_encode($venues); ?>; // Store all venue data
        const uploadsBaseUrl = '/ventech_locator/uploads/'; // ADJUST PATH IF NEEDED! Relative to web root.
        const placeholderImg = 'https://via.placeholder.co/400x250/e5e7eb/4b5563?text=No+Image'; // Placeholder with gray background

        // Populate venueMarkers initially from allVenuesData
        if (allVenuesData && allVenuesData.length > 0) {
            allVenuesData.forEach( venue   =>  {
                if (venue.latitude && venue.longitude) {
                    let  imgSrc = placeholderImg;
                    if (venue.image_path) {
                        imgSrc = uploadsBaseUrl + venue.image_path.replace(/^\/+/, '');
                    }

                    const detailsUrl = (venue.id !== null && venue.id !== undefined && venue.id !== '')
                                       ? `venue_display.php?id=${htmlspecialchars(venue.id)}`
                                       : '#';

                    const  popupContent = `
                        <div class="popup-venue-card flex flex-col">
                            <img src="${htmlspecialchars(imgSrc)}" alt="${htmlspecialchars(venue.title ?? 'Venue Image')}" class="w-full h-48 object-cover rounded-t-lg" onerror="this.onerror=null;this.src='${htmlspecialchars(placeholderImg)}'" />
                            <div class="p-4 flex flex-col flex-grow">
                                <p class="text-xs text-gray-500 mb-1">
                                    Status: <span class="font-medium ${venue.status === 'open' ? 'text-green-600' : 'text-red-600'}">
                                        ${htmlspecialchars(venue.status ? venue.status.charAt(0).toUpperCase() + venue.status.slice(1) : 'Unknown')}
                                    </span>
                                </p>
                                <h3 class="text-lg font-semibold text-gray-800 mb-1 hover:text-orange-600 transition-colors"> <a href="${detailsUrl}">${htmlspecialchars(venue.title ?? 'N/A')}</a> </h3>

                                <p class="text-sm text-gray-600 mb-1">Starting from</p>
                                <p class="text-xl font-bold text-gray-900 mb-3">₱ ${venue.price ? parseFloat(venue.price).toFixed(2) : '0.00'} <span class="text-xs font-normal text-gray-600">/ Hour</span></p>

                                <div class="flex items-center text-sm text-gray-500 mb-4">
                                    <div class="flex text-yellow-400">
                                        <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star-half-alt"></i><i class="far fa-star"></i>
                                    </div>
                                    <span class="ml-2">(${htmlspecialchars(venue.reviews ?? 0)} Reviews)</span> </div>

                                <div class="mt-auto pt-3 border-t border-gray-200 flex justify-center"> <a href="venue_display.php?id=<?= htmlspecialchars($venue['id'] ?? '') ?>" class="flex-1 text-center px-3 py-2 bg-orange-500 text-white text-xs font-bold rounded hover:bg-orange-600 transition shadow-sm max-w-xs"> <i class="fas fa-info-circle mr-1"></i> VIEW DETAILS
                                    </a>
                                    </div>
                            </div>
                        </div>
                    `;

                    const marker = L.marker([venue.latitude, venue.longitude]).bindPopup(popupContent);

                    venueMarkers.push({
                        marker: marker,
                        id: venue.id,
                        title: (venue.title ?? '').toLowerCase(),
                        amenities: (venue.amenities ?? '').toLowerCase(),
                        status: (venue.status ?? '').toLowerCase(),
                        price: parseFloat(venue.price ?? 0), // Store as number for sorting
                        reviews: parseInt(venue.reviews ?? 0) // Store as number for sorting
                    });
                }
            });
        } else {
            console.log("No venue locations found in the database or error fetching data.");
             const noVenuesMessage = L.control({ position: 'topright' });
             noVenuesMessage.onAdd = function (map) {
                 const div = L.DomUtil.create('div', 'info bg-white p-3 rounded shadow');
                 div.innerHTML = '<h4>No Venues Found</h4><p>Could not load venue data or no venues are available with location data.</p>';
                 return div;
             };
             noVenuesMessage.addTo(map);

             const venueListContainer = document.getElementById('venue-list-container');
             if(venueListContainer) {
                 venueListContainer.innerHTML = '<p class="p-4 text-center text-gray-600">No venues found with location data.</p>';
             }
        }


        const venueSearchInput = document.getElementById('venue-search');
        const amenityCheckboxes = document.querySelectorAll('.amenity-filter input[type="checkbox"]');
        const applyFiltersButton = document.getElementById('apply-filters');
        const venueListContainer = document.getElementById('venue-list-container');
        const venueCountDisplay = document.getElementById('venue-count');
        const sortBySelect = document.getElementById('sort-by');


        function renderVenueCards(venuesToRender) {
            venueListContainer.innerHTML = ''; // Clear existing cards
            if (venuesToRender.length === 0) {
                const messageDiv = document.createElement('div');
                messageDiv.classList.add('p-4', 'text-center', 'text-gray-600', 'no-venues-message');
                messageDiv.textContent = 'No venues match the current filters.';
                venueListContainer.appendChild(messageDiv);
                return;
            }

            venuesToRender.forEach(venue => {
                const card = document.createElement('div');
                card.classList.add('venue-card-list');
                card.setAttribute('data-venue-id', htmlspecialchars(venue.id));
                card.setAttribute('data-amenities', htmlspecialchars(venue.amenities));
                card.setAttribute('data-title', htmlspecialchars(venue.title));
                card.setAttribute('data-price', htmlspecialchars(venue.price));
                card.setAttribute('data-reviews', htmlspecialchars(venue.reviews));
                card.setAttribute('data-status', htmlspecialchars(venue.status));

                let imgSrc = placeholderImg;
                if (venue.image_path) {
                    imgSrc = uploadsBaseUrl + venue.image_path.replace(/^\/+/, '');
                }

                const statusBadgeClass = venue.status === 'open' ? 'text-green-600' : 'text-red-600';
                const priceFormatted = parseFloat(venue.price ?? 0).toFixed(2);

                card.innerHTML = `
                    <img src="${htmlspecialchars(imgSrc)}" alt="${htmlspecialchars(venue.title ?? 'Venue Image')}" class="w-full h-48 object-cover" onerror="this.onerror=null;this.src='${htmlspecialchars(placeholderImg)}';">
                    <div class="card-content">
                        <p class="text-xs text-gray-500 mb-1">
                            Status: <span class="font-medium ${statusBadgeClass}">
                                ${htmlspecialchars(venue.status ? venue.status.charAt(0).toUpperCase() + venue.status.slice(1) : 'Unknown')}
                            </span>
                        </p>
                        <h3 class="text-lg font-semibold text-gray-800 mb-1"> <a href="venue_display.php?id=${htmlspecialchars(venue.id)}">${htmlspecialchars(venue.title ?? 'N/A')}</a> </h3>
                        <p class="text-sm text-gray-600 mb-1">Starting from</p>
                        <p class="text-xl font-bold text-gray-900 mb-3">₱ ${priceFormatted} <span class="text-xs font-normal text-gray-600">/ Hour</span></p>
                        ${venue.num_persons ? `<p class="text-sm text-gray-600 mb-1"><i class="fas fa-users mr-1 text-gray-400"></i> Capacity: ${htmlspecialchars(venue.num_persons)}</p>` : ''}
                        ${venue.amenities ? `<p class="text-sm text-gray-600 mb-1"><i class="fas fa-tags mr-1 text-gray-400"></i> Amenities: ${htmlspecialchars(venue.amenities)}</p>` : ''}
                        <div class="flex items-center text-sm text-gray-500 mb-4">
                            <div class="flex text-yellow-400">
                                <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star-half-alt"></i><i class="far fa-star"></i>
                            </div>
                            <span class="ml-2">(${htmlspecialchars(venue.reviews ?? 0)} Reviews)</span>
                        </div>
                        <div class="mt-auto pt-3 border-t border-gray-200 flex justify-center">
                            <a href="venue_display.php?id=${htmlspecialchars(venue.id)}" class="flex-1 text-center px-3 py-2 bg-orange-500 text-white text-xs font-bold rounded hover:bg-orange-600 transition shadow-sm max-w-xs">
                                <i class="fas fa-info-circle mr-1"></i> VIEW DETAILS
                            </a>
                        </div>
                    </div>
                `;
                venueListContainer.appendChild(card);
            });
        }


        function filterAndSortVenues() {
            const searchTerm = venueSearchInput.value.toLowerCase();
            const selectedAmenities = Array.from(amenityCheckboxes)
                .filter(checkbox => checkbox.checked)
                .map(checkbox => checkbox.value);
            const sortBy = sortBySelect.value;

            let filteredVenues = allVenuesData.filter(venue => {
                const titleMatch = (venue.title ?? '').toLowerCase().includes(searchTerm);
                let amenityMatch = true;

                if (selectedAmenities.length > 0) {
                    amenityMatch = selectedAmenities.every(amenity => (venue.amenities ?? '').toLowerCase().includes(amenity));
                }
                return titleMatch && amenityMatch;
            });

            // Sort filtered venues
            filteredVenues.sort((a, b) => {
                switch (sortBy) {
                    case 'title-asc':
                        return (a.title ?? '').localeCompare(b.title ?? '');
                    case 'title-desc':
                        return (b.title ?? '').localeCompare(a.title ?? '');
                    case 'price-asc':
                        return (parseFloat(a.price ?? 0) - parseFloat(b.price ?? 0));
                    case 'price-desc':
                        return (parseFloat(b.price ?? 0) - parseFloat(a.price ?? 0));
                    case 'reviews-desc':
                        return (parseInt(b.reviews ?? 0) - parseInt(a.reviews ?? 0));
                    default:
                        return 0;
                }
            });

            // Update map markers
            const visibleMarkers = [];
            venueMarkers.forEach(venueObj => {
                const isVenueVisible = filteredVenues.some(fv => fv.id === venueObj.id);
                if (isVenueVisible) {
                    venueObj.marker.addTo(map);
                    visibleMarkers.push(venueObj.marker);
                } else {
                    map.removeLayer(venueObj.marker);
                }
            });

            // Adjust map bounds to visible markers only
            if (visibleMarkers.length > 0) {
                const group = new L.featureGroup(visibleMarkers);
                map.fitBounds(group.getBounds(), { padding: [20, 20] });
            } else {
                // If no venues match filters, reset map view to initial center
                map.setView([14.4797, 120.9936], 13);
            }

            // Render the sorted and filtered venue cards
            renderVenueCards(filteredVenues);

            // Update venue count display
            venueCountDisplay.textContent = `${filteredVenues.length} Results Found`; // Changed text to match image
        }


        // Apply filters on button click
        applyFiltersButton.addEventListener('click', filterAndSortVenues);

        // Apply filters and sort on input/change for a more interactive experience
        venueSearchInput.addEventListener('input', filterAndSortVenues);
        amenityCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', filterAndSortVenues);
        });
        sortBySelect.addEventListener('change', filterAndSortVenues);


         // Function to fetch unread notifications count (for navigation badge)
          function  fetchNotificationCount() {
             // Only fetch if a user is logged in (handled by PHP, but double-check in JS if needed)
             // const userId = <?php // echo json_encode($loggedInUserId); ?>; // Get user ID from PHP
             // if (!userId) return; // Don't fetch if user is not logged in

             // Adjust the path to your notification count endpoint if needed
             // You would need a separate lightweight PHP file (e.g., get_unread_count.php)
             // that queries the user_notifications table and returns a JSON response like { "count": 5 }
              const  countEndpoint = 'get_unread_count.php'; // <--- ** Create this file **

             fetch(countEndpoint)
                 .then( response   =>  {
                     if (!response.ok) {
                         console.error('Error fetching notification count:', response.statusText);
                         return  Promise .reject('Network response was not ok.');
                     }
                     return response.json(); // Parse the JSON response
                 })
                 .then( data   =>  {
                      const  badge = document.getElementById('notification-count-badge');
                     if (badge) {
                          const  unreadCount = data.count || 0; // Use 0 if count is not provided

                         if (unreadCount > 0) {
                             badge.textContent = unreadCount; // Update the badge text
                             badge.style.display = 'inline-block'; // Show the badge
                         } else {
                             badge.style.display = 'none'; // Hide the badge if no unread notifications
                         }
                     }
                 })
                 .catch( error   =>  {
                     console.error('There was a problem fetching the notification count:', error);
                     // Hide the badge on fetch errors
                      const  badge = document.getElementById('notification-count-badge');
                     if (badge) {
                          badge.style.display = 'none';
                     }
                 });
         }

         // Fetch notification count when the page loads (if user is logged in)
         document.addEventListener('DOMContentLoaded', ()  =>  {
             <?php if ($loggedInUserId): ?>
                 fetchNotificationCount();
                 // Periodically fetch notification count (e.g., every 30 seconds)
                  const  notificationCountCheckInterval = 30000; // 30 seconds
                 setInterval(fetchNotificationCount, notificationCountCheckInterval);
             <?php endif; ?>

             // Initial filter and sort application on DOMContentLoaded
             filterAndSortVenues();

             // Optionally fit map bounds to all markers if venues are loaded initially
             if (venueMarkers.length > 0) {
                 const group = new L.featureGroup(venueMarkers.map(vm => vm.marker));
                 map.fitBounds(group.getBounds());
             }
         });


         // Helper function to escape HTML for popup content and dynamic rendering
          function  htmlspecialchars( str ) {
             if (typeof str !== 'string' && typeof str !== 'number') return ''; // Handle numbers too
             return String(str).replace(/&/g, '&amp;')
                       .replace(/</g, '&lt;')
                       .replace(/>/g, '&gt;')
                       .replace(/"/g, '&quot;')
                       .replace(/'/g, '&#039;');
         }


    </script>
</body>
</html>