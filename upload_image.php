<?php
include 'db_connection.php';

$target_dir = "images/";
$uploadOk = 1;
$errors = [];

// Check if a file was submitted
if (!isset($_FILES["fileToUpload"]) || $_FILES["fileToUpload"]["error"] == UPLOAD_ERR_NO_FILE) {
    $errors[] = "No file was selected for upload.";
    $uploadOk = 0;
} else {
    // File was submitted, proceed with checks
    $imageFileType = strtolower(pathinfo($_FILES["fileToUpload"]["name"], PATHINFO_EXTENSION));
    $originalFilename = basename($_FILES["fileToUpload"]["name"]); // Get original filename

    // --- Extract Student Number from Filename ---
    $student_number = null; // Initialize to null
    $filename_parts = pathinfo($originalFilename);
    $filename_without_extension = $filename_parts['filename'];

    // Attempt to extract student number (assuming format: 02000xxxxxxx.ext)
    if (preg_match('/^(02000\d{6})$/', $filename_without_extension, $matches)) {
        $student_number = $matches[1]; // Extracted student number
    }

    if (!$student_number) {
        $errors[] = "Invalid filename format.  Filename must be the student number (e.g., 02000123456.jpg).";
        $uploadOk = 0;
    } else {
        // Check if student number exists in the database
        $check_stmt = $conn->prepare("SELECT student_number FROM students WHERE student_number = ?");
        if (!$check_stmt) {
            $errors[] = "Database error: " . htmlspecialchars($conn->error); // Check prepare
            $uploadOk = 0;
        } else {
            $check_stmt->bind_param("s", $student_number);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows == 0) {
                $errors[] = "Student number extracted from filename ($student_number) does not exist in the database.";
                $check_stmt->close();
                $uploadOk = 0;
            } else {
                $check_stmt->close();
            }
        }
    }

    // --- Image Validation (if student number is valid) ---
    if ($uploadOk) { // Only proceed if student number is valid

        // Check if image file is a actual image or fake image
        $check = getimagesize($_FILES["fileToUpload"]["tmp_name"]);
        if ($check !== false) {
            // It's an image
        } else {
            $errors[] = "File is not a valid image.";
            $uploadOk = 0;
        }

        // Construct the new filename (using student number)
        $new_filename = $target_dir . $student_number . "." . $imageFileType;

        // Check if file already exists (using student number)
        if (file_exists($new_filename)) {
            $errors[] = "Sorry, a file with this student number already exists.";
            $uploadOk = 0;
        }

        // Check file size (5MB limit - adjust as needed)
        if ($_FILES["fileToUpload"]["size"] > 5000000) {
            $errors[] = "Sorry, your file is too large.";
            $uploadOk = 0;
        }

        // Allow certain file formats
        if ($imageFileType != "jpg" && $imageFileType != "png" && $imageFileType != "jpeg" && $imageFileType != "gif") {
            $errors[] = "Sorry, only JPG, JPEG, PNG & GIF files are allowed.";
            $uploadOk = 0;
        }
    }
}

// --- Handle Upload or Errors ---
if ($uploadOk == 0) {
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li>" . htmlspecialchars($error) . "</li>";
    }
    echo "</ul>";
} else {
    // Attempt to move the uploaded file
    if (move_uploaded_file($_FILES["fileToUpload"]["tmp_name"], $new_filename)) {
        // File uploaded successfully, now update the database

        $stmt = $conn->prepare("UPDATE students SET picture = ? WHERE student_number = ?");
        if (!$stmt) {
            die("Prepare failed: " . htmlspecialchars($conn->error)); // Check prepare
        }

        $db_filename = $student_number . "." . $imageFileType; // Use extracted student number
        $stmt->bind_param("ss", $db_filename, $student_number);
        if (!$stmt) { // Check if $stmt is still valid after bind_param
            die("Bind failed: " . htmlspecialchars($conn->error)); // Check bind_param
        }

        // $sql = "UPDATE students SET picture = '$db_filename' WHERE student_number = '$student_number'"; // Create the full SQL string  -- REMOVE THIS, it's for debugging only
        // echo "DEBUG: SQL Query: " . htmlspecialchars($sql) . "<br>"; // Display the query -- REMOVE THIS, it's for debugging only


        if ($stmt->execute()) {
            echo "The file " . htmlspecialchars($originalFilename) . " has been uploaded and the database updated.";
        } else {
            echo "Error updating database: " . htmlspecialchars($stmt->error); // More specific error
        }
        $stmt->close();
    } else {
        $error_message = "Sorry, there was an error uploading your file.";
        if (error_get_last() && error_get_last()['type'] === E_WARNING) {
            $error_message .= "  Details: " . error_get_last()['message'];
        }
        echo htmlspecialchars($error_message);
    }
}

$conn->close();
