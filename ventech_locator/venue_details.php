<?php
// Database connection parameters
$host = 'localhost';
$db = 'ventech_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
      PDO::ATTR_ERRMODE            =>   PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE =>   PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
];

// Error handling function
function   handle_error($message, $is_warning = false) {
    $style = 'color:red;border:1px solid red;background-color:#ffe0e0;';
    if ($is_warning) {
        $style = 'color: #856404; background-color: #fff3cd; border-color: #ffeeba;';
         echo "<div style='padding:15px; margin-bottom: 15px; border-radius: 4px; {$style}'>" . htmlspecialchars($message) . "</div>";
         return; // Don't die for warnings
    }
    // Log critical errors
    error_log("Venue Details Error: " . $message);
    die("<div style='padding:15px; border-radius: 4px; {$style}'>\"" . htmlspecialchars($message) . "\"</div>");
}

// Include the PDO database connection
try {
    $pdo = new   PDO  ($dsn, $user, $pass, $options);
} catch (  PDOException   $e) {
    handle_error("Could not connect to the database: " . $e->getMessage());
}

// --- Session Start & Auth Check ---
session_start(); // UNCOMMENTED
$loggedInUserId = $_SESSION['user_id'] ?? null; // Get user ID, null if not logged in
$loggedInUserRole = null;

if ($loggedInUserId) {
    try {
        $stmtUser = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmtUser->execute([$loggedInUserId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $loggedInUserRole = $user['role'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching logged-in user role: " . $e->getMessage());
    }
}


// Get the venue ID from the URL
$venue_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$venue_id) {
    handle_error("No valid venue ID provided.");
}

// Function to fetch data
function   fetch_data($pdo, $query, $params = []) {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (  PDOException   $e) {
        // Log error but let calling code handle it
        error_log("Database Fetch Error: " . $e->getMessage() . " Query: " . $query);
        return false; // Indicate failure
    }
}

// Fetch venue data
$venue_data = fetch_data($pdo, "SELECT * FROM venue WHERE id = ?", [$venue_id]);
if ($venue_data === false) {
     handle_error("Database error fetching venue data.");
}
if (empty($venue_data)) {
    handle_error("Venue not found.");
}
$venue = $venue_data[0];

// **Authorization Check for Editing**: Ensure logged-in user owns this venue (or is admin)
$canEdit = false;
if ($loggedInUserId && ($venue['user_id'] === $loggedInUserId || $loggedInUserRole === 'admin')) {
    $canEdit = true;
}

// If user is not authorized to edit, redirect them to the public display page
// This prevents direct access to the edit form for unauthorized users.
if (!$canEdit && $_SERVER['REQUEST_METHOD'] !== 'POST') { // Only redirect if not a POST attempt and not authorized to view this page
    // Redirect to public display page if this is only for viewing and not authorized to edit
    // Assuming 'venue_display.php' is the public view, adjust as necessary
    header("Location: /ventech_locator/venue_display.php?id=" . $venue_id); // Using the absolute path
    exit;
}


// Fetch media
$media_data = fetch_data($pdo, "SELECT * FROM venue_media WHERE venue_id = ?", [$venue_id]);
$media = ($media_data !== false) ? $media_data : [];


// Fetch unavailable dates
$unavailableDatesData = fetch_data($pdo, "SELECT unavailable_date FROM unavailable_dates WHERE venue_id = ?", [$venue_id]);
$unavailableDates = ($unavailableDatesData !== false) ? array_column($unavailableDatesData, 'unavailable_date') : [];

// Fetch client (venue contact) information
$client_info_data = fetch_data($pdo, "SELECT * FROM client_info WHERE venue_id = ?", [$venue_id]);
$client_info = ($client_info_data !== false && !empty($client_info_data)) ? $client_info_data[0] : null;

// Display warning if no contact info found (but don't die)
if (!$client_info) {
    handle_error("Venue Contact information not available. Please add it below.", true);
     // Initialize $client_info as an empty array to avoid errors when accessing keys later
    $client_info = ['client_name' => '', 'client_email' => '', 'client_phone' => '', 'client_address' => ''];
}


// Function to format file sizes
function   formatBytes($bytes, $precision = 2) { /* ... function code ... */
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0); $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1); $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Handles the upload of multiple files for a given venue.
 *
 * @param array $files The $_FILES array for the specific input name (e.g., $_FILES['venue_images']).
 * @param array $allowed_extensions An array of allowed file extensions (e.g., ['jpg', 'png']).
 * @param int $max_size The maximum allowed file size in bytes.
 * @param string $upload_dir The directory where files will be uploaded.
 * @param int $venue_id The ID of the venue associated with the media.
 * @param string $media_type The type of media (e.g., 'image', 'video').
 * @param PDO $pdo The PDO database connection object.
 * @return array An array of results, each indicating success or error for an individual file.
 */
function  handleMultipleFileUpload($files, $allowed_extensions, $max_size, $upload_dir, $venue_id, $media_type, $pdo) {
    $results = [];

    // Ensure the upload directory exists and is writable
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        return [["error" => "Failed to create upload directory."]];
    }
    if (!is_writable($upload_dir)) {
        return [["error" => "Upload directory is not writable. File uploads failed."]];
    }

    // Reorganize the $_FILES array for easier iteration if it's a multiple file input
    $file_keys = array_keys($files['name']);
    $reorganized_files = [];
    foreach ($file_keys as $index) {
        if ($files['error'][$index] === UPLOAD_ERR_NO_FILE) {
            continue; // Skip if no file was uploaded for this index
        }
        $reorganized_files[] = [
            'name' => $files['name'][$index],
            'type' => $files['type'][$index],
            'tmp_name' => $files['tmp_name'][$index],
            'error' => $files['error'][$index],
            'size' => $files['size'][$index],
        ];
    }

    if (empty($reorganized_files)) {
        return [["success" => "No files selected for upload."]]; // Indicate no files were processed
    }

    foreach ($reorganized_files as $file) {
        if ($file['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $file['tmp_name'];
            // Sanitize filename and create unique name
            $original_name = basename($file['name']);
            $safe_original_name = preg_replace("/[^A-Za-z0-9\.\-\_]/", '', $original_name); // Basic sanitization
            $file_extension = strtolower(pathinfo($safe_original_name, PATHINFO_EXTENSION));
            $file_name = uniqid($media_type . '_', true) . '.' . $file_extension; // More unique name
            $file_path = 'uploads/' . $file_name; // Relative path for DB storage
            $destination = $upload_dir . $file_name; // Full path for move_uploaded_file
             // Get the directory containing the current script
            $current_script_dir = __DIR__;
            $upload_full_path = realpath($current_script_dir . '/uploads') . '/';


            if (!in_array($file_extension, $allowed_extensions)) {
                $results[] = ["error" => "Invalid file format for '{$original_name}'. Only " . implode(', ', $allowed_extensions) . " are allowed."];
                continue; // Skip to next file
            }
            if ($file['size'] > $max_size) {
                $results[] = ["error" => "File '{$original_name}' size exceeds the " . formatBytes($max_size) . " limit."];
                continue; // Skip to next file
            }

            if (!move_uploaded_file($file_tmp, $destination)) {
                $results[] = ["error" => "Failed to move uploaded file '{$original_name}'. Check permissions."];
                continue; // Skip to next file
            }

            try {
                $insert_media = $pdo->prepare("INSERT INTO venue_media (venue_id, media_type, media_url) VALUES (?, ?, ?)");
                $insert_media->execute([$venue_id, $media_type, $file_path]);
                $results[] = ["success" => true, "path" => $file_path, "original_name" => $original_name]; // Return success and path
            } catch (  PDOException   $e) {
                error_log("DB Error inserting media: " . $e->getMessage());
                // Optionally delete the uploaded file if DB insert fails
                unlink($destination);
                $results[] = ["error" => "Failed to save media record for '{$original_name}' to database."];
            }
        } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) {
            // Handle other upload errors (https://www.php.net/manual/en/features.file-upload.errors.php)
            $results[] = ["error" => "File upload error code for '{$file['name']}': " . $file['error']];
        }
    }
    return $results;
}


// --- Handle Form Submission ---
$form_errors = [];
$form_success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if the submitted venue_id matches the one in the URL (security)
    if (!isset($_POST['venue_id']) || $_POST['venue_id'] != $venue_id) {
        handle_error("Form submission error. Venue ID mismatch.");
    }

    // Only process edits if authorized
    if ($canEdit) {
        // --- Sanitize & Validate ---
        // Venue Details
        $amenities = htmlspecialchars(trim($_POST['amenities'] ?? ''), ENT_QUOTES, 'UTF-8');
        $reviews = filter_input(INPUT_POST, 'reviews', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $additional_info = htmlspecialchars(trim($_POST['additional_info'] ?? ''), ENT_QUOTES, 'UTF-8');
        $num_persons = filter_input(INPUT_POST, 'num-persons', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT, FILTER_FLAG_ALLOW_FRACTION); // Added Price validation
        $wifi = isset($_POST['wifi']) && $_POST['wifi'] === 'yes' ? 'yes' : 'no'; // Validate radio
        $parking = isset($_POST['parking']) && $_POST['parking'] === 'yes' ? 'yes' : 'no'; // Validate radio
        $virtual_tour_url = filter_input(INPUT_POST, 'virtual_tour_url', FILTER_VALIDATE_URL);
        // Changed from google_map_embed_code to google_map_url
        $google_map_url = filter_input(INPUT_POST, 'google_map_url', FILTER_VALIDATE_URL);
        $latitude = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
        $longitude = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);

        // Venue Contact Details
        $client_name = htmlspecialchars(trim($_POST['client-name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $client_email = filter_input(INPUT_POST, 'client-email', FILTER_VALIDATE_EMAIL);
        $client_phone = htmlspecialchars(trim($_POST['client-phone'] ?? ''), ENT_QUOTES, 'UTF-8'); // Further validation needed (e.g., regex)
        $client_address = htmlspecialchars(trim($_POST['client-address'] ?? ''), ENT_QUOTES, 'UTF-8');

        // Unavailable Dates
        $unavailable_dates_input = $_POST['unavailable_dates'] ?? [];
        $unavailable_dates = [];
        foreach ($unavailable_dates_input as $date) {
            // Validate date format (YYYY-MM-DD)
            if (preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $date)) {
                $unavailable_dates[] = $date;
            } else {
                $form_errors[] = "Invalid date format submitted: " . htmlspecialchars($date);
            }
        }

        // --- Validation Checks ---
        if ($reviews === false) $form_errors[] = "Invalid value for Reviews (must be zero or more).";
        if ($num_persons === false) $form_errors[] = "Invalid value for Number of Persons (must be at least 1).";
        if ($price === false || $price < 0) $form_errors[] = "Invalid value for Price (must be zero or more)."; // Price validation
        if ($_POST['virtual_tour_url'] && $virtual_tour_url === false) $form_errors[] = "Invalid Virtual Tour URL format.";
        // New: Google Map URL validation
        if ($_POST['google_map_url'] && $google_map_url === false) {
            $form_errors[] = "Invalid Google Map URL format.";
        }
        if (($_POST['latitude'] && $latitude === false) || ($_POST['longitude'] && $longitude === false)) {
             $form_errors[] = "Invalid Latitude or Longitude format (must be numbers).";
        }
        if (empty($client_name)) $form_errors[] = "Venue Contact Name is required.";
        if (empty($client_email)) $form_errors[] = "Venue Contact Email is required.";
         elseif ($client_email === false) $form_errors[] = "Invalid Venue Contact Email format.";
        if (empty($client_phone)) $form_errors[] = "Venue Contact Phone is required.";
        if (empty($client_address)) $form_errors[] = "Venue Contact Address is required.";

        // --- Process Updates if No Errors ---
        if (empty($form_errors)) {
            $upload_dir = __DIR__ . '/uploads/'; // Use absolute path

            // Handle multiple image uploads
            if (isset($_FILES['venue_images']) && $_FILES['venue_images']['error'][0] !== UPLOAD_ERR_NO_FILE) {
                // Check current image count before allowing more uploads
                $current_image_count_query = $pdo->prepare("SELECT COUNT(*) FROM venue_media WHERE venue_id = ? AND media_type = 'image'");
                $current_image_count_query->execute([$venue_id]);
                $current_image_count = $current_image_count_query->fetchColumn();

                $max_allowed_new_images = 6 - $current_image_count;
                $files_to_upload = [];
                $files_count = count($_FILES['venue_images']['name']);

                for ($i = 0; $i < $files_count; $i++) {
                    if ($_FILES['venue_images']['error'][$i] === UPLOAD_ERR_OK) {
                        $files_to_upload[] = [
                            'name' => $_FILES['venue_images']['name'][$i],
                            'type' => $_FILES['venue_images']['type'][$i],
                            'tmp_name' => $_FILES['venue_images']['tmp_name'][$i],
                            'error' => $_FILES['venue_images']['error'][$i],
                            'size' => $_FILES['venue_images']['size'][$i],
                        ];
                    }
                }

                if (count($files_to_upload) > $max_allowed_new_images) {
                    $form_errors[] = "You can only upload " . $max_allowed_new_images . " more image(s). Please select fewer files.";
                } else {
                    // Reorganize $_FILES for handleMultipleFileUpload to process only the files to upload
                    $reorganized_images_for_upload = [
                        'name' => array_column($files_to_upload, 'name'),
                        'type' => array_column($files_to_upload, 'type'),
                        'tmp_name' => array_column($files_to_upload, 'tmp_name'),
                        'error' => array_column($files_to_upload, 'error'),
                        'size' => array_column($files_to_upload, 'size'),
                    ];

                    $image_upload_results = handleMultipleFileUpload($reorganized_images_for_upload, ['jpg', 'jpeg', 'png', 'gif'], 5 * 1024 * 1024, $upload_dir, $venue_id, 'image', $pdo);
                    foreach ($image_upload_results as $result) {
                        if (isset($result['error'])) {
                            $form_errors[] = "Image Upload Error: " . $result['error'];
                        } elseif (isset($result['success']) && $result['success'] === true) {
                            $form_success[] = "Image '{$result['original_name']}' uploaded successfully.";
                        }
                    }
                }
            } else {
                // If no new images were selected, add a success message for no action taken
                $form_success[] = "No new images selected for upload.";
            }


            // Handle video upload (still single)
            if (isset($_FILES['venue_video']) && $_FILES['venue_video']['error'] === UPLOAD_ERR_OK) {
                // Reorganize for handleMultipleFileUpload which expects the $_FILES structure
                $video_file_array = [
                    'name' => [$_FILES['venue_video']['name']],
                    'type' => [$_FILES['venue_video']['type']],
                    'tmp_name' => [$_FILES['venue_video']['tmp_name']],
                    'error' => [$_FILES['venue_video']['error']],
                    'size' => [$_FILES['venue_video']['size']],
                ];
                // Updated allowed video extensions
                $video_upload_results = handleMultipleFileUpload($video_file_array, ['mp4', 'mov', 'avi', 'wmv', 'webm', 'ogg', 'flv'], 50 * 1024 * 1024, $upload_dir, $venue_id, 'video', $pdo);
                foreach ($video_upload_results as $result) {
                    if (isset($result['error'])) {
                        $form_errors[] = "Video Upload Error: " . $result['error'];
                    } elseif (isset($result['success']) && $result['success'] === true) {
                        $form_success[] = "Video uploaded successfully.";
                    }
                }
            } elseif (isset($_FILES['venue_video']) && $_FILES['venue_video']['error'] !== UPLOAD_ERR_NO_FILE) {
                // Handle other upload errors for the single video file
                $form_errors[] = "Video Upload Error: " . $_FILES['venue_video']['error'];
            } else {
                $form_success[] = "No new video selected for upload.";
            }


            // --- Database Updates (only if file uploads didn't cause new errors) ---
            if (empty($form_errors)) {
                 try {
                    $pdo->beginTransaction();

                    // Update venue details (added price and google_map_url)
                    $updateVenue = $pdo->prepare(
                        "UPDATE venue SET amenities = ?, num_persons = ?, reviews = ?, additional_info = ?,
                         price = ?, wifi = ?, parking = ?, virtual_tour_url = ?, google_map_url = ?, latitude = ?, longitude = ?
                         WHERE id = ?"
                    );
                    $updateVenue->execute([
                        $amenities,
                        $num_persons,
                        $reviews,
                        $additional_info,
                        $price,
                        $wifi,
                        $parking,
                        $virtual_tour_url ?: null, // Store null if empty/invalid
                        $google_map_url ?: null,   // Store URL if provided
                        $latitude ?: null,
                        $longitude ?: null,
                        $venue_id
                    ]);
                    // Note: Removed success message here as we are redirecting

                    // Update unavailable dates
                    $deleteOldDates = $pdo->prepare("DELETE FROM unavailable_dates WHERE venue_id = ?");
                    $deleteOldDates->execute([$venue_id]);
                    if (!empty($unavailable_dates)) {
                        $insertDate = $pdo->prepare("INSERT INTO unavailable_dates (venue_id, unavailable_date) VALUES (?, ?)");
                        foreach ($unavailable_dates as $date) {
                            $insertDate->execute([$venue_id, $date]);
                        }
                    }
                    // Note: Removed success message here as we are redirecting


                    // Update or insert client information
                    $stmtCheckClient = $pdo->prepare("SELECT id FROM client_info WHERE venue_id = ?");
                    $stmtCheckClient->execute([$venue_id]);
                    $existingClient = $stmtCheckClient->fetch();

                    if ($existingClient) {
                        $updateClient = $pdo->prepare("UPDATE client_info SET client_name = ?, client_email = ?, client_phone = ?, client_address = ? WHERE venue_id = ?");
                        $updateClient->execute([$client_name, $client_email, $client_phone, $client_address, $venue_id]);
                         // Note: Removed success message here as we are redirecting
                    } else {
                        $insertClient = $pdo->prepare("INSERT INTO client_info (venue_id, client_name, client_email, client_phone, client_address) VALUES (?, ?, ?, ?, ?)");
                        // FIX: Removed the duplicate $venue_id from the execute array
                        $insertClient->execute([$venue_id, $client_name, $client_email, $client_phone, $client_address]);
                         // Note: Removed success message here as we are redirecting
                    }

                    $pdo->commit();

                    // --- REDIRECTION AFTER SUCCESSFUL UPDATE ---
                    // Redirect to client_dashboard.php with a success message
                    $_SESSION['venue_updated_message'] = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-md mb-4' role='alert'><p class='font-bold'><i class='fas fa-check-circle mr-2'></i>Success!</p><p>Venue details updated successfully!</p></div>";
                    header("Location: client_dashboard.php");
                    exit(); // Stop script execution after redirection

                } catch (  PDOException   $e) {
                    $pdo->rollBack();
                    error_log("Database Update Error: " . $e->getMessage());
                    $form_errors[] = "A database error occurred while saving changes. Please try again.";
                }
            } // end check for file upload errors before DB updates
        } // end check for validation errors
    } else {
        // If it's a POST request but user is not authorized to edit
        $form_errors[] = "You are not authorized to perform this action.";
    }
} // end POST request check

// Get calendar navigation parameters
$currentMonth = $_GET['month'] ?? date('n');
$currentYear = $_GET['year'] ?? date('Y');
$currentMonth = filter_var($currentMonth, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: date('n');
$currentYear = filter_var($currentYear, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1900, 'max_range' => 2100]]) ?: date('Y');

$today = date('Y-m-d'); // Get today's date for calendar styling

// Generate Calendar data for PHP side
try {
     $firstDayOfMonth = new   DateTimeImmutable  ("$currentYear-$currentMonth-01");
     $prevMonthDate = $firstDayOfMonth->modify('-1 month');
     $nextMonthDate = $firstDayOfMonth->modify('+1 month');
     $prevMonth = $prevMonthDate->format('n');
     $prevYear = $prevMonthDate->format('Y');
     $nextMonth = $nextMonthDate->format('n');
     $nextYear = $nextMonthDate->format('Y');
} catch (  Exception   $e) {
     // Handle invalid date creation, maybe default to current month/year
     error_log("Calendar Date Error: " . $e->getMessage());
     // Use defaults set earlier
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Venue: <?php echo htmlspecialchars($venue['title']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body {   font-family  : 'Inter', sans-serif;   background-color  : #f3f4f6; /* Light gray bg */ }
        /* Custom Form Styles */
        label {   font-weight  : 500;   color  : #374151; /* Gray 700 */ }
        input[type="text"], input[type="number"], input[type="email"], input[type="url"], textarea {
               border-color  : #d1d5db; /* Gray 300 */   border-radius  : 0.375rem; /* rounded-md */
               padding  : 0.5rem 0.75rem;   width  : 100%;
               transition  : border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        input:focus, textarea:focus {   border-color  : #fbbf24; /* Amber 400 */   box-shadow  : 0 0 0 3px rgba(251, 191, 36, 0.3);   outline  : none; }
        .card {   background-color  : white;   border-radius  : 0.5rem;   box-shadow  : 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);   padding  : 1.5rem; }
        h2 {   font-size  : 1.25rem;   font-weight  : 600;   color  : #1f2937; /* Gray 800 */   border-bottom  : 1px solid #e5e7eb;   padding-bottom  : 0.75rem;   margin-bottom  : 1rem; }
        .file-input-label {   cursor  : pointer;   background-color  : #4f46e5; /* Indigo 600 */   color  : white;   padding  : 0.5rem 1rem;   border-radius  : 0.375rem;   transition  : background-color 0.2s;   display  : inline-flex;   align-items  : center; }
        .file-input-label:hover {   background-color  : #4338ca; /* Indigo 700 */ }
        .file-input-label i {   margin-right  : 0.5rem; }

        /* Enhanced Calendar Styles (Editing Version) */
        .calendar-edit {   width  : 100%;   background-color  : #fff;   border  : 1px solid #e5e7eb;   border-radius  : 0.5rem;   box-shadow  : 0 1px 2px rgba(0,0,0,0.05);   margin-top  : 1rem;   font-size  : 0.9rem; }
        .calendar-edit .month-header {   padding  : 0.75rem;   background-color  : #f9fafb;   border-bottom  : 1px solid #e5e7eb;   font-weight  : 600;   display  : flex;   justify-content  : space-between;   align-items  : center; }
        .calendar-edit .month-header button {   background  : none;   border  : none;   cursor  : pointer;   color  : #4b5563;   padding  : 0.25rem 0.5rem; }
        .calendar-edit .month-header button:hover {   color  : #111827; }
        .calendar-edit .weekdays {   display  : grid;   grid-template-columns  : repeat(7, 1fr);   padding  : 0.5rem 0;   font-weight  : 500;   color  : #6b7280;   background-color  : #f9fafb;   border-bottom  : 1px solid #e5e7eb;   text-align  : center; }
        .calendar-edit .days {   display  : grid;   grid-template-columns  : repeat(7, 1fr);   gap  : 1px;   background-color  : #e5e7eb; }
        .calendar-edit .days div {   text-align  : center;   padding  : 0.5rem 0.25rem;   background-color  : #fff;   min-height  : 55px;   display  : flex;   flex-direction  : column;   align-items  : center;   justify-content  : center;   font-size  : 0.85rem; }
        .calendar-edit .days div.past-date {   color  : #9ca3af;   background-color  : #f9fafb; }
        .calendar-edit .days div.unavailable-full {   background-color  : #fee2e2;   color  : #991b1b;   font-weight  : 500; }
        .calendar-edit .days div.unavailable-full input[type="checkbox"] { /* Style checkbox even if unavailable */ }
        .calendar-edit .days div input[type="checkbox"] {   margin-top  : 0.25rem;   cursor  : pointer;   width  : 1rem;   height  : 1rem;   accent-color  : #ef4444; /* Red accent for unavailable */ }
         /* Hide checkbox visually but keep it accessible for past dates */
        .calendar-edit .days div.past-date input[type="checkbox"] {   opacity  : 0;   position  : absolute;   pointer-events  : none; }
        .calendar-edit .days div.past-date label {   color  : #9ca3af; } /* Style label for past dates */
        .calendar-edit .empty {   background-color  : #f9fafb; }
        #map {   height  : 300px;   width  : 100%;   border-radius  : 0.375rem;   border  : 1px solid #d1d5db; }

        /* New styles for image previews */
        .image-preview-item {
             position : relative;
             border : 1px solid #d1d5db;
             border-radius : 0.375rem;
             overflow : hidden;
             box-shadow : 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        .image-preview-item img {
             width : 100%;
             height : 100px; /* Fixed height for consistent grid */
             object-fit : cover;
        }
        .image-preview-item .remove-image-btn {
             position : absolute;
             top : 0.25rem;
             right : 0.25rem;
             background-color : rgba(239, 68, 68, 0.8); /* Red 500 with opacity */
             color : white;
             border-radius : 9999px; /* Full rounded */
             width : 1.5rem;
             height : 1.5rem;
             display : flex;
             align-items : center;
             justify-content : center;
             font-size : 0.75rem;
             cursor : pointer;
             transition : background-color 0.2s;
        }
        .image-preview-item .remove-image-btn:hover {
             background-color : rgba(220, 38, 38, 0.9); /* Red 600 with opacity */
        }
        .image-preview-item .cover-photo-label {
             position : absolute;
             bottom : 0.25rem;
             left : 0.25rem;
             background-color : rgba(0, 0, 0, 0.6);
             color : white;
             font-size : 0.7rem;
             padding : 0.1rem 0.4rem;
             border-radius : 0.25rem;
        }
        .add-image-placeholder {
             display : flex;
             align-items : center;
             justify-content : center;
             border : 2px dashed #d1d5db;
             border-radius : 0.375rem;
             height : 100px; /* Match image preview height */
             cursor : pointer;
             background-color : #f9fafb;
             color : #9ca3af;
             transition : border-color 0.2s, background-color 0.2s;
        }
        .add-image-placeholder:hover {
             border-color : #93c5fd; /* Blue 300 */
             background-color : #eff6ff; /* Blue 50 */
        }
        .upload-status-bar {
             background-color : #e0e7ff; /* Indigo 100 */
             border-radius : 0.25rem;
             overflow : hidden;
             height : 8px;
             margin-top : 0.5rem;
        }
        .upload-progress {
             width : 0%; /* Will be controlled by JS */
             height : 100%;
             background-color : #4f46e5; /* Indigo 600 */
             transition : width 0.3s ease-in-out;
        }

        /* Loading Overlay Styles */
        #loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.9); /* Semi-transparent white */
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999; /* Ensure it's on top of everything */
            opacity: 1;
            transition: opacity 0.5s ease-out;
        }

        #loading-overlay.hidden {
            opacity: 0;
            pointer-events: none; /* Allow clicks through once hidden */
        }

        .spinner {
            border: 4px solid rgba(0, 0, 0, 0.1);
            border-left-color: #f97316; /* Tailwind orange-500 */
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive adjustments for layout */
        @media (max-width: 1023px) { /* Adjusts for screens smaller than large (lg) breakpoint */
            .grid-cols-1.lg:grid-cols-3 {
                grid-template-columns: 1fr; /* Stack columns on smaller screens */
            }
            .lg\:col-span-2, .lg\:col-span-1 {
                grid-column: span 1 / span 1; /* Ensure full width for stacked columns */
            }
        }

        /* Ensure price input field is not hidden */
        .price-input-container {
            position: relative;
            display: flex;
            align-items: center;
        }
        .price-input-container .currency-symbol {
            position: absolute;
            left: 0.75rem; /* Equivalent to pl-3 */
            color: #6b7280; /* Tailwind gray-500 */
            pointer-events: none; /* Ensure it doesn't interfere with input clicks */
        }
        .price-input-container input {
            padding-left: 2rem; /* Make space for the currency symbol */
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-900">

    <div id="loading-overlay" class="flex">
        <div class="spinner"></div>
    </div>

    <header class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8 flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-800">
                Venue: <?php echo htmlspecialchars($venue['title']); ?>
            </h1>
            <?php if ($canEdit): ?>
                <a href="client_dashboard.php" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">
                    <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
                </a>
            <?php else: ?>
                <a href="/ventech_locator/index.php" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">
                    <i class="fas fa-arrow-left mr-1"></i> Back to Home
                </a>
            <?php endif; ?>
        </div>
    </header>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <?php if (!empty($form_errors)): ?>
             <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded shadow" role="alert">
                 <p class="font-bold mb-1">Please fix the following errors:</p>
                 <ul class="list-disc list-inside text-sm">
                     <?php foreach ($form_errors as $error): ?>
                         <li><?= htmlspecialchars($error) ?></li>
                     <?php endforeach; ?>
                 </ul>
             </div>
         <?php endif; ?>
          <?php if (!empty($form_success) && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
             <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded shadow" role="alert">
                 <p class="font-bold mb-1">Success!</p>
                  <ul class="list-disc list-inside text-sm">
                     <?php foreach ($form_success as $msg): ?>
                         <li><?= htmlspecialchars($msg) ?></li>
                     <?php endforeach; ?>
                 </ul>
             </div>
         <?php endif; ?>

        <?php if ($canEdit): // Show edit form only if authorized ?>
            <form action="venue_details.php?id=<?php echo $venue_id; ?>" method="POST" enctype="multipart/form-data" id="venue-details-form">
                <input type="hidden" name="venue_id" value="<?php echo $venue_id; ?>">

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                    <div class="lg:col-span-2 space-y-6">

                        <div class="card">
                            <h2>Core Venue Information</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="num-persons" class="block text-sm">Capacity (Persons):</label>
                                    <input type="number" id="num-persons" name="num-persons" value="<?php echo htmlspecialchars($venue['num_persons'] ?? 1); ?>" min="1" required>
                                </div>
                                <div>
                                    <label for="price" class="block text-sm">Price (per Hour):</label>
                                    <div class="price-input-container">
                                         <span class="currency-symbol">₱</span>
                                         <input type="number" id="price" name="price" value="<?php echo htmlspecialchars($venue['price'] ?? 0.00); ?>" min="0" step="0.01" required>
                                    </div>
                                </div>
                                 <div>
                                    <label for="reviews" class="block text-sm">Reviews Count:</label>
                                    <input type="number" id="reviews" name="reviews" value="<?php echo htmlspecialchars($venue['reviews'] ?? 0); ?>" min="0">
                                </div>
                                <div>
                                    <label for="virtual_tour_url" class="block text-sm">Virtual Tour URL:</label>
                                    <input type="url" id="virtual_tour_url" name="virtual_tour_url" value="<?php echo htmlspecialchars($venue['virtual_tour_url'] ?? ''); ?>" placeholder="https://...">
                                </div>
                                 <div class="md:col-span-2">
                                    <label for="google_map_url" class="block text-sm">Google Map URL:</label>
                                    <input type="url" id="google_map_url" name="google_map_url" value="<?php echo htmlspecialchars($venue['google_map_url'] ?? ''); ?>" placeholder="https://maps.app.goo.gl/xh8DXiUXmyXcixzo6">
                                    <p class="text-xs text-gray-500 mt-1">Go to Google Maps, search for a location, click 'Share', then 'Copy link', and paste the URL here.</p>
                                </div>
                                 <div class="md:col-span-1">
                                    <span class="block text-sm mb-2">Wifi Available:</span>
                                    <div class="flex items-center space-x-4">
                                        <label class="inline-flex items-center">
                                            <input type="radio" id="wifi-yes" name="wifi" value="yes" <?php echo (($venue['wifi'] ?? 'no') == 'yes') ? 'checked' : ''; ?> class="form-radio h-4 w-4 text-indigo-600">
                                            <span class="ml-2 text-sm">Yes</span>
                                        </label>
                                        <label class="inline-flex items-center">
                                            <input type="radio" id="wifi-no" name="wifi" value="no" <?php echo (($venue['wifi'] ?? 'no') == 'no') ? 'checked' : ''; ?> class="form-radio h-4 w-4 text-indigo-600">
                                            <span class="ml-2 text-sm">No</span>
                                        </label>
                                    </div>
                                </div>
                                <div class="md:col-span-1">
                                     <span class="block text-sm mb-2">Parking Available:</span>
                                    <div class="flex items-center space-x-4">
                                         <label class="inline-flex items-center">
                                            <input type="radio" id="parking-yes" name="parking" value="yes" <?php echo (($venue['parking'] ?? 'no') == 'yes') ? 'checked' : ''; ?> class="form-radio h-4 w-4 text-indigo-600">
                                            <span class="ml-2 text-sm">Yes</span>
                                        </label>
                                         <label class="inline-flex items-center">
                                            <input type="radio" id="parking-no" name="parking" value="no" <?php echo (($venue['parking'] ?? 'no') == 'no') ? 'checked' : ''; ?> class="form-radio h-4 w-4 text-indigo-600">
                                            <span class="ml-2 text-sm">No</span>
                                        </label>
                                    </div>
                                </div>
                                <div class="md:col-span-2">
                                    <label for="amenities" class="block text-sm">Amenities:</label>
                                    <textarea id="amenities" name="amenities" rows="4" placeholder="List amenities separated by commas (e.g., Projector, Sound System, Whiteboard)"><?php echo htmlspecialchars($venue['amenities'] ?? ''); ?></textarea>
                                </div>
                                <div class="md:col-span-2">
                                    <label for="additional_info" class="block text-sm">Additional Information:</label>
                                    <textarea id="additional_info" name="additional_info" rows="4" placeholder="Any other relevant details, rules, or notes"><?php echo htmlspecialchars($venue['additional_info'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <h2>Location</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label for="latitude" class="block text-sm">Latitude:</label>
                                    <input type="text" id="latitude" name="latitude" value="<?php echo htmlspecialchars($venue['latitude'] ?? ''); ?>" placeholder="e.g., 14.12345">
                                </div>
                                <div>
                                    <label for="longitude" class="block text-sm">Longitude:</label>
                                    <input type="text" id="longitude" name="longitude" value="<?php echo htmlspecialchars($venue['longitude'] ?? ''); ?>" placeholder="e.g., 121.12345">
                                </div>
                            </div>
                             <div id="map"></div>
                             <p class="text-xs text-gray-500 mt-2">Drag the marker on the map to update coordinates, or enter manually above.</p>
                        </div>

                         <div class="card">
                            <h2>Venue Contact Person</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                 <div>
                                    <label for="client-name" class="block text-sm">Contact Name:</label>
                                    <input type="text" id="client-name" name="client-name" value="<?php echo htmlspecialchars($client_info['client_name'] ?? ''); ?>" required>
                                </div>
                                <div>
                                    <label for="client-email" class="block text-sm">Contact Email:</label>
                                    <input type="email" id="client-email" name="client-email" value="<?php echo htmlspecialchars($client_info['client_email'] ?? ''); ?>" required>
                                </div>
                                 <div>
                                    <label for="client-phone" class="block text-sm">Contact Phone:</label>
                                    <input type="text" id="client-phone" name="client-phone" value="<?php echo htmlspecialchars($client_info['client_phone'] ?? ''); ?>" required>
                                </div>
                                 <div>
                                    <label for="client-address" class="block text-sm">Venue Address:</label>
                                    <input type="text" id="client-address" name="client-address" value="<?php echo htmlspecialchars($client_info['client_address'] ?? ''); ?>" required>
                                </div>
                            </div>
                        </div>


                    </div><div class="lg:col-span-1 space-y-6">

                        <div class="card">
                             <h2>Media Management</h2>
                              <div class="mb-4">
                                 <h3 class="text-sm font-medium text-gray-700 mb-2">Existing Media:</h3>
                                 <?php if (!empty($media)): ?>
                                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-2 lg:grid-cols-3 gap-2">
                                        <?php foreach ($media as $item): ?>
                                            <div class="relative group">
                                                <?php if ($item['media_type'] === 'image'): ?>
                                                    <img src="<?php echo htmlspecialchars($item['media_url']); ?>" alt="Venue Media" class="w-full h-20 object-cover rounded">
                                                <?php elseif ($item['media_type'] === 'video'): ?>
                                                    <video preload="metadata" class="w-full h-20 object-cover rounded bg-black">
                                                        <source src="<?php echo htmlspecialchars($item['media_url']); ?>#t=0.5" type="video/mp4">
                                                    </video>
                                                     <div class="absolute inset-0 flex items-center justify-center bg-black bg-opacity-30">
                                                        <i class="fas fa-play text-white text-xl"></i>
                                                    </div>
                                                <?php endif; ?>
                                                </div>
                                        <?php endforeach; ?>
                                    </div>
                                 <?php else: ?>
                                    <p class="text-sm text-gray-500">No media uploaded yet.</p>
                                 <?php endif; ?>
                             </div>
                             <hr class="my-4">
                             <div class="space-y-4">
                                 <div>
                                     <label for="venue_images" class="block text-sm mb-2">Upload New Images (Max 6):</label>
                                     <label class="file-input-label">
                                         <i class="fas fa-image"></i> Choose Images...
                                         <input type="file" id="venue_images" name="venue_images[]" accept="image/*" multiple class="sr-only">
                                     </label>
                                     <div id="uploadStatus" class="text-sm text-gray-600 mt-2 hidden">
                                         <p id="uploadCount">Uploading images...</p>
                                         <div class="upload-status-bar">
                                             <div id="uploadProgress" class="upload-progress"></div>
                                         </div>
                                     </div>
                                     <div id="imagePreviewsContainer" class="mt-2 grid grid-cols-3 gap-2">
                                         </div>
                                     <p class="text-xs text-gray-500 mt-1">Max 5MB per image. Formats: jpg, png, gif. Up to 6 images.</p>
                                 </div>
                                 <div>
                                     <label for="venue_video" class="block text-sm mb-2">Upload New Video:</label>
                                      <label class="file-input-label !bg-green-600 hover:!bg-green-700">
                                         <i class="fas fa-video"></i> Choose Video...
                                         <input type="file" id="venue_video" name="venue_video" accept="video/*" class="sr-only">
                                     </label>
                                      <video id="videoPreview" class="mt-2 w-full h-32 border border-gray-300 rounded hidden bg-black" controls>
                                         <source src="#" type="video/mp4"> Your browser does not support the video tag.
                                     </video>
                                     <p class="text-xs text-gray-500 mt-1">Max 50MB. Accepts any video format.</p>
                                 </div>
                             </div>
                        </div>

                        <div class="card">
                             <h2>Manage Availability</h2>
                             <p class="text-sm text-gray-600 mb-2">Check the boxes for dates when the venue is <strong class="text-red-600">unavailable</strong>.</p>
                             <div id="full-calendar-container" class="calendar-edit">
                                 <div class="calendar-navigation text-center mb-3 text-sm space-x-2">
                                     <a href="?id=<?php echo $venue_id; ?>&month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="inline-block px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">« Prev</a>
                                     <span class="font-semibold"><?php echo date('F Y', strtotime("$currentYear-$currentMonth-01")); ?></span>
                                     <a href="?id=<?php echo $venue_id; ?>&month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="inline-block px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">Next »</a>
                                 </div>
                                 <div class="weekdays">
                                     <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
                                 </div>
                                 <div class="days">
                                     <?php
                                     try {
                                        $daysInMonth = $firstDayOfMonth->format('t');
                                        $dayOfWeek = $firstDayOfMonth->format('w');
                                        $dayCounter = 1;

                                        // Add empty cells for start
                                        for ($i = 0; $i < $dayOfWeek; $i++) { echo '<div class="empty"></div>'; }

                                        // Add day cells
                                        while ($dayCounter <= $daysInMonth) {
                                            $dateString = date('Y-m-d', strtotime("$currentYear-$currentMonth-$dayCounter"));
                                            $isUnavailable = in_array($dateString, $unavailableDates);
                                            $isPastDate = $dateString < $today;
                                            $cellClasses = $isPastDate ? 'past-date' : '';
                                            if ($isUnavailable && !$isPastDate) { $cellClasses .= ' unavailable-full'; }

                                            echo '<div class="' . trim($cellClasses) . '">';
                                            echo '<label for="date-' . $dateString . '" class="flex flex-col items-center cursor-pointer ' . ($isPastDate ? 'cursor-not-allowed' : '') . '">';
                                            echo '<span class="day-number">' . $dayCounter . '</span>';
                                            // Checkbox is always present but disabled/hidden visually for past dates
                                            echo '<input type="checkbox" id="date-' . $dateString . '" name="unavailable_dates[]" value="' . $dateString . '" '
                                                . ($isUnavailable ? 'checked' : '')
                                                . ($isPastDate ? ' disabled ' : '') // Add disabled attribute for past dates
                                                . '>';
                                            echo '</label>';
                                            echo '</div>'; // Close day cell

                                            if (($dayOfWeek + $dayCounter) % 7 == 0) { // End of week
                                                 // No need for </tr> <tr> as grid handles it
                                            }
                                            $dayCounter++;
                                        }
                                        // Add empty cells for end
                                         $totalCells = $dayOfWeek + $daysInMonth;
                                         $remainingCells = (7 - ($totalCells % 7)) % 7;
                                         for ($i = 0; $i < $remainingCells; $i++) { echo '<div class="empty"></div>'; }

                                     } catch (  Exception   $e) {
                                          echo '<div class="col-span-7 text-red-500 p-4">Error generating calendar days.</div>';
                                     }

                                     ?>
                                 </div></div><p class="text-xs text-gray-500 mt-2 text-center">Past dates cannot be changed.</p>
                        </div>

                    </div>
                </div>
                <div class="mt-8 pt-6 border-t border-gray-200 flex justify-end">
                    <a href="client_dashboard.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-medium py-2 px-5 rounded mr-3 transition duration-150 ease-in-out">
                        Cancel
                    </a>
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 shadow transition duration-150 ease-in-out">
                        <i class="fas fa-save mr-2"></i>
                        Save All Changes
                    </button>
                </div>
            </form>
        <?php else: // Display booking option if not authorized to edit ?>
            <div class="card mt-6">
                <h2>Book This Venue</h2>
                <?php if ($loggedInUserId): // If user is logged in, show booking form (placeholder) ?>
                    <p class="text-lg text-gray-800 mb-4">You are logged in as **<?= htmlspecialchars($owner['username'] ?? 'User') ?>**. Ready to book this venue?</p>
                    <div class="bg-blue-50 p-4 rounded-md border border-blue-200">
                        <h3 class="text-md font-semibold text-blue-800 mb-2">Booking Form (Placeholder)</h3>
                        <p class="text-sm text-gray-700 mb-4">This section would contain the actual booking form, including date/time selection, number of guests, and any other relevant booking details. The form submission would go to a `process_booking.php` or similar script.</p>
                        <form action="process_booking.php" method="POST" class="space-y-4">
                            <input type="hidden" name="venue_id" value="<?= htmlspecialchars($venue_id) ?>">
                            <input type="hidden" name="user_id" value="<?= htmlspecialchars($loggedInUserId) ?>">

                            <div>
                                <label for="booking_date" class="block text-sm font-medium text-gray-700">Preferred Date:</label>
                                <input type="date" id="booking_date" name="booking_date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            </div>
                            <div>
                                <label for="booking_time" class="block text-sm font-medium text-gray-700">Preferred Time:</label>
                                <input type="time" id="booking_time" name="booking_time" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            </div>
                            <div>
                                <label for="number_of_guests" class="block text-sm font-medium text-gray-700">Number of Guests:</label>
                                <input type="number" id="number_of_guests" name="number_of_guests" min="1" max="<?= htmlspecialchars($venue['num_persons'] ?? 100) ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            </div>
                            <button type="submit" class="w-full bg-indigo-600 text-white py-2 px-4 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out">
                                Book Now
                            </button>
                        </form>
                    </div>
                <?php else: // If user is NOT logged in, prompt to log in/sign up ?>
                    <p class="text-lg text-gray-800 mb-4">To book this venue, please log in or create an account:</p>
                    <div class="flex flex-col sm:flex-row gap-4">
                        <a href="/ventech_locator/client/client_login.php" class="flex-1 text-center bg-indigo-600 text-white font-bold py-3 px-6 rounded-md hover:bg-indigo-700 transition duration-150 ease-in-out shadow-md">
                            <i class="fas fa-sign-in-alt mr-2"></i> Log In
                        </a>
                        <a href="/ventech_locator/client/client_signup.php" class="flex-1 text-center bg-green-600 text-white font-bold py-3 px-6 rounded-md hover:bg-green-700 transition duration-150 ease-in-out shadow-md">
                            <i class="fas fa-user-plus mr-2"></i> Sign Up
                        </a>
                    </div>
                    <p class="text-sm text-gray-500 mt-4 text-center">Booking is only available for registered users.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
         <script>
        document.addEventListener("DOMContentLoaded",   function   () {
              const   imageInput = document.getElementById('venue_images'); // Changed ID
              const   imagePreviewsContainer = document.getElementById('imagePreviewsContainer'); // New container for multiple previews
              const   uploadStatus = document.getElementById('uploadStatus');
              const   uploadCount = document.getElementById('uploadCount');
              const   uploadProgress = document.getElementById('uploadProgress');
              const   videoInput = document.getElementById('venue_video');
              const   videoPreview = document.getElementById('videoPreview');
              const   videoPreviewSource = videoPreview ? videoPreview.querySelector('source') : null;
               let   map = null; // Leaflet map instance
               let   marker = null; // Leaflet marker instance
               const   loadingOverlay = document.getElementById('loading-overlay');


            // Hide loading overlay once content is loaded
            if (loadingOverlay) {
                loadingOverlay.classList.add('hidden');
            }


            // Array to store files selected for upload
             let  selectedImageFiles = [];
             const  MAX_IMAGES = 6;

            // Function to render image previews
             function  renderImagePreviews() {
                imagePreviewsContainer.innerHTML = ''; // Clear previous previews
                
                // Show status container if files are selected, otherwise hide
                if (selectedImageFiles.length > 0) {
                    uploadStatus.classList.remove('hidden'); 
                } else {
                    uploadStatus.classList.add('hidden');
                }

                uploadCount.textContent = `Selected: ${selectedImageFiles.length} / ${MAX_IMAGES}`; // Update count immediately
                uploadProgress.style.width = `${(selectedImageFiles.length / MAX_IMAGES) * 100}%`; // Update progress immediately

                // Create and append image previews
                selectedImageFiles.forEach(( file ,  index )  =>  {
                     const  imgContainer = document.createElement('div');
                    imgContainer.className = 'image-preview-item';
                   
                     const  img = document.createElement('img');
                    img.alt = 'Image Preview';
                    imgContainer.appendChild(img);

                     const  reader = new FileReader();
                    reader.onload =  function  ( e ) {
                        img.src = e.target.result;
                    };
                    reader.readAsDataURL(file);

                    // Add remove button
                     const  removeBtn = document.createElement('button');
                    removeBtn.className = 'remove-image-btn';
                    removeBtn.type = 'button';
                    removeBtn.innerHTML = '<i class="fas fa-times"></i>';
                    removeBtn.title = 'Remove image';
                    // Use a closure for the correct index
                    removeBtn.addEventListener('click', ( function ( idx ) {
                        return  function () {
                            selectedImageFiles.splice(idx, 1);
                            renderImagePreviews(); // Re-render to update display and indices
                        };
                    })(index)); // Pass current index to closure
                    imgContainer.appendChild(removeBtn);

                    // Add "Cover Photo" label to the first image
                    if (index === 0) {
                         const  coverLabel = document.createElement('span');
                        coverLabel.className = 'cover-photo-label';
                        coverLabel.textContent = 'Cover Photo';
                        imgContainer.appendChild(coverLabel);
                    }
                    imagePreviewsContainer.appendChild(imgContainer);
                });

                // Add placeholder for remaining slots if MAX_IMAGES is not reached
                if (selectedImageFiles.length < MAX_IMAGES) {
                     const  placeholder = document.createElement('div');
                    placeholder.className = 'add-image-placeholder';
                    placeholder.innerHTML = '<i class="fas fa-plus text-3xl"></i>';
                    placeholder.addEventListener('click', ()  =>  imageInput.click());
                    imagePreviewsContainer.appendChild(placeholder);
                }
            }


            // Image Input Change Listener
            if (imageInput && imagePreviewsContainer) {
                imageInput.addEventListener('change',   function  (  event  ) {
                     const  files = event.target.files;
                    selectedImageFiles = []; // Reset selected files

                    if (files && files.length > 0) {
                        for ( let  i = 0; i < Math.min(files.length, MAX_IMAGES); i++) {
                            selectedImageFiles.push(files[i]);
                        }
                    }
                    renderImagePreviews(); // Render previews for newly selected files
                });
            }

            // Handle form submission to ensure only selectedImageFiles are sent
             const  venueDetailsForm = document.getElementById('venue-details-form');
            if (venueDetailsForm) {
                venueDetailsForm.addEventListener('submit',  function ( event ) {
                    // Create a new DataTransfer object to hold the files
                     const  dataTransfer = new DataTransfer();
                    selectedImageFiles.forEach( file   =>  {
                        dataTransfer.items.add(file);
                    });
                    // Assign the new FileList to the input
                    imageInput.files = dataTransfer.files;

                    // Show loading overlay
                    if (loadingOverlay) {
                        loadingOverlay.classList.remove('hidden');
                    }
                });
            }


            // Video Preview Handler
            if (videoInput && videoPreview && videoPreviewSource) {
                videoInput.addEventListener('change',   function  (  event  ) {
                      const   file = event.target.files[0];
                    if (file && file.type.startsWith('video/')) {
                          const   reader = new FileReader();
                        reader.onload =   function  (  e  ) {
                             videoPreviewSource.src = e.target.result;
                             videoPreview.load(); // Important to load the new source
                             videoPreview.classList.remove('hidden');
                        }
                        reader.readAsDataURL(file);
                    } else {
                         videoPreviewSource.src = '#';
                         videoPreview.classList.add('hidden');
                    }
                });
            }

            // Leaflet Map Initialization
               const   latInput = document.getElementById('latitude');
               const   lonInput = document.getElementById('longitude');
               const   initialLat = parseFloat(latInput.value) || 14.4797; // Default to a reasonable location like Las Piñas if invalid/empty
               const   initialLon = parseFloat(lonInput.value) || 120.9936; // Default to a reasonable location like Las Piñas if invalid/empty
               const   mapDiv = document.getElementById('map');

             if (mapDiv && typeof L !== 'undefined') {
                 try {
                    map = L.map(mapDiv).setView([initialLat, initialLon], 15);

                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors'
                    }).addTo(map);

                     // Add Draggable Marker
                     marker = L.marker([initialLat, initialLon], {
                        draggable: true
                    }).addTo(map);

                    // Update inputs when marker is dragged
                    marker.on('dragend',   function  (  event  ) {
                          const   position = marker.getLatLng();
                         if (latInput) latInput.value = position.lat.toFixed(6);
                         if (lonInput) lonInput.value = position.lng.toFixed(6);
                    });

                     // Update map if lat/lon inputs change manually
                      function   updateMapFromInputs() {
                           const   newLat = parseFloat(latInput.value);
                           const   newLon = parseFloat(lonInput.value);
                         if (!isNaN(newLat) && !isNaN(newLon) && map && marker) {
                              const   newLatLng = L.latLng(newLat, newLon);
                            map.setView(newLatLng, map.getZoom());
                            marker.setLatLng(newLatLng);
                         }
                    }

                    if(latInput) latInput.addEventListener('change', updateMapFromInputs);
                    if(lonInput) lonInput.addEventListener('change', updateMapFromInputs);

                 } catch (e) {
                     console.error("Leaflet map initialization failed:", e);
                     mapDiv.innerHTML = '<p class="text-center text-red-500 p-4">Error loading map.</p>';
                 }
             }

              // Optional: Delete Media Functionality Placeholder
             // function deleteMedia(mediaId) {
             //       // Use fetch API to send request to a backend script (e.g., delete_media.php)
             //       fetch('delete_media.php', {
             //          method: 'POST',
             //          headers: { 'Content-Type': 'application/json' },
             //          body: JSON.stringify({ media_id: mediaId, venue_id: <?php echo $venue_id; ?> /* Add CSRF token */ })
             //       })
             //       .then(response => response.json())
             //       .then(data => {
             //          if (data.success) {
             //              // Remove the media item from the DOM
             //             // Consider using a more specific selector
             //             document.querySelector(`[onclick*="deleteMedia(${mediaId})"]`).closest('.relative').remove();
             //          } else {
             //              console.error('Error deleting media: ' + (data.message || 'Unknown error'));
             //          }
             //       })
             //       .catch(error => {
             //          console.error('Error:', error);
             //       });
             // }

        }); // End DOMContentLoaded
    </script>

</body>
</html>
