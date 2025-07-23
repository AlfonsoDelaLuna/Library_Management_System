# Library Management System

This is a simple library management system built using PHP, HTML, CSS, and JavaScript.

## Setup

1.  **Database Setup:**
    - Import the `library_management.sql` file into your MySQL database using a tool like phpMyAdmin or MySQL Workbench. This will create the necessary tables and initial data for the library management system.
2.  **Configuration:**
    - Update the `db_connection.php` file with your database credentials (host, username, password, database name). This file establishes the connection between the PHP application and your MySQL database.
3.  **File Placement:**
    - Place all the files in a directory accessible by your web server (e.g., `c:/xampp/htdocs/Library_Management`). This directory will serve as the root directory for your library management system.
4.  **Access:**
    - Open your browser and navigate to the directory where you placed the files. You should be able to access the login page of the library management system.

## Features

- **Admin Dashboard:** Provides an interface for administrators to manage time in for the users upload and download data via excel or pdf.
- **Student Dashboard:** Provides an interface for students to view to time in.
- **Image Uploading:** Allows administrators to upload images to be provided in the student dashboard.

## Technologies Used

- PHP
- HTML
- CSS
- JavaScript
- MySQL

## Notes

- The `uploads/` directory stores uploaded images for books and user profiles.
- The `image/` and `images/` directories contain various images used in the system's interface.
- `db_connection.php` contains the database connection details.
- `admin_dashboard.php` is the main page for administrators.
- `student_dashboard.php` is the main page for students.
- `login.php` is the login page.
- `logout.php` is the logout page.
- `library_management.sql` is the database schema.
