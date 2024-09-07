<?php
session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
include 'connection.php';

class User {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    // Handle check-in and check-out logic based on QR code scan
    public function handleAttendance($studentId, $eventId) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM attendance WHERE student_id = :student_id AND event_id = :event_id");
            $stmt->bindParam(':student_id', $studentId);
            $stmt->bindParam(':event_id', $eventId);
            $stmt->execute();
            $attendance = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($attendance && !$attendance['check_out_time']) {
                $updateStmt = $this->pdo->prepare(
                    "UPDATE attendance SET check_out_time = :check_out_time, status = 'PRESENT' WHERE attendance_id = :attendance_id"
                );
                $checkOutTime = date('Y-m-d H:i:s');
                $updateStmt->bindParam(':check_out_time', $checkOutTime);
                $updateStmt->bindParam(':attendance_id', $attendance['attendance_id']);
                
                if ($updateStmt->execute()) {
                    return ['success' => true, 'message' => 'Checked out successfully. Status: PRESENT'];
                } else {
                    return ['error' => 'Failed to update check-out time'];
                }
            }

            // If the student hasn't checked in yet, insert a new attendance record
            if (!$attendance) {
                $insertStmt = $this->pdo->prepare(
                    "INSERT INTO attendance (student_id, event_id, check_in_time, status) 
                    VALUES (:student_id, :event_id, :check_in_time, 'INCOMPLETE')"
                );
                $checkInTime = date('Y-m-d H:i:s');
                $insertStmt->bindParam(':student_id', $studentId);
                $insertStmt->bindParam(':event_id', $eventId);
                $insertStmt->bindParam(':check_in_time', $checkInTime);

                if ($insertStmt->execute()) {
                    return ['success' => true, 'message' => 'Checked in successfully. Status: INCOMPLETE'];
                } else {
                    return ['error' => 'Failed to insert check-in time'];
                }
            }

            return ['message' => 'Attendance already completed. Status: PRESENT'];

        } catch (Exception $e) {
            return ['error' => 'An unexpected error occurred: ' . $e->getMessage()];
        }
    }

    // Login user and fetch current active event or generate QR code
    public function login($idNumber, $password) {
        try {
            // Fetch student info with tribu name
            $stmt = $this->pdo->prepare(
                "SELECT s.*, t.tribu_name 
                 FROM students s 
                 LEFT JOIN tribus t ON s.tribu_id = t.tribu_id 
                 WHERE id_number = :id_number"
            );
            $stmt->bindParam(':id_number', $idNumber);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return ['error' => 'User not found with this ID number'];
            }

            if (!password_verify($password, $user['password_hash'])) {
                return ['error' => 'Invalid password'];
            }

            // Fetch the active event
            $stmt = $this->pdo->prepare("SELECT * FROM events WHERE active = 1 LIMIT 1");
            $stmt->execute();
            $event = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$event) {
                return [
                    'success' => true,
                    'qr_data' => null,
                    'message' => 'No active event available',
                    'user' => [
                        'role' => $user['role']
                    ]
                ];
            }

            // Generate QR code data for the student, including tribu name
            $qrData = [
                'student_id' => $user['student_id'],
                'id_number' => $user['id_number'],
                'event_id' => $event['event_id'],
                'name' => $user['first_name'] . ' ' . $user['last_name'],
                'tribu_name' => $user['tribu_name'],
                'event_name' => $event['event_name'],
                'check_in_time' => $event['check_in_time'],
                'check_out_time' => $event['check_out_time']
            ];

            // Store user session
            $_SESSION['user'] = $user;
            $_SESSION['qr_data'] = $qrData;

            return [
                'success' => true,
                'qr_data' => $qrData,
                'user' => [
                    'role' => $user['role']
                ]
            ];
        } catch (Exception $e) {
            return ['error' => 'An unexpected error occurred during login: ' . $e->getMessage()];
        }
    }

    // Register a new user
    public function register($firstName, $lastName, $email, $password, $idNumber, $yearLevel, $section, $tribu) {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM students WHERE email = :email OR id_number = :id_number");
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':id_number', $idNumber);
            $stmt->execute();
            if ($stmt->fetchColumn() > 0) {
                return ['error' => 'Email or ID number already registered'];
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $this->pdo->prepare(
                "INSERT INTO students (first_name, last_name, email, password_hash, id_number, year_level, section, tribu_id, role) 
                VALUES (:first_name, :last_name, :email, :password_hash, :id_number, :year_level, :section, :tribu, 'student')"
            );
            $stmt->bindParam(':first_name', $firstName);
            $stmt->bindParam(':last_name', $lastName);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password_hash', $passwordHash);
            $stmt->bindParam(':id_number', $idNumber);
            $stmt->bindParam(':year_level', $yearLevel);
            $stmt->bindParam(':section', $section);
            $stmt->bindParam(':tribu', $tribu);

            if ($stmt->execute()) {
                return ['success' => 'Registration successful'];
            } else {
                return ['error' => 'Registration failed'];
            }
        } catch (Exception $e) {
            return ['error' => 'An error occurred during registration: ' . $e->getMessage()];
        }
    }

    // Fetch list of tribes
    public function fetchTribes() {
        try {
            $stmt = $this->pdo->prepare("SELECT tribu_id, tribu_name FROM tribus");
            $stmt->execute();
            $tribes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return json_encode($tribes);
        } catch (Exception $e) {
            return json_encode(['error' => 'Failed to fetch tribes: ' . $e->getMessage()]);
        }
    }

    // Fetch past attendance records for the user
    public function fetchPastAttendance($studentId) {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT e.event_name, e.event_date, a.check_in_time, a.check_out_time, a.status 
                 FROM attendance a
                 JOIN events e ON a.event_id = e.event_id
                 WHERE a.student_id = :student_id"
            );
            $stmt->bindParam(':student_id', $studentId);
            $stmt->execute();
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$records) {
                return ['message' => 'No attendance records found'];
            }

            return ['success' => true, 'records' => $records];
        } catch (Exception $e) {
            return ['error' => 'Failed to fetch past attendance records: ' . $e->getMessage()];
        }
    }

    // Change password for the user
    public function changePassword($studentId, $oldPassword, $newPassword) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM students WHERE student_id = :student_id");
            $stmt->bindParam(':student_id', $studentId);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($oldPassword, $user['password_hash'])) {
                return ['error' => 'Old password is incorrect'];
            }

            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $this->pdo->prepare("UPDATE students SET password_hash = :password_hash WHERE student_id = :student_id");
            $stmt->bindParam(':password_hash', $newPasswordHash);
            $stmt->bindParam(':student_id', $studentId);

            if ($stmt->execute()) {
                return ['success' => 'Password changed successfully'];
            } else {
                return ['error' => 'Failed to change password'];
            }
        } catch (Exception $e) {
            return ['error' => 'An error occurred while changing password: ' . $e->getMessage()];
        }
    }

    // Logout user and destroy session
    public function logout() {
        session_destroy();
        return ['success' => true, 'message' => 'Logged out successfully'];
    }
}

// Instantiate the User class and process the action
$user = new User($pdo);
$action = $_GET['action'] ?? null;
$data = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

switch ($action) {
    case 'fetchTribes':
        echo $user->fetchTribes();
        break;

    case 'register':
        $response = $user->register(
            $data['firstName'],
            $data['lastName'],
            $data['email'],
            $data['password'],
            $data['idNumber'],
            $data['yearLevel'],
            $data['section'],
            $data['tribu']
        );
        echo json_encode($response);
        break;

    case 'login':
        $response = $user->login($data['idNumber'], $data['password']);
        echo json_encode($response);
        break;

    case 'handleAttendance':
        $response = $user->handleAttendance($data['student_id'], $data['event_id']);
        echo json_encode($response);
        break;

    case 'fetchPastAttendance':
        $response = $user->fetchPastAttendance($data['student_id']);
        echo json_encode($response);
        break;

    case 'changePassword':
        $response = $user->changePassword($data['student_id'], $data['oldPassword'], $data['newPassword']);
        echo json_encode($response);
        break;

    case 'logout':
        $response = $user->logout();
        echo json_encode($response);
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
}
?>
