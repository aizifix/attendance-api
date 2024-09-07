<?php
session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
include 'connection.php';

class Admin {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    // Fetch all attendance records
    public function fetchAttendanceRecords($eventId = null) {
        try {
            $sql = "SELECT a.attendance_id, s.first_name, s.last_name, t.tribu_name AS tribu, e.event_name, e.event_date, a.check_in_time, a.check_out_time, a.status 
                    FROM attendance a
                    JOIN students s ON a.student_id = s.student_id
                    JOIN events e ON a.event_id = e.event_id
                    LEFT JOIN tribus t ON s.tribu_id = t.tribu_id"; // Join tribus to get tribu_name
            
            if ($eventId) {
                $sql .= " WHERE a.event_id = :event_id";
            }

            $stmt = $this->pdo->prepare($sql);
            
            if ($eventId) {
                $stmt->bindParam(':event_id', $eventId);
            }

            $stmt->execute();
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return ['success' => true, 'records' => $records];
        } catch (Exception $e) {
            return ['error' => 'Failed to fetch attendance records: ' . $e->getMessage()];
        }
    }

    // Fetch all events
    public function fetchEvents() {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM events ORDER BY event_date DESC");
            $stmt->execute();
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return ['success' => true, 'events' => $events];
        } catch (Exception $e) {
            return ['error' => 'Failed to fetch events: ' . $e->getMessage()];
        }
    }

    // Add new event
    public function addEvent($data) {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO events (event_name, event_date, check_in_time, check_out_time, event_code, active) 
                VALUES (:event_name, :event_date, :check_in_time, :check_out_time, :event_code, 0)" // Add event as inactive by default
            );
            $stmt->bindParam(':event_name', $data['event_name']);
            $stmt->bindParam(':event_date', $data['event_date']);
            $stmt->bindParam(':check_in_time', $data['check_in_time']);
            $stmt->bindParam(':check_out_time', $data['check_out_time']);
            $stmt->bindParam(':event_code', $data['event_code']);

            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Event added successfully'];
            } else {
                return ['error' => 'Failed to add event'];
            }
        } catch (Exception $e) {
            return ['error' => 'Error adding event: ' . $e->getMessage()];
        }
    }

    // Activate an event and deactivate others
    public function activateEvent($eventId) {
        try {
            // Deactivate all events
            $this->pdo->prepare("UPDATE events SET active = 0")->execute();

            // Activate the selected event
            $stmt = $this->pdo->prepare("UPDATE events SET active = 1 WHERE event_id = :event_id");
            $stmt->bindParam(':event_id', $eventId);

            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Event activated successfully'];
            } else {
                return ['error' => 'Failed to activate event'];
            }
        } catch (Exception $e) {
            return ['error' => 'Error activating event: ' . $e->getMessage()];
        }
    }

    // Delete an attendance record
    public function deleteAttendanceRecord($attendanceId) {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM attendance WHERE attendance_id = :attendance_id");
            $stmt->bindParam(':attendance_id', $attendanceId);
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Record deleted successfully'];
            } else {
                return ['error' => 'Failed to delete the record'];
            }
        } catch (Exception $e) {
            return ['error' => 'Error deleting record: ' . $e->getMessage()];
        }
    }

    // Edit an attendance record
    public function editAttendanceRecord($data) {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE attendance SET first_name = :first_name, last_name = :last_name, check_in_time = :check_in_time, check_out_time = :check_out_time 
                 WHERE attendance_id = :attendance_id"
            );
            $stmt->bindParam(':first_name', $data['first_name']);
            $stmt->bindParam(':last_name', $data['last_name']);
            $stmt->bindParam(':check_in_time', $data['check_in_time']);
            $stmt->bindParam(':check_out_time', $data['check_out_time']);
            $stmt->bindParam(':attendance_id', $data['attendance_id']);
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Record updated successfully'];
            } else {
                return ['error' => 'Failed to update the record'];
            }
        } catch (Exception $e) {
            return ['error' => 'Error updating record: ' . $e->getMessage()];
        }
    }
}

// Instantiate the Admin class and handle actions
$admin = new Admin($pdo);
$action = $_GET['action'] ?? null;
$data = json_decode(file_get_contents('php://input'), true);

switch ($action) {
    // Attendance-related cases
    case 'fetchAttendance':
        $eventId = $_GET['event_id'] ?? null;
        $response = $admin->fetchAttendanceRecords($eventId);
        echo json_encode($response);
        break;

    case 'deleteAttendance':
        $response = $admin->deleteAttendanceRecord($data['attendance_id']);
        echo json_encode($response);
        break;

    case 'editAttendance':
        $response = $admin->editAttendanceRecord($data);
        echo json_encode($response);
        break;

    // Event-related cases
    case 'fetchEvents':
        $response = $admin->fetchEvents();
        echo json_encode($response);
        break;

    case 'addEvent':
        $response = $admin->addEvent($data);
        echo json_encode($response);
        break;

    case 'activateEvent':
        $response = $admin->activateEvent($data['event_id']);
        echo json_encode($response);
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
}
?>
