<?php
include 'db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['student_number'])) {
    $student_number = $_POST['student_number'];

    // Validate student number (as always!)
    if (strlen($student_number) != 11 || !ctype_digit($student_number) || substr($student_number, 0, 5) != '02000') {
        die("Invalid student number."); // Or handle the error more gracefully
    }

    // 1. Get the filename from the database (if it exists)
    $stmt = $conn->prepare("SELECT picture FROM students WHERE student_number = ?");
    $stmt->bind_param("s", $student_number);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($filename);
        $stmt->fetch();

        // 2. Delete the file (if it exists)
        if (!empty($filename) && file_exists('images/' . $filename)) {
            if (unlink('images/' . $filename)) {
                // File deleted successfully
            } else {
                echo "Error deleting file.";
                exit(); // Stop if file deletion fails
            }
        }

        // 3. Update the database (set picture to NULL)
        $update_stmt = $conn->prepare("UPDATE students SET picture = NULL WHERE student_number = ?");
        $update_stmt->bind_param("s", $student_number);
        if ($update_stmt->execute()) {
            // Database updated successfully
            header("Location: admin_dashboard.php?tab=studentRecords"); // Redirect back
            exit();
        } else {
            echo "Error updating database: " . htmlspecialchars($update_stmt->error);
        }
        $update_stmt->close();
    } else {
        echo "Student number not found.";
    }
    $stmt->close();
    $conn->close();
} else {
    echo "Invalid request.";
}
