<?php
// admin-actions-secure.php
require_once 'db_connect.php';

header('Content-Type: application/json');

// 1. Check authentication FIRST
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['ADMIN', 'SUPERADMIN'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// User management is restricted to SUPERADMIN only.
$user_management_actions = ['add_user', 'toggle_user_status', 'update_user_role', 'update_user_profile'];
if (in_array(($_POST['action'] ?? ''), $user_management_actions, true) && $_SESSION['user_type'] !== 'SUPERADMIN') {
    echo json_encode(['success' => false, 'message' => 'Only Super Admins can manage users.']);
    exit;
}

// Cross-account creation (children + appointments on behalf of any parent) is
// elevated functionality that only SUPERADMIN should be able to perform from
// the admin dashboard.
$superadmin_only_actions = ['add_patient', 'create_appointment', 'get_patient_summary'];
if (in_array(($_POST['action'] ?? ''), $superadmin_only_actions, true) && $_SESSION['user_type'] !== 'SUPERADMIN') {
    echo json_encode(['success' => false, 'message' => 'Only Super Admins can perform this action.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// 2. CSRF Protection
if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'add_user':
            handleAddUser($conn);
            break;
        case 'toggle_user_status':
            handleToggleUserStatus($conn);
            break;
        case 'update_user_role':
            handleUpdateUserRole($conn);
            break;
        case 'update_user_profile':
            handleUpdateUserProfile($conn);
            break;
        case 'add_schedule':
            handleAddSchedule($conn);
            break;
        case 'delete_schedule':
            handleDeleteSchedule($conn);
            break;
        case 'add_service':
            handleAddService($conn);
            break;
        case 'toggle_service_status':
            handleToggleServiceStatus($conn);
            break;
        case 'update_clinic_settings':
            handleUpdateClinicSettings($conn);
            break;
        case 'export_patient_data':
            handleExportPatientData($conn);
            break;
        case 'get_doctors':
            handleGetDoctors($conn);
            break;
        case 'add_announcement':
            handleAddAnnouncement($conn);
            break;
        case 'toggle_announcement':
            handleToggleAnnouncement($conn);
            break;
        case 'delete_announcement':
            handleDeleteAnnouncement($conn);
            break;
        case 'update_appointment_status':
            handleUpdateAppointmentStatus($conn);
            break;
        case 'add_patient':
            handleAddPatient($conn);
            break;
        case 'create_appointment':
            handleCreateAppointment($conn);
            break;
        case 'get_patient_summary':
            handleGetPatientSummary($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
            exit;
    }
} catch (Exception $e) {
    error_log("Admin action error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}

function handleAddUser($conn) {
    // Validate required fields
    $required = ['first_name', 'last_name', 'email', 'user_type', 'password'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
            return;
        }
    }
    
    $first_name = sanitize_input($_POST['first_name']);
    $last_name = sanitize_input($_POST['last_name']);
    $email = sanitize_input($_POST['email']);
    $phone = sanitize_input($_POST['phone'] ?? '');
    $user_type = sanitize_input($_POST['user_type']);
    
    // Validate input lengths
    if (strlen($first_name) > 50 || strlen($last_name) > 50) {
        echo json_encode(['success' => false, 'message' => 'Name too long (max 50 characters)']);
        return;
    }
    
    // Email validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        return;
    }
    
    if (strlen($email) > 100) {
        echo json_encode(['success' => false, 'message' => 'Email too long (max 100 characters)']);
        return;
    }
    
    // Validate user type
    $allowed_types = ['PARENT', 'DOCTOR', 'ADMIN', 'SUPERADMIN'];
    if (!in_array($user_type, $allowed_types)) {
        echo json_encode(['success' => false, 'message' => 'Invalid user type']);
        return;
    }
    
    // Password strength check
    $password = $_POST['password'];
    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters']);
        return;
    }
    
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Check if email exists using prepared statement
    $check_query = "SELECT id FROM users WHERE email = ?";
    $stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    
    if (mysqli_stmt_num_rows($stmt) > 0) {
        echo json_encode(['success' => false, 'message' => 'Email already exists']);
        return;
    }
    
    // Insert user with prepared statement
    $query = "INSERT INTO users (first_name, last_name, email, phone, password, user_type, status) VALUES (?, ?, ?, ?, ?, ?, 'active')";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ssssss", $first_name, $last_name, $email, $phone, $hashed_password, $user_type);
    
    if (mysqli_stmt_execute($stmt)) {
        $new_user_id = mysqli_insert_id($conn);
        
        // Log activity with actual user ID
        $user_id = $_SESSION['user_id'];
        $details = "Created new user: $first_name $last_name ($user_type) - ID: $new_user_id";
        $ip_address = $_SERVER['REMOTE_ADDR'];
        
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'CREATE', ?, ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        mysqli_stmt_bind_param($log_stmt, "iss", $user_id, $details, $ip_address);
        mysqli_stmt_execute($log_stmt);
        
        echo json_encode(['success' => true, 'message' => 'User created successfully', 'user_id' => $new_user_id]);
    } else {
        error_log("User creation error: " . mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => 'Failed to create user']);
    }
}

function handleToggleUserStatus($conn) {
    if (!isset($_POST['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Missing user ID']);
        return;
    }
    
    $user_id = intval($_POST['user_id']);
    $current_user_id = $_SESSION['user_id'];
    
    // Prevent self-deactivation
    if ($user_id == $current_user_id) {
        echo json_encode(['success' => false, 'message' => 'Cannot change your own status']);
        return;
    }
    
    // Get current status
    $query = "SELECT status, first_name, last_name, user_type FROM users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        return;
    }
    
    $new_status = $user['status'] === 'active' ? 'inactive' : 'active';
    
    // Update status
    $update_query = "UPDATE users SET status = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $update_query);
    mysqli_stmt_bind_param($stmt, "si", $new_status, $user_id);
    
    if (mysqli_stmt_execute($stmt)) {
        // Log activity
        $details = "Changed user status: {$user['first_name']} {$user['last_name']} ({$user['user_type']}) to $new_status";
        $ip_address = $_SERVER['REMOTE_ADDR'];

        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'UPDATE', ?, ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        mysqli_stmt_bind_param($log_stmt, "iss", $current_user_id, $details, $ip_address);
        mysqli_stmt_execute($log_stmt);

        // Notify the user that their account status changed.
        if (function_exists('send_user_notification')) {
            $msg = "Your AlagApp Clinic account status has been updated to: " . strtoupper($new_status) . ".";
            if ($new_status === 'inactive' || $new_status === 'suspended') {
                $msg .= "\n\nIf you believe this is a mistake, please contact the clinic.";
            }
            send_user_notification($conn, $user_id, 'Your account status changed', $msg, 'SYSTEM');
        }

        echo json_encode(['success' => true, 'message' => 'User status updated', 'new_status' => $new_status]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update user status']);
    }
}

function handleUpdateUserRole($conn) {
    if (!isset($_POST['user_id'], $_POST['new_role'])) {
        echo json_encode(['success' => false, 'message' => 'Missing user ID or new role']);
        return;
    }

    $user_id = intval($_POST['user_id']);
    $new_role = strtoupper(sanitize_input($_POST['new_role']));
    $current_user_id = $_SESSION['user_id'];

    $allowed_roles = ['PARENT', 'DOCTOR', 'ADMIN', 'SUPERADMIN'];
    if (!in_array($new_role, $allowed_roles, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid role']);
        return;
    }

    // Look up the target user's current role
    $stmt = mysqli_prepare($conn, "SELECT first_name, last_name, user_type FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $target = mysqli_fetch_assoc($result);

    if (!$target) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        return;
    }

    // Doctors' roles cannot be changed and a user cannot be re-assigned to DOCTOR
    // from another role through this surface either.
    if ($target['user_type'] === 'DOCTOR' || $target['user_type'] === 'DOCTOR_OWNER') {
        echo json_encode(['success' => false, 'message' => "A doctor's role cannot be changed."]);
        return;
    }
    if ($new_role === 'DOCTOR') {
        echo json_encode(['success' => false, 'message' => 'Doctors must be onboarded as new accounts; cannot promote to DOCTOR.']);
        return;
    }

    $update = mysqli_prepare($conn, "UPDATE users SET user_type = ? WHERE id = ?");
    mysqli_stmt_bind_param($update, "si", $new_role, $user_id);
    if (!mysqli_stmt_execute($update)) {
        echo json_encode(['success' => false, 'message' => 'Failed to update role']);
        return;
    }

    $details = "Changed role for {$target['first_name']} {$target['last_name']} from {$target['user_type']} to $new_role";
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $log = mysqli_prepare($conn, "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'UPDATE', ?, ?)");
    mysqli_stmt_bind_param($log, "iss", $current_user_id, $details, $ip_address);
    mysqli_stmt_execute($log);

    // Notify the user that their role has been changed.
    if (function_exists('send_user_notification')) {
        $msg = "Your AlagApp Clinic account role has been changed from " . $target['user_type'] . " to " . $new_role . ".\n\n"
             . "If you have any questions about this change, please contact the clinic.";
        send_user_notification($conn, $user_id, 'Your account role changed', $msg, 'SYSTEM');
    }

    echo json_encode(['success' => true, 'message' => 'User role updated', 'new_role' => $new_role]);
}

function handleAddSchedule($conn) {
    $required = ['doctor_id', 'day_of_week', 'start_time', 'end_time'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
            return;
        }
    }
    
    $doctor_id = intval($_POST['doctor_id']);
    $day_of_week = sanitize_input($_POST['day_of_week']);
    $start_time = sanitize_input($_POST['start_time']);
    $end_time = sanitize_input($_POST['end_time']);
    $slot_duration = intval($_POST['slot_duration'] ?? 30);
    $max_patients = intval($_POST['max_patients'] ?? 10);
    
    // Validate day of week
    $allowed_days = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];
    if (!in_array($day_of_week, $allowed_days)) {
        echo json_encode(['success' => false, 'message' => 'Invalid day of week']);
        return;
    }
    
    // Validate time format
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $start_time) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $end_time)) {
        echo json_encode(['success' => false, 'message' => 'Invalid time format']);
        return;
    }
    
    // Validate time range
    if (strtotime($start_time) >= strtotime($end_time)) {
        echo json_encode(['success' => false, 'message' => 'End time must be after start time']);
        return;
    }
    
    // Validate doctor exists
    $check_query = "SELECT id FROM users WHERE id = ? AND user_type IN ('DOCTOR', 'DOCTOR_OWNER')";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "i", $doctor_id);
    mysqli_stmt_execute($check_stmt);
    mysqli_stmt_store_result($check_stmt);
    
    if (mysqli_stmt_num_rows($check_stmt) === 0) {
        echo json_encode(['success' => false, 'message' => 'Doctor not found']);
        return;
    }
    
    // Check for overlapping schedules
    $overlap_query = "SELECT id FROM doctor_schedules WHERE doctor_id = ? AND day_of_week = ? AND active = 1 AND (
        (start_time <= ? AND end_time > ?) OR
        (start_time < ? AND end_time >= ?) OR
        (start_time >= ? AND end_time <= ?)
    )";
    $overlap_stmt = mysqli_prepare($conn, $overlap_query);
    mysqli_stmt_bind_param($overlap_stmt, "isssssss", $doctor_id, $day_of_week, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time);
    mysqli_stmt_execute($overlap_stmt);
    mysqli_stmt_store_result($overlap_stmt);
    
    if (mysqli_stmt_num_rows($overlap_stmt) > 0) {
        echo json_encode(['success' => false, 'message' => 'Schedule overlaps with existing schedule']);
        return;
    }
    
    $query = "INSERT INTO doctor_schedules (doctor_id, day_of_week, start_time, end_time, slot_duration, max_patients) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "isssii", $doctor_id, $day_of_week, $start_time, $end_time, $slot_duration, $max_patients);
    
    if (mysqli_stmt_execute($stmt)) {
        // Log activity
        $user_id = $_SESSION['user_id'];
        $details = "Added schedule for doctor ID: $doctor_id on $day_of_week from $start_time to $end_time";
        $ip_address = $_SERVER['REMOTE_ADDR'];
        
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'CREATE', ?, ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        mysqli_stmt_bind_param($log_stmt, "iss", $user_id, $details, $ip_address);
        mysqli_stmt_execute($log_stmt);
        
        echo json_encode(['success' => true, 'message' => 'Schedule added successfully']);
    } else {
        error_log("Schedule add error: " . mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => 'Failed to add schedule']);
    }
}

function handleDeleteSchedule($conn) {
    if (!isset($_POST['schedule_id'])) {
        echo json_encode(['success' => false, 'message' => 'Missing schedule ID']);
        return;
    }
    
    $schedule_id = intval($_POST['schedule_id']);
    
    // Get schedule details for logging
    $query = "SELECT ds.*, u.first_name, u.last_name FROM doctor_schedules ds 
              JOIN users u ON ds.doctor_id = u.id 
              WHERE ds.id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $schedule_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $schedule = mysqli_fetch_assoc($result);
    
    if (!$schedule) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found']);
        return;
    }
    
    $delete_query = "DELETE FROM doctor_schedules WHERE id = ?";
    $stmt = mysqli_prepare($conn, $delete_query);
    mysqli_stmt_bind_param($stmt, "i", $schedule_id);
    
    if (mysqli_stmt_execute($stmt)) {
        // Log activity
        $user_id = $_SESSION['user_id'];
        $details = "Deleted schedule for Dr. {$schedule['first_name']} {$schedule['last_name']} on {$schedule['day_of_week']} ({$schedule['start_time']} - {$schedule['end_time']})";
        $ip_address = $_SERVER['REMOTE_ADDR'];
        
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'DELETE', ?, ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        mysqli_stmt_bind_param($log_stmt, "iss", $user_id, $details, $ip_address);
        mysqli_stmt_execute($log_stmt);
        
        echo json_encode(['success' => true, 'message' => 'Schedule deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete schedule']);
    }
}

function handleAddService($conn) {
    // All fields are now required (including description).
    // Treat "0" as a legal cost/duration value — do not fail empty() on those.
    $required = ['name', 'description', 'duration', 'cost'];
    foreach ($required as $field) {
        if (!isset($_POST[$field]) || trim((string)$_POST[$field]) === '') {
            echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
            return;
        }
    }

    $name = sanitize_input($_POST['name']);
    $description = sanitize_input($_POST['description']);
    if (strlen($description) < 10) {
        echo json_encode(['success' => false, 'message' => 'Description must be at least 10 characters']);
        return;
    }

    // Strict numeric validation to reject strings like "abc" or "10abc"
    if (!is_numeric($_POST['duration'])) {
        echo json_encode(['success' => false, 'message' => 'Duration must be a number']);
        return;
    }
    if (!is_numeric($_POST['cost'])) {
        echo json_encode(['success' => false, 'message' => 'Cost must be a number']);
        return;
    }
    $duration = intval($_POST['duration']);
    $cost = floatval($_POST['cost']);
    
    // Validate name length
    if (strlen($name) > 100) {
        echo json_encode(['success' => false, 'message' => 'Service name too long (max 100 characters)']);
        return;
    }
    
    // Validate duration
    if ($duration <= 0 || $duration > 480) {
        echo json_encode(['success' => false, 'message' => 'Duration must be between 1 and 480 minutes']);
        return;
    }
    
    // Validate cost
    if ($cost < 0 || $cost > 999999.99) {
        echo json_encode(['success' => false, 'message' => 'Invalid cost amount']);
        return;
    }
    
    // Check if service already exists
    $check_query = "SELECT id FROM services WHERE name = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "s", $name);
    mysqli_stmt_execute($check_stmt);
    mysqli_stmt_store_result($check_stmt);
    
    if (mysqli_stmt_num_rows($check_stmt) > 0) {
        echo json_encode(['success' => false, 'message' => 'Service with this name already exists']);
        return;
    }
    
    $query = "INSERT INTO services (name, description, duration, cost) VALUES (?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ssid", $name, $description, $duration, $cost);
    
    if (mysqli_stmt_execute($stmt)) {
        // Log activity
        $user_id = $_SESSION['user_id'];
        $details = "Added new service: $name (\$$cost, {$duration}min)";
        $ip_address = $_SERVER['REMOTE_ADDR'];
        
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'CREATE', ?, ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        mysqli_stmt_bind_param($log_stmt, "iss", $user_id, $details, $ip_address);
        mysqli_stmt_execute($log_stmt);
        
        echo json_encode(['success' => true, 'message' => 'Service added successfully']);
    } else {
        error_log("Service add error: " . mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => 'Failed to add service']);
    }
}

function handleToggleServiceStatus($conn) {
    if (!isset($_POST['service_id'])) {
        echo json_encode(['success' => false, 'message' => 'Missing service ID']);
        return;
    }
    
    $service_id = intval($_POST['service_id']);
    
    $query = "SELECT name, active FROM services WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $service_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $service = mysqli_fetch_assoc($result);
    
    if (!$service) {
        echo json_encode(['success' => false, 'message' => 'Service not found']);
        return;
    }
    
    $new_status = $service['active'] ? 0 : 1;
    $status_text = $new_status ? 'Active' : 'Inactive';
    
    $update_query = "UPDATE services SET active = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $update_query);
    mysqli_stmt_bind_param($stmt, "ii", $new_status, $service_id);
    
    if (mysqli_stmt_execute($stmt)) {
        // Log activity
        $user_id = $_SESSION['user_id'];
        $details = "Changed service status: {$service['name']} to $status_text";
        $ip_address = $_SERVER['REMOTE_ADDR'];
        
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'UPDATE', ?, ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        mysqli_stmt_bind_param($log_stmt, "iss", $user_id, $details, $ip_address);
        mysqli_stmt_execute($log_stmt);
        
        echo json_encode(['success' => true, 'message' => 'Service status updated', 'new_status' => $status_text]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update service status']);
    }
}

function handleUpdateClinicSettings($conn) {
    $allowed_settings = [
        'clinic_name', 'clinic_phone', 'clinic_email',
        'clinic_address', 'appointment_reminder_hours',
        // Landing page footer "Get In Touch"
        'contact_address', 'contact_phone', 'contact_email', 'contact_hours',
    ];
    
    $success = true;
    $errors = [];
    $updated_settings = [];
    
    foreach ($allowed_settings as $key) {
        if (isset($_POST[$key])) {
            $value = sanitize_input($_POST[$key]);
            
            // Special validation for specific fields
            if ($key === 'appointment_reminder_hours') {
                if (!is_numeric($value) || $value < 1 || $value > 168) {
                    $errors[] = "Reminder hours must be between 1-168";
                    continue;
                }
            }
            
            if (($key === 'clinic_email' || $key === 'contact_email') && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Invalid email format for $key";
                continue;
            }

            // Upsert so newly-added setting keys are created on first save.
            $query = "INSERT INTO clinic_settings (setting_key, setting_value, setting_type) VALUES (?, ?, 'STRING')
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "ss", $key, $value);
            
            if (mysqli_stmt_execute($stmt)) {
                $updated_settings[] = $key;
            } else {
                $success = false;
                $errors[] = "Failed to update $key";
            }
        }
    }
    
    if ($success && empty($errors)) {
        // Log activity
        $user_id = $_SESSION['user_id'];
        $details = "Updated clinic settings: " . implode(', ', $updated_settings);
        $ip_address = $_SERVER['REMOTE_ADDR'];
        
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'UPDATE', ?, ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        mysqli_stmt_bind_param($log_stmt, "iss", $user_id, $details, $ip_address);
        mysqli_stmt_execute($log_stmt);
        
        echo json_encode(['success' => true, 'message' => 'Settings updated successfully']);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Some settings failed to update', 
            'errors' => $errors
        ]);
    }
}

function handleExportPatientData($conn) {
    if (!isset($_POST['patient_id']) || !isset($_POST['doctor_id'])) {
        echo json_encode(['success' => false, 'message' => 'Missing patient or doctor ID']);
        return;
    }
    
    $patient_id = intval($_POST['patient_id']);
    $doctor_id = intval($_POST['doctor_id']);
    $format = sanitize_input($_POST['format'] ?? 'json');
    
    $result = exportPatientData($conn, $patient_id, $doctor_id, $format);
    
    if ($result['success']) {
        // For JSON format, return the data
        if ($format === 'json') {
            echo json_encode([
                'success' => true,
                'message' => 'Data exported successfully',
                'data' => $result['data']
            ]);
        } else {
            // For CSV or other formats, you would generate a file here
            echo json_encode([
                'success' => true,
                'message' => 'Export initiated. Download will start shortly.'
            ]);
        }
    } else {
        echo json_encode($result);
    }
}

function handleGetDoctors($conn) {
    $doctors = get_all_doctors($conn);

    if (!empty($doctors)) {
        echo json_encode(['success' => true, 'doctors' => $doctors]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No doctors found']);
    }
}

function handleAddAnnouncement($conn) {
    $required = ['title', 'content'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
            return;
        }
    }

    $title = sanitize_input($_POST['title']);
    $content = $_POST['content']; // Allow HTML content
    $category = sanitize_input($_POST['category'] ?? 'GENERAL');
    $priority = sanitize_input($_POST['priority'] ?? 'NORMAL');
    $created_by = $_SESSION['user_id'];

    // Validate category
    $allowed_categories = ['GENERAL', 'MAINTENANCE', 'HEALTH_ADVISORY', 'EVENT', 'PROMOTION'];
    if (!in_array($category, $allowed_categories)) {
        echo json_encode(['success' => false, 'message' => 'Invalid category']);
        return;
    }

    // Validate priority
    $allowed_priorities = ['LOW', 'NORMAL', 'HIGH', 'URGENT'];
    if (!in_array($priority, $allowed_priorities)) {
        echo json_encode(['success' => false, 'message' => 'Invalid priority']);
        return;
    }

    if (strlen($title) > 200) {
        echo json_encode(['success' => false, 'message' => 'Title too long (max 200 characters)']);
        return;
    }

    $query = "INSERT INTO announcements (title, content, category, priority, is_active, published_at, created_by)
              VALUES (?, ?, ?, ?, 1, NOW(), ?)";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ssssi", $title, $content, $category, $priority, $created_by);

    if (mysqli_stmt_execute($stmt)) {
        // Log activity
        $details = "Created announcement: $title";
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'CREATE', ?, ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        mysqli_stmt_bind_param($log_stmt, "iss", $created_by, $details, $ip_address);
        mysqli_stmt_execute($log_stmt);

        echo json_encode(['success' => true, 'message' => 'Announcement published successfully']);
    } else {
        error_log("Announcement add error: " . mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => 'Failed to create announcement']);
    }
}

function handleToggleAnnouncement($conn) {
    if (!isset($_POST['announcement_id'])) {
        echo json_encode(['success' => false, 'message' => 'Missing announcement ID']);
        return;
    }

    $announcement_id = intval($_POST['announcement_id']);
    $is_active = intval($_POST['is_active'] ?? 0);

    $query = "UPDATE announcements SET is_active = ?, updated_at = NOW() WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $is_active, $announcement_id);

    if (mysqli_stmt_execute($stmt)) {
        $status_text = $is_active ? 'activated' : 'deactivated';
        // Log activity
        $user_id = $_SESSION['user_id'];
        $details = "Announcement $status_text (ID: $announcement_id)";
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'UPDATE', ?, ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        mysqli_stmt_bind_param($log_stmt, "iss", $user_id, $details, $ip_address);
        mysqli_stmt_execute($log_stmt);

        echo json_encode(['success' => true, 'message' => "Announcement $status_text"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update announcement']);
    }
}

function handleDeleteAnnouncement($conn) {
    if (!isset($_POST['announcement_id'])) {
        echo json_encode(['success' => false, 'message' => 'Missing announcement ID']);
        return;
    }

    $announcement_id = intval($_POST['announcement_id']);

    // Get title for logging
    $check_query = "SELECT title FROM announcements WHERE id = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "i", $announcement_id);
    mysqli_stmt_execute($check_stmt);
    $result = mysqli_stmt_get_result($check_stmt);
    $announcement = mysqli_fetch_assoc($result);

    $delete_query = "DELETE FROM announcements WHERE id = ?";
    $stmt = mysqli_prepare($conn, $delete_query);
    mysqli_stmt_bind_param($stmt, "i", $announcement_id);

    if (mysqli_stmt_execute($stmt)) {
        // Log activity
        $user_id = $_SESSION['user_id'];
        $title = $announcement ? $announcement['title'] : 'Unknown';
        $details = "Deleted announcement: $title (ID: $announcement_id)";
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'DELETE', ?, ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        mysqli_stmt_bind_param($log_stmt, "iss", $user_id, $details, $ip_address);
        mysqli_stmt_execute($log_stmt);

        echo json_encode(['success' => true, 'message' => 'Announcement deleted']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete announcement']);
    }
}

function handleUpdateAppointmentStatus($conn) {
    $appointment_id = intval($_POST['appointment_id'] ?? 0);
    $new_status = strtoupper(trim($_POST['status'] ?? ''));

    $allowed = ['SCHEDULED', 'CONFIRMED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED', 'NO_SHOW', 'CANCELLATION_REQUESTED'];
    if ($appointment_id <= 0 || !in_array($new_status, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid appointment or status']);
        return;
    }

    // Verify appointment exists and pull metadata for notifications.
    $check = mysqli_prepare(
        $conn,
        "SELECT a.status, a.appointment_date, a.appointment_time, a.doctor_id, p.parent_id
         FROM appointments a JOIN patients p ON a.patient_id = p.id WHERE a.id = ?"
    );
    mysqli_stmt_bind_param($check, "i", $appointment_id);
    mysqli_stmt_execute($check);
    $res = mysqli_stmt_get_result($check);
    if (!($row = mysqli_fetch_assoc($res))) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
        return;
    }
    $old_status = $row['status'];
    $parent_id  = intval($row['parent_id']);
    $doctor_id  = intval($row['doctor_id']);
    $when       = date('M j, Y', strtotime($row['appointment_date'])) . ' at ' . date('h:i A', strtotime($row['appointment_time']));

    $extra_sql = '';
    if ($new_status === 'CANCELLED') {
        $extra_sql = ', cancelled_at = NOW()';
    }
    $stmt = mysqli_prepare($conn, "UPDATE appointments SET status = ?, updated_at = NOW()" . $extra_sql . " WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $new_status, $appointment_id);

    if (mysqli_stmt_execute($stmt)) {
        // Audit log
        $user_id = $_SESSION['user_id'];
        $details = "Updated appointment #{$appointment_id} status: {$old_status} → {$new_status}";
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $log = mysqli_prepare($conn, "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'UPDATE', ?, ?)");
        mysqli_stmt_bind_param($log, "iss", $user_id, $details, $ip);
        mysqli_stmt_execute($log);

        // Notify the parent, the assigned doctor, and clinic staff (admins +
        // superadmins) on every status transition so all parties see the
        // appointment lifecycle: confirmed, rescheduled, completed, etc.
        if (function_exists('send_appointment_notification')) {
            $title = 'Appointment ' . strtolower($new_status);
            switch ($new_status) {
                case 'CONFIRMED':
                    $title = 'Appointment approved';
                    $parent_msg = "Good news — your appointment booking on $when has been APPROVED and confirmed.";
                    $doctor_msg = "Appointment on $when has been confirmed.";
                    $staff_msg  = "Appointment #{$appointment_id} on $when was confirmed.";
                    break;
                case 'CANCELLED':
                    if ($old_status === 'CANCELLATION_REQUESTED') {
                        $title = 'Cancellation approved';
                        $parent_msg = "Your cancellation request for the appointment on $when has been approved.";
                        $doctor_msg = "A cancellation request was approved for the appointment on $when.";
                        $staff_msg  = "Cancellation approved for appointment #{$appointment_id} on $when.";
                    } else {
                        $title = 'Appointment cancelled';
                        $parent_msg = "Your appointment on $when has been CANCELLED. If you did not request this, please contact the clinic.";
                        $doctor_msg = "The appointment on $when has been cancelled.";
                        $staff_msg  = "Appointment #{$appointment_id} on $when was cancelled.";
                    }
                    break;
                case 'COMPLETED':
                    $title = 'Appointment completed';
                    $parent_msg = "The appointment on $when has been marked as completed. Thank you for visiting AlagApp Clinic.";
                    $doctor_msg = "The appointment on $when has been marked completed.";
                    $staff_msg  = "Appointment #{$appointment_id} on $when was completed.";
                    break;
                case 'IN_PROGRESS':
                    $title = 'Appointment in progress';
                    $parent_msg = "The appointment on $when is now in progress with the doctor.";
                    $doctor_msg = "You have marked the appointment on $when as in progress.";
                    $staff_msg  = "Appointment #{$appointment_id} on $when started.";
                    break;
                case 'NO_SHOW':
                    $title = 'Appointment marked as no-show';
                    $parent_msg = "The appointment on $when was marked as a NO-SHOW. Please contact the clinic if this is incorrect.";
                    $doctor_msg = "The appointment on $when was marked as a no-show.";
                    $staff_msg  = "Appointment #{$appointment_id} on $when was marked NO_SHOW.";
                    break;
                case 'SCHEDULED':
                    // Treat returning to SCHEDULED as a reschedule confirmation.
                    $title = 'Appointment rescheduled';
                    $parent_msg = "Your appointment is now rescheduled to $when. We'll see you then.";
                    $doctor_msg = "An appointment on your schedule has been rescheduled to $when.";
                    $staff_msg  = "Appointment #{$appointment_id} rescheduled to $when.";
                    break;
                default:
                    $parent_msg = "Your appointment on $when is now: $new_status.";
                    if ($old_status === 'CANCELLATION_REQUESTED') {
                        $parent_msg = "Your cancellation request for the appointment on $when was reviewed; the appointment remains $new_status.";
                    }
                    $doctor_msg = "Appointment on $when status changed to $new_status.";
                    $staff_msg  = "Appointment #{$appointment_id} on $when: $old_status → $new_status.";
            }
            if ($parent_id) {
                send_appointment_notification($conn, $parent_id, $title, $parent_msg, $appointment_id);
            }
            if ($doctor_id) {
                send_appointment_notification($conn, $doctor_id, $title, $doctor_msg, $appointment_id);
            }
            // Fan out to clinic staff (admins + superadmins) — skip the
            // current actor so they don't email themselves.
            if (function_exists('get_admin_user_ids')) {
                foreach (get_admin_user_ids($conn) as $aid) {
                    if ((int) $aid === (int) $user_id) continue;
                    send_appointment_notification($conn, (int) $aid, $title, $staff_msg, $appointment_id);
                }
            }
        }

        echo json_encode(['success' => true, 'message' => 'Appointment status updated', 'new_status' => $new_status]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update status']);
    }
}

/**
 * SuperAdmin Edit User: update core profile + role/status, then email the user.
 *
 * Doctor accounts can have profile fields edited but not their role; only
 * SUPERADMIN can call this (already gated above).
 */
function handleUpdateUserProfile($conn) {
    if (!isset($_POST['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Missing user ID']);
        return;
    }

    $user_id = intval($_POST['user_id']);
    $current_user_id = (int) ($_SESSION['user_id'] ?? 0);

    $target_stmt = mysqli_prepare($conn, "SELECT id, first_name, last_name, email, user_type, status FROM users WHERE id = ?");
    if (!$target_stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
        return;
    }
    mysqli_stmt_bind_param($target_stmt, "i", $user_id);
    mysqli_stmt_execute($target_stmt);
    $target = mysqli_fetch_assoc(mysqli_stmt_get_result($target_stmt));
    if (!$target) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        return;
    }

    $first_name = sanitize_input($_POST['first_name'] ?? '');
    $last_name = sanitize_input($_POST['last_name'] ?? '');
    $email = sanitize_input($_POST['email'] ?? '');
    $phone = sanitize_input($_POST['phone'] ?? '');
    $date_of_birth = sanitize_input($_POST['date_of_birth'] ?? '') ?: null;
    $gender = strtoupper(sanitize_input($_POST['gender'] ?? '')) ?: null;
    $address = trim($_POST['address'] ?? '');
    $emergency_contact_name = sanitize_input($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_phone = sanitize_input($_POST['emergency_contact_phone'] ?? '');
    $new_role = strtoupper(sanitize_input($_POST['user_type'] ?? ''));
    $new_status = strtolower(sanitize_input($_POST['status'] ?? ''));
    // Optional new password — when present the superadmin is overriding it.
    // Do NOT sanitize: passwords may legitimately contain HTML/special chars.
    $new_password = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';

    if ($first_name === '' || $last_name === '' || $email === '') {
        echo json_encode(['success' => false, 'message' => 'First name, last name and email are required.']);
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
        return;
    }
    if (strlen($first_name) > 50 || strlen($last_name) > 50 || strlen($email) > 100) {
        echo json_encode(['success' => false, 'message' => 'Name/email is too long.']);
        return;
    }

    // Validate the new password if one was provided. Empty string = keep existing.
    if ($new_password !== '') {
        if (strlen($new_password) < 8 || strlen($new_password) > 128) {
            echo json_encode(['success' => false, 'message' => 'Password must be between 8 and 128 characters.']);
            return;
        }
        if (!preg_match('/[A-Z]/', $new_password)
            || !preg_match('/[a-z]/', $new_password)
            || !preg_match('/[0-9]/', $new_password)
            || !preg_match('/[!@#$%^&*()_+\-=\[\]{}|;:,.<>?]/', $new_password)) {
            echo json_encode(['success' => false, 'message' => 'Password must contain upper, lower, digit, and symbol.']);
            return;
        }
    }

    $allowed_roles = ['PARENT', 'DOCTOR', 'DOCTOR_OWNER', 'ADMIN', 'SUPERADMIN'];
    $allowed_statuses = ['active', 'inactive', 'suspended', 'pending'];
    $current_role = strtoupper($target['user_type']);
    $current_status = strtolower($target['status']);

    if ($new_role === '' || !in_array($new_role, $allowed_roles, true)) {
        $new_role = $current_role;
    }
    if ($new_status === '' || !in_array($new_status, $allowed_statuses, true)) {
        $new_status = $current_status;
    }

    $isDoctor = ($current_role === 'DOCTOR' || $current_role === 'DOCTOR_OWNER');
    if ($isDoctor && $new_role !== $current_role) {
        echo json_encode(['success' => false, 'message' => "A doctor's role cannot be changed."]);
        return;
    }
    if (!$isDoctor && ($new_role === 'DOCTOR' || $new_role === 'DOCTOR_OWNER')) {
        echo json_encode(['success' => false, 'message' => 'Promoting a user into a doctor role must be done via Add User.']);
        return;
    }
    if ($user_id === $current_user_id) {
        $new_role = $current_role;
        $new_status = $current_status;
    }

    // Email uniqueness check
    if (strtolower($email) !== strtolower($target['email'])) {
        $dupe = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? AND id <> ?");
        mysqli_stmt_bind_param($dupe, "si", $email, $user_id);
        mysqli_stmt_execute($dupe);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($dupe))) {
            echo json_encode(['success' => false, 'message' => 'That email address is already in use.']);
            return;
        }
    }

    $update = mysqli_prepare($conn,
        "UPDATE users
            SET first_name = ?, last_name = ?, email = ?, phone = ?,
                date_of_birth = ?, gender = ?, address = ?,
                emergency_contact_name = ?, emergency_contact_phone = ?,
                user_type = ?, status = ?
          WHERE id = ?");
    if (!$update) {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
        return;
    }
    mysqli_stmt_bind_param(
        $update, "sssssssssssi",
        $first_name, $last_name, $email, $phone,
        $date_of_birth, $gender, $address,
        $emergency_contact_name, $emergency_contact_phone,
        $new_role, $new_status, $user_id
    );
    if (!mysqli_stmt_execute($update)) {
        error_log('handleUpdateUserProfile update failed: ' . mysqli_stmt_error($update));
        echo json_encode(['success' => false, 'message' => 'Failed to update user.']);
        return;
    }

    $password_changed = false;
    if ($new_password !== '') {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $pwd_stmt = mysqli_prepare(
            $conn,
            "UPDATE users
                SET password = ?,
                    password_reset_token = NULL,
                    password_reset_expires = NULL,
                    force_password_change = 1,
                    login_attempts = 0,
                    locked_until = NULL
              WHERE id = ?"
        );
        if (!$pwd_stmt) {
            error_log('handleUpdateUserProfile password prepare failed: ' . mysqli_error($conn));
            echo json_encode(['success' => false, 'message' => 'Failed to update password.']);
            return;
        }
        mysqli_stmt_bind_param($pwd_stmt, "si", $hashed, $user_id);
        if (!mysqli_stmt_execute($pwd_stmt)) {
            error_log('handleUpdateUserProfile password update failed: ' . mysqli_stmt_error($pwd_stmt));
            echo json_encode(['success' => false, 'message' => 'Failed to update password.']);
            return;
        }
        $password_changed = true;
    }

    // Audit log
    $details = "Updated user profile for {$target['first_name']} {$target['last_name']} (id={$user_id})";
    $changes = [];
    if ($current_role !== $new_role) $changes[] = "role $current_role→$new_role";
    if ($current_status !== $new_status) $changes[] = "status $current_status→$new_status";
    if (strtolower($email) !== strtolower($target['email'])) $changes[] = "email changed";
    if ($password_changed) $changes[] = "password reset";
    if (!empty($changes)) $details .= ' [' . implode(', ', $changes) . ']';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $log = mysqli_prepare($conn, "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'UPDATE', ?, ?)");
    mysqli_stmt_bind_param($log, "iss", $current_user_id, $details, $ip_address);
    mysqli_stmt_execute($log);

    // Email the user that their account was updated by a SuperAdmin. When the
    // password was changed, surface the new password in the email so the user
    // can log in with it (they will be required to change it on next login).
    if (function_exists('send_user_notification')) {
        $msg = "Your AlagApp Clinic account profile was updated by a system administrator.";
        if (!empty($changes)) $msg .= "\n\nChanges: " . implode(', ', $changes);
        if ($password_changed) {
            $msg .= "\n\nYour new password is: " . $new_password;
            $msg .= "\nYou will be required to change it on your next login.";
        }
        $msg .= "\n\nIf you did not expect this change, please contact the clinic immediately.";
        send_user_notification($conn, $user_id, 'Your account was updated', $msg, 'SYSTEM');
    }

    echo json_encode(['success' => true, 'message' => 'User updated successfully.']);
}

// SuperAdmin: create a child/patient profile linked to an existing parent
// account. Mirrors the parent-side "Add Child" flow but lets staff act on
// behalf of any parent.
function handleAddPatient($conn) {
    $parent_id = intval($_POST['parent_id'] ?? 0);
    $first_name = sanitize_input($_POST['first_name'] ?? '');
    $last_name = sanitize_input($_POST['last_name'] ?? '');
    $dob = sanitize_input($_POST['date_of_birth'] ?? '');
    $gender = strtoupper(sanitize_input($_POST['gender'] ?? ''));
    $blood_type = sanitize_input($_POST['blood_type'] ?? '');
    $height_raw = $_POST['height'] ?? '';
    $weight_raw = $_POST['weight'] ?? '';
    $allergies = trim($_POST['allergies'] ?? '');
    $conditions = trim($_POST['medical_conditions'] ?? '');

    if ($parent_id <= 0 || $first_name === '' || $last_name === '' || $dob === '' || $gender === '') {
        echo json_encode(['success' => false, 'message' => 'Parent, name, date of birth and gender are required.']);
        return;
    }
    if (!in_array($gender, ['MALE', 'FEMALE', 'OTHER'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid gender.']);
        return;
    }
    if (!strtotime($dob) || strtotime($dob) > time()) {
        echo json_encode(['success' => false, 'message' => 'Invalid date of birth.']);
        return;
    }
    if ($blood_type !== '' && !in_array($blood_type, ['A+','A-','B+','B-','AB+','AB-','O+','O-'], true)) {
        $blood_type = '';
    }
    $height = ($height_raw !== '' && is_numeric($height_raw)) ? floatval($height_raw) : null;
    $weight = ($weight_raw !== '' && is_numeric($weight_raw)) ? floatval($weight_raw) : null;

    // Confirm the parent_id actually maps to a PARENT-role user.
    $pstmt = mysqli_prepare($conn, "SELECT id FROM users WHERE id = ? AND user_type = 'PARENT' LIMIT 1");
    mysqli_stmt_bind_param($pstmt, "i", $parent_id);
    mysqli_stmt_execute($pstmt);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($pstmt))) {
        echo json_encode(['success' => false, 'message' => 'Selected parent account is invalid.']);
        return;
    }

    $blood_type_val = $blood_type === '' ? null : $blood_type;
    $stmt = mysqli_prepare($conn,
        "INSERT INTO patients (parent_id, first_name, last_name, date_of_birth, gender, blood_type, height, weight, allergies, medical_conditions)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
        return;
    }
    mysqli_stmt_bind_param($stmt, "isssssddss",
        $parent_id, $first_name, $last_name, $dob, $gender, $blood_type_val, $height, $weight, $allergies, $conditions);
    if (!mysqli_stmt_execute($stmt)) {
        error_log('handleAddPatient execute failed: ' . mysqli_stmt_error($stmt));
        echo json_encode(['success' => false, 'message' => 'Failed to add child profile.']);
        return;
    }
    $patient_id = mysqli_insert_id($conn);

    // Audit log
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $details = "Added child profile {$first_name} {$last_name} (id={$patient_id}) under parent {$parent_id}";
    $log = mysqli_prepare($conn, "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'CREATE', ?, ?)");
    mysqli_stmt_bind_param($log, "iss", $_SESSION['user_id'], $details, $ip);
    mysqli_stmt_execute($log);

    echo json_encode(['success' => true, 'message' => 'Child profile created successfully.']);
}

// SuperAdmin: create an appointment for any patient/doctor combination.
function handleCreateAppointment($conn) {
    $patient_id = intval($_POST['patient_id'] ?? 0);
    $doctor_id = intval($_POST['doctor_id'] ?? 0);
    $date = sanitize_input($_POST['appointment_date'] ?? '');
    $time = sanitize_input($_POST['appointment_time'] ?? '');
    $type = strtoupper(sanitize_input($_POST['type'] ?? 'CONSULTATION'));
    $reason = trim($_POST['reason'] ?? '');

    if ($patient_id <= 0 || $doctor_id <= 0 || $date === '' || $time === '') {
        echo json_encode(['success' => false, 'message' => 'Patient, doctor, date and time are required.']);
        return;
    }
    $dateTs = strtotime($date);
    if ($dateTs === false || $dateTs < strtotime(date('Y-m-d'))) {
        echo json_encode(['success' => false, 'message' => 'Appointment date cannot be in the past.']);
        return;
    }
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
        echo json_encode(['success' => false, 'message' => 'Invalid time format.']);
        return;
    }
    $allowed_types = ['CONSULTATION', 'VACCINATION', 'CHECKUP', 'FOLLOW_UP', 'EMERGENCY', 'OTHER'];
    if (!in_array($type, $allowed_types, true)) $type = 'CONSULTATION';

    // Validate doctor + patient existence
    $check = mysqli_prepare($conn, "SELECT 1 FROM users WHERE id = ? AND user_type IN ('DOCTOR','DOCTOR_OWNER') AND status = 'active' LIMIT 1");
    mysqli_stmt_bind_param($check, "i", $doctor_id);
    mysqli_stmt_execute($check);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($check))) {
        echo json_encode(['success' => false, 'message' => 'Selected doctor is invalid or inactive.']);
        return;
    }
    $check2 = mysqli_prepare($conn, "SELECT 1 FROM patients WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($check2, "i", $patient_id);
    mysqli_stmt_execute($check2);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($check2))) {
        echo json_encode(['success' => false, 'message' => 'Selected patient is invalid.']);
        return;
    }

    // Conflict check
    $conflict = mysqli_prepare($conn, "SELECT id FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status IN ('SCHEDULED','CONFIRMED')");
    mysqli_stmt_bind_param($conflict, "iss", $doctor_id, $date, $time);
    mysqli_stmt_execute($conflict);
    if (mysqli_num_rows(mysqli_stmt_get_result($conflict)) > 0) {
        echo json_encode(['success' => false, 'message' => 'That time slot is already booked for the selected doctor.']);
        return;
    }

    $creator = (int) ($_SESSION['user_id'] ?? 0);
    if (strlen($reason) > 1000) $reason = substr($reason, 0, 1000);
    $ins = mysqli_prepare($conn,
        "INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, type, reason, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, 'CONFIRMED', ?)");
    if (!$ins) {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
        return;
    }
    mysqli_stmt_bind_param($ins, "iissssi", $patient_id, $doctor_id, $date, $time, $type, $reason, $creator);
    if (!mysqli_stmt_execute($ins)) {
        error_log('handleCreateAppointment execute failed: ' . mysqli_stmt_error($ins));
        echo json_encode(['success' => false, 'message' => 'Failed to create appointment.']);
        return;
    }
    $appt_id = mysqli_insert_id($conn);

    // Audit log
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $details = "Created appointment #$appt_id (patient=$patient_id, doctor=$doctor_id, $date $time, $type)";
    $log = mysqli_prepare($conn, "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'CREATE', ?, ?)");
    mysqli_stmt_bind_param($log, "iss", $creator, $details, $ip);
    mysqli_stmt_execute($log);

    // Notify parent + doctor
    if (function_exists('send_appointment_notification')) {
        $parent_lookup = mysqli_prepare($conn, "SELECT parent_id FROM patients WHERE id = ?");
        mysqli_stmt_bind_param($parent_lookup, "i", $patient_id);
        mysqli_stmt_execute($parent_lookup);
        $pres = mysqli_fetch_assoc(mysqli_stmt_get_result($parent_lookup));
        $parent_id = $pres ? intval($pres['parent_id']) : 0;
        $when = date('M j, Y', $dateTs) . ' at ' . date('h:i A', strtotime($time));
        if ($parent_id > 0) {
            send_appointment_notification($conn, $parent_id, 'Appointment scheduled',
                "An appointment was scheduled for your child on $when ($type) by clinic staff.", $appt_id);
        }
        send_appointment_notification($conn, $doctor_id, 'New appointment scheduled',
            "Clinic staff scheduled an appointment for $when ($type).", $appt_id);
    }

    echo json_encode(['success' => true, 'message' => 'Appointment created successfully.']);
}

/**
 * Return a single patient's full summary: profile, parent/guardian, appointments,
 * vaccinations, recent consultations, and recent prescriptions. SuperAdmin only.
 */
function handleGetPatientSummary($conn) {
    $patient_id = intval($_POST['patient_id'] ?? 0);
    if ($patient_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid patient id']);
        return;
    }

    // Patient + parent
    $sql = "SELECT p.*, u.id AS parent_id, u.first_name AS parent_first_name,
                   u.last_name AS parent_last_name, u.email AS parent_email,
                   u.phone AS parent_phone
            FROM patients p
            JOIN users u ON u.id = p.parent_id
            WHERE p.id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
        return;
    }
    mysqli_stmt_bind_param($stmt, "i", $patient_id);
    mysqli_stmt_execute($stmt);
    $patient = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$patient) {
        echo json_encode(['success' => false, 'message' => 'Patient not found']);
        return;
    }

    // Compute age in years
    $age = null;
    if (!empty($patient['date_of_birth']) && $patient['date_of_birth'] !== '0000-00-00') {
        try {
            $dob = new DateTime($patient['date_of_birth']);
            $age = $dob->diff(new DateTime('today'))->y;
        } catch (Exception $e) {
            $age = null;
        }
    }

    // Appointments — most recent 20
    $appointments = [];
    $stmt = mysqli_prepare(
        $conn,
        "SELECT a.id, a.appointment_date, a.appointment_time, a.type, a.status, a.reason,
                d.first_name AS doctor_first_name, d.last_name AS doctor_last_name
         FROM appointments a
         LEFT JOIN users d ON d.id = a.doctor_id
         WHERE a.patient_id = ?
         ORDER BY a.appointment_date DESC, a.appointment_time DESC
         LIMIT 20"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $patient_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($r = mysqli_fetch_assoc($res)) { $appointments[] = $r; }
    }

    // Vaccinations
    $vaccinations = [];
    $stmt = mysqli_prepare(
        $conn,
        "SELECT vr.vaccine_name, vr.dose_number, vr.total_doses, vr.administration_date,
                vr.next_due_date, vr.status, vr.site, vr.notes,
                u.first_name AS doctor_first_name, u.last_name AS doctor_last_name
         FROM vaccination_records vr
         LEFT JOIN users u ON u.id = vr.administered_by
         WHERE vr.patient_id = ?
         ORDER BY vr.administration_date DESC, vr.id DESC
         LIMIT 25"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $patient_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($r = mysqli_fetch_assoc($res)) { $vaccinations[] = $r; }
    }

    // Consultations (best-effort: schema varies, so guard with a try/catch)
    $consultations = [];
    try {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT cn.id, cn.diagnosis, cn.notes, cn.created_at,
                    u.first_name AS doctor_first_name, u.last_name AS doctor_last_name
             FROM consultation_notes cn
             LEFT JOIN users u ON u.id = cn.doctor_id
             WHERE cn.patient_id = ?
             ORDER BY cn.created_at DESC
             LIMIT 10"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $patient_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($r = mysqli_fetch_assoc($res)) { $consultations[] = $r; }
        }
    } catch (Throwable $e) {
        // Schema mismatch — return empty consultations rather than fail the modal.
        error_log('handleGetPatientSummary consultations error: ' . $e->getMessage());
    }

    // Prescriptions (best-effort similarly)
    $prescriptions = [];
    try {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT pr.id, pr.diagnosis, pr.created_at,
                    u.first_name AS doctor_first_name, u.last_name AS doctor_last_name
             FROM prescriptions pr
             LEFT JOIN users u ON u.id = pr.doctor_id
             WHERE pr.patient_id = ?
             ORDER BY pr.created_at DESC
             LIMIT 10"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $patient_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($r = mysqli_fetch_assoc($res)) { $prescriptions[] = $r; }
        }
    } catch (Throwable $e) {
        error_log('handleGetPatientSummary prescriptions error: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'patient' => [
                'id' => (int) $patient['id'],
                'first_name' => $patient['first_name'],
                'last_name' => $patient['last_name'],
                'date_of_birth' => $patient['date_of_birth'],
                'age' => $age,
                'gender' => $patient['gender'],
                'blood_type' => $patient['blood_type'],
                'height' => $patient['height'],
                'weight' => $patient['weight'],
                'allergies' => $patient['allergies'],
                'medical_conditions' => $patient['medical_conditions'],
                'special_notes' => $patient['special_notes'],
                'created_at' => $patient['created_at'],
            ],
            'parent' => [
                'id' => (int) $patient['parent_id'],
                'first_name' => $patient['parent_first_name'],
                'last_name' => $patient['parent_last_name'],
                'email' => $patient['parent_email'],
                'phone' => $patient['parent_phone'],
            ],
            'appointments' => $appointments,
            'vaccinations' => $vaccinations,
            'consultations' => $consultations,
            'prescriptions' => $prescriptions,
        ],
    ]);
}
?>