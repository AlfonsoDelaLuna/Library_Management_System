# Library Management System
It is a web-based application developed using PHP, HTML, CSS, and JavaScript that records and manages students’ time in and time out when accessing the library. It provides librarians with an efficient way to monitor student attendance and track how long each student spends in the library. Instead of relying on manual log sheets, the system automatically captures entry and exit times, stores the data securely in a database, and allows librarians to retrieve attendance records easily for documentation or analysis. This helps improve accuracy, save time, and maintain organized digital records of library usage.

## Process Before
Before, there was already an existing library system in our school. When entering the library, the user needs to type their student number in the login textbox to access their books. When the student is ready to leave, whether due to their next class, break time, or closing, they must type their student number again to log out of the text box. The user's login and logout data are stored in the admin via Excel for documentation.

## Problem Encountered
After a long period of use, the library management system has begun to malfunction. Specifically, when a user enters their student number to log in or log out, the data is not stored correctly and therefore is not recorded in the admin’s Excel file. To address this issue, the librarian decided to create a paper-based authentication system to maintain operations. Additionally, the log-out feature was removed because during lunch breaks or at closing time, long queues would form, causing significant delays in the process.

## Objectives

During my OJT, my members and I are tasked to solve the issue to create a library management system using these objectives:
1. Data transfer from user data to admin - The main objective is that when a user types their student number, it will save that data to the admin side of that system. 
2. Availability to download - The admin can easily download their login data via Excel for documentation.
3. Removal of logout - The admin requested to remove the logout feature for the students due to the long queue during lunch breaks or closing.

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

- The `uploads/` directory stores uploaded images for books and user profiles that will shown in the student dashboard.
- The `image/` and `images/` directories contain various images used in the system's interface.
- `db_connection.php` contains the database connection details.
- `admin_dashboard.php` is the main page for administrators.
- `student_dashboard.php` is the main page for the student to write their student number.
- `login.php` is the login page.
- `logout.php` is the logout page.
- `library_management.sql` is the database schema.
