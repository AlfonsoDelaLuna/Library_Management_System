<?php
// --- (Your existing PHP code, unchanged) ---
session_start();
include 'db_connection.php';

$student_number_error = "";
$form_submitted = false;
$student_data = null;
$hide_info = false;

function get_image_path($student_number, $uploads_dir = 'uploads')
{
    $extensions = ['.jpg', '.jpeg', '.png', '.gif'];
    foreach ($extensions as $ext) {
        $image_path = $uploads_dir . '/' . $student_number . $ext;
        if (file_exists($image_path)) {
            return $image_path;
        }
    }
    return 'images/no_image.png';
}

if (isset($_GET['student_number'])) {
    $student_number = $_GET['student_number'];

    if (strlen($student_number) != 11 || !ctype_digit($student_number) || (substr($student_number, 0, 4) != '0200' && substr($student_number, 0, 5) != '10000')) {
        echo json_encode(['error' => 'Invalid student number format.']);
        exit();
    }

    $student_stmt = $conn->prepare("SELECT last_name, first_name, middle_name, course FROM students WHERE student_number = ?");
    $student_stmt->bind_param("s", $student_number);
    $student_stmt->execute();
    $student_result = $student_stmt->get_result();

    if ($student_result->num_rows == 0) {
        echo json_encode(['error' => 'Student number not found.']);
        exit();
    }

    $student_data = $student_result->fetch_assoc();
    $student_stmt->close();
    $student_data['image_url'] = get_image_path($student_number);

    echo json_encode($student_data);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $form_submitted = true;
    $student_number = $_POST['student_number'];
    $action = $_POST['action'];
    $time_in_out_message = "";

    if (strlen($student_number) != 11 || !ctype_digit($student_number) || (substr($student_number, 0, 4) != '0200' && substr($student_number, 0, 5) != '10000')) {
        $student_number_error = "Student number must be 11 digits, contain only numbers, and start with 0200 or 10000.";
        $time_in_out_message = "<div class='message error'>" . htmlspecialchars($student_number_error) . "</div>";
    } else {
        $student_stmt = $conn->prepare("SELECT last_name, first_name, middle_name, course FROM students WHERE student_number = ?");
        $student_stmt->bind_param("s", $student_number);
        $student_stmt->execute();
        $student_result = $student_stmt->get_result();

        if ($student_result->num_rows == 0) {
            $student_number_error = "Student number not found.  Please ask the admin for signup.";
            $time_in_out_message = "<div class='message error'>" . htmlspecialchars($student_number_error) . "</div>";
            $student_stmt->close();
        } else {
            $student_data = $student_result->fetch_assoc();
            $student_stmt->close();
            $student_data['image_url'] = get_image_path($student_number);

            $last_name = $student_data['last_name'];
            $first_name = $student_data['first_name'];
            $middle_name = $student_data['middle_name'];
            $course = $student_data['course'];

            if ($action == 'time_in') {
                // No check for existing time-in, allow multiple
                $stmt = $conn->prepare("INSERT INTO login_records (student_number, last_name, first_name, middle_name, course, time_in) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("sssss", $student_number, $last_name, $first_name, $middle_name, $course);
            }
            // No else, Time Out is Removed

            if (empty($student_number_error)) {
                if (isset($stmt) && $stmt->execute()) {
                    $time_in_out_message = "<div class='message success'>Time In Successful!</div>";
                    $hide_info = true;
                } else {
                    $error_message = isset($stmt) ? $stmt->error : 'An unknown error occurred.';
                    $time_in_out_message = "<div class='message error'>Error during time in: " . htmlspecialchars($error_message) . "</div>";
                }
                if (isset($stmt)) {
                    $stmt->close();
                }
            }
        }
    }

    $_SESSION['student_number_error'] = $student_number_error;
    $_SESSION['time_in_out_message'] = $time_in_out_message;
    unset($_SESSION['student_data']);
}

$student_number_error = $_SESSION['student_number_error'] ?? '';
$time_in_out_message = $_SESSION['time_in_out_message'] ?? '';

if (isset($_SESSION['student_data'])) {
    $student_data = $_SESSION['student_data'];
}

unset($_SESSION['student_number_error']);
unset($_SESSION['time_in_out_message']);
unset($_SESSION['student_data']);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="image/sti_logo.png" type="image/png">
    <title>Student Dashboard</title>
    <style>
        /* --- (Your existing styles) --- */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: url('Studentbg.png') no-repeat center center fixed;
            background-size: cover;
            background-color: #f0f8ff;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            transition: background-color 0.5s ease;
        }

        .dashboard-container {
            display: flex;
            width: 90%;
            max-width: 1500px;
            background-color: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            position: relative;
            min-height: 800px;
            transition: background-color 0.5s ease;
        }

        .left-panel {
            flex: 1;
            padding: 30px;
            display: flex;
            flex-direction: column;
            align-items: center;
            height: 100%;
        }

        .right-panel {
            flex: 2;
            padding: 30px;
            background-color: #f7f7f7;
            display: flex;
            flex-direction: column;
            align-items: center;
            transition: background-color 0.5s ease;
        }

        h2 {
            color: #2c3e50;
            margin-top: 150px;
            margin-bottom: 40px;
            font-size: 3em;
            text-align: center;
            transition: color 0.5s ease;
        }

        form {
            display: flex;
            flex-direction: column;
            align-items: stretch;
            width: 100%;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        label {
            color: #34495e;
            margin-bottom: 8px;
            display: block;
            font-size: 1.1em;
            font-weight: 500;
            transition: color 0.5s ease;
        }

        input[type="text"] {
            padding: 12px;
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            font-size: 2.5em;
            color: #2c3e50;
            transition: border-color 0.3s ease, color 0.5s ease;
            width: 100%;
            box-sizing: border-box;
        }

        input[type="text"]:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 8px rgba(52, 152, 219, 0.3);
        }

        .error {
            border-color: #e74c3c !important;
        }

        .error-message {
            color: #e74c3c;
            font-size: 0.9em;
            margin-top: 5px;
            transition: color 0.5s ease;
        }

        button {
            padding: 15px;
            border: none;
            border-radius: 10px;
            color: white;
            font-size: 2em;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background-color 0.5s ease;
            margin-top: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            background-color: #2ecc71;
        }

        button[type="submit"]:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.25);
        }

        .student-info {
            text-align: center;
            margin-top: 20px;
        }

        .student-info img {
            margin-top: 100px;
            border-radius: 50%;
            margin-bottom: 20px;
            border: 3px solid #3498db;
            object-fit: cover;
            width: 400px;
            height: 400px;
            transition: border-color 0.5s ease;
        }

        .student-info p {
            margin: 5px 0;
            font-size: 2em;
            color: #2c3e50;
            transition: color 0.5s ease;
        }

        .loading-indicator {
            display: none;
            margin-top: 10px;
            color: #3498db;
            transition: color 0.5s ease;
        }

        .login-link {
            position: absolute;
            top: 10px;
            right: 10px;
            margin-top: 20px;
            text-align: center;
        }

        .login-link img {
            width: 50px;
            height: auto;
            cursor: pointer;
            border-radius: 50%;
        }

        .message {
            padding: 10px;
            margin-top: 10px;
            border-radius: 5px;
            text-align: center;
            font-weight: bold;
            animation: fadeOut 5s forwards;
            transition: background-color 0.5s ease, border-color 0.5s ease, color 0.5s ease;
        }

        .message.success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .message.error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        @keyframes fadeOut {
            0% {
                opacity: 1;
            }

            80% {
                opacity: 1;
            }

            100% {
                opacity: 0;
                display: none;
            }
        }

        .adjustable-container {
            overflow-y: auto;
            transition: max-height 0.5s ease;
            padding-right: 15px;
            box-sizing: border-box;
        }

        .adjustable-container::-webkit-scrollbar {
            width: 0.5em;
        }

        .adjustable-container::-webkit-scrollbar-track {
            background: transparent;
        }

        .adjustable-container::-webkit-scrollbar-thumb {
            background-color: transparent;
        }

        .hidden {
            display: none;
        }

        .button-container {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 10;
            font-size: small;
        }

        /* --- Dark Mode Styles --- */
        body.dark-mode {
            background-color: #121212;
        }

        .dark-mode .dashboard-container {
            background-color: #1e1e1e;
        }

        .dark-mode .right-panel {
            background-color: #292929;
        }

        .dark-mode h2,
        .dark-mode label,
        .dark-mode .student-info p,
        .dark-mode .loading-indicator {
            color: #f0f0f0;
        }

        .dark-mode input[type="text"] {
            background-color: #333;
            color: #fff;
            border-color: #555;
        }

        .dark-mode .error-message {
            color: #ff6b6b;
        }

        .dark-mode .student-info img {
            border-color: #ddd;
        }

        .dark-mode .message.success {
            background-color: #274e13;
            border-color: #387002;
            color: #c3e6cb;
        }

        .dark-mode .message.error {
            background-color: #4c1130;
            border-color: #721c24;
            color: #f5c6cb;
        }

        /* --- Dark Mode Toggle Styles (adjusted) --- */
        .dark-mode-toggle {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 10;
            display: flex;
            align-items: center;
            cursor: pointer;
        }

        .dark-mode-toggle img {
            width: 30px;
            height: 30px;
            margin-left: 10px;
        }

        .dark-mode #dark-mode-text {
            color: #fff;
        }
    </style>
</head>

<body>
    <!-- --- Admin Button --- -->
    <div class="button-container">
        <button type="button" onclick="window.location.href='login.php'">Admin</button>
    </div>

    <div class="dashboard-container">
        <!-- --- Dark Mode Toggle (moved inside) --- -->
        <div class="dark-mode-toggle" onclick="toggleDarkMode()">
            <span id="dark-mode-text">Light Mode</span>
            <img id="dark-mode-icon" src="images/sun.png" alt="Light Mode">
        </div>

        <div class="left-panel">
            <h2>Student Dashboard</h2>
            <form method="post">
                <div class="form-group">
                    <label for="student_number">Student Number:</label>
                    <input type="text" id="student_number" name="student_number" required pattern="\d{11}" maxlength="11" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '')" <?php if (!empty($student_number_error)) echo 'class="error"'; ?>>
                    <?php if (!empty($student_number_error)) echo '<div class="error-message">' . htmlspecialchars($student_number_error) . '</div>'; ?>
                    <div class="loading-indicator">Loading...</div>
                </div>
                <button type="submit" name="action" value="time_in">Time In</button>
                <?php echo $time_in_out_message; ?>
            </form>
        </div>

        <div class="right-panel">
            <div class="adjustable-container <?php if ($hide_info) echo 'hidden'; ?>">
                <div id="student-info-container" class="student-info">
                    <?php if ($student_data): ?>
                        <img src="<?php echo htmlspecialchars($student_data['image_url']); ?>" alt="Student Picture"
                            onerror="this.src='images/no_image.png';">
                        <p><strong>Name:</strong>
                            <?php echo htmlspecialchars($student_data['first_name'] . ' ' . ($student_data['middle_name'] ? $student_data['middle_name'] . ' ' : '') . $student_data['last_name']); ?>
                        </p>
                        <p><strong>Course:</strong> <?php echo htmlspecialchars($student_data['course']); ?></p>
                    <?php else: ?>
                        <p>Enter your student number to display your information.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // --- (Your existing script) ---
        const studentNumberInput = document.getElementById('student_number');
        const studentInfoContainer = document.getElementById('student-info-container');
        const loadingIndicator = document.querySelector('.loading-indicator');
        const adjustableContainer = document.querySelector('.adjustable-container');

        studentNumberInput.addEventListener('input', () => {
            const studentNumber = studentNumberInput.value.trim();

            studentInfoContainer.innerHTML = '';
            loadingIndicator.style.display = 'block';
            adjustableContainer.classList.remove('hidden');

            if (studentNumber.length !== 11 || !/^\d+$/.test(studentNumber) || (!studentNumber.startsWith('0200') && !studentNumber.startsWith('10000'))) {
                loadingIndicator.style.display = 'none';
                return;
            }

            adjustableContainer.classList.remove('hidden');

            fetch(`?student_number=${encodeURIComponent(studentNumber)}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    loadingIndicator.style.display = 'none';

                    if (data.error) {
                        studentInfoContainer.innerHTML = `<p>${data.error}</p>`;
                    } else {
                        const firstName = data.first_name || '';
                        const middleName = data.middle_name ? ` ${data.middle_name} ` : '';
                        const lastName = data.last_name || '';
                        const course = data.course || '';
                        const imageUrl = data.image_url;

                        const img = document.createElement('img');
                        img.src = imageUrl;
                        img.alt = "Student Picture";
                        let placeholderTried = false;

                        img.onload = () => {};

                        img.onerror = () => {
                            if (!placeholderTried) {
                                placeholderTried = true;
                                setTimeout(() => {
                                    if (img.naturalWidth === 0) {
                                        img.src = 'images/no_image.png';
                                    }
                                }, 500);
                            }
                        };

                        const html = `
                            <p><strong>Name:</strong> ${firstName}${middleName}${lastName}</p>
                            <p><strong>Course:</strong> ${course}</p>
                        `;
                        studentInfoContainer.innerHTML = html;
                        studentInfoContainer.prepend(img);

                        <?php $_SESSION['student_data'] = 'data'; ?>
                    }
                })
                .catch(error => {
                    loadingIndicator.style.display = 'none';
                    console.error('Error:', error);
                    studentInfoContainer.innerHTML = `<p>An error occurred: ${error.message}</p>`;
                });
        });

        document.querySelector('form').addEventListener('submit', (event) => {
            adjustableContainer.classList.add('hidden');
        });

        // --- Dark Mode Toggle Script (updated) ---
        function toggleDarkMode() {
            const body = document.body;
            const icon = document.getElementById('dark-mode-icon');
            const text = document.getElementById('dark-mode-text');
            body.classList.toggle('dark-mode');
            const isDarkMode = body.classList.contains('dark-mode');

            if (isDarkMode) {
                icon.src = 'images/moon.png';
                icon.alt = 'Dark Mode';
                text.textContent = 'Dark Mode';
            } else {
                icon.src = 'images/sun.png';
                icon.alt = 'Light Mode';
                text.textContent = 'Light Mode';
            }
            localStorage.setItem('darkMode', isDarkMode);
        }

        // --- Check for saved preference on load (updated) ---
        document.addEventListener('DOMContentLoaded', () => {
            const isDarkMode = localStorage.getItem('darkMode') === 'true';
            const icon = document.getElementById('dark-mode-icon');
            const text = document.getElementById('dark-mode-text');

            if (isDarkMode) {
                document.body.classList.add('dark-mode');
                icon.src = 'images/moon.png';
                icon.alt = 'Dark Mode';
                text.textContent = 'Dark Mode';
            } else {
                icon.src = 'images/sun.png';
                icon.alt = 'Light Mode';
                text.textContent = 'Light Mode';
            }
            if (studentNumberInput) {
                studentNumberInput.focus();
            }
        });
    </script>
</body>

</html>