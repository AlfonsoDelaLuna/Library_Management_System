<?php
session_start();
include 'db_connection.php';

$error_message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Prepare the statement
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND password = ?");
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role']; // Store the role

        // Redirect based on role
        if ($user['role'] == 'admin') {
            header("Location: admin_dashboard.php");
        } else {
            //  redirect to student_dashboard.php if not an admin
            header("Location: student_dashboard.php");
        }
        exit();
    } else {
        $error_message = "Invalid username or password";
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="image/sti_logo.png" type="image/png">
    <title>Login</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: url('library.png') no-repeat center center fixed;
            background-size: cover;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }

        .login-container {
            background-color: rgba(255, 255, 255, 0.9);
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
            width: 350px;
            text-align: center;
            backdrop-filter: blur(12px);
        }

        h2 {
            margin-bottom: 30px;
            color: #2c3e50;
            font-size: 2.4em;
            font-weight: 500;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #34495e;
            font-size: 1.1em;
            font-weight: 500;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            margin-bottom: 15px;
            border: 2px solid #bdc3c7;
            border-radius: 8px;
            font-size: 1.1em;
            color: #2c3e50;
            transition: border-color 0.3s ease;
            box-sizing: border-box;
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 8px rgba(52, 152, 219, 0.3);
        }

        button {
            width: 100%;
            padding: 15px;
            background-color: #3498db;
            border: none;
            border-radius: 10px;
            color: white;
            font-size: 1.2em;
            cursor: pointer;
            margin-bottom: 10px;
            /* Add some space between buttons */
        }

        /* Style for the return button */
        .return-button {
            width: 100%;
            padding: 15px;
            background-color: #7f8c8d;
            /* Different color for distinction */
            border: none;
            border-radius: 10px;
            color: white;
            font-size: 1.2em;
            cursor: pointer;
        }


        .error {
            color: #e74c3c;
            margin-bottom: 20px;
            font-size: 1.1em;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <h2>Library Management System</h2>
        <?php if ($error_message): ?>
            <div class="error"><?php echo $error_message; ?></div>
        <?php endif; ?>
        <form action="login.php" method="post">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit">Login</button>
        </form>
        <!-- Return Button -->
        <a href="student_dashboard.php"><button type="button" class="return-button">Return</button></a>
    </div>
</body>

</html>