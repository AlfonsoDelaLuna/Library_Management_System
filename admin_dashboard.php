<?php
session_start();
include 'db_connection.php';

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// --- Function to get image path (uploads folder ONLY) ---
function getStudentImagePath($studentNumber)
{
    $uploadsDir = "uploads/";
    $extensions = ['jpg', 'jpeg', 'png', 'gif']; // Allowed extensions

    foreach ($extensions as $ext) {
        $image_path = $uploadsDir . $studentNumber . "." . $ext;
        if (file_exists($image_path)) {
            return $image_path; // Return the path if found
        }
    }

    return "images/no_image.png"; // Default image
}

// Initialize variables.
$student_number = isset($_SESSION['student_number']) ? $_SESSION['student_number'] : '';
$last_name = isset($_SESSION['last_name']) ? $_SESSION['last_name'] : '';
$first_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] : '';
$middle_name = isset($_SESSION['middle_name']) ? $_SESSION['middle_name'] : '';
$course = isset($_SESSION['course']) ? $_SESSION['course'] : '';

$errors = isset($_SESSION['errors']) ? $_SESSION['errors'] : [];
$success_message = '';
$excel_message = '';

//----------------------------------------------------------------
// Login Records Actions
//----------------------------------------------------------------
if (isset($_POST['download_excel_login'])) {
    $sql = "SELECT student_number, last_name, first_name, middle_name, course, time_in FROM login_records";  // Removed time_out
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'Student Number');
        $sheet->setCellValue('B1', 'Last Name');
        $sheet->setCellValue('C1', 'First Name');
        $sheet->setCellValue('D1', 'Middle Name');
        $sheet->setCellValue('E1', 'Course');
        $sheet->setCellValue('F1', 'Date');       // Date Only
        $sheet->setCellValue('G1', 'Time In');    // Time Only

        $row = 2;
        while ($record = $result->fetch_assoc()) {
            $sheet->setCellValue('A' . $row, $record['student_number']);
            $sheet->setCellValue('B' . $row, $record['last_name']);
            $sheet->setCellValue('C' . $row, $record['first_name']);
            $sheet->setCellValue('D' . $row, $record['middle_name']);
            $sheet->setCellValue('E' . $row, $record['course']);
            $sheet->setCellValue('F' . $row, date('m/d/Y', strtotime($record['time_in']))); // Date Only
            $sheet->setCellValue('G' . $row, date('h:i A', strtotime($record['time_in']))); // Time Only
            $row++;
        }

        $writer = new Xlsx($spreadsheet);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="login_records.xlsx"');
        header('Cache-Control: max-age=0');
        $writer->save('php://output');
        exit();
    } else {
        $excel_message = "No login records found to download.";
    }
}
if (isset($_POST['clear_table_login'])) {
    $sql_truncate = "TRUNCATE TABLE login_records";
    if ($conn->query($sql_truncate) === TRUE) {
        $success_message = "Login records table cleared successfully.";
    } else {
        $errors['db_error'] = "Error clearing login records table: " . $conn->error;
    }

    // Refresh login records after clearing the table (This part is likely not needed anymore)
    $login_records = [];
    $sql = "SELECT * FROM login_records";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $login_records[] = $row;
        }
    } else {
        echo "Error: " . $conn->error;
    }

    // *** KEY CHANGE: Clear any existing errors ***
    unset($_SESSION['errors']);

    header("Location: admin_dashboard.php?tab=loginRecords");
    exit();
}

$student_number_search = '';
$login_records = [];

if (isset($_POST['student_number_search'])) {
    $student_number_search = $_POST['student_number_search'];
    $sql = "SELECT * FROM login_records WHERE student_number LIKE '%$student_number_search%' ORDER BY time_in DESC";
} else {
    $sql = "SELECT * FROM login_records ORDER BY time_in DESC";
}
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $login_records[] = $row;
    }
} else {
    echo "Error: " . $conn->error;
}

//----------------------------------------------------------------
// Student Records Actions
//----------------------------------------------------------------

$student_number_search_students = '';  // Initialize
$students = [];
$edit_student = null;
$edit_errors = [];

// --- Import from Excel ---
if (isset($_POST['import_excel_students'])) {
    if ($_FILES['excel_file']['error'] == 0) {
        $file_name = $_FILES['excel_file']['name'];
        $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
        $allowed_ext = ['xls', 'xlsx', 'csv'];

        if (in_array($file_ext, $allowed_ext)) {
            $inputFileName = $_FILES['excel_file']['tmp_name'];

            try {
                $spreadsheet = IOFactory::load($inputFileName);
                $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
                array_shift($sheetData); // Remove header row

                $errors = [];
                $success_count = 0;

                // --- Collect Student Numbers from Excel ---
                $excel_student_numbers = [];
                foreach ($sheetData as $row) {
                    $student_number = trim((string) ($row[1] ?? ''));
                    if (!empty($student_number)) { // Avoid empty student numbers
                        $excel_student_numbers[] = $student_number;
                    }
                }

                // --- Delete Related Login Records ---
                if (!empty($excel_student_numbers)) { // Only delete if we have student numbers
                    $conn->query("SET FOREIGN_KEY_CHECKS=0"); // Disable FK checks

                    // Build a parameterized query for the IN clause
                    $placeholders = implode(',', array_fill(0, count($excel_student_numbers), '?'));
                    $delete_sql = "DELETE FROM login_records WHERE student_number IN ($placeholders)";
                    $delete_stmt = $conn->prepare($delete_sql);

                    // Bind the student numbers to the placeholders
                    $types = str_repeat('s', count($excel_student_numbers)); // All strings
                    $delete_stmt->bind_param($types, ...$excel_student_numbers);
                    $delete_stmt->execute();
                    $delete_stmt->close();

                    $conn->query("SET FOREIGN_KEY_CHECKS=1"); // Re-enable FK checks
                }


                foreach ($sheetData as $row_number => $row) {
                    $student_number = trim((string) ($row[1] ?? ''));
                    $last_name = trim($row[2] ?? '');
                    $first_name = trim($row[3] ?? '');
                    $middle_name = trim($row[4] ?? '');  //Keep This
                    $course = trim($row[5] ?? ''); //Keep This
                    $section = trim($row[6] ?? '');  // Handle missing section

                    // --- Validation Checks (with row skipping) ---
                    $row_has_error = false; // *** KEY CHANGE: Flag for row errors ***

                    if (
                        empty($student_number) ||
                        strlen($student_number) != 11 ||
                        !ctype_digit($student_number) ||
                        (substr($student_number, 0, 5) != '02000' && substr($student_number, 0, 5) != '10000')
                    ) {
                        $errors['excel_data'][] = "Row " . ($row_number + 2) . ": Invalid student number.";
                        $row_has_error = true; // Set the flag
                    }

                    // Check for duplicates *in the database*
                    if (!$row_has_error) { // *** KEY CHANGE: Only check if no prior error ***
                        $check_stmt = $conn->prepare("SELECT student_number FROM students WHERE student_number = ?");
                        $check_stmt->bind_param("s", $student_number);
                        $check_stmt->execute();
                        $check_stmt->store_result();
                        if ($check_stmt->num_rows > 0) {
                            $errors['excel_data'][] = "Row " . ($row_number + 2) . ": Duplicate student number ($student_number).";
                            $row_has_error = true; // Set the flag
                        }
                        $check_stmt->close();
                    }

                    // Last Name (letters and spaces only)
                    if (!$row_has_error && !preg_match("/^[a-zA-Z\s]+$/", $last_name)) { // *** KEY CHANGE: Only check if no prior error ***
                        $errors['excel_data'][] = "Row " . ($row_number + 2) . ": Invalid last name.";
                        $row_has_error = true;
                    }

                    // First Name (letters and spaces only)
                    if (!$row_has_error && !preg_match("/^[a-zA-Z\s]+$/", $first_name)) { // *** KEY CHANGE: Only check if no prior error ***
                        $errors['excel_data'][] = "Row " . ($row_number + 2) . ": Invalid first name.";
                        $row_has_error = true;
                    }

                    // Middle Name Validation (Excel Import):  <- THIS IS MODIFIED
                    if (!$row_has_error && !preg_match("/^(?:[a-zA-Z\s]+|I\.|I)?$/", $middle_name)) {
                        $errors['excel_data'][] = "Row " . ($row_number + 2) . ": Invalid middle name. Must be a full middle name, middle initial or blank.";
                        $row_has_error = true;
                    }

                    // Course (letters only)  <- THIS IS MODIFIED
                    if (!$row_has_error && !empty($course) && !ctype_alpha($course)) { // Allow empty course
                        $errors['excel_data'][] = "Row " . ($row_number + 2) . ": Invalid course.";
                        $row_has_error = true;
                    }

                    if (!$row_has_error && (empty($last_name) || empty($first_name))) { //Removed empty($course)
                        $errors['excel_data'][] = "Row " . ($row_number + 2) . ": Missing data.";
                        $row_has_error = true;
                    }


                    // --- Insert into Database (ONLY if no errors in the row) ---
                    if (!$row_has_error) { // *** KEY CHANGE:  Insert ONLY if no errors ***
                        $sql = "INSERT INTO students (student_number, last_name, first_name, middle_name, course) VALUES (?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("sssss", $student_number, $last_name, $first_name, $middle_name, $course);
                        if ($stmt->execute()) {
                            $success_count++;
                        } else {
                            $errors['db_error'][] = "Row " . ($row_number + 2) . ": Error inserting record: " . $stmt->error;
                        }
                        $stmt->close();
                    }
                }

                if (!empty($errors['excel_data'])) {
                    $_SESSION['errors'] = $errors;
                    $_SESSION['errors_once'] = true; // Set flag
                }

                if ($success_count > 0) {
                    $success_message = "$success_count student records imported successfully!";
                    // --- CORRECTED LINE: Only hide on success ---
                    $_SESSION['hide_import_form'] = true;
                    unset($_SESSION['errors']); //Clear Error after successfull
                }
            } catch (\Exception $e) {
                $errors['excel_import'] = "Error importing Excel file: " . $e->getMessage();
                $_SESSION['errors'] = $errors;
                $_SESSION['errors_once'] = true; // Set flag
            }
        } else {
            $errors['excel_file'] = "Invalid file type. Only XLS, XLSX, and CSV files are allowed.";
            $_SESSION['errors'] = $errors;
            $_SESSION['errors_once'] = true; // Set flag
        }
    } else {
        $errors['excel_file'] = "Error uploading file.";
        $_SESSION['errors'] = $errors;
        $_SESSION['errors_once'] = true; // Set flag
    }
    //If there's error, import form will not be hidden
    if (!empty($errors)) {
        $_SESSION['hide_import_form'] = false;
    }

    header("Location: admin_dashboard.php?tab=studentRecords");
    exit();
}

// Handle Student Record Editing
if (isset($_POST['edit_student'])) {
    $edit_id = intval($_POST['edit_id']); // Get the ID.  intval() is important for security.

    // Fetch the student's data.  Use a prepared statement for security.
    $stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $edit_student = $result->fetch_assoc();
    }
    $stmt->close();

    //$active_tab = 'studentRecords'; // Keep active tab //REMOVED
}

// Handle Student Record Update (including image)
if (isset($_POST['update_student'])) {
    $edit_id = intval($_POST['edit_id']);
    $student_number = trim($_POST['student_number']); // Get the student number from the form
    $last_name = trim($_POST['last_name']);  // Get last name
    $first_name = trim($_POST['first_name']); // Get first name
    $middle_name = trim($_POST['middle_name']); // Get middle name
    $course = trim($_POST['course']); // Get course

    $old_student_number = ""; // Initialize

    // --- Get the *old* student number (before any update) ---
    $old_stmt = $conn->prepare("SELECT student_number FROM students WHERE id = ?");
    $old_stmt->bind_param("i", $edit_id);
    $old_stmt->execute();
    $old_stmt->store_result();
    if ($old_stmt->num_rows > 0) {
        $old_stmt->bind_result($old_student_number);
        $old_stmt->fetch();
    }
    $old_stmt->close();

    // --- Validation (Student Number) ---
    $errors = [];

    // Student Number
    if (empty($student_number) || strlen($student_number) != 11 || !ctype_digit($student_number) || (substr($student_number, 0, 5) != '02000' && substr($student_number, 0, 5) != '10000')) {
        $errors['student_number'] = "Student number must be 11 digits, numeric, and start with 02000 or 10000.";
    }

    // Check for duplicates, but ALLOW the current student's number
    $check_stmt = $conn->prepare("SELECT student_number FROM students WHERE student_number = ? AND id != ?");
    $check_stmt->bind_param("si", $student_number, $edit_id);
    $check_stmt->execute();
    $check_stmt->store_result();
    if ($check_stmt->num_rows > 0) {
        $errors['student_number'] = "Student number already exists.";
    }
    $check_stmt->close();

    // --- Validation (Other Fields) ---
    // Last Name
    if (empty($last_name)) {
        $errors['last_name'] = "Last name is required.";
    } elseif (!preg_match("/^[a-zA-Z\s]+$/", $last_name)) {
        $errors['last_name'] = "Invalid last name. Use only letters and spaces.";
    }

    // First Name
    if (empty($first_name)) {
        $errors['first_name'] = "First name is required.";
    } elseif (!preg_match("/^[a-zA-Z\s]+$/", $first_name)) {
        $errors['first_name'] = "Invalid first name. Use only letters and spaces.";
    }

    // Middle Name  <- THIS IS MODIFIED
    if (empty($middle_name)) {
        $middle_name = ''; // Allow blank
    } elseif (!preg_match("/^(?:[a-zA-Z\s]+|I\.|I)?$/", $middle_name)) {
        $errors['middle_name'] = "Invalid middle name. Must be a full middle name or middle initial.";
    }

    // Course  <- THIS IS MODIFIED
    if (!empty($course) && !ctype_alpha($course)) { //Allow empty course
        $errors['course'] = "Invalid course. Use only letters.";
    }

    // --- Image Upload Handling (for updates) ---
    $image_path = ""; // Initialize
    if (empty($errors['student_number'])) { // Only proceed if student number is valid
        if (isset($_FILES['edit_image']) && $_FILES['edit_image']['error'] == 0) {
            $target_dir = "uploads/";
            $imageFileType = strtolower(pathinfo($_FILES['edit_image']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif');

            if (!is_dir($target_dir)) {
                $edit_errors['edit_image'] = "Error: The uploads directory does not exist.";
            } else {
                $check = getimagesize($_FILES['edit_image']['tmp_name']);
                if ($check === false) {
                    $edit_errors['edit_image'] = "File is not an image.";
                } elseif ($_FILES['edit_image']['size'] > 5000000) {
                    $edit_errors['edit_image'] = "Sorry, your file is too large.";
                } elseif (!in_array($imageFileType, $allowed_extensions)) {
                    $edit_errors['edit_image'] = "Sorry, only JPG, JPEG, PNG & GIF files are allowed.";
                } elseif (empty($edit_errors)) {
                    // --- CRITICAL CHANGE: Use student number as filename ---

                    $target_file = $target_dir . $student_number . "." . $imageFileType;

                    // --- Overwrite existing file (if any) ---
                    // Delete old image (if it exists)
                    $old_image_path = getStudentImagePath($old_student_number);
                    if ($old_image_path && $old_image_path != "images/no_image.png" && file_exists($old_image_path)) {
                        unlink($old_image_path);
                    }

                    if (move_uploaded_file($_FILES['edit_image']['tmp_name'], $target_file)) {
                        $image_path = $target_file; // Set to new image path
                    } else {
                        $edit_errors['edit_image'] = "Sorry, there was an error uploading your file.";
                    }
                }
            }
        } else {
            // If no new image, keep the old one (if it exists)
            $image_path = getStudentImagePath($old_student_number);
        }
    }
    // Update the database (only if no image upload errors and other validation errors)
    if (empty($edit_errors) && empty($errors)) {
        // --- Rename old image file if student number changed ---
        // NO renaming needed.  We always use the student number for the filename.

        // --- Prepare Update Statement (Corrected) ---
        $stmt = $conn->prepare("UPDATE students SET student_number = ?, last_name = ?, first_name = ?, middle_name = ?, course = ? WHERE id = ?");
        $stmt->bind_param("sssssi", $student_number, $last_name, $first_name, $middle_name, $course, $edit_id); // Corrected bind_param

        if ($stmt->execute()) {
            // --- Update corresponding login records ---
            $update_login_stmt = $conn->prepare("UPDATE login_records SET student_number = ?, last_name = ?, first_name = ?, middle_name = ?, course = ? WHERE student_number = ?");
            $update_login_stmt->bind_param("ssssss", $student_number, $last_name, $first_name, $middle_name, $course, $old_student_number);
            $update_login_stmt->execute();
            $update_login_stmt->close();


            $success_message = "Student record updated successfully!";
        } else {
            $edit_errors['db_error'] = "Error updating record: " . $stmt->error;
        }
        $stmt->close();
        $edit_student = null; // Clear edit mode

    } else { // Added else block
        // If there are errors, keep the edit form open
        $_SESSION['edit_errors'] = $edit_errors; // Store edit errors
        // Refetch the student data to repopulate the form
        $stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $edit_student = $result->fetch_assoc();
        }
        $stmt->close();
    }

    // Refresh student list (Include id)
    $students = [];
    $sql = "SELECT id, student_number, last_name, first_name, middle_name, course FROM students"; // Include image_path //Removed Section
    if (isset($_POST['student_number_search_students'])) {
        $student_number_search_students = $_POST['student_number_search_students'];
        $sql = "SELECT id, student_number, last_name, first_name, middle_name, course FROM students WHERE student_number LIKE '%$student_number_search_students%'"; // Include image_path //Removed Section
    }
    $students_result = $conn->query($sql);
    if ($students_result) {
        while ($row = $students_result->fetch_assoc()) {
            $students[] = $row;
        }
    }
    //$active_tab = 'studentRecords'; // Stay on Student Records tab //REMOVED
    header("Location: admin_dashboard.php?tab=studentRecords");
    exit();
}

// Handle Image Deletion
if (isset($_POST['delete_image'])) {
    $edit_id = intval($_POST['edit_id']);
    //$image_path = $_POST['existing_image']; //REMOVED

    $student_number = "";

    // Get the student number before deleting the image
    $stmt = $conn->prepare("SELECT student_number FROM students WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->bind_result($student_number);
        $stmt->fetch();
    }
    $stmt->close();

    // Delete the image file (if it exists and is not the default)
    $image_path = getStudentImagePath($student_number);
    if ($image_path && $image_path != "images/no_image.png" && file_exists($image_path)) {
        unlink($image_path);
    }

    // --- NO DATABASE UPDATE NEEDED ---
    // We don't store image paths in the database anymore.

    $success_message = "Image deleted successfully!";

    //Refesh
    $students = [];
    $sql = "SELECT id, student_number, last_name, first_name, middle_name, course FROM students"; // Include image_path //Removed Section
    if (isset($_POST['student_number_search_students'])) {
        $student_number_search_students = $_POST['student_number_search_students'];
        $sql = "SELECT id, student_number, last_name, first_name, middle_name, course FROM students WHERE student_number LIKE '%$student_number_search_students%'"; // Include image_path //Removed Section
    }
    $students_result = $conn->query($sql);
    if ($students_result) {
        while ($row = $students_result->fetch_assoc()) {
            $students[] = $row;
        }
    }

    //$active_tab = 'studentRecords'; //REMOVED
    header("Location: admin_dashboard.php?tab=studentRecords");
    exit();
}

// Pagination and Search - Student Records
$per_page = 50;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $per_page;

// Get the search term *before* the query.
$student_number_search_students = isset($_POST['student_number_search_students'])
    ? $_POST['student_number_search_students']
    : (isset($_GET['search']) ? $_GET['search'] : ''); // Get from $_GET if available

$sql = "SELECT id, student_number, last_name, first_name, middle_name, course FROM students";
if (!empty($student_number_search_students)) {
    $sql .= " WHERE student_number LIKE '%$student_number_search_students%'";
}
$sql .= " LIMIT $per_page OFFSET $offset";

$students_result = $conn->query($sql);
$students = [];
if ($students_result) {
    while ($row = $students_result->fetch_assoc()) {
        $students[] = $row;
    }
}

// Get total number of records (for pagination), considering search
$total_records_sql = "SELECT COUNT(*) as total FROM students";
if (!empty($student_number_search_students)) {
    $total_records_sql .= " WHERE student_number LIKE '%$student_number_search_students%'";
}
$total_result = $conn->query($total_records_sql);
$total_records = 0;
if ($total_result) {
    $total_row = $total_result->fetch_assoc();
    $total_records = $total_row['total'];
}
$total_pages = ceil($total_records / $per_page);


// Handle download to Excel (students)
if (isset($_POST['download_excel_students'])) {
    if (!empty($students)) {
        // Create a new Spreadsheet
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Add headers
        $sheet->setCellValue('A1', 'ID');
        $sheet->setCellValue('B1', 'Student Number');
        $sheet->setCellValue('C1', 'Last Name');
        $sheet->setCellValue('D1', 'First Name');
        $sheet->setCellValue('E1', 'Middle Name');
        $sheet->setCellValue('F1', 'Course');
        //$sheet->setCellValue('G1', 'Image Path'); // Include Image Path //REMOVED

        // Add data
        $row = 2;
        foreach ($students as $student) {
            $sheet->setCellValue('A' . $row, $student['id']);
            $sheet->setCellValue('B' . $row, $student['student_number']);
            $sheet->setCellValue('C' . $row, $student['last_name']);
            $sheet->setCellValue('D' . $row, $student['first_name']);
            $sheet->setCellValue('E' . $row, $student['middle_name']);
            $sheet->setCellValue('F' . $row, $student['course']);
            //$sheet->setCellValue('G' . $row, $student['image_path']); // Include Image Path //REMOVED
            $row++;
        }

        // Create writer and set headers
        $writer = new Xlsx($spreadsheet);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="student_records.xlsx"');
        header('Cache-Control: max-age=0');

        // Output to browser
        $writer->save('php://output');
        exit();
    } else {
        $excel_message = "No student records found to download.";
    }
}

// --- Modified clear_table_students ---
if (isset($_POST['clear_table_students'])) {
    // Get all student numbers before clearing
    $student_numbers = [];
    $result = $conn->query("SELECT student_number FROM students");
    while ($row = $result->fetch_assoc()) {
        $student_numbers[] = $row['student_number'];
    }

    // Disable foreign key checks temporarily
    $conn->query("SET FOREIGN_KEY_CHECKS=0");

    // Delete related records from login_records FIRST (using student numbers)
    $conn->query("DELETE lr FROM login_records lr
                  INNER JOIN students s ON lr.student_number = s.student_number");

    // THEN, truncate the students table
    $conn->query("TRUNCATE TABLE students");

    // Re-enable foreign key checks
    $conn->query("SET FOREIGN_KEY_CHECKS=1");

    // --- DO NOT DELETE IMAGES --- (This is the key change)

    // *** KEY CHANGE: Clear any existing errors ***
    unset($_SESSION['errors']);

    header("Location: admin_dashboard.php?tab=studentRecords"); // Redirect is best
    exit();
}

//----------------------------------------------------------------
//Sign Up for New Student Actions
//----------------------------------------------------------------

if (isset($_POST['add_student'])) {
    // Get and trim input values
    $student_number = trim($_POST['student_number']);
    $last_name = trim($_POST['last_name']);
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $course = trim($_POST['course']);
    //$image_path = 'images/no_image.png'; // Initialize to default image //REMOVED

    // --- Validation ---
    $errors = [];

    // Student Number (validation - as before, but I've streamlined it)
    if (empty($student_number) || strlen($student_number) != 11 || !ctype_digit($student_number) || (substr($student_number, 0, 5) != '02000' && substr($student_number, 0, 5) != '10000')) {
        $errors['student_number'] = "Student number must be 11 digits, numeric, and start with 02000 or 10000.";
    }

    // Check for duplicates *before* other checks
    $check_stmt = $conn->prepare("SELECT student_number FROM students WHERE student_number = ?");
    $check_stmt->bind_param("s", $student_number);
    $check_stmt->execute();
    $check_stmt->store_result();
    if ($check_stmt->num_rows > 0) {
        $errors['student_number'] = "Student number already exists.";
    }
    $check_stmt->close();

    // Other fields (only validate if student number is unique)
    if (empty($errors['student_number'])) {
        // ... (Your existing validation for last_name, first_name, middle_name, course) ...
        if (empty($last_name)) {
            $errors['last_name'] = "Last name is required.";
        } elseif (!preg_match("/^[a-zA-Z\s]+$/", $last_name)) {
            $errors['last_name'] = "Invalid last name. Use only letters and spaces.";
        }

        if (empty($first_name)) {
            $errors['first_name'] = "First name is required.";
        } elseif (!preg_match("/^[a-zA-Z\s]+$/", $first_name)) {
            $errors['first_name'] = "Invalid first name. Use only letters and spaces.";
        }

        //Middle name validation (Edit Student)
        if (empty($middle_name)) {
            // Allow blank middle name.
            $middle_name = '';
        } elseif (!preg_match("/^(?:[a-zA-Z]+|I\.|I)$/", $middle_name)) {
            $errors['middle_name'] = "Invalid middle name.  Must be a full middle name or middle initial.";
        }

        // --- Image Upload Handling ---
        $image_path = ""; // Initialize
        if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            $target_dir = "uploads/";
            $imageFileType = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif');

            if (!is_dir($target_dir)) {
                $errors['image'] = "Error: The uploads directory does not exist.";
            } else {
                // Get image size and validate
                $check = getimagesize($_FILES['image']['tmp_name']);
                if ($check === false) {
                    $errors['image'] = "File is not an image.";
                } elseif ($_FILES['image']['size'] > 5000000) { // 5MB limit
                    $errors['image'] = "Sorry, your file is too large.";
                } elseif (!in_array($imageFileType, $allowed_extensions)) {
                    $errors['image'] = "Sorry, only JPG, JPEG, PNG & GIF files are allowed.";
                } else {
                    // --- CRITICAL CHANGE: Use student number as filename ---

                    $target_file = $target_dir . $student_number . "." . $imageFileType;

                    // --- Overwrite existing file (if any) ---
                    // Delete old image (if it exists) - Corrected
                    $old_image_path = getStudentImagePath($student_number);
                    if ($old_image_path && $old_image_path != "images/no_image.png" && file_exists($old_image_path)) {
                        unlink($old_image_path);
                    }


                    if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                        $image_path = $target_file; // Set to new image path
                    } else {
                        $errors['image'] = "Sorry, there was an error uploading your file.";
                    }
                }
            }
        } // End image upload handling
        else {
            // If no image uploaded, set to default
            $image_path = "images/no_image.png";
        }
    } // End validation checks

    // --- Database Insertion (if no errors) ---
    if (empty($errors)) {
        // --- Corrected INSERT statement ---
        $sql = "INSERT INTO students (student_number, last_name, first_name, middle_name, course) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);

        if ($stmt) {
            $stmt->bind_param("sssss", $student_number, $last_name, $first_name, $middle_name, $course); // Corrected bind_param
            if ($stmt->execute()) {
                $success_message = "Student added successfully!";
                // Clear form fields on success (as before)
                unset($_SESSION['student_number']);
                unset($_SESSION['last_name']);
                unset($_SESSION['first_name']);
                unset($_SESSION['middle_name']);
                unset($_SESSION['course']);

                // Redirect to student records and exit
                //$active_tab = '
                header("Location: admin_dashboard.php?tab=studentRecords");
                exit();
            } else {
                $errors['db_error'] = "Something went wrong. Please try again later. " . $stmt->error;
            }
            $stmt->close();
        } else {
            $errors['db_error'] = "Failed to prepare statement: " . $conn->error;
        }
    }

    // --- Error Handling ---
    $_SESSION['errors'] = $errors; // Store errors in session
    // Store input values in session
    $_SESSION['student_number'] = $student_number;
    $_SESSION['last_name'] = $last_name;
    $_SESSION['first_name'] = $first_name;
    $_SESSION['middle_name'] = $middle_name;
    $_SESSION['course'] = $course;

    header("Location: admin_dashboard.php?tab=signupNewStudents"); // Stay on signup page
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <!--  jQuery and Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="icon" href="image/sti_logo.png" type="image/png">

    <style>
        /* Prevent modal backdrop from blocking clicks */
        .modal-backdrop {
            pointer-events: none;
        }

        /* ... (Your existing styles, as before) ... */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: url('Adminbg.png') no-repeat center center fixed;
            background-size: cover;
            background-color: #e9ecef;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
        }

        .container {
            width: 90%;
            max-width: 1200px;
            background-color: #fff;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 12px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            text-align: center;
            margin-bottom: 20px;
            position: relative;
        }

        h1 {
            color: #343a40;
            font-size: 28px;
            margin-bottom: 25px;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.1);
        }

        .tab-container {
            width: 100%;
        }

        .tabs {
            display: flex;
            justify-content: flex-start;
            border-bottom: 2px solid #ccc;
        }

        .tab {
            padding: 12px 24px;
            cursor: pointer;
            background-color: #f0f0f0;
            border: none;
            border-top-left-radius: 7px;
            border-top-right-radius: 7px;
            margin-right: 5px;
            font-size: 16px;
            transition: background-color 0.3s;
        }

        .tab:hover {
            background-color: #e0e0e0;
        }

        .tab.active {
            background-color: #ddd;
        }

        .tab-content {
            display: none;
            padding: 25px;
            text-align: left;
        }

        .tab-content.active {
            display: block;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        th,
        td {
            padding: 14px;
            border: 1px solid #dee2e6;
            text-align: left;
            font-size: 15px;
        }

        th {
            background-color: #f8f9fa;
            color: #6c757d;
            font-weight: bold;
            text-transform: uppercase;
        }

        tr:nth-child(even) {
            background-color: #f3f4f6;
        }

        tr:hover {
            background-color: #e2e6ea;
        }

        .actions-logout {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 25px;
        }

        .actions-logout button {
            padding: 12px 24px;
            background-color: #dc3545;
            border: none;
            border-radius: 6px;
            color: white;
            font-size: 17px;
            cursor: pointer;
            transition: background-color 0.3s;
            margin-left: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .actions-logout button:hover {
            background-color: #c82333;
        }

        .search-form {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            margin-bottom: 25px;
        }

        .search-input {
            display: flex;
            align-items: center;
        }

        .search-input input[type="text"] {
            padding: 12px;
            width: 450px;
            /* Adjusted width */
            border: 1px solid #ced4da;
            border-radius: 6px;
            margin-right: 10px;
            font-size: 16px;
        }

        .search-input button[type="submit"] {
            padding: 12px 24px;
            background-color: #007bff;
            border: none;
            border-radius: 6px;
            color: white;
            font-size: 17px;
            cursor: pointer;
            transition: background-color 0.3s;
            margin-right: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .search-input button[type="submit"]:hover {
            background-color: #0056b3;
        }

        .student-search-input button[type="submit"] {
            padding: 12px 24px;
            background-color: #007bff;
            border: none;
            border-radius: 6px;
            color: white;
            font-size: 17px;
            cursor: pointer;
            transition: background-color 0.3s;
            margin-right: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .student-search-input button[type="submit"]:hover {
            background-color: #0056b3;
        }

        .search-input button[name="download_excel_login"] {
            background-color: #28a745;
            margin-right: 10px;
            /* Add margin for spacing */
        }

        .search-input button[name="download_excel_login"]:hover {
            background-color: #1e7e34;
        }

        .search-input button[name="clear_table_login"] {
            background-color: #6B1D1D;
            /* No margin-right here */
        }

        .search-input button[name="clear_table_login"]:hover {
            background-color: #401111;
        }

        .student-actions {
            margin-top: 25px;
            display: flex;
            justify-content: center;
            gap: 15px;
        }

        .student-actions button {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            color: white;
            font-size: 17px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .student-actions button:hover {
            background-color: #5a6268;
        }

        .student-actions button[name="download_excel_students"] {
            background-color: #28a745;
            color: white;
            padding: 16px 24px;
            border: none;
            border-radius: 6px;
            font-size: 17px;
            cursor: pointer;
            transition: background-color 0.3s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .student-actions button[name="download_excel_students"]:hover {
            background-color: #1e7e34;
            color: white;
            padding: 16px 24px;
            border: none;
            border-radius: 6px;
            font-size: 17px;
            cursor: pointer;
            transition: background-color 0.3s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .student-actions button[name="clear_table_students"] {
            background-color: #6B1D1D;
            color: white;
            padding: 16px 24px;
            border: none;
            border-radius: 6px;
            font-size: 17px;
            cursor: pointer;
            transition: background-color 0.3s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .student-actions button[name="clear_table_students"]:hover {
            background-color: #401111;
            color: white;
            padding: 16px 24px;
            border: none;
            border-radius: 6px;
            font-size: 17px;
            cursor: pointer;
            transition: background-color 0.3s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .student-actions button[name="download_pdf_students"]:hover {
            background-color: #AA0A01;
            color: white;
            padding: 16px 24px;
            border: none;
            border-radius: 6px;
            font-size: 17px;
            cursor: pointer;
            transition: background-color 0.3s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            margin-right: 10px;
        }

        .student-search-form {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            margin-bottom: 25px;
        }

        .student-search-input {
            display: flex;
            align-items: center;
        }

        .student-search-input input[type="text"] {
            padding: 12px;
            width: 300px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            margin-right: 10px;
            font-size: 16px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 25px;
        }

        .pagination a,
        .pagination span {
            padding: 10px 16px;
            border: 1px solid #ddd;
            margin: 0 5px;
            text-decoration: none;
            color: #333;
            background-color: #f9f9f9;
            border-radius: 6px;
            transition: background-color 0.3s;
            font-size: 16px;
        }

        .pagination a:hover {
            background-color: #ddd;
        }

        .pagination .active {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }

        .pagination .disabled {
            color: #aaa;
            pointer-events: none;
            background-color: #eee;
            border-color: #ddd;
        }

        .combined-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .combined-actions button {
            padding: 14px 24px;
            background-color: #007bff;
            border: none;
            border-radius: 5px;
            color: white;
            font-size: 14px;
            cursor: pointer;
            transition: background-color 0.3s;
            box-sizing: border-box;
            line-height: 1;
        }

        .combined-actions button:hover {
            background-color: #0056b3;
        }

        .combined-actions button[name="download_excel_students"] {
            background-color: #28a745;
            color: white;
            padding: 16px 24px;
            border: none;
            border-radius: 6px;
            font-size: 17px;
            cursor: pointer;
            transition: background-color 0.3s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .combined-actions button[name="download_excel_students"]:hover {
            background-color: #1e7e34;
            color: white;
            padding: 16px 24px;
            border: none;
            border-radius: 6px;
            font-size: 17px;
            cursor: pointer;
            transition: background-color 0.3s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .combined-actions button[name="clear_table_students"] {
            background-color: #6B1D1D;
            color: white;
            padding: 16px 24px;
            border: none;
            border-radius: 6px;
            font-size: 17px;
            cursor: pointer;
            transition: background-color 0.3s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .combined-actions button[name="clear_table_students"]:hover {
            background-color: #401111;
            color: white;
            padding: 16px 24px;
            border: none;
            border-radius: 6px;
            font-size: 17px;
            cursor: pointer;
            transition: background-color 0.3s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .combined-actions button[name="download_pdf_students"] {
            background-color: #F40F02;
            color: white;
            padding: 16px 24px;
            border: none;
            border-radius: 6px;
            font-size: 17px;
            cursor: pointer;
            transition: background-color 0.3s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .combined-actions button[name="download_pdf_students"]:hover {
            background-color: #AA0A01;
            color: white;
            padding: 16px 24px;
            border: none;
            border-radius: 6px;
            font-size: 17px;
            cursor: pointer;
            transition: background-color 0.3s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .logout-container {
            display: flex;
            justify-content: flex-end;
            padding: 10px;
        }

        .logout-container button {
            padding: 12px 24px;
            background-color: #dc3545;
            border: none;
            border-radius: 6px;
            color: white;
            font-size: 17px;
            cursor: pointer;
            transition: background-color 0.3s;
            margin-left: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .logout-container button:hover {
            background-color: #c82333;
        }

        /* Form Styles (Inside Tab) */
        .signup-tab .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .signup-tab label {
            color: #34495e;
            margin-bottom: 8px;
            display: block;
            font-size: 1.1em;
            font-weight: 500;
        }

        .signup-tab input[type="text"] {
            padding: 12px;
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            font-size: 1.1em;
            color: #2c3e50;
            transition: border-color 0.3s ease;
            width: 100%;
            box-sizing: border-box;
        }

        .signup-tab input[type="text"]:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 8px rgba(52, 152, 219, 0.3);
        }

        .signup-tab .error {
            border-color: #e74c3c !important;
        }

        .signup-tab .error-message {
            color: #e74c3c;
            font-size: 0.9em;
            margin-top: 5px;
        }

        .signup-tab button[type="submit"] {
            padding: 15px;
            border: none;
            border-radius: 10px;
            color: white;
            font-size: 1.1em;
            cursor: pointer;
            transition: transform 0.2s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            background-color: #3498db;
        }

        .signup-tab button[type="submit"]:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.25);
        }

        /* Existing styles... */

        /* Style for the "Choose File" button in the Sign Up tab */
        .signup-tab .blue-button {
            /* You can keep the same blue-button styles or customize further */
            background-color: #007bff;
            /* Bootstrap primary color */
            color: #fff;
            border: none;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .signup-tab .blue-button:hover {
            background-color: #0056b3;
            /* Darker blue on hover */
        }

        /* Style the file name display in the Sign Up tab*/
        .signup-tab #file_name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Excel Message Style */
        .excel-message {
            color: #d35400;
            /* A shade of orange */
            margin-top: 10px;
            font-weight: bold;
        }

        .error-container {
            margin-top: 10px;
            padding: 10px;
            border: 1px solid #d9534f;
            /* Bootstrap danger color */
            background-color: #f2dede;
            /* Light red background */
            color: #d9534f;
            /* Dark red text */
            border-radius: 4px;
        }

        .error-list {
            list-style-type: none;
            /* Remove bullet points */
            padding: 0;
            margin: 0;
        }

        .error-list li {
            margin-bottom: 5px;
            /* Add some spacing between error messages */
        }

        .sign-in-button {
            background-color: #3498db;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 17px;
            transition: background-color 0.3s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .sign-in-button:hover {
            background-color: #0056b3;
        }

        .sign-in-container {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }

        /* Custom "Choose File" and "Import" Button Styles */
        .blue-button {
            background-color: #3498db;
            /* Match your blue color */
            color: #fff;
            border: none;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .blue-button:hover {
            background-color: #2980b9;
            /* Darker blue on hover */
        }

        #fileName {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Style for the image preview */
        .image-preview {
            max-width: 200px;
            /* Adjust as needed */
            max-height: 200px;
            /* Adjust as needed */
            margin-top: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        /* Existing styles... */

        .edit-form {
            border: 1px solid #ced4da;
            /* Lighter border */
            padding: 20px;
            /* More padding */
            margin-top: 20px;
            background-color: #fff;
            /* White background */
            border-radius: 8px;
            /* Rounded corners */
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            /* Subtle shadow */
        }

        .edit-form h3 {
            color: #343a40;
            /* Darker heading color */
            margin-bottom: 1rem;
            /* Spacing below heading */
        }

        .edit-form .form-label {
            font-weight: bold;
            /* Bold labels */
        }

        .edit-form .input-group-text {
            background-color: #e9ecef;
            /* Light gray background for input group */
            border: 1px solid #ced4da;
        }

        .edit-form .img-thumbnail {
            border: 2px solid #dee2e6;
            /* Slightly thicker border for preview */
        }

        /* Style for the "Choose File" button */
        .blue-button {
            background-color: #007bff;
            /* Bootstrap primary color */
            color: #fff;
            border: none;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .blue-button:hover {
            background-color: #0056b3;
            /* Darker blue on hover */
        }

        /* Style the file name display */
        #edit_file_name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .hidden {
            display: none;
        }

        body.dark-mode {
            background-color: #121212;
            /* Dark background */
            color: #ffffff;
            /* Light text */
        }

        .dark-mode .container {
            background-color: #1e1e1e;
            /* Darker container */
            box-shadow: 0 8px 12px rgba(255, 255, 255, 0.1);
            /* Lighter shadow */
        }

        .dark-mode h1 {
            color: #f0f0f0;
            /* Light heading */
            text-shadow: 1px 1px 2px rgba(255, 255, 255, 0.2);
        }

        .dark-mode .tab {
            background-color: #333;
            color: #fff;
        }

        .dark-mode .tab:hover {
            background-color: #444;
        }

        .dark-mode .tab.active {
            background-color: #555;
        }

        .dark-mode .tab-content {
            background-color: #292929;
        }

        .dark-mode th {
            background-color: #333;
            color: #ddd;
        }

        .dark-mode tr:nth-child(even) {
            background-color: #222;
        }

        .dark-mode tr:hover {
            background-color: #333;
        }

        .dark-mode .actions-logout button,
        .dark-mode .search-input button,
        .dark-mode .student-actions button,
        .dark-mode .combined-actions button,
        .dark-mode .logout-container button,
        .dark-mode .sign-in-button,
        .dark-mode .blue-button {
            /* Keep button colors consistent, but adjust for visibility */
            filter: brightness(1.2);
            /* Slightly brighter buttons */
        }

        .dark-mode .pagination a,
        .dark-mode .pagination span {
            color: #fff;
            background-color: #444;
            border-color: #555;
        }

        .dark-mode .pagination .active {
            background-color: #007bff;
            /* Keep active page color */
            border-color: #007bff;
        }

        .dark-mode .signup-tab label {
            color: #ddd;
        }

        .dark-mode .signup-tab input[type="text"] {
            background-color: #333;
            color: #fff;
            border-color: #555;
        }

        .dark-mode .signup-tab input[type="text"]:focus {
            border-color: #66afe9;
            /* Lighter blue focus */
            box-shadow: 0 0 8px rgba(102, 175, 233, 0.6);
        }

        .dark-mode .error-message {
            color: #f88;
            /* Lighter error message */
        }

        .dark-mode .error-container {
            background-color: #301919;
            border-color: #983232;
            color: #f88;
        }

        .dark-mode .edit-form {
            background-color: #333;
            border-color: #555;
        }

        .dark-mode .edit-form h3 {
            color: #fff;
        }

        .dark-mode .edit-form .input-group-text {
            background-color: #444;
            border-color: #555;
            color: #fff;
        }

        .dark-mode .form-control {
            background-color: #444;
            border-color: #555;
            color: #fff;
        }

        /* Styles for the dark mode toggle */
        #darkModeToggle {
            position: absolute;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            cursor: pointer;
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        #darkModeToggle img {
            width: 30px;
            height: 30px;
        }

        /* Added: Style the text in dark mode */
        .dark-mode #dark-mode-text {
            color: #fff;
            /* White text */
        }

        .dark-mode .modal-content {
            background-color: #333;
            /* Dark modal background */
            color: #fff;
            /* Light text in modal */
        }

        .dark-mode .modal-header,
        .dark-mode .modal-footer {
            border-color: #555;
            /* Darker borders */
        }

        .dark-mode .modal-title {
            color: #fff;
            /* Light title */
        }

        .dark-mode .btn-close {
            /* Style the close button (Bootstrap 5) */
            filter: invert(1);
            /* Invert colors for visibility */
            opacity: 0.8;
        }

        .dark-mode .btn-close:hover {
            opacity: 1;
        }

        .dark-mode input[type="text"],
        .dark-mode input[type="password"],
        .dark-mode input[type="email"],
        .dark-mode input[type="number"],
        .dark-mode input[type="tel"],
        .dark-mode input[type="url"],
        .dark-mode input[type="search"],
        .dark-mode input[type="date"],
        .dark-mode input[type="time"],
        .dark-mode select,
        .dark-mode textarea {
            background-color: #333;
            color: #fff;
            border-color: #555;
        }

        .dark-mode input[type="text"]:focus,
        .dark-mode input[type="password"]:focus,
        .dark-mode input[type="email"]:focus,
        .dark-mode input[type="number"]:focus,
        .dark-mode input[type="tel"]:focus,
        .dark-mode input[type="url"]:focus,
        .dark-mode input[type="search"]:focus,
        .dark-mode input[type="date"]:focus,
        .dark-mode input[type="time"]:focus,
        .dark-mode select:focus,
        .dark-mode textarea:focus {
            border-color: #66afe9;
            box-shadow: 0 0 8px rgba(102, 175, 233, 0.6);
            outline: none;
            /* Remove default focus outline */
        }
    </style>
    <script>
        function openTab(evt, tabName) {
            var i, tabContent, tabLinks;
            tabContent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabContent.length; i++) {
                tabContent[i].classList.remove("active");
            }
            tabLinks = document.getElementsByClassName("tab");
            for (i = 0; i < tabLinks.length; i++) {
                tabLinks[i].classList.remove("active");
            }
            document.getElementById(tabName).classList.add("active");
            evt.currentTarget.classList.add("active");

            // Store the active tab in localStorage
            localStorage.setItem('activeTab', tabName);
        }


        // Open the Login Records tab by default or the stored active tab
        document.addEventListener("DOMContentLoaded", function (event) {
            // Use localStorage, fallback to 'loginRecords'
            let activeTab = localStorage.getItem('activeTab') || 'loginRecords';
            let tabButton = document.getElementById(activeTab + 'Button');
            if (tabButton) {
                tabButton.click(); // Programmatically click the tab
            } else {
                // Fallback to default if the stored tab doesn't exist
                document.getElementById('loginRecordsButton').click();
            }

            // Preview image on page load (for edit form)
            previewEditImage();

            // Clear any displayed errors after they've been shown once.
            if (document.querySelector('.error-message, .error-container')) { // Check for ANY error display
                setTimeout(function () {
                    let errorElements = document.querySelectorAll('.error-message, .error-container');
                    errorElements.forEach(function (el) {
                        el.remove();
                    });
                }, 5000); // Remove after 5 seconds (adjust as needed)
            }
            // Dark Mode Toggle -  CORRECTED
            const darkModeToggle = document.getElementById('darkModeToggle');
            const body = document.body;
            const icon = document.getElementById('dark-mode-icon');
            const text = document.getElementById('dark-mode-text');

            // Load preference and set initial state
            const isDarkMode = localStorage.getItem('darkMode') === 'enabled';
            if (isDarkMode) {
                body.classList.add('dark-mode');
                icon.src = 'images/moon.png';
                icon.alt = 'Dark Mode';
                text.textContent = 'Dark Mode';
            } else {
                icon.src = 'images/sun.png';
                icon.alt = 'Light Mode';
                text.textContent = 'Light Mode';
            }

            // Single-click handler
            darkModeToggle.addEventListener('click', () => {
                body.classList.toggle('dark-mode'); // Toggle the class
                const isDarkMode = body.classList.contains('dark-mode');

                // Update icon, text, and save preference
                if (isDarkMode) {
                    icon.src = 'images/moon.png';
                    icon.alt = 'Dark Mode';
                    text.textContent = 'Dark Mode';
                    localStorage.setItem('darkMode', 'enabled');
                } else {
                    icon.src = 'images/sun.png';
                    icon.alt = 'Light Mode';
                    text.textContent = 'Light Mode';
                    localStorage.setItem('darkMode', 'disabled');
                }
            });
        });



        function downloadPDF() {
            const {
                jsPDF
            } = window.jspdf;
            let doc = new jsPDF();

            // Add the text to the upper-left corner
            doc.setFontSize(10);
            doc.text("MIS Department", 10, 10);
            doc.text("Dela Luna", 10, 15);
            doc.text("Mamerto", 10, 20);
            doc.text("Ramos", 10, 25);

            doc.setFontSize(16); // Reset font size for the title
            doc.text("Student Records Report", 14, 35); // Adjusted y-coordinate

            let table = document.getElementById("studentTable");

            if (!table || table.rows.length <= 1) {
                alert("No data available to download.");
                return;
            }

            let headers = [];
            let headerRow = table.rows[0];
            for (let i = 0; i < headerRow.cells.length - 1; i++) { // -1 to exclude "Actions"
                headers.push(headerRow.cells[i].innerText);
            }

            let data = [];
            for (let i = 1; i < table.rows.length; i++) {
                let row = table.rows[i];
                let rowData = [];

                for (let j = 0; j < row.cells.length - 1; j++) { // -1 to exclude "Actions"
                    let cell = row.cells[j];

                    if (j === 6) { // Image column (Corrected index: 6)
                        let img = cell.querySelector('img');
                        if (img) {
                            let imgType = 'image/jpeg'; // Default to JPEG
                            let src = img.src.toLowerCase();

                            // Determine image type
                            if (src.endsWith('.png')) {
                                imgType = 'image/png';
                            } else if (src.endsWith('.gif')) {
                                imgType = 'image/gif';
                            }

                            let canvas = document.createElement('canvas');
                            canvas.width = img.width;
                            canvas.height = img.height;
                            let ctx = canvas.getContext('2d');
                            ctx.drawImage(img, 0, 0, img.width, img.height);
                            let imgData = canvas.toDataURL(imgType, 0.7);

                            rowData.push({
                                image: imgData,
                                fit: [50, 50]
                            });
                        } else {
                            rowData.push("");
                        }
                    } else {
                        rowData.push(cell.innerText);
                    }
                }
                data.push(rowData);
            }
            doc.autoTable({
                head: [headers],
                body: data,
                startY: 40, //Adjusted start y
                styles: {
                    fontSize: 8,
                    cellWidth: 'auto',
                    minCellHeight: 10,
                    lineWidth: 0.1,
                    lineColor: [0, 0, 0],
                    valign: 'middle',
                    overflow: 'linebreak'
                },
                headStyles: {
                    fillColor: [0, 86, 179],
                    textColor: [255, 255, 255],
                    fontStyle: 'bold',
                    lineWidth: 0.1,
                    lineColor: [0, 0, 0]
                },
                columnStyles: {
                    6: {
                        cellWidth: 60
                    } // Corrected: Image column style (index 6)
                }
            });
            doc.save("student_records.pdf");
        }

        function updateFileName() {
            let input = document.getElementById('excelFile');
            let fileName = document.getElementById('fileName');
            if (input.files.length > 0) {
                fileName.textContent = input.files[0].name;
            } else {
                fileName.textContent = "No file chosen";
            }
        }

        // Image preview function
        function previewImage() {
            var preview = document.querySelector('.image-preview');
            var file = document.querySelector('input[type=file]').files[0];
            var reader = new FileReader();

            reader.onloadend = function () {
                preview.src = reader.result;
                preview.style.display = 'block'; // Show the preview
            }

            if (file) {
                reader.readAsDataURL(file); //reads the data as a URL
            } else {
                preview.src = "";
                preview.style.display = 'none'; // Hide the preview
            }
        }

        function updateCustomButton() {
            let input = document.getElementById('image');
            let fileNameDisplay = document.getElementById('file_name');

            if (input.files.length > 0) {
                fileNameDisplay.textContent = input.files[0].name;
            } else {
                fileNameDisplay.textContent = "No file chosen";
            }
        }
        // Function to preview image on edit form
        function previewEditImage() {
            var preview = document.getElementById('edit_image_preview');
            if (!preview) return; // Exit if element doesn't exist.
            var file = document.querySelector('input[name=edit_image]').files[0];
            var reader = new FileReader();
            var fileNameSpan = document.getElementById('edit_file_name');

            reader.onloadend = function () {
                preview.src = reader.result;
                preview.style.display = 'block';
            }

            if (file) {
                reader.readAsDataURL(file);
                fileNameSpan.textContent = file.name; // Update file name
            }
        }

        // Function to add prefix to the student number input
        function addStudentPrefix() {
            var studentNumberInput = document.getElementById('student_number');
            if (studentNumberInput && studentNumberInput.value === '') {
                studentNumberInput.value = ''; // Make it blank
            }
        }
        // Function to add "02000" prefix on blur
        function addStudentPrefixBlur() {
            var studentNumberInput = document.getElementById('student_number');
            if (studentNumberInput && studentNumberInput.value === '') {
                studentNumberInput.value = ''; // Make it blank
            }
        }

        // Call the function when the page loads and on blur
        document.addEventListener("DOMContentLoaded", function () {
            addStudentPrefix(); // For initial load

            var studentNumberInput = document.getElementById('student_number');
            if (studentNumberInput) {
                studentNumberInput.addEventListener('blur', addStudentPrefixBlur); // For blur event
            }
        });

        function confirmLogout() {
            $('#confirmationModal .modal-body').text("Are you sure you want to logout?");
            $('#confirmationModal').data('action', 'logout').modal('show');
        }

        function confirmClearTable(tableType) {
            let message = "";
            if (tableType === 'login') {
                message = "Are you sure you want to clear the Login Records table?";
            } else if (tableType === 'students') {
                message = "Are you sure you want to clear the Student Records table? This will also delete related login records.";
            }
            $('#confirmationModal .modal-body').text(message);
            $('#confirmationModal').data('action', tableType).modal('show');
        }

        $(document).ready(function () {
            $('#confirmYes').click(function () {
                let action = $('#confirmationModal').data('action');
                if (action === 'logout') {
                    window.location.href = "logout.php";
                } else if (action === 'login') {
                    document.getElementById('clearLoginForm').submit();
                } else if (action === 'students') {
                    document.getElementById('clearStudentsForm').submit();
                }
                $('#confirmationModal').modal('hide');
            });
        });
    </script>
</head>

<body>
    <div id="darkModeToggle">
        <span id="dark-mode-text"></span>
        <img id="dark-mode-icon" src="" alt="">
    </div>
    <!-- Confirmation Modal -->
    <div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmationModalLabel">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Message will be inserted here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="confirmYes">Yes</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">No</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Hidden forms for clear table actions -->
    <form id="clearLoginForm" method="post" action="admin_dashboard.php?tab=loginRecords" style="display:none;">
        <input type="hidden" name="clear_table_login" value="1">
    </form>
    <form id="clearStudentsForm" method="post" action="admin_dashboard.php?tab=studentRecords" style="display:none;">
        <input type="hidden" name="clear_table_students" value="1">
    </form>

    <div class="container">
        <div class="logout-container">
            <!-- Changed to a button, calls confirmLogout() -->
            <button type="button" onclick="confirmLogout()">Logout</button>
        </div>
        <h1>Admin Dashboard</h1>
        <div style="position: absolute; top: 10px; left: 10px; text-align: left; font-size: 20px; font-weight: bold;">
            By MIS Department Intern 2025<br>
            <div style="display: flex; text-align: center;">
                <!-- Image 1 -->
                <div style="margin-right: 15px;"> <!-- Add some spacing -->
                    <img src="image/Mamerto.jpg" alt="Mamerto" style="width: 50px; height: 50px; border-radius: 50%;">
                    <!-- Example styling -->
                    <div>Mamerto</div>
                </div>

                <!-- Image 2 -->
                <div style="margin-right: 15px;">
                    <img src="image/Dela_Luna.jpg" alt="Dela Luna"
                        style="width: 50px; height: 50px; border-radius: 100%;;">
                    <div>Dela Luna</div>
                </div>

                <!-- Image 3 -->
                <div>
                    <img src="image/Ramos.jpg" alt="Ramos" style="width: 50px; height: 50px; border-radius: 100%;">
                    <div style>Ramos</div>
                </div>
            </div>
        </div>
        <div class="tab-container">
            <div class="tabs">
                <button class="tab" id="loginRecordsButton" onclick="openTab(event, 'loginRecords')">Login
                    Records</button>
                <button class="tab" id="studentRecordsButton" onclick="openTab(event, 'studentRecords')">Student
                    Records</button>
                <button class="tab" id="signupNewStudentsButton" onclick="openTab(event, 'signupNewStudents')">Sign Up
                    for New Students</button>
            </div>

            <div id="loginRecords" class="tab-content">
                <!-- class="tab-content <?php //echo $active_tab == 'loginRecords' ? 'active' : ''; 
                ?>"> -->
                <h2>Login Records</h2>
                <div class="search-form">
                    <form method="post" action="admin_dashboard.php?tab=loginRecords">
                        <!-- <input type="hidden" name="active_tab" value="loginRecords"> -->
                        <div class="search-input">
                            <input type="text" name="student_number_search" placeholder="Enter Student Number"
                                value="<?php echo htmlspecialchars($student_number_search); ?>">
                            <button type="submit">Search</button>
                            <button type="submit" name="download_excel_login">Download to Excel</button>
                            <!-- Changed to button, calls confirmClearTable() -->
                            <button type="button" onclick="confirmClearTable('login')" name="clear_table_login"
                                style="background-color: #6B1D1D; color: white; padding: 12px 24px; border: none; border-radius: 6px; font-size: 17px; cursor: pointer; transition: background-color 0.3s; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);">Clear
                                Table</button>
                        </div>
                    </form>
                </div>

                <?php if (!empty($login_records)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Student Number</th>
                                <th>Last Name</th>
                                <th>First Name</th>
                                <th>Middle Name</th>
                                <th>Course</th>
                                <th>Date</th> <!-- Date Column -->
                                <th>Time In</th> <!-- Time In Column -->
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($login_records as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['student_number']); ?></td>
                                    <td><?php echo htmlspecialchars($row['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['first_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['middle_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['course']); ?></td>
                                    <td><?php echo htmlspecialchars(date('m/d/y', strtotime($row['time_in']))); ?></td>
                                    <!-- Date -->
                                    <td><?php echo htmlspecialchars(date('h:i A', strtotime($row['time_in']))); ?></td>
                                    <!-- Time In -->
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No login records found.</p>
                <?php endif; ?>
            </div>

            <div id="studentRecords" class="tab-content">
                <!-- class="tab-content <?php //echo $active_tab == 'studentRecords' ? 'active' : ''; 
                ?>"> -->
                <h2>Student Records</h2>
                <div class="student-search-form">
                    <form method="post" action="admin_dashboard.php?tab=studentRecords">
                        <div class="student-search-input">
                            <input type="text" name="student_number_search_students" placeholder="Enter Student Number"
                                value="<?php echo htmlspecialchars($student_number_search_students); ?>">
                            <button type="submit">Search</button>
                            <!-- Combined Actions -->
                            <div class="combined-actions">
                                <button type="button" onclick="downloadPDF()" name="download_pdf_students">Download to
                                    PDF</button>
                                <button type="submit" name="download_excel_students">Download to Excel</button>
                                <button type="button" onclick="confirmClearTable('students')"
                                    name="clear_table_students">Clear Table</button>
                            </div>
                        </div>
                        <!-- Hidden input for search term, VERY IMPORTANT -->
                        <input type="hidden" name="search"
                            value="<?php echo htmlspecialchars($student_number_search_students); ?>">
                    </form>
                </div>

                <!-- Excel Import Form -->
                <form method="post" action="admin_dashboard.php?tab=studentRecords" enctype="multipart/form-data">
                    <!-- <input type="hidden" name="active_tab" value="studentRecords"> -->
                    <div class="mb-3">
                        <label for="excelFile" class="form-label">Import Student Records from Excel</label>
                        <input type="file" class="form-control" id="excelFile" name="excel_file"
                            accept=".xls, .xlsx, .csv" style="display: none;" onchange="updateFileName()">
                        <div class="input-group">
                            <label class="input-group-text blue-button" for="excelFile">Choose File</label>
                            <span class="input-group-text" id="fileName">No file chosen</span>
                            <button type="submit" name="import_excel_students"
                                class="btn btn-primary blue-button">Import from
                                Excel</button>
                        </div>
                        <?php if (isset($_SESSION['errors_once']) && $_SESSION['errors_once'] && isset($errors['excel_file'])): ?>
                            <div class="error-message"><?php echo htmlspecialchars($errors['excel_file']); ?></div>
                        <?php endif; ?>
                        <?php if (isset($_SESSION['errors_once']) && $_SESSION['errors_once'] && isset($errors['excel_data']) && is_array($errors['excel_data'])): ?>
                            <div class="error-container">
                                <ul class="error-list">
                                    <?php foreach ($errors['excel_data'] as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php elseif (isset($_SESSION['errors_once']) && $_SESSION['errors_once'] && isset($errors['excel_data'])): ?>
                            <div class="error-message"><?php echo htmlspecialchars($errors['excel_data']); ?></div>
                        <?php endif; ?>

                        <?php if (isset($_SESSION['errors_once']) && $_SESSION['errors_once'] && isset($errors['excel_import'])): ?>
                            <div class="error-message"><?php echo htmlspecialchars($errors['excel_import']); ?></div>
                        <?php endif; ?>
                        <!-- Display the excel_message -->
                        <?php if (!empty($excel_message)): ?>
                            <div class="excel-message"><?php echo htmlspecialchars($excel_message); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($success_message)): ?>
                            <div class="alert alert-success" role="alert">
                                <?php echo htmlspecialchars($success_message); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Display Edit Form (if in edit mode) -->
                <?php if ($edit_student): ?>
                    <div class="edit-form">
                        <h3>Edit Student:
                            <?php echo htmlspecialchars($edit_student['student_number']); ?>
                        </h3>
                        <form method="post" action="admin_dashboard.php?tab=studentRecords" enctype="multipart/form-data"
                            class="row g-3">
                            <!-- <input type="hidden" name="active_tab" value="studentRecords"> -->
                            <input type="hidden" name="edit_id" value="<?php echo $edit_student['id']; ?>">

                            <!-- Add Student Number Input -->
                            <div class="col-md-6">
                                <label for="edit_student_number" class="form-label">Student Number:</label>
                                <input type="text" class="form-control" id="edit_student_number" name="student_number"
                                    value="<?php echo htmlspecialchars($edit_student['student_number']); ?>" required>
                                <?php if (isset($_SESSION['edit_errors']['student_number'])): ?>
                                    <div class="error-message">
                                        <?php echo htmlspecialchars($_SESSION['edit_errors']['student_number']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <!-- Add Last Name Input -->
                            <div class="col-md-6">
                                <label for="edit_last_name" class="form-label">Last Name:</label>
                                <input type="text" class="form-control" id="edit_last_name" name="last_name"
                                    value="<?php echo htmlspecialchars($edit_student['last_name']); ?>" required>
                                <?php if (isset($_SESSION['edit_errors']['last_name'])): ?>
                                    <div class="error-message">
                                        <?php echo htmlspecialchars($_SESSION['edit_errors']['last_name']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Add First Name Input -->
                            <div class="col-md-6">
                                <label for="edit_first_name" class="form-label">First Name:</label>
                                <input type="text" class="form-control" id="edit_first_name" name="first_name"
                                    value="<?php echo htmlspecialchars($edit_student['first_name']); ?>" required>
                                <?php if (isset($_SESSION['edit_errors']['first_name'])): ?>
                                    <div class="error-message">
                                        <?php echo htmlspecialchars($_SESSION['edit_errors']['first_name']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Add Middle Name Input -->
                            <div class="col-md-6">
                                <label for="edit_middle_name" class="form-label">Middle Name:</label>
                                <input type="text" class="form-control" id="edit_middle_name" name="middle_name"
                                    value="<?php echo htmlspecialchars($edit_student['middle_name']); ?>" required>
                                <?php if (isset($_SESSION['edit_errors']['middle_name'])): ?>
                                    <div class="error-message">
                                        <?php echo htmlspecialchars($_SESSION['edit_errors']['middle_name']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Add Course Input -->
                            <div class="col-md-6">
                                <label for="edit_course" class="form-label">Course:</label>
                                <input type="text" class="form-control" id="edit_course" name="course"
                                    value="<?php echo htmlspecialchars($edit_student['course']); ?>" required>
                                <?php if (isset($_SESSION['edit_errors']['course'])): ?>
                                    <div class="error-message">
                                        <?php echo htmlspecialchars($_SESSION['edit_errors']['course']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-6">
                                <label for="edit_image" class="form-label">Student Image:</label>
                                <div class="input-group">
                                    <input type="file" class="form-control" id="edit_image" name="edit_image"
                                        accept="image/*" onchange="previewEditImage()" style="display: none;">
                                    <label class="input-group-text blue-button" for="edit_image">Choose File</label>
                                    <span class="input-group-text" id="edit_file_name">No file chosen</span>
                                </div>
                                <img id="edit_image_preview"
                                    src="<?php echo htmlspecialchars(getStudentImagePath($edit_student['student_number'])); ?>"
                                    alt="Student Image" class="img-thumbnail mt-2"
                                    style="max-width: 200px; max-height: 200px; display: block;">
                                <?php if (isset($edit_errors['edit_image'])): ?>
                                    <div class="error-message">
                                        <?php echo htmlspecialchars($edit_errors['edit_image']); ?>
                                    </div>
                                <?php endif; ?>

                            </div>

                            <div class="col-12">
                                <button type="submit" name="update_student" class="btn btn-primary">Update</button>
                                <button type="submit" name="delete_image" class="btn btn-danger">Delete Image</button>
                                <a href="admin_dashboard.php?tab=studentRecords" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                    <?php
                    unset($_SESSION['edit_errors']); // Clear edit errors after displaying the form
                endif;
                ?>

                <?php //if ($active_tab == 'studentRecords'): 
                ?>
                <table id="studentTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Student Number</th>
                            <th>Last Name</th>
                            <th>First Name</th>
                            <th>Middle Name</th>
                            <th>Course</th>
                            <th>Image</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['id']); ?></td>
                                <td><?php echo htmlspecialchars($row['student_number']); ?></td>
                                <td><?php echo htmlspecialchars($row['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['first_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['middle_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['course']); ?></td>
                                <td>
                                    <img src="<?php echo htmlspecialchars(getStudentImagePath($row['student_number'])); ?>"
                                        alt="Student Image" style="width: 50px; height: 50px;">
                                </td>
                                <td>
                                    <form method="post" action="admin_dashboard.php?tab=studentRecords">
                                        <!-- <input type="hidden" name="active_tab" value="studentRecords"> -->
                                        <input type="hidden" name="edit_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="edit_student">Edit</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Pagination Links -->
                <div class="pagination">
                    <nav aria-label="Page navigation">
                        <ul class="pagination">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link"
                                        href="admin_dashboard.php?tab=studentRecords&page=1&search=<?php echo htmlspecialchars($student_number_search_students); ?>"
                                        aria-label="First">
                                        <span aria-hidden="true">««</span>
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link"
                                        href="admin_dashboard.php?tab=studentRecords&page=<?php echo $page - 1; ?>&search=<?php echo htmlspecialchars($student_number_search_students); ?>"
                                        aria-label="Previous">
                                        <span aria-hidden="true">«</span>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php
                            //  display a limited number of page links
                            $max_links = 5; //  number of page links to show
                            $start = max(1, $page - floor($max_links / 2));
                            $end = min($total_pages, $start + $max_links - 1);

                            // Adjust start if we're near the end
                            if ($end - $start + 1 < $max_links) {
                                $start = max(1, $end - $max_links + 1);
                            }

                            for ($i = $start; $i <= $end; $i++): ?>
                                <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                    <a class="page-link"
                                        href="admin_dashboard.php?tab=studentRecords&page=<?php echo $i; ?>&search=<?php echo htmlspecialchars($student_number_search_students); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link"
                                        href="admin_dashboard.php?tab=studentRecords&page=<?php echo $page + 1; ?>&search=<?php echo htmlspecialchars($student_number_search_students); ?>"
                                        aria-label="Next">
                                        <span aria-hidden="true">»</span>
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link"
                                        href="admin_dashboard.php?tab=studentRecords&page=<?php echo $total_pages; ?>&search=<?php echo htmlspecialchars($student_number_search_students); ?>"
                                        aria-label="Last">
                                        <span aria-hidden="true">»»</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>

                <?php //endif; 
                ?>
            </div>

            <div id="signupNewStudents" class="tab-content signup-tab">
                <!-- class="tab-content <?php //echo $active_tab == 'signupNewStudents' ? 'active' : ''; 
                ?> signup-tab"> -->
                <h2>Sign Up for New Students</h2>

                <?php if (!empty($success_message)): ?>
                    <div class="success-message">
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>

                <form id="addStudentForm" method="post" action="admin_dashboard.php?tab=signupNewStudents"
                    enctype="multipart/form-data">
                    <!-- <input type="hidden" name="active_tab" value="signupNewStudents"> -->
                    <div class="form-group">
                        <label for="student_number">Student Number:</label>
                        <input type="text" id="student_number" name="student_number"
                            value="<?php echo htmlspecialchars($student_number); ?>" required>
                        <?php if (isset($errors['student_number'])): ?>
                            <div class="error-message">
                                <?php echo htmlspecialchars($errors['student_number']); ?>
                            </div>
                        <?php endif; ?>

                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name:</label>
                        <input type="text" id="last_name" name="last_name"
                            value="<?php echo htmlspecialchars($last_name); ?>" required>
                        <?php if (isset($errors['last_name'])): ?>
                            <div class="error-message">
                                <?php echo htmlspecialchars($errors['last_name']); ?>
                            </div>
                        <?php endif; ?>

                    </div>
                    <div class="form-group">
                        <label for="first_name">First Name:</label>
                        <input type="text" id="first_name" name="first_name"
                            value="<?php echo htmlspecialchars($first_name); ?>" required>
                        <?php if (isset($errors['first_name'])): ?>
                            <div class="error-message">
                                <?php echo htmlspecialchars($errors['first_name']); ?>
                            </div>
                        <?php endif; ?>

                    </div>
                    <div class="form-group">
                        <label for="middle_name">Middle Name:</label>
                        <input type="text" id="middle_name" name="middle_name"
                            value="<?php echo htmlspecialchars($middle_name); ?>" required>
                        <?php if (isset($errors['middle_name'])): ?>
                            <div class="error-message">
                                <?php echo htmlspecialchars($errors['middle_name']); ?>
                            </div>
                        <?php endif; ?>

                    </div>
                    <div class="form-group">
                        <label for="course">Course:</label>
                        <input type="text" id="course" name="course" value="<?php echo htmlspecialchars($course); ?>"
                            required>
                        <?php if (isset($errors['course'])): ?>
                            <div class="error-message">
                                <?php echo htmlspecialchars($errors['course']); ?>
                            </div>
                        <?php endif; ?>

                    </div>

                    <div class="form-group">
                        <label for="image">Student Image:</label>
                        <input type="file" id="image" name="image" accept="image/*" style="display: none;"
                            onchange="previewImage(); updateCustomButton();">
                        <div class="inputgroup">
                            <label class="input-group-text blue-button" for="image">Choose File</label>
                            <span class="input-group-text" id="file_name">No file chosen</span>
                        </div>
                        <img class="image-preview"
                            style="display: none; max-width: 200px; max-height: 200px; margin-top: 10px;">
                        <?php if (isset($errors['image'])): ?>
                            <div class="error-message">
                                <?php echo htmlspecialchars($errors['image']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <button type="submit" name="add_student">Sign Up</button>
                </form>
            </div>
        </div>
    </div>
</body>

</html>